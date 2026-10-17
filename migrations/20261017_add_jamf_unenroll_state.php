<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/../db.php';
require __DIR__ . '/lib/jamf_state_migration.php';

try {
    $result = disown_migrate_jamf_state($mysqli);
    echo 'DISOWN 2.6 Jamf-Zustandsmigration abgeschlossen.' . PHP_EOL;
    echo 'Neue Spalten: ' . count($result['added_columns']) . PHP_EOL;
    echo 'Neue Indizes: ' . count($result['added_indexes']) . PHP_EOL;
    echo 'Konservativ klassifizierte Legacy-Zeilen: ' . $result['backfilled_rows'] . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration fehlgeschlagen: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
