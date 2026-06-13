<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/db.php';
require __DIR__ . '/ade_api.php';

$options = getopt('', ['days::', 'limit::', 'serial::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php sync_ade_enrollments.php [--days=30] [--limit=100] [--serial=SERIAL]\n";
    exit(0);
}

$days = max(1, (int) ($options['days'] ?? 30));
$limit = max(1, min(100, (int) ($options['limit'] ?? 100)));
$serialFilter = strtoupper(trim((string) ($options['serial'] ?? '')));

$token = ade_asm_access_token();
$devices = $serialFilter !== ''
    ? array_values(array_filter(ade_asm_recent_devices($token, 3650, $limit), static fn (array $device): bool => $device['serial'] === $serialFilter))
    : ade_asm_recent_devices($token, $days, $limit);

$stats = [
    'asm' => count($devices),
    'inserted' => 0,
    'updated' => 0,
    'jamf_seen' => 0,
    'jamf_active' => 0,
    'jamf_trash' => 0,
    'jamf_missing' => 0,
];

foreach ($devices as $device) {
    $serial = strtoupper(trim((string) ($device['serial'] ?? '')));
    if ($serial === '') {
        continue;
    }

    $assignedServer = ade_asm_assigned_server($token, $serial) ?? ade_existing_assigned_server($mysqli, $serial);
    $jamfDevice = ade_jamf_device_by_serial($serial);
    if ($jamfDevice) {
        $stats['jamf_seen']++;
        if (($jamfDevice['jamf_state'] ?? '') === 'trash') {
            $stats['jamf_trash']++;
        } else {
            $stats['jamf_active']++;
        }
    } else {
        $stats['jamf_missing']++;
    }

    $row = array_merge($device, $assignedServer, $jamfDevice ?? [
        'jamf_seen' => 0,
        'jamf_state' => 'missing',
        'jamf_device_name' => '',
        'jamf_asset_tag' => '',
        'jamf_owner_name' => '',
        'jamf_owner_username' => '',
        'jamf_owner_email' => '',
        'jamf_model' => '',
        'jamf_model_identifier' => '',
        'jamf_dep_profile' => '',
        'jamf_last_checkin' => '',
        'jamf_modified' => '',
    ]);

    $existingId = ade_existing_id($mysqli, $serial);
    ade_upsert_enrollment($mysqli, $row);
    if ($existingId) {
        $stats['updated']++;
    } else {
        $stats['inserted']++;
    }
}

echo "ADE-Sync abgeschlossen\n";
echo "ASM-Geräte: {$stats['asm']}\n";
echo "Neu: {$stats['inserted']}\n";
echo "Aktualisiert: {$stats['updated']}\n";
echo "Jamf aktiv: {$stats['jamf_active']}\n";
echo "Jamf Trash: {$stats['jamf_trash']}\n";
echo "Jamf n/a: {$stats['jamf_missing']}\n";

function ade_existing_id(mysqli $mysqli, string $serial): ?int
{
    $stmt = $mysqli->prepare('SELECT id FROM ade_enrollments WHERE serial = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException($mysqli->error);
    }
    $stmt->bind_param('s', $serial);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int) $row['id'] : null;
}

function ade_existing_assigned_server(mysqli $mysqli, string $serial): array
{
    $stmt = $mysqli->prepare(
        'SELECT asm_mdm_server_id, asm_mdm_server_name, asm_mdm_server_type
         FROM ade_enrollments
         WHERE serial = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('s', $serial);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return $row;
}

function ade_upsert_enrollment(mysqli $mysqli, array $row): void
{
    $sql = <<<SQL
INSERT INTO ade_enrollments (
    serial, asm_added_at, asm_updated_at, asm_model, asm_product_family, asm_product_type,
    asm_capacity, asm_order_number, asm_part_number, asm_purchase_source_type, asm_status,
    asm_mdm_server_id, asm_mdm_server_name, asm_mdm_server_type,
    jamf_seen, jamf_state, jamf_seen_at, jamf_device_name, jamf_asset_tag, jamf_owner_name,
    jamf_owner_username, jamf_owner_email, jamf_model, jamf_model_identifier,
    jamf_dep_profile, jamf_last_checkin, jamf_modified, last_sync_at
) VALUES (
    ?, ?, ?, ?, ?, ?,
    ?, ?, ?, ?, ?,
    ?, ?, ?,
    ?, ?, IF(? = 1, NOW(), NULL), ?, ?, ?,
    ?, ?, ?, ?,
    ?, ?, ?, NOW()
)
ON DUPLICATE KEY UPDATE
    asm_added_at = VALUES(asm_added_at),
    asm_updated_at = VALUES(asm_updated_at),
    asm_model = VALUES(asm_model),
    asm_product_family = VALUES(asm_product_family),
    asm_product_type = VALUES(asm_product_type),
    asm_capacity = VALUES(asm_capacity),
    asm_order_number = VALUES(asm_order_number),
    asm_part_number = VALUES(asm_part_number),
    asm_purchase_source_type = VALUES(asm_purchase_source_type),
    asm_status = VALUES(asm_status),
    asm_mdm_server_id = VALUES(asm_mdm_server_id),
    asm_mdm_server_name = VALUES(asm_mdm_server_name),
    asm_mdm_server_type = VALUES(asm_mdm_server_type),
    jamf_seen = VALUES(jamf_seen),
    jamf_state = VALUES(jamf_state),
    jamf_seen_at = IF(VALUES(jamf_seen) = 1, COALESCE(jamf_seen_at, NOW()), NULL),
    jamf_device_name = VALUES(jamf_device_name),
    jamf_asset_tag = VALUES(jamf_asset_tag),
    jamf_owner_name = VALUES(jamf_owner_name),
    jamf_owner_username = VALUES(jamf_owner_username),
    jamf_owner_email = VALUES(jamf_owner_email),
    jamf_model = VALUES(jamf_model),
    jamf_model_identifier = VALUES(jamf_model_identifier),
    jamf_dep_profile = VALUES(jamf_dep_profile),
    jamf_last_checkin = VALUES(jamf_last_checkin),
    jamf_modified = VALUES(jamf_modified),
    last_sync_at = NOW()
SQL;

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($mysqli->error);
    }

    $serial = (string) $row['serial'];
    $asmAddedAt = ade_mysql_datetime($row['asm_added_at'] ?? '');
    $asmUpdatedAt = ade_mysql_datetime($row['asm_updated_at'] ?? '');
    $asmModel = ade_null_if_empty($row['asm_model'] ?? '');
    $asmProductFamily = ade_null_if_empty($row['asm_product_family'] ?? '');
    $asmProductType = ade_null_if_empty($row['asm_product_type'] ?? '');
    $asmCapacity = ade_null_if_empty($row['asm_capacity'] ?? '');
    $asmOrderNumber = ade_null_if_empty($row['asm_order_number'] ?? '');
    $asmPartNumber = ade_null_if_empty($row['asm_part_number'] ?? '');
    $asmPurchaseSourceType = ade_null_if_empty($row['asm_purchase_source_type'] ?? '');
    $asmStatus = ade_null_if_empty($row['asm_status'] ?? '');
    $asmMdmServerId = ade_null_if_empty($row['asm_mdm_server_id'] ?? '');
    $asmMdmServerName = ade_null_if_empty($row['asm_mdm_server_name'] ?? '');
    $asmMdmServerType = ade_null_if_empty($row['asm_mdm_server_type'] ?? '');
    $jamfSeen = (int) (!empty($row['jamf_seen']));
    $jamfState = ade_null_if_empty($row['jamf_state'] ?? '') ?? 'missing';
    $jamfDeviceName = ade_null_if_empty($row['jamf_device_name'] ?? '');
    $jamfAssetTag = ade_null_if_empty($row['jamf_asset_tag'] ?? '');
    $jamfOwnerName = ade_null_if_empty($row['jamf_owner_name'] ?? '');
    $jamfOwnerUsername = ade_null_if_empty($row['jamf_owner_username'] ?? '');
    $jamfOwnerEmail = ade_null_if_empty($row['jamf_owner_email'] ?? '');
    $jamfModel = ade_null_if_empty($row['jamf_model'] ?? '');
    $jamfModelIdentifier = ade_null_if_empty($row['jamf_model_identifier'] ?? '');
    $jamfDepProfile = ade_null_if_empty($row['jamf_dep_profile'] ?? '');
    $jamfLastCheckin = ade_mysql_datetime($row['jamf_last_checkin'] ?? '');
    $jamfModified = ade_mysql_datetime($row['jamf_modified'] ?? '');

    $stmt->bind_param(
        'ssssssssssssssisissssssssss',
        $serial,
        $asmAddedAt,
        $asmUpdatedAt,
        $asmModel,
        $asmProductFamily,
        $asmProductType,
        $asmCapacity,
        $asmOrderNumber,
        $asmPartNumber,
        $asmPurchaseSourceType,
        $asmStatus,
        $asmMdmServerId,
        $asmMdmServerName,
        $asmMdmServerType,
        $jamfSeen,
        $jamfState,
        $jamfSeen,
        $jamfDeviceName,
        $jamfAssetTag,
        $jamfOwnerName,
        $jamfOwnerUsername,
        $jamfOwnerEmail,
        $jamfModel,
        $jamfModelIdentifier,
        $jamfDepProfile,
        $jamfLastCheckin,
        $jamfModified
    );
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();
}

function ade_mysql_datetime(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    } catch (Exception) {
        return null;
    }
}

function ade_null_if_empty(?string $value): ?string
{
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}
