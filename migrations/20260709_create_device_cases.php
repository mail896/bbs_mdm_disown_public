<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../db.php';

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS device_cases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    serial VARCHAR(64) NOT NULL,
    request_id INT NULL,
    source VARCHAR(64) NOT NULL DEFAULT 'admin',
    title VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'offen',
    priority VARCHAR(32) NOT NULL DEFAULT 'normal',
    note TEXT NULL,
    resolution_note TEXT NULL,
    created_by VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    closed_by VARCHAR(255) NULL,
    closed_at DATETIME NULL,
    KEY idx_device_cases_serial_status (serial, status),
    KEY idx_device_cases_request (request_id),
    KEY idx_device_cases_status_updated (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

if (!$mysqli->query($sql)) {
    fwrite(STDERR, "Migration fehlgeschlagen: {$mysqli->error}\n");
    exit(1);
}

echo "Migration ausgeführt: device_cases\n";
