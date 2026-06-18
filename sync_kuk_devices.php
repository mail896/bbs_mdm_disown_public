<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/db.php';
require __DIR__ . '/kuk_api.php';

$options = getopt('', ['help']);
if (isset($options['help'])) {
    echo "Usage: php sync_kuk_devices.php\n";
    exit(0);
}

$devices = kuk_jamf_devices();
$stats = [
    'jamf_total' => count($devices),
    'kuk_total' => 0,
    'asset_match' => 0,
    'group_match' => 0,
    'both_match' => 0,
    'inserted' => 0,
    'updated' => 0,
    'removed' => 0,
    'without_owner' => 0,
];
$currentKukSerials = [];

foreach ($devices as $device) {
    if (!kuk_is_kuk_device($device)) {
        continue;
    }

    $row = kuk_normalize_device($device);
    if ($row['serial'] === '') {
        continue;
    }
    $currentKukSerials[] = $row['serial'];

    $stats['kuk_total']++;
    $stats['asset_match'] += (int) $row['matched_by_asset'];
    $stats['group_match'] += (int) $row['matched_by_group'];
    if ($row['matched_by_asset'] && $row['matched_by_group']) {
        $stats['both_match']++;
    }
    if (($row['owner_name'] ?? null) === null && ($row['owner_username'] ?? null) === null && ($row['owner_email'] ?? null) === null) {
        $stats['without_owner']++;
    }

    $existing = kuk_existing_device($mysqli, $row['serial']);
    kuk_upsert_device($mysqli, $row);
    kuk_update_owner_history($mysqli, $row, $existing);
    if ($existing) {
        $stats['updated']++;
    } else {
        $stats['inserted']++;
    }
}

$stats['removed'] = kuk_delete_devices_not_in_current_set($mysqli, $currentKukSerials);

echo "KUK-Sync abgeschlossen\n";
echo "Jamf-Geraete total: {$stats['jamf_total']}\n";
echo "KUK-Geraete: {$stats['kuk_total']}\n";
echo "LK-Asset: {$stats['asset_match']}\n";
echo "LK-Gruppe: {$stats['group_match']}\n";
echo "Ueberschneidung: {$stats['both_match']}\n";
echo "Neu: {$stats['inserted']}\n";
echo "Aktualisiert: {$stats['updated']}\n";
echo "Entfernt: {$stats['removed']}\n";
echo "Ohne Owner: {$stats['without_owner']}\n";

function kuk_existing_device(mysqli $mysqli, string $serial): ?array
{
    $stmt = $mysqli->prepare(
        'SELECT serial, owner_name, owner_username, owner_email
         FROM kuk_devices
         WHERE serial = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException($mysqli->error);
    }
    $stmt->bind_param('s', $serial);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function kuk_upsert_device(mysqli $mysqli, array $row): void
{
    $sql = <<<SQL
INSERT INTO kuk_devices (
    serial, jamf_device_id, device_name, asset_tag, owner_name, owner_username, owner_email,
    model_name, model_identifier, os_version, last_checkin, enrollment_date, jamf_modified,
    jamf_groups, matched_by_asset, matched_by_group, raw_json, last_sync_at
) VALUES (
    ?, ?, ?, ?, ?, ?, ?,
    ?, ?, ?, ?, ?, ?,
    ?, ?, ?, ?, NOW()
)
ON DUPLICATE KEY UPDATE
    jamf_device_id = VALUES(jamf_device_id),
    device_name = VALUES(device_name),
    asset_tag = VALUES(asset_tag),
    owner_name = VALUES(owner_name),
    owner_username = VALUES(owner_username),
    owner_email = VALUES(owner_email),
    model_name = VALUES(model_name),
    model_identifier = VALUES(model_identifier),
    os_version = VALUES(os_version),
    last_checkin = VALUES(last_checkin),
    enrollment_date = VALUES(enrollment_date),
    jamf_modified = VALUES(jamf_modified),
    jamf_groups = VALUES(jamf_groups),
    matched_by_asset = VALUES(matched_by_asset),
    matched_by_group = VALUES(matched_by_group),
    raw_json = VALUES(raw_json),
    last_sync_at = NOW()
SQL;

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($mysqli->error);
    }

    $stmt->bind_param(
        'ssssssssssssssiis',
        $row['serial'],
        $row['jamf_device_id'],
        $row['device_name'],
        $row['asset_tag'],
        $row['owner_name'],
        $row['owner_username'],
        $row['owner_email'],
        $row['model_name'],
        $row['model_identifier'],
        $row['os_version'],
        $row['last_checkin'],
        $row['enrollment_date'],
        $row['jamf_modified'],
        $row['jamf_groups'],
        $row['matched_by_asset'],
        $row['matched_by_group'],
        $row['raw_json']
    );
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();
}

function kuk_delete_devices_not_in_current_set(mysqli $mysqli, array $currentKukSerials): int
{
    $currentKukSerials = array_values(array_unique(array_filter($currentKukSerials)));
    if (!$currentKukSerials) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($currentKukSerials), '?'));
    $stmt = $mysqli->prepare("DELETE FROM kuk_devices WHERE serial NOT IN ({$placeholders})");
    if (!$stmt) {
        throw new RuntimeException($mysqli->error);
    }

    $types = str_repeat('s', count($currentKukSerials));
    $stmt->bind_param($types, ...$currentKukSerials);
    $stmt->execute();
    $removed = $stmt->affected_rows;
    $stmt->close();

    return max(0, $removed);
}

function kuk_update_owner_history(mysqli $mysqli, array $row, ?array $existing): void
{
    $serial = $row['serial'];
    $ownerName = $row['owner_name'];
    $ownerUsername = $row['owner_username'];
    $ownerEmail = $row['owner_email'];
    $hasOwner = $ownerName !== null || $ownerUsername !== null || $ownerEmail !== null;

    $changed = !$existing
        || !kuk_same_nullable($existing['owner_name'] ?? null, $ownerName)
        || !kuk_same_nullable($existing['owner_username'] ?? null, $ownerUsername)
        || !kuk_same_nullable($existing['owner_email'] ?? null, $ownerEmail);

    if ($changed) {
        $closeStmt = $mysqli->prepare(
            'UPDATE kuk_owner_history
             SET last_seen_at = NOW()
             WHERE serial = ?
               AND last_seen_at = (
                   SELECT latest_seen FROM (
                       SELECT MAX(last_seen_at) AS latest_seen
                       FROM kuk_owner_history
                       WHERE serial = ?
                   ) AS latest
               )'
        );
        if ($closeStmt) {
            $closeStmt->bind_param('ss', $serial, $serial);
            $closeStmt->execute();
            $closeStmt->close();
        }
    }

    if (!$hasOwner) {
        return;
    }

    $stmt = $mysqli->prepare(
        'SELECT id
         FROM kuk_owner_history
         WHERE serial = ?
           AND COALESCE(owner_name, \'\') = COALESCE(?, \'\')
           AND COALESCE(owner_username, \'\') = COALESCE(?, \'\')
           AND COALESCE(owner_email, \'\') = COALESCE(?, \'\')
         ORDER BY id DESC
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException($mysqli->error);
    }
    $stmt->bind_param('ssss', $serial, $ownerName, $ownerUsername, $ownerEmail);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($history) {
        $historyId = (int) $history['id'];
        $updateStmt = $mysqli->prepare('UPDATE kuk_owner_history SET last_seen_at = NOW() WHERE id = ?');
        if (!$updateStmt) {
            throw new RuntimeException($mysqli->error);
        }
        $updateStmt->bind_param('i', $historyId);
        $updateStmt->execute();
        $updateStmt->close();
        return;
    }

    $insertStmt = $mysqli->prepare(
        'INSERT INTO kuk_owner_history (serial, owner_name, owner_username, owner_email, first_seen_at, last_seen_at)
         VALUES (?, ?, ?, ?, NOW(), NOW())'
    );
    if (!$insertStmt) {
        throw new RuntimeException($mysqli->error);
    }
    $insertStmt->bind_param('ssss', $serial, $ownerName, $ownerUsername, $ownerEmail);
    $insertStmt->execute();
    $insertStmt->close();
}

function kuk_same_nullable(?string $a, ?string $b): bool
{
    return trim((string) $a) === trim((string) $b);
}
