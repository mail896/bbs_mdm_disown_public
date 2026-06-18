<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../db.php';

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS kuk_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    serial VARCHAR(64) NOT NULL,
    jamf_device_id VARCHAR(64) NULL,
    device_name VARCHAR(255) NULL,
    asset_tag VARCHAR(128) NULL,
    owner_name VARCHAR(255) NULL,
    owner_username VARCHAR(255) NULL,
    owner_email VARCHAR(255) NULL,
    model_name VARCHAR(255) NULL,
    model_identifier VARCHAR(128) NULL,
    os_version VARCHAR(64) NULL,
    last_checkin DATETIME NULL,
    enrollment_date DATETIME NULL,
    jamf_modified DATETIME NULL,
    jamf_groups TEXT NULL,
    matched_by_asset TINYINT(1) NOT NULL DEFAULT 0,
    matched_by_group TINYINT(1) NOT NULL DEFAULT 0,
    raw_json JSON NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_sync_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_kuk_devices_serial (serial),
    KEY idx_kuk_devices_asset_tag (asset_tag),
    KEY idx_kuk_devices_owner_username (owner_username),
    KEY idx_kuk_devices_last_checkin (last_checkin),
    KEY idx_kuk_devices_last_sync_at (last_sync_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

if (!$mysqli->query($sql)) {
    fwrite(STDERR, "Migration fehlgeschlagen: {$mysqli->error}\n");
    exit(1);
}

echo "Migration ausgeführt: kuk_devices\n";
