<?php

function disown_is_dev_mode(): bool
{
    return basename(__DIR__) === 'disown-dev';
}

function jamf_lookup_by_serial(string $serial): ?array
{
    $serial = strtoupper(trim($serial));

    if (disown_is_dev_mode()) {
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
            'serial'        => $device['serial'] ?? ''
        ];
    }

    $cfg = parse_ini_file('/etc/disown/jamf.conf');
    if (!$cfg || empty($cfg['JAMF_URL']) || empty($cfg['JAMF_NETWORK_ID']) || empty($cfg['JAMF_API_KEY'])) {
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

    if ($response === false || $curlError !== '' || $httpCode !== 200) {
        return null;
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['devices']) || !is_array($data['devices'])) {
        return null;
    }

    foreach ($data['devices'] as $device) {
        if (strtoupper(trim($device['serialNumber'] ?? '')) !== $serial) {
            continue;
        }

        return [
            'username'      => $device['owner']['username'] ?? '',
            'email'         => $device['owner']['email'] ?? '',
            'full_name'     => $device['owner']['name'] ?? '',
            'jamf_user_id'  => $device['owner']['id'] ?? null,
            'jamf_modified' => $device['owner']['modified'] ?? null,
            'device_name'   => $device['name'] ?? '',
            'serial'        => $device['serialNumber'] ?? ''
        ];
    }

    return null;
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

    if (disown_is_dev_mode()) {
        return ['success' => true, 'message' => 'DEV-Modus: Jamf-Unenroll wurde simuliert.'];
    }

    // First, look up the device to get its UDID
    $device = jamf_lookup_by_serial($serial);
    if (!$device) {
        return ['success' => false, 'message' => 'Gerät wurde in Jamf nicht gefunden.'];
    }

    // Extract UDID from the device lookup
    // Note: jamf_lookup_by_serial returns 'serial' but not UDID
    // We need to fetch the full device data to get UDID
    $cfg = parse_ini_file('/etc/disown/jamf.conf');
    if (!$cfg || empty($cfg['JAMF_URL']) || empty($cfg['JAMF_NETWORK_ID']) || empty($cfg['JAMF_API_KEY'])) {
        return ['success' => false, 'message' => 'Jamf-Konfiguration fehlt oder ist unvollständig.'];
    }

    $lookupUrl = rtrim($cfg['JAMF_URL'], '/') . '/api/devices?serialnumber=' . urlencode($serial);
    $ch = curl_init($lookupUrl);
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

    if ($response === false || $curlError !== '' || $httpCode !== 200) {
        return ['success' => false, 'message' => 'Fehler beim Abrufen des Geräts: ' . ($curlError ?: 'HTTP ' . $httpCode)];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['devices']) || empty($data['devices'])) {
        return ['success' => false, 'message' => 'Gerät wurde in Jamf nicht gefunden.'];
    }

    $udid = $data['devices'][0]['UDID'] ?? null;
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
