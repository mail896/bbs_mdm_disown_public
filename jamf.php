<?php

require_once __DIR__ . '/workflow_state.php';

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

/**
 * Deterministic DEV-only fixtures for the visible FAKE0 devices. No Jamf call is made.
 *
 * @return array<string, mixed>
 */
function jamf_dev_fixture_result(string $serial, bool $verificationOnly = false, bool $retryExhausted = false): array
{
    $serial = strtoupper(trim($serial));
    $base = [
        'success' => false,
        'accepted' => false,
        'http_status' => 200,
        'command_id' => 'dev-' . strtolower($serial),
        'command_status' => 'simulated',
        'observed_location' => 'active',
        'observed_is_managed' => true,
        'active_absent' => false,
        'trash_observed' => false,
        'dev_fixture' => true,
    ];

    if ($serial === 'FAKE0DIANA1') {
        if ($verificationOnly) {
            return array_merge($base, [
                'success' => true,
                'accepted' => true,
                'state' => DISOWN_JAMF_STATE_VERIFIED_UNMANAGED,
                'response_class' => 'dev_fixture_verified_after_retry',
                'command_status' => 'completed',
                'message' => 'DEV-Simulation: Der später zugestellte Befehl wurde read-only als abgeschlossen verifiziert.',
                'observed_is_managed' => false,
            ]);
        }

        return array_merge($base, [
            'accepted' => true,
            'state' => DISOWN_JAMF_STATE_PENDING_DEVICE,
            'response_class' => 'dev_fixture_command_accepted',
            'command_status' => 'queued',
            'message' => 'DEV-Simulation: HTTP 200, Gerät offline; der Befehl wartet auf späteren Kontakt.',
        ]);
    }

    if ($serial === 'FAKE0MULDER') {
        return array_merge($base, [
            'success' => true,
            'accepted' => true,
            'state' => DISOWN_JAMF_STATE_VERIFIED_UNMANAGED,
            'response_class' => 'dev_fixture_verified_immediately',
            'command_status' => 'completed',
            'message' => 'DEV-Simulation: Un-enroll wurde unmittelbar verifiziert.',
            'observed_is_managed' => false,
        ]);
    }

    if ($serial === 'FAKE0SCULLY') {
        return array_merge($base, [
            'state' => DISOWN_JAMF_STATE_FAILED,
            'response_class' => 'dev_fixture_unexpected_http_200',
            'message' => 'DEV-Simulation: HTTP 200 mit unerwarteter Antwort; kein Erfolg wird angenommen.',
        ]);
    }

    if ($serial === 'FAKE0KNOPE1') {
        return array_merge($base, [
            'http_status' => null,
            'state' => DISOWN_JAMF_STATE_FAILED,
            'response_class' => 'dev_fixture_transport_timeout',
            'message' => 'DEV-Simulation: Zeitüberschreitung beim Jamf-Aufruf.',
        ]);
    }

    return array_merge($base, [
        'accepted' => true,
        'state' => $retryExhausted ? DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED : DISOWN_JAMF_STATE_PENDING_DEVICE,
        'response_class' => $retryExhausted ? 'dev_fixture_manual_review' : 'dev_fixture_pending',
        'command_status' => 'queued',
        'message' => $retryExhausted
            ? 'DEV-Simulation: Nach begrenzten read-only Prüfungen bleibt manuelle Verifizierung erforderlich.'
            : 'DEV-Simulation: Befehl angenommen; Verifikation steht aus.',
    ]);
}

/**
 * @param array<string, mixed> $request
 * @return array{http_status: int, body: string, transport_error: string}
 */
function jamf_curl_transport(array $request): array
{
    $ch = curl_init((string) ($request['url'] ?? ''));
    $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
    $method = strtoupper((string) ($request['method'] ?? 'GET'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => (string) ($request['username'] ?? '') . ':' . (string) ($request['password'] ?? ''),
        CURLOPT_CONNECTTIMEOUT => (int) ($request['connect_timeout'] ?? 10),
        CURLOPT_TIMEOUT => (int) ($request['timeout'] ?? 30),
        CURLOPT_FAILONERROR => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, (string) ($request['body'] ?? ''));
    }

    $body = curl_exec($ch);
    $transportError = curl_errno($ch) ? curl_error($ch) : '';
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [
        'http_status' => $httpStatus,
        'body' => $body === false ? '' : (string) $body,
        'transport_error' => $body === false && $transportError === '' ? 'Jamf request failed.' : $transportError,
    ];
}

/**
 * @param array<string, string> $cfg
 * @return array{http_status: int, body: string, transport_error: string}
 */
function jamf_api_request(string $method, string $url, array $cfg, ?callable $transport = null): array
{
    $transport ??= 'jamf_curl_transport';
    $result = $transport([
        'method' => strtoupper($method),
        'url' => $url,
        'username' => (string) ($cfg['JAMF_NETWORK_ID'] ?? ''),
        'password' => (string) ($cfg['JAMF_API_KEY'] ?? ''),
        'connect_timeout' => 10,
        'timeout' => 30,
        'headers' => [
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        'body' => '',
    ]);

    return [
        'http_status' => (int) ($result['http_status'] ?? 0),
        'body' => (string) ($result['body'] ?? ''),
        'transport_error' => trim((string) ($result['transport_error'] ?? '')),
    ];
}

/**
 * @param array<string, string>|null $cfg
 * @return array{ok: bool, http_status: int|null, response_class: string, devices: array<int, mixed>, message: string}
 */
function jamf_request_devices_by_serial_result(
    string $serial,
    bool $inTrash = false,
    ?callable $transport = null,
    ?array $cfg = null
): array {
    $cfg ??= jamf_config();
    if (!$cfg) {
        return [
            'ok' => false,
            'http_status' => null,
            'response_class' => 'config_missing',
            'devices' => [],
            'message' => 'Jamf-Konfiguration fehlt oder ist unvollständig.',
        ];
    }

    $url = rtrim((string) $cfg['JAMF_URL'], '/') . '/api/devices?serialnumber=' . urlencode($serial);
    if ($inTrash) {
        $url .= '&inTrash=true';
    }
    $response = jamf_api_request('GET', $url, $cfg, $transport);
    if ($response['transport_error'] !== '') {
        return [
            'ok' => false,
            'http_status' => $response['http_status'] ?: null,
            'response_class' => 'transport_error',
            'devices' => [],
            'message' => 'Jamf-Status konnte wegen eines Verbindungsfehlers nicht gelesen werden.',
        ];
    }
    if ($response['http_status'] !== 200) {
        return [
            'ok' => false,
            'http_status' => $response['http_status'],
            'response_class' => 'http_error',
            'devices' => [],
            'message' => 'Jamf-Statusabfrage fehlgeschlagen: HTTP ' . $response['http_status'],
        ];
    }

    $data = json_decode($response['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || !isset($data['devices']) || !is_array($data['devices'])) {
        return [
            'ok' => false,
            'http_status' => 200,
            'response_class' => 'invalid_device_list',
            'devices' => [],
            'message' => 'Jamf-Statusantwort war nicht lesbar oder unvollständig.',
        ];
    }

    return [
        'ok' => true,
        'http_status' => 200,
        'response_class' => 'device_list',
        'devices' => $data['devices'],
        'message' => '',
    ];
}

function jamf_request_devices_by_serial(string $serial, bool $inTrash = false): ?array
{
    $result = jamf_request_devices_by_serial_result($serial, $inTrash);
    return $result['ok'] ? $result['devices'] : null;
}

/** @param array<int, mixed> $devices */
function jamf_find_device_by_serial(array $devices, string $serial): ?array
{
    $serial = strtoupper(trim($serial));
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

function jamf_device_by_serial(string $serial, bool $inTrash = false): ?array
{
    $serial = strtoupper(trim($serial));
    $devices = jamf_request_devices_by_serial($serial, $inTrash);
    if (!$devices) {
        return null;
    }

    return jamf_find_device_by_serial($devices, $serial);
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

function jamf_lookup_from_device(array $device, string $state = 'active'): array
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
        'jamf_state'    => $state,
        'asset_tag'     => $device['assetTag'] ?? '',
        'groups'        => jamf_group_names($device),
        'icloud_backup_enabled' => $device['iCloudBackupEnabled'] ?? null,
        'itunes_store_logged_in' => $device['iTunesStoreLoggedIn'] ?? null,
    ];
}

function jamf_truthy($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int) $value === 1;
    }

    $value = strtolower(trim((string) $value));
    return in_array($value, ['1', 'true', 'yes', 'ja', 'on'], true);
}

function jamf_device_has_apple_id_risk(array $device): bool
{
    return jamf_truthy($device['icloud_backup_enabled'] ?? $device['iCloudBackupEnabled'] ?? null)
        || jamf_truthy($device['itunes_store_logged_in'] ?? $device['iTunesStoreLoggedIn'] ?? null);
}

function jamf_device_is_school_loan_exception(array $device): bool
{
    $serial = strtoupper(trim((string) ($device['serial'] ?? $device['serialNumber'] ?? '')));
    if (in_array($serial, ['FFKDP0VBMF3M', 'F9FZMUJFMF3M'], true)) {
        return true;
    }

    $assetTag = strtoupper(trim((string) ($device['asset_tag'] ?? $device['assetTag'] ?? '')));
    return in_array($assetTag, ['BBS #126', 'BBS #024'], true);
}

function jamf_device_is_school_loan(array $device): bool
{
    if (jamf_device_is_school_loan_exception($device)) {
        return false;
    }

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
    if (jamf_device_is_school_loan_exception($device)) {
        return [];
    }

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
        'icloud_backup_enabled' => null,
        'itunes_store_logged_in' => null,
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

function jamf_lookup_in_trash_by_serial(string $serial): ?array
{
    $serial = strtoupper(trim($serial));
    $device = jamf_device_by_serial($serial, true);
    if (!$device) {
        return null;
    }

    return jamf_lookup_from_device($device, 'trash');
}

function jamf_nullable_bool($value): ?bool
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int) $value === 1;
    }

    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['1', 'true', 'yes', 'ja', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'nein', 'off'], true)) {
        return false;
    }

    return null;
}

/** @return array<string, mixed> */
function jamf_observation_from_device(?array $device, string $location): array
{
    if (!$device) {
        return [
            'found' => false,
            'location' => $location,
            'is_managed' => null,
            'modified' => null,
            'last_checkin' => null,
        ];
    }

    $managedValue = $device['isManaged'] ?? $device['is_managed'] ?? $device['managed'] ?? null;
    return [
        'found' => true,
        'location' => $location,
        'is_managed' => jamf_nullable_bool($managedValue),
        'modified' => trim((string) ($device['modified'] ?? '')) ?: null,
        'last_checkin' => trim((string) ($device['lastCheckin'] ?? $device['last_checkin'] ?? '')) ?: null,
        'udid' => trim((string) ($device['UDID'] ?? $device['udid'] ?? '')),
        'device' => $device,
    ];
}

/**
 * Read Active and Trash independently. Lookup failures remain distinguishable
 * from an authoritative empty list.
 *
 * @param array<string, string>|null $cfg
 * @return array<string, mixed>
 */
function jamf_observe_serial(
    string $serial,
    ?callable $transport = null,
    ?array $cfg = null
): array {
    $serial = strtoupper(trim($serial));
    $activeResult = jamf_request_devices_by_serial_result($serial, false, $transport, $cfg);
    $trashResult = jamf_request_devices_by_serial_result($serial, true, $transport, $cfg);
    $activeDevice = $activeResult['ok'] ? jamf_find_device_by_serial($activeResult['devices'], $serial) : null;
    $trashDevice = $trashResult['ok'] ? jamf_find_device_by_serial($trashResult['devices'], $serial) : null;

    return [
        'serial' => $serial,
        'active_lookup_ok' => $activeResult['ok'],
        'trash_lookup_ok' => $trashResult['ok'],
        'active_http_status' => $activeResult['http_status'],
        'trash_http_status' => $trashResult['http_status'],
        'active_response_class' => $activeResult['response_class'],
        'trash_response_class' => $trashResult['response_class'],
        'active' => jamf_observation_from_device($activeDevice, 'active'),
        'trash' => jamf_observation_from_device($trashDevice, 'trash'),
    ];
}

/** @return array<string, mixed> */
function jamf_classify_unenroll_response(array $response): array
{
    $httpStatus = (int) ($response['http_status'] ?? 0);
    if (trim((string) ($response['transport_error'] ?? '')) !== '') {
        return [
            'accepted' => false,
            'http_status' => $httpStatus ?: null,
            'response_class' => 'transport_error',
            'command_id' => null,
            'command_status' => null,
            'message' => 'Jamf-Unenroll konnte wegen eines Verbindungsfehlers nicht angefordert werden.',
        ];
    }
    if ($httpStatus !== 200) {
        return [
            'accepted' => false,
            'http_status' => $httpStatus,
            'response_class' => 'http_error',
            'command_id' => null,
            'command_status' => null,
            'message' => 'Jamf-Unenroll wurde nicht angenommen: HTTP ' . $httpStatus,
        ];
    }

    $data = json_decode((string) ($response['body'] ?? ''), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return [
            'accepted' => false,
            'http_status' => 200,
            'response_class' => 'invalid_json',
            'command_id' => null,
            'command_status' => null,
            'message' => 'Jamf lieferte HTTP 200, aber keine lesbare Unenroll-Antwort.',
        ];
    }
    if (($data['message'] ?? null) !== 'DeviceUnenrolled') {
        return [
            'accepted' => false,
            'http_status' => 200,
            'response_class' => 'unexpected_response',
            'command_id' => null,
            'command_status' => null,
            'message' => 'Jamf lieferte HTTP 200, bestätigte den Unenroll-Befehl aber nicht.',
        ];
    }

    $commandId = null;
    foreach (['commandId', 'command_id', 'taskId', 'task_id', 'uuid'] as $key) {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value !== '') {
            $commandId = substr($value, 0, 128);
            break;
        }
    }
    $commandStatus = null;
    foreach (['commandStatus', 'command_status', 'taskStatus', 'task_status', 'status'] as $key) {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value !== '') {
            $commandStatus = substr($value, 0, 32);
            break;
        }
    }

    return [
        'accepted' => true,
        'http_status' => 200,
        'response_class' => 'device_unenrolled_command_accepted',
        'command_id' => $commandId,
        'command_status' => $commandStatus,
        'message' => 'Jamf hat den Unenroll-Befehl angenommen. Die Gerätebestätigung steht noch aus.',
    ];
}

function jamf_timestamp_is_after(?string $candidate, ?string $reference): bool
{
    if (!$candidate || !$reference) {
        return false;
    }
    $candidateTime = strtotime($candidate);
    $referenceTime = strtotime($reference);
    return $candidateTime !== false && $referenceTime !== false && $candidateTime >= $referenceTime;
}

/** @return array<string, mixed> */
function jamf_evidence_from_observation(
    array $observation,
    bool $commandAccepted,
    ?string $commandAcceptedAt = null,
    bool $commandTerminalSuccess = false
): array {
    $active = is_array($observation['active'] ?? null) ? $observation['active'] : [];
    $trash = is_array($observation['trash'] ?? null) ? $observation['trash'] : [];
    $activeLookupOk = !empty($observation['active_lookup_ok']);
    $trashLookupOk = !empty($observation['trash_lookup_ok']);

    return [
        'command_accepted' => $commandAccepted,
        'command_terminal_success' => $commandTerminalSuccess,
        'direct_unmanaged' => $activeLookupOk
            && !empty($active['found'])
            && array_key_exists('is_managed', $active)
            && $active['is_managed'] === false,
        'active_found' => $activeLookupOk ? !empty($active['found']) : null,
        'trash_found' => $trashLookupOk && !empty($trash['found']),
        'trash_changed_after_command' => $trashLookupOk
            && !empty($trash['found'])
            && jamf_timestamp_is_after($trash['modified'] ?? null, $commandAcceptedAt),
        'trash_last_checkin_after_command' => $trashLookupOk
            && !empty($trash['found'])
            && jamf_timestamp_is_after($trash['last_checkin'] ?? null, $commandAcceptedAt),
        // Trash values only qualify as part of the correlated transition above.
        'trash_is_managed' => $trash['is_managed'] ?? null,
        'trash_last_checkin' => $trash['last_checkin'] ?? null,
    ];
}

/** @return array<string, mixed> */
function jamf_verification_result(
    array $observation,
    bool $commandAccepted,
    ?string $commandAcceptedAt = null,
    bool $commandTerminalSuccess = false,
    bool $retryExhausted = false
): array {
    $evidence = jamf_evidence_from_observation(
        $observation,
        $commandAccepted,
        $commandAcceptedAt,
        $commandTerminalSuccess
    );
    $state = disown_jamf_state_from_evidence($evidence, $retryExhausted);
    $active = is_array($observation['active'] ?? null) ? $observation['active'] : [];
    $trash = is_array($observation['trash'] ?? null) ? $observation['trash'] : [];
    $location = !empty($active['found']) ? 'active' : (!empty($trash['found']) ? 'trash' : 'missing');
    $observedManaged = !empty($active['found']) ? ($active['is_managed'] ?? null) : ($trash['is_managed'] ?? null);

    $message = match ($state) {
        DISOWN_JAMF_STATE_VERIFIED_UNMANAGED => 'Jamf führt das Gerät nachweislich nicht mehr als verwaltet.',
        DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED => 'Jamf-Abmeldung konnte nach den begrenzten Prüfungen nicht eindeutig verifiziert werden.',
        DISOWN_JAMF_STATE_PENDING_DEVICE => 'Jamf-Befehl wurde angenommen; das Gerät hat die Abmeldung noch nicht nachweislich abgeschlossen.',
        default => 'Jamf-Abmeldung ist fehlgeschlagen oder nicht bestätigt.',
    };

    return [
        'success' => $state === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED,
        'state' => $state,
        'message' => $message,
        'observed_location' => $location,
        'observed_is_managed' => $observedManaged,
        'active_absent' => !empty($observation['active_lookup_ok']) && empty($active['found']),
        'trash_observed' => !empty($observation['trash_lookup_ok']) && !empty($trash['found']),
        'evidence' => $evidence,
    ];
}

/**
 * Read-only retry. It never resends the Unenroll command.
 *
 * @param array<string, string>|null $cfg
 * @return array<string, mixed>
 */
function jamf_verify_unenroll_by_serial(
    string $serial,
    ?string $commandAcceptedAt,
    bool $commandTerminalSuccess = false,
    bool $retryExhausted = false,
    ?callable $transport = null,
    ?array $cfg = null
): array {
    $normalizedSerial = strtoupper(trim($serial));
    if (disown_is_dev_mode() && $transport === null && str_starts_with($normalizedSerial, 'FAKE0')) {
        return jamf_dev_fixture_result($normalizedSerial, true, $retryExhausted);
    }

    $observation = jamf_observe_serial($serial, $transport, $cfg);
    $result = jamf_verification_result(
        $observation,
        true,
        $commandAcceptedAt,
        $commandTerminalSuccess,
        $retryExhausted
    );
    $result['observation'] = $observation;
    return $result;
}

/**
 * Unenroll a device from Jamf School by serial number.
 * This calls POST /api/devices/{UDID}/unenroll.
 */
function jamf_unenroll_by_serial(
    string $serial,
    bool $allowSchoolLoan = false,
    ?callable $transport = null,
    ?array $cfg = null,
    ?int $postCommandPollAttempts = null,
    int $postCommandPollDelayMs = 750
): array
{
    $serial = strtoupper(trim($serial));
    if ($serial === '') {
        return [
            'success' => false,
            'accepted' => false,
            'state' => DISOWN_JAMF_STATE_FAILED,
            'response_class' => 'serial_missing',
            'message' => 'Seriennummer fehlt.',
        ];
    }

    if (disown_is_dev_mode() && $transport === null && str_starts_with($serial, 'FAKE0')) {
        return jamf_dev_fixture_result($serial);
    }

    $cfg ??= jamf_config();
    if (!$cfg) {
        return [
            'success' => false,
            'accepted' => false,
            'state' => DISOWN_JAMF_STATE_FAILED,
            'response_class' => 'config_missing',
            'message' => 'Jamf-Konfiguration fehlt oder ist unvollständig.',
        ];
    }

    $before = jamf_observe_serial($serial, $transport, $cfg);
    if (empty($before['active_lookup_ok'])) {
        return [
            'success' => false,
            'accepted' => false,
            'state' => DISOWN_JAMF_STATE_FAILED,
            'http_status' => $before['active_http_status'],
            'response_class' => $before['active_response_class'],
            'message' => 'Aktiver Jamf-Gerätestatus konnte nicht sicher gelesen werden.',
            'observation' => $before,
        ];
    }

    $active = $before['active'];
    $trash = $before['trash'];
    if (empty($active['found'])) {
        $responseClass = !empty($trash['found']) ? 'already_in_trash_unverified' : 'device_not_found';
        return [
            'success' => false,
            'accepted' => false,
            'state' => !empty($trash['found'])
                ? DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED
                : DISOWN_JAMF_STATE_FAILED,
            'response_class' => $responseClass,
            'message' => !empty($trash['found'])
                ? 'Gerät ist nur im Jamf-Trash sichtbar; die Profilentfernung ist damit nicht bewiesen.'
                : 'Gerät wurde in Jamf weder aktiv noch im Trash eindeutig gefunden.',
            'observation' => $before,
        ];
    }

    $fullDevice = is_array($active['device'] ?? null) ? $active['device'] : [];
    if (($active['is_managed'] ?? null) === false) {
        $verified = jamf_verification_result($before, false);
        return array_merge($verified, [
            'accepted' => false,
            'http_status' => $before['active_http_status'],
            'response_class' => 'already_unmanaged',
            'command_id' => null,
            'command_status' => null,
            'observation' => $before,
        ]);
    }

    if (!$allowSchoolLoan && jamf_device_is_school_loan($fullDevice)) {
        return [
            'success' => false,
            'accepted' => false,
            'state' => DISOWN_JAMF_STATE_FAILED,
            'response_class' => 'school_loan_blocked',
            'message' => 'Dieses Gerät ist als schulisches Leih-/Koffergerät markiert und darf nicht per Jamf abgemeldet werden.',
        ];
    }

    $udid = trim((string) ($fullDevice['UDID'] ?? $fullDevice['udid'] ?? ''));
    if (!$udid) {
        return [
            'success' => false,
            'accepted' => false,
            'state' => DISOWN_JAMF_STATE_FAILED,
            'response_class' => 'udid_missing',
            'message' => 'UDID des Geräts konnte nicht ermittelt werden.',
        ];
    }

    if (disown_is_dev_mode() && $transport === null) {
        return [
            'success' => false,
            'accepted' => true,
            'state' => DISOWN_JAMF_STATE_PENDING_DEVICE,
            'http_status' => 200,
            'response_class' => 'dev_simulated_command_accepted',
            'command_id' => null,
            'command_status' => 'simulated',
            'message' => 'DEV-Simulation: Jamf-Befehl angenommen; Gerätebestätigung bleibt absichtlich ausstehend.',
            'observed_location' => 'active',
            'observed_is_managed' => $active['is_managed'] ?? true,
            'active_absent' => false,
            'trash_observed' => false,
            'observation' => $before,
        ];
    }

    $unenrollUrl = rtrim((string) $cfg['JAMF_URL'], '/') . '/api/devices/' . urlencode($udid) . '/unenroll';
    $command = jamf_classify_unenroll_response(jamf_api_request('POST', $unenrollUrl, $cfg, $transport));
    if (empty($command['accepted'])) {
        return array_merge($command, [
            'success' => false,
            'state' => DISOWN_JAMF_STATE_FAILED,
            'observation' => $before,
        ]);
    }

    $acceptedAt = date('Y-m-d H:i:s');
    $pollAttempts = $postCommandPollAttempts ?? ($transport === null ? 4 : 1);
    $pollAttempts = max(1, min(6, $pollAttempts));
    $postCommandPollDelayMs = max(0, min(2000, $postCommandPollDelayMs));
    $after = [];
    $verified = [];
    for ($pollAttempt = 1; $pollAttempt <= $pollAttempts; $pollAttempt++) {
        if ($pollAttempt > 1 && $postCommandPollDelayMs > 0) {
            usleep($postCommandPollDelayMs * 1000);
        }
        $after = jamf_observe_serial($serial, $transport, $cfg);
        $verified = jamf_verification_result($after, true, $acceptedAt);
        if (($verified['state'] ?? '') === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED) {
            break;
        }
    }

    return array_merge($verified, [
        'accepted' => true,
        'http_status' => $command['http_status'],
        'response_class' => $command['response_class'],
        'command_id' => $command['command_id'],
        'command_status' => $command['command_status'],
        'command_accepted_at' => $acceptedAt,
        'post_command_poll_attempts' => $pollAttempt,
        'observation' => $after,
    ]);
}
