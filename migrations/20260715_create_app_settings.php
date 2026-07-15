<?php
require __DIR__ . '/../db.php';

$sql = "CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(96) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by VARCHAR(128) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$mysqli->query($sql)) {
    fwrite(STDERR, "Fehler beim Erstellen von app_settings: " . $mysqli->error . PHP_EOL);
    exit(1);
}

echo "app_settings ist vorhanden.\n";
