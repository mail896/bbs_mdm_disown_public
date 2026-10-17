<?php

declare(strict_types=1);

require_once __DIR__ . '/workflow_state.php';

/** @param array<string, mixed> $result */
function persist_jamf_workflow_result(mysqli $mysqli, int $requestId, array $result): string
{
    $state = (string) ($result['state'] ?? DISOWN_JAMF_STATE_FAILED);
    if (!disown_jamf_state_is_valid($state)) {
        $state = DISOWN_JAMF_STATE_FAILED;
    }
    $currentStmt = $mysqli->prepare('SELECT jamf_unenroll_state, jamf_unenrolled, jamf_unenroll_error FROM requests WHERE id = ?');
    if (!$currentStmt) {
        throw new RuntimeException('Aktueller Jamf-Zustand konnte nicht vorbereitet werden.');
    }
    $currentStmt->bind_param('i', $requestId);
    if (!$currentStmt->execute()) {
        $currentStmt->close();
        throw new RuntimeException('Aktueller Jamf-Zustand konnte nicht gelesen werden.');
    }
    $currentRow = $currentStmt->get_result()->fetch_assoc();
    $currentStmt->close();
    if (!$currentRow) {
        throw new RuntimeException('Antrag für Jamf-Zustand wurde nicht gefunden.');
    }
    $currentState = disown_jamf_state_from_row($currentRow);
    if (!disown_jamf_can_transition($currentState, $state)) {
        throw new RuntimeException('Unzulässiger Jamf-Zustandswechsel von ' . $currentState . ' nach ' . $state . '.');
    }
    $verified = $state === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED ? 1 : 0;
    $accepted = !empty($result['accepted']) ? 1 : 0;
    $message = trim((string) ($result['message'] ?? ''));
    $error = in_array($state, [DISOWN_JAMF_STATE_FAILED, DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED], true)
        ? ($message !== '' ? $message : 'Jamf-Abmeldung ist nicht bestätigt.')
        : null;
    $httpStatus = isset($result['http_status']) ? (int) $result['http_status'] : null;
    $responseClass = substr(trim((string) ($result['response_class'] ?? 'unknown')), 0, 64);
    $commandId = substr(trim((string) ($result['command_id'] ?? '')), 0, 128) ?: null;
    $commandStatus = substr(trim((string) ($result['command_status'] ?? '')), 0, 32) ?: null;
    $location = substr(trim((string) ($result['observed_location'] ?? 'unknown')), 0, 16);
    $observedManaged = array_key_exists('observed_is_managed', $result) && $result['observed_is_managed'] !== null
        ? (!empty($result['observed_is_managed']) ? 1 : 0)
        : null;
    $activeAbsent = !empty($result['active_absent']) ? 1 : 0;
    $trashObserved = !empty($result['trash_observed']) ? 1 : 0;

    $stmt = $mysqli->prepare(
        "UPDATE requests
         SET jamf_unenroll_state = ?,
             jamf_unenrolled = ?,
             jamf_unenrolled_at = IF(? = 1, COALESCE(jamf_unenrolled_at, NOW()), jamf_unenrolled_at),
             jamf_unenroll_error = ?,
             jamf_command_accepted_at = IF(? = 1, COALESCE(jamf_command_accepted_at, NOW()), jamf_command_accepted_at),
             jamf_verified_unmanaged_at = IF(? = 1, COALESCE(jamf_verified_unmanaged_at, NOW()), jamf_verified_unmanaged_at),
             jamf_last_checked_at = NOW(),
             jamf_next_check_at = IF(? = 'pending_device', DATE_ADD(NOW(), INTERVAL 15 MINUTE), NULL),
             jamf_check_attempts = jamf_check_attempts + 1,
             jamf_last_http_status = ?,
             jamf_response_class = ?,
             jamf_command_id = COALESCE(?, jamf_command_id),
             jamf_command_status = COALESCE(?, jamf_command_status),
             jamf_command_status_checked_at = IF(? IS NULL, jamf_command_status_checked_at, NOW()),
             jamf_observed_location = ?,
             jamf_observed_is_managed = ?,
             jamf_active_absent_at = IF(? = 1, COALESCE(jamf_active_absent_at, NOW()), jamf_active_absent_at),
             jamf_trash_observed_at = IF(? = 1, COALESCE(jamf_trash_observed_at, NOW()), jamf_trash_observed_at)
         WHERE id = ?
           AND jamf_unenroll_state = ?"
    );
    if (!$stmt) {
        throw new RuntimeException('Jamf-Zustand konnte nicht vorbereitet werden.');
    }
    $stmt->bind_param(
        'siisiisisssssiiiis',
        $state,
        $verified,
        $verified,
        $error,
        $accepted,
        $verified,
        $state,
        $httpStatus,
        $responseClass,
        $commandId,
        $commandStatus,
        $commandStatus,
        $location,
        $observedManaged,
        $activeAbsent,
        $trashObserved,
        $requestId,
        $currentState
    );
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Jamf-Zustand konnte nicht gespeichert werden.');
    }
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('Jamf-Zustand wurde parallel verändert; bitte erneut laden.');
    }
    $stmt->close();

    return $state;
}

function persist_completion_mail_result(
    mysqli $mysqli,
    int $requestId,
    int $mailState,
    string $deliveryStatus,
    string $completedBy
): bool {
    // Der Mailversand ist ein nachgelagerter Benachrichtigungsversuch. Auch ein
    // dokumentierter Zustellfehler (2) darf die fachliche Abmeldung nicht offenhalten.
    $completed = in_array($mailState, [1, 2], true) ? 1 : 0;
    $stmt = $mysqli->prepare(
        "UPDATE requests
         SET mail_sent = ?,
             mail_sent_at = NOW(),
             mail_sent_to = ?,
             status = IF(? = 1, 'erledigt', status),
             completed_at = IF(? = 1, COALESCE(completed_at, NOW()), completed_at),
             completed_by = IF(? = 1, COALESCE(completed_by, ?), completed_by)
         WHERE id = ?"
    );
    if (!$stmt) {
        throw new RuntimeException('Mailstatus konnte nicht vorbereitet werden.');
    }
    $stmt->bind_param('isiiisi', $mailState, $deliveryStatus, $completed, $completed, $completed, $completedBy, $requestId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Mailstatus konnte nicht gespeichert werden.');
    }
    $changed = $stmt->affected_rows === 1;
    $stmt->close();
    return $changed;
}
