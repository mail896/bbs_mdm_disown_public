<?php

declare(strict_types=1);

const ASM_TOKEN_URL = 'https://account.apple.com/auth/oauth2/token';
const ASM_API_BASE = 'https://api-school.apple.com/v1';
const DEFAULT_DAYS = 30;
const DEFAULT_LIMIT = 100;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$options = getopt('', ['serial::', 'days::', 'limit::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php tools/ade_probe.php [--serial=SERIAL] [--days=30] [--limit=100]\n";
    exit(0);
}

$serialFilter = strtoupper(trim((string) ($options['serial'] ?? '')));
$days = max(1, (int) ($options['days'] ?? DEFAULT_DAYS));
$limit = max(1, min(100, (int) ($options['limit'] ?? DEFAULT_LIMIT)));

if ($serialFilter !== '') {
    $asmToken = asm_access_token();
    $device = asm_device($asmToken, $serialFilter);
    $recentDevices = $device ? [$device] : [];
} else {
    $asmToken = asm_access_token();
    $recentDevices = asm_recent_devices($asmToken, $days, $limit);
}

echo "ADE-Aufnahmen Probe\n";
echo "Zeitraum: {$days} Tage\n";
echo "ASM-Geraete: " . count($recentDevices) . "\n\n";

foreach ($recentDevices as $device) {
    $serial = strtoupper((string) ($device['serial'] ?? ''));
    $assignedServer = $serial !== '' ? asm_assigned_server($asmToken, $serial) : null;
    $jamfDevice = $serial !== '' ? jamf_device_by_serial($serial) : null;

    echo "# {$serial}\n";
    echo "ASM updated: " . value_or_na($device['updated_at'] ?? null) . "\n";
    echo "ASM added:   " . value_or_na($device['added_at'] ?? null) . "\n";
    echo "ASM model:   " . value_or_na($device['model'] ?? null) . "\n";
    echo "ASM order:   " . value_or_na($device['order_number'] ?? null) . "\n";
    echo "ASM MDM:     " . value_or_na($assignedServer['server_name'] ?? null) . "\n";

    if ($jamfDevice) {
        echo "Jamf:        enrolled\n";
        echo "Jamf name:   " . value_or_na($jamfDevice['name'] ?? null) . "\n";
        echo "Asset tag:   " . value_or_na($jamfDevice['asset_tag'] ?? null) . "\n";
        echo "Owner:       " . value_or_na($jamfDevice['owner_name'] ?? null) . "\n";
        echo "Jamf model:  " . value_or_na($jamfDevice['model'] ?? null) . "\n";
    } else {
        echo "Jamf:        n/a\n";
    }
    echo "\n";
}

function asm_access_token(): string
{
    $config = parse_ini_file('/etc/disown/asm.conf');
    if (!$config || empty($config['ASM_CLIENT_ID'])) {
        throw new RuntimeException('ASM-Konfiguration fehlt oder ist unvollstaendig.');
    }

    $assertion = trim((string) shell_exec('/etc/disown/asm-jwt.py'));
    if ($assertion === '') {
        throw new RuntimeException('ASM client_assertion konnte nicht erzeugt werden.');
    }

    $response = http_request('POST', ASM_TOKEN_URL, [
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

function asm_recent_devices(string $token, int $days, int $limit): array
{
    $cutoff = new DateTimeImmutable("-{$days} days", new DateTimeZone('UTC'));
    $url = ASM_API_BASE . '/orgDevices?limit=' . $limit;
    $devices = [];
    $pages = 0;

    while ($url !== '' && $pages < 100) {
        $pages++;
        $response = http_request('GET', $url, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);

        if ($response['status'] !== 200) {
            throw new RuntimeException('ASM orgDevices fehlgeschlagen. HTTP ' . $response['status']);
        }

        foreach (($response['json']['data'] ?? []) as $row) {
            $device = normalize_asm_device($row);
            $updatedAt = parse_utc($device['updated_at'] ?? null);
            if ($updatedAt && $updatedAt >= $cutoff) {
                $devices[] = $device;
            }
        }

        $url = (string) ($response['json']['links']['next'] ?? '');
    }

    usort($devices, static function (array $a, array $b): int {
        return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
    });

    return $devices;
}

function asm_device(string $token, string $serial): ?array
{
    $response = http_request('GET', ASM_API_BASE . '/orgDevices/' . rawurlencode($serial), [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);

    if ($response['status'] === 404) {
        return null;
    }
    if ($response['status'] !== 200) {
        throw new RuntimeException('ASM orgDevice fehlgeschlagen. HTTP ' . $response['status']);
    }

    return normalize_asm_device($response['json']['data'] ?? []);
}

function asm_assigned_server(string $token, string $serial): ?array
{
    $response = http_request('GET', ASM_API_BASE . '/orgDevices/' . rawurlencode($serial) . '/assignedServer', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);

    if ($response['status'] === 404) {
        return null;
    }
    if ($response['status'] !== 200) {
        throw new RuntimeException('ASM assignedServer fehlgeschlagen. HTTP ' . $response['status']);
    }

    $data = $response['json']['data'] ?? [];
    $attributes = is_array($data) ? ($data['attributes'] ?? []) : [];

    return [
        'id' => (string) ($data['id'] ?? ''),
        'server_name' => (string) ($attributes['serverName'] ?? ''),
        'server_type' => (string) ($attributes['serverType'] ?? ''),
    ];
}

function jamf_device_by_serial(string $serial): ?array
{
    $config = parse_ini_file('/etc/disown/jamf.conf');
    if (!$config || empty($config['JAMF_URL']) || empty($config['JAMF_NETWORK_ID']) || empty($config['JAMF_API_KEY'])) {
        throw new RuntimeException('Jamf-Konfiguration fehlt oder ist unvollstaendig.');
    }

    $url = rtrim((string) $config['JAMF_URL'], '/') . '/api/devices?serialnumber=' . rawurlencode($serial);
    $response = http_request('GET', $url, [
        'Authorization: Basic ' . base64_encode($config['JAMF_NETWORK_ID'] . ':' . $config['JAMF_API_KEY']),
        'Accept: application/json',
    ]);

    if ($response['status'] !== 200) {
        throw new RuntimeException('Jamf Device-Lookup fehlgeschlagen. HTTP ' . $response['status']);
    }

    foreach (($response['json']['devices'] ?? []) as $device) {
        if (strtoupper((string) ($device['serialNumber'] ?? '')) !== $serial) {
            continue;
        }
        $owner = $device['owner'] ?? [];
        $model = $device['model'] ?? [];

        return [
            'name' => (string) ($device['name'] ?? ''),
            'asset_tag' => (string) ($device['assetTag'] ?? ''),
            'owner_name' => (string) ($owner['name'] ?? ''),
            'owner_username' => (string) ($owner['username'] ?? ''),
            'model' => (string) ($model['name'] ?? ''),
        ];
    }

    return null;
}

function normalize_asm_device(array $row): array
{
    $attributes = $row['attributes'] ?? [];

    return [
        'id' => (string) ($row['id'] ?? ''),
        'serial' => (string) ($attributes['serialNumber'] ?? $row['id'] ?? ''),
        'added_at' => (string) ($attributes['addedToOrgDateTime'] ?? ''),
        'updated_at' => (string) ($attributes['updatedDateTime'] ?? ''),
        'model' => (string) ($attributes['deviceModel'] ?? ''),
        'product_family' => (string) ($attributes['productFamily'] ?? ''),
        'product_type' => (string) ($attributes['productType'] ?? ''),
        'capacity' => (string) ($attributes['deviceCapacity'] ?? ''),
        'order_number' => (string) ($attributes['orderNumber'] ?? ''),
        'part_number' => (string) ($attributes['partNumber'] ?? ''),
        'purchase_source_type' => (string) ($attributes['purchaseSourceType'] ?? ''),
        'status' => (string) ($attributes['status'] ?? ''),
    ];
}

function http_request(string $method, string $url, array $headers = [], ?string $body = null): array
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

function parse_utc(?string $value): ?DateTimeImmutable
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

function value_or_na(?string $value): string
{
    $value = trim((string) $value);
    return $value === '' ? 'n/a' : $value;
}
