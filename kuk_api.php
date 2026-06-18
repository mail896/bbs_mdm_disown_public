<?php

declare(strict_types=1);

function kuk_jamf_config(): array
{
    $config = parse_ini_file('/etc/disown/jamf.conf');
    if (!$config || empty($config['JAMF_URL']) || empty($config['JAMF_NETWORK_ID']) || empty($config['JAMF_API_KEY'])) {
        throw new RuntimeException('Jamf-Konfiguration fehlt oder ist unvollstaendig.');
    }

    return $config;
}

function kuk_jamf_request(string $path, array $query = []): array
{
    $config = kuk_jamf_config();
    $url = rtrim((string) $config['JAMF_URL'], '/') . $path;
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $config['JAMF_NETWORK_ID'] . ':' . $config['JAMF_API_KEY'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FAILONERROR => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
    ]);

    $raw = curl_exec($ch);
    $error = curl_errno($ch) ? curl_error($ch) : '';
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false || $error !== '') {
        throw new RuntimeException('Jamf HTTP-Fehler: ' . $error);
    }
    if ((int) $status !== 200) {
        throw new RuntimeException('Jamf API fehlgeschlagen. HTTP ' . (int) $status);
    }

    $json = json_decode((string) $raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
        throw new RuntimeException('Jamf API lieferte kein gueltiges JSON.');
    }

    return $json;
}

function kuk_jamf_devices(): array
{
    $json = kuk_jamf_request('/api/devices');
    $devices = $json['devices'] ?? $json['data'] ?? [];

    if (!is_array($devices)) {
        return [];
    }

    return array_values(array_filter($devices, 'is_array'));
}

function kuk_is_kuk_device(array $device): bool
{
    $assetTag = strtoupper(trim((string) ($device['assetTag'] ?? '')));
    if (str_starts_with($assetTag, 'LK-')) {
        return true;
    }

    return in_array('LK - Leihgeräte', kuk_jamf_group_names($device), true);
}

function kuk_jamf_group_names(array $device): array
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

function kuk_normalize_device(array $device): array
{
    $owner = is_array($device['owner'] ?? null) ? $device['owner'] : [];
    $model = is_array($device['model'] ?? null) ? $device['model'] : [];
    $os = is_array($device['os'] ?? null) ? $device['os'] : [];
    $groups = kuk_jamf_group_names($device);
    $assetTag = trim((string) ($device['assetTag'] ?? ''));

    return [
        'serial' => strtoupper(trim((string) ($device['serialNumber'] ?? ''))),
        'jamf_device_id' => kuk_string_or_null($device['id'] ?? $device['deviceId'] ?? null),
        'device_name' => kuk_string_or_null($device['name'] ?? null),
        'asset_tag' => kuk_string_or_null($assetTag),
        'owner_name' => kuk_string_or_null($owner['name'] ?? null),
        'owner_username' => kuk_string_or_null($owner['username'] ?? null),
        'owner_email' => kuk_string_or_null($owner['email'] ?? null),
        'model_name' => kuk_string_or_null($model['name'] ?? $device['modelName'] ?? null),
        'model_identifier' => kuk_string_or_null($model['identifier'] ?? null),
        'os_version' => kuk_string_or_null($os['version'] ?? $device['osVersion'] ?? null),
        'last_checkin' => kuk_mysql_datetime($device['lastCheckin'] ?? null),
        'enrollment_date' => kuk_first_mysql_datetime($device, [
            'enrollmentDate',
            'enrolledAt',
            'dateEnrolled',
            'enrollmentDateTime',
            'created',
        ]),
        'jamf_modified' => kuk_mysql_datetime($device['modified'] ?? null),
        'jamf_groups' => $groups ? implode(', ', $groups) : null,
        'matched_by_asset' => str_starts_with(strtoupper($assetTag), 'LK-') ? 1 : 0,
        'matched_by_group' => in_array('LK - Leihgeräte', $groups, true) ? 1 : 0,
        'raw_json' => json_encode($device, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function kuk_first_mysql_datetime(array $device, array $keys): ?string
{
    foreach ($keys as $key) {
        $value = $device[$key] ?? null;
        $date = kuk_mysql_datetime($value);
        if ($date !== null) {
            return $date;
        }
    }

    $enrollment = $device['enrollment'] ?? null;
    if (is_array($enrollment)) {
        foreach (['date', 'dateTime', 'created', 'enrolledAt'] as $key) {
            $date = kuk_mysql_datetime($enrollment[$key] ?? null);
            if ($date !== null) {
                return $date;
            }
        }
    }

    return null;
}

function kuk_mysql_datetime($value): ?string
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

function kuk_string_or_null($value): ?string
{
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}
