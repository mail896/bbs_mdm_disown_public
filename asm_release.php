<?php

declare(strict_types=1);

require_once __DIR__ . '/ade_api.php';

const ASM_RELEASE_CONFIG_PATH = '/etc/disown/asm-release-broker.conf';

function asm_release_is_dev_mode(): bool
{
    return basename(__DIR__) === 'disown-dev';
}

function asm_release_config(bool $requireRuntime = false): array
{
    $config = [];
    if (is_readable(ASM_RELEASE_CONFIG_PATH)) {
        $parsed = parse_ini_file(ASM_RELEASE_CONFIG_PATH);
        if (is_array($parsed)) {
            $config = $parsed;
        }
    }

    $config += [
        'ASM_JAMF_MDM_SERVER_ID' => '1A7A1B6F553B4C06907559E92B84D19A',
        'ASM_BROKER_MDM_SERVER_ID' => '61268AA367B84D728F84176E37D4F5FA',
        'ASM_BROKER_DEP_NAME' => 'asm-release-broker',
        'ASM_BROKER_DEP_BASE_URL' => 'http://127.0.0.1:9001',
        'ASM_BROKER_DEP_API_KEY' => '',
        'ASM_BROKER_DEP_API_KEY_FILE' => '',
    ];

    if ($config['ASM_BROKER_DEP_API_KEY'] === '' && $config['ASM_BROKER_DEP_API_KEY_FILE'] !== '') {
        $apiKeyFile = (string) $config['ASM_BROKER_DEP_API_KEY_FILE'];
        if (is_readable($apiKeyFile)) {
            $config['ASM_BROKER_DEP_API_KEY'] = trim((string) file_get_contents($apiKeyFile));
        }
    }

    if ($requireRuntime && !asm_release_is_dev_mode() && $config['ASM_BROKER_DEP_API_KEY'] === '') {
        throw new RuntimeException('ASM Release Broker API-Key fehlt.');
    }

    return array_map('strval', $config);
}

function asm_release_preview(string $serial): array
{
    return asm_release_run($serial, true);
}

function asm_release_execute(string $serial): array
{
    return asm_release_run($serial, asm_release_is_dev_mode());
}

function asm_release_run(string $serial, bool $dryRun): array
{
    $serial = strtoupper(trim($serial));
    $steps = [];

    if ($serial === '') {
        return asm_release_result(false, $dryRun, 'Seriennummer fehlt.', $steps);
    }

    try {
        $config = asm_release_config(!$dryRun);
        $token = ade_asm_access_token();

        $device = asm_release_get_org_device($token, $serial);
        $releasedAt = (string) ($device['released_from_org_at'] ?? '');
        $steps[] = asm_release_step(
            'ASM-Gerät',
            $device ? 'ok' : 'failed',
            $device ? 'Gerät in ASM gefunden.' : 'Gerät in ASM nicht gefunden.'
        );
        if (!$device) {
            return asm_release_result(false, $dryRun, 'ASM-Gerät wurde nicht gefunden.', $steps);
        }

        $steps[] = asm_release_step(
            'Organisation',
            $releasedAt === '' ? 'ok' : 'failed',
            $releasedAt === '' ? 'Gerät ist noch nicht aus der Organisation entfernt.' : 'Gerät wurde bereits am ' . $releasedAt . ' aus der Organisation entfernt.'
        );
        if ($releasedAt !== '') {
            return asm_release_result(false, $dryRun, 'Gerät ist bereits freigegeben.', $steps);
        }

        $assigned = ade_asm_assigned_server($token, $serial);
        $assignedId = (string) ($assigned['asm_mdm_server_id'] ?? '');
        $assignedName = (string) ($assigned['asm_mdm_server_name'] ?? '');
        $isJamfAssigned = strcasecmp($assignedId, $config['ASM_JAMF_MDM_SERVER_ID']) === 0;
        $isBrokerAssigned = strcasecmp($assignedId, $config['ASM_BROKER_MDM_SERVER_ID']) === 0;

        $steps[] = asm_release_step(
            'MDM-Zuweisung',
            ($isJamfAssigned || $isBrokerAssigned) ? 'ok' : 'failed',
            $assignedId !== ''
                ? 'Aktuell zugewiesen an ' . ($assignedName ?: $assignedId) . '.'
                : 'Aktuelle MDM-Zuweisung konnte nicht gelesen werden.'
        );
        if (!$isJamfAssigned && !$isBrokerAssigned) {
            return asm_release_result(false, $dryRun, 'Gerät ist nicht Jamf School oder dem Release Broker zugewiesen.', $steps);
        }

        if ($dryRun) {
            $steps[] = asm_release_step(
                'ASM-Zuweisung',
                $isBrokerAssigned ? 'ok' : 'planned',
                $isBrokerAssigned
                    ? 'Gerät ist bereits dem Release Broker zugewiesen.'
                    : 'Würde dieses eine Gerät auf den Release Broker umhängen.'
            );
            $steps[] = asm_release_step('ADE-Freigabe', 'planned', 'Würde danach automatisch per ADE/DEP-API aus der Organisation freigeben.');

            return asm_release_result(true, true, 'Dry-Run erfolgreich: automatische ASM/ADE-Freigabe ist für dieses Gerät bereit.', $steps, [
                'serial' => $serial,
                'assigned_server' => $assigned,
                'device' => $device,
            ]);
        }

        if ($isJamfAssigned) {
            $activityId = asm_release_assign_to_broker($token, $serial, $config['ASM_BROKER_MDM_SERVER_ID']);
            $steps[] = asm_release_step('ASM-Zuweisung', 'ok', 'Zuweisung an Release Broker gestartet. Activity: ' . $activityId);

            $brokerAssigned = asm_release_wait_for_broker_assignment($token, $serial, $config['ASM_BROKER_MDM_SERVER_ID']);
            $steps[] = asm_release_step(
                'Broker sichtbar',
                $brokerAssigned ? 'ok' : 'failed',
                $brokerAssigned ? 'ASM meldet das Gerät beim Release Broker.' : 'ASM meldet das Gerät noch nicht beim Release Broker.'
            );
            if (!$brokerAssigned) {
                return asm_release_result(false, false, 'ASM-Zuweisung an den Broker wurde nicht bestätigt.', $steps);
            }
        } else {
            $steps[] = asm_release_step('ASM-Zuweisung', 'ok', 'Gerät ist bereits dem Release Broker zugewiesen.');
        }

        $brokerDetails = asm_release_broker_request($config, '/devices', $serial);
        $deviceStatus = (string) ($brokerDetails['devices'][$serial]['response_status'] ?? '');
        $steps[] = asm_release_step(
            'Broker-Details',
            $deviceStatus === 'SUCCESS' ? 'ok' : 'failed',
            $deviceStatus === 'SUCCESS' ? 'Release Broker kann das Gerät lesen.' : 'Release Broker Antwort: ' . ($deviceStatus ?: 'unbekannt')
        );
        if ($deviceStatus !== 'SUCCESS') {
            return asm_release_result(false, false, 'Release Broker kann das Gerät nicht lesen.', $steps, ['broker_details' => $brokerDetails]);
        }

        $disownResponse = asm_release_broker_request($config, '/devices/disown', $serial);
        $disownStatus = (string) ($disownResponse['devices'][$serial] ?? '');
        $steps[] = asm_release_step(
            'ADE-Freigabe',
            $disownStatus === 'SUCCESS' ? 'ok' : 'failed',
            $disownStatus === 'SUCCESS' ? 'Apple ADE/DEP meldet SUCCESS.' : 'Apple ADE/DEP Antwort: ' . ($disownStatus ?: 'unbekannt')
        );
        if ($disownStatus !== 'SUCCESS') {
            return asm_release_result(false, false, 'ADE-Freigabe wurde nicht erfolgreich abgeschlossen.', $steps, ['disown_response' => $disownResponse]);
        }

        $afterDetails = asm_release_broker_request($config, '/devices', $serial);
        $afterStatus = (string) ($afterDetails['devices'][$serial]['response_status'] ?? '');
        $steps[] = asm_release_step(
            'Nachkontrolle',
            $afterStatus === 'NOT_ACCESSIBLE' ? 'ok' : 'warning',
            $afterStatus === 'NOT_ACCESSIBLE'
                ? 'Broker kann das Gerät nach Freigabe nicht mehr lesen, wie erwartet.'
                : 'Broker-Nachkontrolle meldet: ' . ($afterStatus ?: 'unbekannt')
        );

        return asm_release_result(true, false, 'ASM/ADE-Freigabe erfolgreich abgeschlossen.', $steps, [
            'broker_details' => $brokerDetails,
            'disown_response' => $disownResponse,
            'after_details' => $afterDetails,
        ]);
    } catch (Throwable $e) {
        $steps[] = asm_release_step('Fehler', 'failed', $e->getMessage());
        return asm_release_result(false, $dryRun, 'ASM/ADE-Freigabe fehlgeschlagen: ' . $e->getMessage(), $steps);
    }
}

function asm_release_get_org_device(string $token, string $serial): ?array
{
    $response = ade_http_request('GET', ADE_ASM_API_BASE . '/orgDevices/' . rawurlencode($serial), [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);

    if ($response['status'] === 404) {
        return null;
    }
    if ($response['status'] !== 200) {
        throw new RuntimeException('ASM orgDevice fehlgeschlagen. HTTP ' . $response['status']);
    }

    $data = $response['json']['data'] ?? [];
    $attributes = is_array($data) ? ($data['attributes'] ?? []) : [];

    return [
        'id' => (string) ($data['id'] ?? $serial),
        'serial' => strtoupper((string) ($attributes['serialNumber'] ?? $serial)),
        'model' => (string) ($attributes['deviceModel'] ?? ''),
        'status' => (string) ($attributes['status'] ?? ''),
        'released_from_org_at' => (string) ($attributes['releasedFromOrgDateTime'] ?? ''),
    ];
}

function asm_release_assign_to_broker(string $token, string $serial, string $brokerMdmServerId): string
{
    $payload = [
        'data' => [
            'type' => 'orgDeviceActivities',
            'attributes' => [
                'activityType' => 'ASSIGN_DEVICES',
            ],
            'relationships' => [
                'mdmServer' => [
                    'data' => [
                        'type' => 'mdmServers',
                        'id' => $brokerMdmServerId,
                    ],
                ],
                'devices' => [
                    'data' => [
                        [
                            'type' => 'orgDevices',
                            'id' => $serial,
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = ade_http_request('POST', ADE_ASM_API_BASE . '/orgDeviceActivities', [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ], json_encode($payload, JSON_THROW_ON_ERROR));

    if ($response['status'] !== 201) {
        throw new RuntimeException('ASM Broker-Zuweisung fehlgeschlagen. HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 300));
    }

    return (string) ($response['json']['data']['id'] ?? 'unbekannt');
}

function asm_release_wait_for_broker_assignment(string $token, string $serial, string $brokerMdmServerId): bool
{
    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $assigned = ade_asm_assigned_server($token, $serial);
        $assignedId = (string) ($assigned['asm_mdm_server_id'] ?? '');
        if (strcasecmp($assignedId, $brokerMdmServerId) === 0) {
            return true;
        }
        sleep(2);
    }

    return false;
}

function asm_release_broker_request(array $config, string $endpoint, string $serial): array
{
    $baseUrl = rtrim((string) $config['ASM_BROKER_DEP_BASE_URL'], '/');
    $depName = rawurlencode((string) $config['ASM_BROKER_DEP_NAME']);
    $apiKey = (string) $config['ASM_BROKER_DEP_API_KEY'];
    $url = $baseUrl . '/proxy/' . $depName . $endpoint;
    $payload = json_encode(['devices' => [$serial]], JSON_THROW_ON_ERROR);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json;charset=UTF8',
            'Accept: application/json',
            'User-Agent: BBS-Einbeck-iPad-Disown/2.0',
        ],
        CURLOPT_USERAGENT => 'BBS-Einbeck-iPad-Disown/2.0',
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_USERPWD => 'depserver:' . $apiKey,
        CURLOPT_FAILONERROR => false,
    ]);

    $raw = curl_exec($ch);
    $error = curl_errno($ch) ? curl_error($ch) : '';
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false || $error !== '') {
        throw new RuntimeException('Release Broker HTTP-Fehler: ' . $error);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Release Broker fehlgeschlagen. HTTP ' . $status . ': ' . substr((string) $raw, 0, 300));
    }

    $json = json_decode((string) $raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
        throw new RuntimeException('Release Broker lieferte keine JSON-Antwort.');
    }

    return $json;
}

function asm_release_step(string $label, string $status, string $detail): array
{
    return [
        'label' => $label,
        'status' => $status,
        'detail' => $detail,
    ];
}

function asm_release_result(bool $success, bool $dryRun, string $message, array $steps, array $details = []): array
{
    return [
        'success' => $success,
        'dry_run' => $dryRun,
        'message' => $message,
        'steps' => $steps,
        'details' => $details,
    ];
}
