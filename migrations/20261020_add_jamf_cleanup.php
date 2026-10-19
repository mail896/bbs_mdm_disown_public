<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/../db.php';
require __DIR__ . '/lib/jamf_cleanup_migration.php';

try {
    $result = disown_migrate_jamf_cleanup($mysqli);
    echo 'Jamf-Nacharbeit: ' . count($result['added_columns']) . ' neue Spalten, '
        . count($result['added_indexes']) . " neue Indizes. Keine Anträge freigegeben.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
