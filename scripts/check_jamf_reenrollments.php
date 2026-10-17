<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../db.php';
require __DIR__ . '/../jamf.php';

$limit = 100;
foreach ($argv as $argument) {
    if (preg_match('/^--limit=(\d+)$/', $argument, $match)) {
        $limit = max(1, min(500, (int) $match[1]));
    }
}

$stmt = $mysqli->prepare(
    "SELECT id, serial
     FROM requests
     WHERE jamf_unenroll_state = 'verified_unmanaged'
       AND completed_at IS NOT NULL
       AND jamf_reenrollment_detected_at IS NULL
       AND TRIM(COALESCE(serial, '')) <> ''
     ORDER BY COALESCE(jamf_last_checked_at, '1000-01-01 00:00:00') ASC, completed_at ASC
     LIMIT ?"
);
if (!$stmt) {
    fwrite(STDERR, "Wiederanmeldungsprüfung konnte nicht vorbereitet werden.\n");
    exit(1);
}
$stmt->bind_param('i', $limit);
if (!$stmt->execute()) {
    fwrite(STDERR, "Wiederanmeldungsprüfung konnte nicht gestartet werden.\n");
    $stmt->close();
    exit(1);
}
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$checked = 0;
$detected = 0;
$unavailable = 0;
foreach ($rows as $row) {
    $requestId = (int) ($row['id'] ?? 0);
    $serial = strtoupper(trim((string) ($row['serial'] ?? '')));
    if ($requestId <= 0 || $serial === '') {
        continue;
    }

    $observation = jamf_observe_serial($serial);
    $touch = $mysqli->prepare('UPDATE requests SET jamf_last_checked_at = NOW() WHERE id = ?');
    if ($touch) {
        $touch->bind_param('i', $requestId);
        $touch->execute();
        $touch->close();
    }
    if (empty($observation['active_lookup_ok'])) {
        $unavailable++;
        continue;
    }
    $checked++;
    if (!disown_reenrollment_detected($observation)) {
        continue;
    }

    $update = $mysqli->prepare(
        'UPDATE requests
         SET jamf_reenrollment_detected_at = COALESCE(jamf_reenrollment_detected_at, NOW())
         WHERE id = ?
           AND jamf_reenrollment_detected_at IS NULL'
    );
    if (!$update) {
        continue;
    }
    $update->bind_param('i', $requestId);
    $update->execute();
    $changed = $update->affected_rows === 1;
    $update->close();
    if (!$changed) {
        continue;
    }

    $action = 'JAMF_REENROLLMENT_DETECTED';
    $actor = 'system:jamf-readonly-check';
    $details = 'Read-only erkannt: Seriennummer nach abgeschlossenem Workflow wieder aktiv mit isManaged=true. Keine automatische Änderung ausgelöst.';
    $audit = $mysqli->prepare(
        'INSERT INTO request_audit_log (request_id, action, admin_user, details)
         VALUES (?, ?, ?, ?)'
    );
    if ($audit) {
        $audit->bind_param('isss', $requestId, $action, $actor, $details);
        $audit->execute();
        $audit->close();
    }
    $detected++;
}

echo "Jamf-Wiederanmeldungsprüfung: {$checked} geprüft, {$detected} Abweichungen neu erkannt, {$unavailable} Lookups nicht verfügbar.\n";
