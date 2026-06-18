<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../db.php';

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS kuk_device_workflow (
    serial VARCHAR(64) NOT NULL PRIMARY KEY,
    inactivity_mail_sent_at DATETIME NULL,
    inactivity_mail_sent_by VARCHAR(255) NULL,
    inactivity_mail_sent_to VARCHAR(255) NULL,
    ios_mail_sent_at DATETIME NULL,
    ios_mail_sent_by VARCHAR(255) NULL,
    ios_mail_sent_to VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_kuk_workflow_inactivity_mail (inactivity_mail_sent_at),
    KEY idx_kuk_workflow_ios_mail (ios_mail_sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

if (!$mysqli->query($sql)) {
    fwrite(STDERR, "Migration fehlgeschlagen: {$mysqli->error}\n");
    exit(1);
}

$columnsToDrop = ['local_status', 'local_note'];
foreach ($columnsToDrop as $column) {
    $safeColumn = $mysqli->real_escape_string($column);
    $result = $mysqli->query("SHOW COLUMNS FROM kuk_device_workflow LIKE '{$safeColumn}'");
    if ($result && $result->fetch_assoc()) {
        if (!$mysqli->query("ALTER TABLE kuk_device_workflow DROP COLUMN {$column}")) {
            fwrite(STDERR, "Migration fehlgeschlagen beim Entfernen von {$column}: {$mysqli->error}\n");
            exit(1);
        }
    }
}

echo "Migration ausgeführt: kuk_device_workflow\n";
