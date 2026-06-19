<?php

if (!function_exists('disown_is_dev_mode')) {
    function disown_is_dev_mode(): bool
    {
        return basename(__DIR__) === 'disown-dev';
    }
}

function jamf_config(): ?array
{
    $cfg = parse_ini_file('/etc/disown/jamf.conf');
    if (!$cfg || empty($cfg['JAMF_URL']) || empty($cfg['JAMF_NETWORK_ID']) || empty($cfg['JAMF_API_KEY'])) {
        return null;
    }

    return $cfg;
}

function jamf_request_devices_by_serial(string $serial): ?array
{
    $cfg = jamf_config();
    if (!$cfg) {
        return null;
    }

    $url = rtrim($cfg['JAMF_URL'], '/') . '/api/devices?serialnumber=' . urlencode($serial);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $cfg['JAMF_NETWORK_ID'] . ':' . $cfg['JAMF_API_KEY'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FAILONERROR => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $curlError = curl_errno($ch) ? curl_error($ch) : '';
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '' || (int) $httpCode !== 200) {
        return null;
    }

    $data = json_decode((string) $response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['devices']) || !is_array($data['devices'])) {
        return null;
    }

    return $data['devices'];
}

function jamf_device_by_serial(string $serial): ?array
{
    $serial = strtoupper(trim($serial));
    $devices = jamf_request_devices_by_serial($serial);
    if (!$devices) {
        return null;
    }

    foreach ($devices as $device) {
        if (!is_array($device)) {
            continue;
        }
        if (strtoupper(trim((string) ($device['serialNumber'] ?? ''))) === $serial) {
            return $device;
        }
    }

    return null;
}

function jamf_group_names(array $device): array
{
    $names = [];
    $candidateKeys = ['groups', 'deviceGroups', 'deviceGroup', 'group', 'groupName', 'groupsNames'];

    foreach ($candidateKeys as $key) {
        if (!array_key_exists($key, $device)) {
            continue;
        }
        $value = $device[$key];
        if (is_string($value)) {
            $names[] = $value;
            continue;
        }
        if (!is_array($value)) {
            continue;
        }
        foreach ($value as $entry) {
            if (is_string($entry)) {
                $names[] = $entry;
            } elseif (is_array($entry)) {
                foreach (['name', 'displayName', 'groupName'] as $nameKey) {
                    if (!empty($entry[$nameKey]) && is_string($entry[$nameKey])) {
                        $names[] = $entry[$nameKey];
                    }
                }
            }
        }
    }

    return array_values(array_unique(array_map('trim', array_filter($names, static fn ($name) => trim((string) $name) !== ''))));
}

function jamf_lookup_from_device(array $device): array
{
    $owner = is_array($device['owner'] ?? null) ? $device['owner'] : [];

    return [
        'username'      => $owner['username'] ?? '',
        'email'         => $owner['email'] ?? '',
        'full_name'     => $owner['name'] ?? '',
        'jamf_user_id'  => $owner['id'] ?? null,
        'jamf_modified' => $owner['modified'] ?? null,
        'device_name'   => $device['name'] ?? '',
        'serial'        => $device['serialNumber'] ?? '',
        'asset_tag'     => $device['assetTag'] ?? '',
        'groups'        => jamf_group_names($device),
    ];
}

function jamf_device_is_school_loan(array $device): bool
{
    $assetTag = strtoupper(trim((string) ($device['asset_tag'] ?? $device['assetTag'] ?? '')));
    if ($assetTag !== '' && strpos($assetTag, 'BBS') !== false) {
        return true;
    }

    $groups = $device['groups'] ?? null;
    if (!is_array($groups)) {
        $groups = jamf_group_names($device);
    }

    foreach ($groups as $group) {
        $normalized = strtoupper(trim((string) $group));
        if ($normalized !== '' && strpos($normalized, 'KOFFER') !== false) {
            return true;
        }
    }

    return false;
}

function jamf_school_loan_reasons(array $device): array
{
    $reasons = [];
    $assetTag = trim((string) ($device['asset_tag'] ?? $device['assetTag'] ?? ''));
    if ($assetTag !== '' && stripos($assetTag, 'BBS') !== false) {
        $reasons[] = 'Asset-Tag: ' . $assetTag;
    }

    $groups = $device['groups'] ?? null;
    if (!is_array($groups)) {
        $groups = jamf_group_names($device);
    }
    foreach ($groups as $group) {
        $group = trim((string) $group);
        if ($group !== '' && stripos($group, 'Koffer') !== false) {
            $reasons[] = 'Jamf-Gruppe: ' . $group;
        }
    }

    return array_values(array_unique($reasons));
}

function jamf_dev_lookup_mock_by_serial(string $serial): ?array
{
    global $mysqli;
    if (!isset($mysqli)) {
        return null;
    }

    $stmt = $mysqli->prepare(
        "SELECT username, email, full_name, jamf_user_id, jamf_modified, device_name, serial
         FROM requests
         WHERE UPPER(serial) = ?
         ORDER BY id DESC
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $serial);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $device = $result->fetch_assoc();
    $stmt->close();

    if (!$device) {
        return null;
    }

    return [
        'username'      => $device['username'] ?? '',
        'email'         => $device['email'] ?? '',
        'full_name'     => $device['full_name'] ?? '',
        'jamf_user_id'  => $device['jamf_user_id'] ?? null,
        'jamf_modified' => $device['jamf_modified'] ?? null,
        'device_name'   => $device['device_name'] ?? '',
        'serial'        => $device['serial'] ?? '',
        'asset_tag'     => '',
        'groups'        => [],
    ];
}

function jamf_lookup_by_serial(string $serial): ?array
{
    $serial = strtoupper(trim($serial));

    $device = jamf_device_by_serial($serial);
    if ($device) {
        return jamf_lookup_from_device($device);
    }

    return disown_is_dev_mode() ? jamf_dev_lookup_mock_by_serial($serial) : null;
}

/**
 * Unenroll a device from Jamf School by serial number.
 * This calls POST /api/devices/{UDID}/unenroll.
 */
function jamf_unenroll_by_serial(string $serial): array
{
    $serial = trim($serial);
    if ($serial === '') {
        return ['success' => false, 'message' => 'Seriennummer fehlt.'];
    }

    // First, look up the device to get its UDID
    $device = jamf_lookup_by_serial($serial);
    if (!$device) {
        return ['success' => false, 'message' => 'Gerät wurde in Jamf nicht gefunden.'];
    }

    if (disown_is_dev_mode()) {
        if (jamf_device_is_school_loan($device)) {
            return ['success' => false, 'message' => 'Dieses Gerät ist als schulisches Leih-/Koffergerät markiert und darf nicht per Jamf abgemeldet werden.'];
        }
        return ['success' => true, 'message' => 'DEV-Modus: Jamf-Unenroll wurde simuliert.'];
    }

    // Extract UDID from the full device lookup.
    $cfg = jamf_config();
    if (!$cfg) {
        return ['success' => false, 'message' => 'Jamf-Konfiguration fehlt oder ist unvollständig.'];
    }

    $fullDevice = jamf_device_by_serial($serial);
    if (!$fullDevice) {
        return ['success' => false, 'message' => 'Gerät wurde in Jamf nicht gefunden.'];
    }

    if (jamf_device_is_school_loan($fullDevice)) {
        return ['success' => false, 'message' => 'Dieses Gerät ist als schulisches Leih-/Koffergerät markiert und darf nicht per Jamf abgemeldet werden.'];
    }

    $udid = $fullDevice['UDID'] ?? null;
    if (!$udid) {
        return ['success' => false, 'message' => 'UDID des Geräts konnte nicht ermittelt werden.'];
    }

    // Now call unenroll endpoint: POST /api/devices/{UDID}/unenroll
    $unenrollUrl = rtrim($cfg['JAMF_URL'], '/') . '/api/devices/' . urlencode($udid) . '/unenroll';

    $ch = curl_init($unenrollUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $cfg['JAMF_NETWORK_ID'] . ':' . $cfg['JAMF_API_KEY'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FAILONERROR => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    $unenrollResponse = curl_exec($ch);
    $unenrollCurlError = curl_errno($ch) ? curl_error($ch) : '';
    $unenrollHttpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($unenrollResponse === false || $unenrollCurlError !== '') {
        return ['success' => false, 'message' => 'Fehler beim Unenroll: ' . $unenrollCurlError];
    }

    if ($unenrollHttpCode === 200) {
        $unenrollData = json_decode($unenrollResponse, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($unenrollData['message']) && $unenrollData['message'] === 'DeviceUnenrolled') {
            return ['success' => true, 'message' => 'Gerät erfolgreich aus Jamf abgemeldet.'];
        }
    }

    if ($unenrollHttpCode === 200) {
        // Even if we can't parse the exact success message, HTTP 200 means success
        return ['success' => true, 'message' => 'Gerät erfolgreich aus Jamf abgemeldet.'];
    }

    return ['success' => false, 'message' => 'Unenroll fehlgeschlagen: HTTP ' . $unenrollHttpCode];
}
