<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/../db.php';
require __DIR__ . '/../jamf_cleanup.php';

$limit = 25;
$dryRun = in_array('--dry-run', $argv, true);
foreach ($argv as $argument) {
    if (preg_match('/^--limit=(\d+)$/', $argument, $match)) {
        $limit = max(1, min(100, (int) $match[1]));
    }
}
$lock = fopen(sys_get_temp_dir() . '/disown-jamf-cleanup-' . hash('sha256', __DIR__) . '.lock', 'c');
if (!$lock) {
    fwrite(STDERR, "Jamf-Nachprüfung: Prozess-Lock nicht zugänglich; Runtime-Benutzer und Rechte prüfen.\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Jamf-Nachprüfung läuft bereits; kein paralleler Start.\n";
    exit(0);
}
try {
    $mysqli->query('SET SESSION innodb_lock_wait_timeout = 3');
    $scope = disown_jamf_cleanup_scope_sql();
    $result = $mysqli->query("SELECT * FROM requests WHERE {$scope}
        AND (jamf_cleanup_next_check_at IS NULL OR jamf_cleanup_next_check_at <= NOW())
        ORDER BY COALESCE(jamf_cleanup_next_check_at, '1000-01-01'), id LIMIT {$limit}");
    $counts = [];
    $errors = 0;
    $deadline = microtime(true) + 45;
    while ($row = $result->fetch_assoc()) {
        if (microtime(true) >= $deadline) {
            break;
        }
        try {
            $observation = disown_jamf_cleanup_observe($row);
            $state = $dryRun ? disown_jamf_cleanup_classify($row, $observation)
                : persist_jamf_cleanup_observation($mysqli, $row, $observation);
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        } catch (Throwable $e) {
            $errors++;
            // No raw device data, credentials or transport responses in cron logs.
            fwrite(STDERR, 'Jamf-Nachprüfung: lokaler Prüf-/Speicherfehler bei Antrag #' . (int) $row['id'] . ".\n");
        }
    }
    echo ($dryRun ? 'Dry-run; keine DB-Änderung. ' : '') . 'Jamf-Nachprüfung: '
        . json_encode($counts, JSON_UNESCAPED_UNICODE) . "; lokale Fehler: {$errors}. Keine externen Schreibaktionen.\n";
    exit($errors > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, "Jamf-Nachprüfung konnte nicht gestartet werden.\n");
    exit(1);
}
