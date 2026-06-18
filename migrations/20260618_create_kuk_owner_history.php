<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../db.php';

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS kuk_owner_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    serial VARCHAR(64) NOT NULL,
    owner_name VARCHAR(255) NULL,
    owner_username VARCHAR(255) NULL,
    owner_email VARCHAR(255) NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_kuk_owner_history_serial (serial),
    KEY idx_kuk_owner_history_owner_username (owner_username),
    KEY idx_kuk_owner_history_seen (first_seen_at, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

if (!$mysqli->query($sql)) {
    fwrite(STDERR, "Migration fehlgeschlagen: {$mysqli->error}\n");
    exit(1);
}

echo "Migration ausgeführt: kuk_owner_history\n";
