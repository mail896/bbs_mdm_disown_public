<?php

declare(strict_types=1);

require __DIR__ . '/../db.php';

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS ade_enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    serial VARCHAR(64) NOT NULL,
    asm_added_at DATETIME NULL,
    asm_updated_at DATETIME NULL,
    asm_model VARCHAR(255) NULL,
    asm_product_family VARCHAR(128) NULL,
    asm_product_type VARCHAR(128) NULL,
    asm_capacity VARCHAR(64) NULL,
    asm_order_number VARCHAR(255) NULL,
    asm_part_number VARCHAR(128) NULL,
    asm_purchase_source_type VARCHAR(128) NULL,
    asm_status VARCHAR(64) NULL,
    asm_mdm_server_id VARCHAR(128) NULL,
    asm_mdm_server_name VARCHAR(255) NULL,
    asm_mdm_server_type VARCHAR(128) NULL,
    jamf_seen TINYINT(1) NOT NULL DEFAULT 0,
    jamf_seen_at DATETIME NULL,
    jamf_device_name VARCHAR(255) NULL,
    jamf_asset_tag VARCHAR(128) NULL,
    jamf_owner_name VARCHAR(255) NULL,
    jamf_owner_username VARCHAR(255) NULL,
    jamf_owner_email VARCHAR(255) NULL,
    jamf_model VARCHAR(255) NULL,
    jamf_model_identifier VARCHAR(128) NULL,
    jamf_dep_profile VARCHAR(255) NULL,
    jamf_last_checkin DATETIME NULL,
    jamf_modified DATETIME NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_sync_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ade_enrollments_serial (serial),
    KEY idx_ade_enrollments_asm_updated_at (asm_updated_at),
    KEY idx_ade_enrollments_jamf_seen (jamf_seen),
    KEY idx_ade_enrollments_last_sync_at (last_sync_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

if (!$mysqli->query($sql)) {
    fwrite(STDERR, "Migration fehlgeschlagen: {$mysqli->error}\n");
    exit(1);
}

echo "Migration ausgeführt: ade_enrollments\n";
