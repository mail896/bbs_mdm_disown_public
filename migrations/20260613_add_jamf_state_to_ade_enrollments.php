<?php

declare(strict_types=1);

require __DIR__ . '/../db.php';

$result = $mysqli->query("SHOW COLUMNS FROM ade_enrollments LIKE 'jamf_state'");
if ($result && $result->fetch_assoc()) {
    echo "Migration bereits vorhanden: jamf_state\n";
    exit(0);
}

$sql = "ALTER TABLE ade_enrollments
        ADD COLUMN jamf_state VARCHAR(32) NOT NULL DEFAULT 'missing' AFTER jamf_seen,
        ADD KEY idx_ade_enrollments_jamf_state (jamf_state)";

if (!$mysqli->query($sql)) {
    fwrite(STDERR, "Migration fehlgeschlagen: {$mysqli->error}\n");
    exit(1);
}

echo "Migration ausgeführt: jamf_state\n";
