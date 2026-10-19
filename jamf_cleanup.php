<?php

declare(strict_types=1);

require_once __DIR__ . '/jamf.php';
require_once __DIR__ . '/workflow_store.php';

/** Same scope for UI and job, including completed/mail-failed requests. */
function disown_jamf_cleanup_scope_sql(): string
{
    return "asm_manual_done = 1 AND COALESCE(completed_by, '') <> 'history-import'
        AND (jamf_unenroll_state = 'verified_unmanaged'
            OR (jamf_command_accepted_at IS NOT NULL
                AND jamf_unenroll_state IN ('command_accepted', 'pending_device', 'manual_intervention_required')))
        AND LOWER(TRIM(COALESCE(jamf_command_status, ''))) NOT IN ('failed', 'error', 'cancelled', 'canceled', 'rejected')";
}

/** Only actionable findings count as Nacharbeit, not the normal grace period. */
function disown_jamf_cleanup_open_sql(): string
{
    return '(' . disown_jamf_cleanup_scope_sql()
        . ") AND jamf_cleanup_state IN ('manual_trash_required', 'lookup_error', 'conflict', 'not_found')";
}

/** Trash proves inventory cleanup, NOT removal of the profile on the device. */
function disown_jamf_cleanup_classify(array $row, array $observation, ?int $now = null): string
{
    if (empty($observation['active_lookup_ok']) || empty($observation['trash_lookup_ok'])) {
        return 'lookup_error';
    }
    $active = !empty($observation['active']['found']);
    $trash = !empty($observation['trash']['found']);
    if ($active && $trash) {
        return 'conflict';
    }
    if (!$active && $trash) {
        return 'trashed';
    }
    if (!$active) {
        return 'not_found';
    }
    $releaseTime = strtotime((string) ($row['asm_manual_done_at'] ?? ''));
    if ($releaseTime === false) {
        return 'conflict';
    }
    return ($now ?? time()) - $releaseTime < 900 ? 'waiting' : 'manual_trash_required';
}

function disown_jamf_cleanup_label(string $state): string
{
    return match ($state) {
        'manual_trash_required' => 'Apple freigegeben · bitte in Jamf in den Papierkorb verschieben',
        'trashed' => 'Jamf-Bestand bereinigt (Trash)',
        'lookup_error' => 'Jamf-Nachprüfung nicht verfügbar · kein Trash-Befund',
        'conflict' => 'Jamf-Zuordnung widersprüchlich · manuell prüfen',
        'not_found' => 'Weder im aktiven Jamf-Bestand noch im Trash gefunden · manuell prüfen',
        'waiting' => 'Apple freigegeben · kurze Jamf-Nachprüfung läuft',
        default => 'Apple freigegeben · Jamf-Nachprüfung ausstehend',
    };
}

/** Persist only local evidence. Never calls Unenroll, Trash, Apple or mail. */
function persist_jamf_cleanup_observation(mysqli $mysqli, array $row, array $observation): string
{
    $id = (int) ($row['id'] ?? 0);
    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare('SELECT * FROM requests WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$current || strtoupper(trim((string) $current['serial'])) !== strtoupper(trim((string) ($observation['serial'] ?? '')))
            || empty($current['asm_manual_done']) || !disown_request_has_release_basis($current)) {
            throw new RuntimeException('Antrag oder Seriennummer wurde während der Prüfung verändert.');
        }
        $state = disown_jamf_cleanup_classify($current, $observation);
        $verified = jamf_verification_result($observation,
            !empty($current['jamf_command_accepted_at']), $current['jamf_command_accepted_at'] ?? null);
        $oldJamfState = disown_jamf_state_from_row($current);
        if ($oldJamfState !== DISOWN_JAMF_STATE_VERIFIED_UNMANAGED
            && ($verified['state'] === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED
                || ($verified['state'] === DISOWN_JAMF_STATE_PENDING_DEVICE && !empty($current['jamf_command_accepted_at'])))) {
            $verified['accepted'] = false;
            $verified['http_status'] = $observation['active_http_status'] ?? null;
            $verified['response_class'] = 'background_readonly_check';
            persist_jamf_workflow_result($mysqli, $id, $verified);
        }
        $delay = match ($state) {
            'waiting', 'manual_trash_required' => 5, 'lookup_error' => 15, 'trashed' => 1440, default => 60,
        };
        $error = in_array($state, ['lookup_error', 'conflict', 'not_found'], true)
            ? disown_jamf_cleanup_label($state) : null;
        $location = (string) $verified['observed_location'];
        $managed = $verified['observed_is_managed'] === null ? null : (int) $verified['observed_is_managed'];
        $stmt = $mysqli->prepare("UPDATE requests SET
            jamf_cleanup_state = ?, jamf_cleanup_checked_at = NOW(),
            jamf_cleanup_next_check_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
            jamf_cleanup_needed_at = IF(? = 'manual_trash_required', COALESCE(jamf_cleanup_needed_at, NOW()), jamf_cleanup_needed_at),
            jamf_cleanup_resolved_at = IF(? = 'trashed', COALESCE(jamf_cleanup_resolved_at, NOW()), NULL),
            jamf_cleanup_error = ?, jamf_last_checked_at = NOW(),
            jamf_observed_location = ?, jamf_observed_is_managed = ?
            WHERE id = ?");
        $stmt->bind_param('sissssii', $state, $delay, $state, $state, $error, $location, $managed, $id);
        $stmt->execute();
        $stmt->close();
        if (($current['jamf_cleanup_state'] ?? 'not_checked') !== $state
            || ($oldJamfState !== DISOWN_JAMF_STATE_VERIFIED_UNMANAGED && $verified['state'] === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED)) {
            $event = match ($state) {
                'manual_trash_required' => 'JAMF_CLEANUP_REQUIRED',
                'trashed' => 'JAMF_CLEANUP_TRASH_CONFIRMED',
                'waiting' => 'JAMF_CLEANUP_WAITING',
                default => 'JAMF_CLEANUP_CHECK_PROBLEM',
            };
            $actor = 'system:jamf-cleanup-check';
            $detail = disown_jamf_cleanup_label($state) . '; nur read-only; Jamf-Verwaltungsstatus: '
                . ($verified['state'] === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED ? 'verifiziert' : 'hierdurch nicht bestätigt');
            $audit = $mysqli->prepare('INSERT INTO request_audit_log (request_id, action, admin_user, details) VALUES (?, ?, ?, ?)');
            $audit->bind_param('isss', $id, $event, $actor, $detail);
            $audit->execute();
            $audit->close();
        }
        $mysqli->commit();
        return $state;
    } catch (Throwable $e) {
        $mysqli->rollback();
        throw $e;
    }
}

/** Only DEV demo serials, deliberately including stale Trash status fields. */
function disown_jamf_cleanup_observe(array $row): array
{
    $serial = strtoupper(trim((string) ($row['serial'] ?? '')));
    if (disown_is_dev_mode() && str_starts_with($serial, 'FAKE0')) {
        $trash = in_array($serial, ['FAKE0DIANA1', 'FAKE0MULDER'], true);
        $ok = $serial !== 'FAKE0KNOPE1';
        return ['serial' => $serial, 'active_lookup_ok' => $ok, 'trash_lookup_ok' => $ok,
            'active_http_status' => $ok ? 200 : 503, 'trash_http_status' => $ok ? 200 : 503,
            'active' => ['found' => !$trash, 'is_managed' => true],
            'trash' => ['found' => $trash, 'is_managed' => true,
                'modified' => '2026-10-17 16:48:02', 'last_checkin' => '2026-10-17 14:33:59']];
    }
    // Bound every GET so cron cannot pile up on a slow external service.
    return jamf_observe_serial($serial, static function (array $request): array {
        $request['connect_timeout'] = 3;
        $request['timeout'] = 5;
        return jamf_curl_transport($request);
    });
}
