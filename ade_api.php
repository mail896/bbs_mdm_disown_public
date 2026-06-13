<?php

declare(strict_types=1);

const ADE_ASM_TOKEN_URL = 'https://account.apple.com/auth/oauth2/token';
const ADE_ASM_API_BASE = 'https://api-school.apple.com/v1';

function ade_asm_access_token(): string
{
    $config = parse_ini_file('/etc/disown/asm.conf');
    if (!$config || empty($config['ASM_CLIENT_ID'])) {
        throw new RuntimeException('ASM-Konfiguration fehlt oder ist unvollstaendig.');
    }

    $assertion = trim((string) shell_exec('/etc/disown/asm-jwt.py'));
    if ($assertion === '') {
        throw new RuntimeException('ASM client_assertion konnte nicht erzeugt werden.');
    }

    $response = ade_http_request('POST', ADE_ASM_TOKEN_URL, [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => $config['ASM_CLIENT_ID'],
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'client_assertion' => $assertion,
        'scope' => 'school.api',
    ]));

    if ($response['status'] !== 200 || empty($response['json']['access_token'])) {
        throw new RuntimeException('ASM Token konnte nicht geholt werden. HTTP ' . $response['status']);
    }

    return (string) $response['json']['access_token'];
}

function ade_asm_recent_devices(string $token, int $days = 30, int $limit = 100): array
{
    $cutoff = new DateTimeImmutable("-{$days} days", new DateTimeZone('UTC'));
    $url = ADE_ASM_API_BASE . '/orgDevices?limit=' . max(1, min(100, $limit));
    $devices = [];
    $pages = 0;

    while ($url !== '' && $pages < 100) {
        $pages++;
        $response = ade_http_request('GET', $url, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);

        if ($response['status'] !== 200) {
            throw new RuntimeException('ASM orgDevices fehlgeschlagen. HTTP ' . $response['status']);
        }

        foreach (($response['json']['data'] ?? []) as $row) {
            $device = ade_normalize_asm_device($row);
            $updatedAt = ade_parse_utc($device['asm_updated_at'] ?? null);
            if ($updatedAt && $updatedAt >= $cutoff) {
                $devices[] = $device;
            }
        }

        $url = (string) ($response['json']['links']['next'] ?? '');
    }

    usort($devices, static function (array $a, array $b): int {
        return strcmp((string) ($b['asm_updated_at'] ?? ''), (string) ($a['asm_updated_at'] ?? ''));
    });

    return $devices;
}

function ade_asm_assigned_server(string $token, string $serial): ?array
{
    $url = ADE_ASM_API_BASE . '/orgDevices/' . rawurlencode($serial) . '/assignedServer';
    $response = null;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $response = ade_http_request('GET', $url, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);

        if ($response['status'] !== 429) {
            break;
        }

        sleep($attempt * 3);
    }

    if ($response['status'] === 404) {
        return null;
    }
    if ($response['status'] === 429) {
        return null;
    }
    if ($response['status'] !== 200) {
        throw new RuntimeException('ASM assignedServer fehlgeschlagen. HTTP ' . $response['status']);
    }

    $data = $response['json']['data'] ?? [];
    $attributes = is_array($data) ? ($data['attributes'] ?? []) : [];

    return [
        'asm_mdm_server_id' => (string) ($data['id'] ?? ''),
        'asm_mdm_server_name' => (string) ($attributes['serverName'] ?? ''),
        'asm_mdm_server_type' => (string) ($attributes['serverType'] ?? ''),
    ];
}

function ade_jamf_device_by_serial(string $serial): ?array
{
    $config = parse_ini_file('/etc/disown/jamf.conf');
    if (!$config || empty($config['JAMF_URL']) || empty($config['JAMF_NETWORK_ID']) || empty($config['JAMF_API_KEY'])) {
        throw new RuntimeException('Jamf-Konfiguration fehlt oder ist unvollstaendig.');
    }

    $baseUrl = rtrim((string) $config['JAMF_URL'], '/') . '/api/devices?serialnumber=' . rawurlencode($serial);
    $response = ade_http_request('GET', $baseUrl, [
        'Authorization: Basic ' . base64_encode($config['JAMF_NETWORK_ID'] . ':' . $config['JAMF_API_KEY']),
        'Accept: application/json',
    ]);

    if ($response['status'] !== 200) {
        throw new RuntimeException('Jamf Device-Lookup fehlgeschlagen. HTTP ' . $response['status']);
    }

    $device = ade_find_jamf_device($response['json']['devices'] ?? [], $serial);
    if ($device) {
        return ade_normalize_jamf_device($device, 'active');
    }

    $trashResponse = ade_http_request('GET', $baseUrl . '&inTrash=true', [
        'Authorization: Basic ' . base64_encode($config['JAMF_NETWORK_ID'] . ':' . $config['JAMF_API_KEY']),
        'Accept: application/json',
    ]);

    if ($trashResponse['status'] !== 200) {
        throw new RuntimeException('Jamf Trash-Lookup fehlgeschlagen. HTTP ' . $trashResponse['status']);
    }

    $trashDevice = ade_find_jamf_device($trashResponse['json']['devices'] ?? [], $serial);
    if ($trashDevice) {
        return ade_normalize_jamf_device($trashDevice, 'trash');
    }

    return null;
}

function ade_find_jamf_device($devices, string $serial): ?array
{
    if (!is_array($devices)) {
        return null;
    }

    foreach ($devices as $device) {
        if (!is_array($device)) {
            continue;
        }
        if (strtoupper((string) ($device['serialNumber'] ?? '')) === strtoupper($serial)) {
            return $device;
        }
    }

    return null;
}

function ade_normalize_jamf_device(array $device, string $state): array
{
    $owner = $device['owner'] ?? [];
    $model = $device['model'] ?? [];
    $depProfile = $device['depProfile'] ?? [];

    return [
        'jamf_seen' => 1,
        'jamf_state' => $state,
        'jamf_device_name' => (string) ($device['name'] ?? ''),
        'jamf_asset_tag' => (string) ($device['assetTag'] ?? ''),
        'jamf_owner_name' => (string) ($owner['name'] ?? ''),
        'jamf_owner_username' => (string) ($owner['username'] ?? ''),
        'jamf_owner_email' => (string) ($owner['email'] ?? ''),
        'jamf_model' => (string) ($model['name'] ?? ''),
        'jamf_model_identifier' => (string) ($model['identifier'] ?? ''),
        'jamf_dep_profile' => is_array($depProfile) ? (string) ($depProfile['name'] ?? '') : (string) $depProfile,
        'jamf_last_checkin' => (string) ($device['lastCheckin'] ?? ''),
        'jamf_modified' => (string) ($device['modified'] ?? ''),
    ];
}

function ade_normalize_asm_device(array $row): array
{
    $attributes = $row['attributes'] ?? [];

    return [
        'serial' => strtoupper((string) ($attributes['serialNumber'] ?? $row['id'] ?? '')),
        'asm_added_at' => (string) ($attributes['addedToOrgDateTime'] ?? ''),
        'asm_updated_at' => (string) ($attributes['updatedDateTime'] ?? ''),
        'asm_model' => (string) ($attributes['deviceModel'] ?? ''),
        'asm_product_family' => (string) ($attributes['productFamily'] ?? ''),
        'asm_product_type' => (string) ($attributes['productType'] ?? ''),
        'asm_capacity' => (string) ($attributes['deviceCapacity'] ?? ''),
        'asm_order_number' => (string) ($attributes['orderNumber'] ?? ''),
        'asm_part_number' => (string) ($attributes['partNumber'] ?? ''),
        'asm_purchase_source_type' => (string) ($attributes['purchaseSourceType'] ?? ''),
        'asm_status' => (string) ($attributes['status'] ?? ''),
    ];
}

function ade_http_request(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FAILONERROR => false,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($ch);
    $error = curl_errno($ch) ? curl_error($ch) : '';
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false || $error !== '') {
        throw new RuntimeException('HTTP-Fehler: ' . $error);
    }

    $json = json_decode((string) $raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $json = null;
    }

    return [
        'status' => (int) $status,
        'body' => (string) $raw,
        'json' => $json,
    ];
}

function ade_parse_utc(?string $value): ?DateTimeImmutable
{
    if (!$value) {
        return null;
    }

    try {
        return new DateTimeImmutable($value);
    } catch (Exception) {
        return null;
    }
}
