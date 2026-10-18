<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../jamf.php';
require __DIR__ . '/../asm_release.php';

$block3Assertions = 0;
$testConfig = [
    'JAMF_URL' => 'https://jamf.invalid',
    'JAMF_NETWORK_ID' => 'test-network',
    'JAMF_API_KEY' => 'test-key',
];

function block3_assert(bool $condition, string $message): void
{
    global $block3Assertions;
    $block3Assertions++;
    if (!$condition) {
        throw new RuntimeException('Test fehlgeschlagen: ' . $message);
    }
}

/** @param array<int, array<string, mixed>> $responses */
function block3_transport(array $responses, ?array &$requests = null): callable
{
    $queue = array_values($responses);
    return static function (array $request) use (&$queue, &$requests): array {
        if ($requests !== null) {
            $requests[] = [
                'method' => (string) ($request['method'] ?? ''),
                'url' => (string) ($request['url'] ?? ''),
            ];
        }
        if (!$queue) {
            throw new RuntimeException('Unerwarteter zusätzlicher Mock-Aufruf.');
        }
        $response = array_shift($queue);
        return [
            'http_status' => (int) ($response['http_status'] ?? 0),
            'body' => (string) ($response['body'] ?? ''),
            'transport_error' => (string) ($response['transport_error'] ?? ''),
        ];
    };
}

/** @param array<int, array<string, mixed>> $devices */
function block3_device_list(array $devices): array
{
    return [
        'http_status' => 200,
        'body' => json_encode(['devices' => $devices], JSON_THROW_ON_ERROR),
    ];
}

function block3_device(string $serial, bool $managed = true, array $extra = []): array
{
    return array_merge([
        'serialNumber' => $serial,
        'UDID' => 'TEST-UDID-' . $serial,
        'isManaged' => $managed,
        'name' => 'Test-iPad',
        'modified' => '2026-10-17 16:48:02',
        'lastCheckin' => '2026-10-17 14:33:59',
        'owner' => [],
    ], $extra);
}

try {
    $accepted = jamf_classify_unenroll_response([
        'http_status' => 200,
        'body' => '{"message":"DeviceUnenrolled","commandId":"safe-id"}',
        'transport_error' => '',
    ]);
    block3_assert($accepted['accepted'] === true, 'exaktes DeviceUnenrolled wird als angenommen klassifiziert');
    block3_assert($accepted['response_class'] === 'device_unenrolled_command_accepted', 'Annahme ist kein Abschlussstatus');
    block3_assert($accepted['command_id'] === 'safe-id', 'ungefährliche Command-ID wird übernommen');

    $unexpected = jamf_classify_unenroll_response([
        'http_status' => 200,
        'body' => '{"message":"Queued"}',
        'transport_error' => '',
    ]);
    block3_assert($unexpected['accepted'] === false, 'unerwarteter HTTP-200-Body ist kein Erfolg');
    block3_assert($unexpected['response_class'] === 'unexpected_response', 'unerwarteter Body wird sicher klassifiziert');

    $invalid = jamf_classify_unenroll_response([
        'http_status' => 200,
        'body' => '<html>not json</html>',
        'transport_error' => '',
    ]);
    block3_assert($invalid['accepted'] === false, 'unlesbarer HTTP-200-Body ist kein Erfolg');
    block3_assert($invalid['response_class'] === 'invalid_json', 'unlesbarer Body wird ohne Rohdaten klassifiziert');

    $timeout = jamf_classify_unenroll_response([
        'http_status' => 0,
        'body' => '',
        'transport_error' => 'timeout details must not escape',
    ]);
    block3_assert($timeout['accepted'] === false, 'Timeout ist kein Erfolg');
    block3_assert($timeout['response_class'] === 'transport_error', 'Timeout erhält sichere Antwortklasse');
    block3_assert(!str_contains($timeout['message'], 'timeout details'), 'Transport-Rohdetails werden nicht weitergegeben');

    $serverError = jamf_classify_unenroll_response([
        'http_status' => 503,
        'body' => '{"secret":"must not escape"}',
        'transport_error' => '',
    ]);
    block3_assert($serverError['accepted'] === false, 'HTTP-Fehler ist kein Erfolg');
    block3_assert($serverError['response_class'] === 'http_error', 'HTTP-Fehler erhält sichere Antwortklasse');
    block3_assert(!str_contains($serverError['message'], 'secret'), 'HTTP-Rohbody wird nicht weitergegeben');

    $serial = 'TESTSERIAL01';
    $requests = [];
    $verifiedTransport = block3_transport([
        block3_device_list([block3_device($serial, true)]),
        block3_device_list([]),
        ['http_status' => 200, 'body' => '{"message":"DeviceUnenrolled"}'],
        block3_device_list([block3_device($serial, false)]),
        block3_device_list([]),
    ], $requests);
    $verified = jamf_unenroll_by_serial($serial, false, $verifiedTransport, $testConfig);
    block3_assert($verified['accepted'] === true, 'gültiger Befehl wird angenommen');
    block3_assert($verified['state'] === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED, 'aktuelles isManaged=false verifiziert den Abschluss');
    block3_assert($verified['success'] === true, 'nur verifizierter Abschluss meldet success');
    block3_assert(count($requests) === 5, 'Active und Trash werden vor und nach dem Befehl getrennt gelesen');
    block3_assert($requests[2]['method'] === 'POST', 'nur der mittlere Aufruf ist schreibend');

    $pendingTransport = block3_transport([
        block3_device_list([block3_device($serial, true)]),
        block3_device_list([]),
        ['http_status' => 200, 'body' => '{"message":"DeviceUnenrolled"}'],
        block3_device_list([block3_device($serial, true)]),
        block3_device_list([]),
    ]);
    $pending = jamf_unenroll_by_serial($serial, false, $pendingTransport, $testConfig);
    block3_assert($pending['accepted'] === true, 'Offline-Fall bewahrt Befehlsannahme');
    block3_assert($pending['state'] === DISOWN_JAMF_STATE_PENDING_DEVICE, 'weiterhin managed bleibt pending');
    block3_assert($pending['success'] === false, 'pending ist kein abgeschlossener Erfolg');

    $unexpectedRequests = [];
    $unexpectedTransport = block3_transport([
        block3_device_list([block3_device($serial, true)]),
        block3_device_list([]),
        ['http_status' => 200, 'body' => '{"message":"Queued"}'],
    ], $unexpectedRequests);
    $unexpectedResult = jamf_unenroll_by_serial($serial, false, $unexpectedTransport, $testConfig);
    block3_assert($unexpectedResult['state'] === DISOWN_JAMF_STATE_FAILED, 'unerwarteter HTTP-200-Body führt zu failed');
    block3_assert($unexpectedResult['accepted'] === false, 'unerwarteter Body gilt nicht als angenommen');
    block3_assert(count($unexpectedRequests) === 3, 'nach nicht bestätigtem Befehl wird kein Abschluss erfunden');

    $alreadyUnmanagedTransport = block3_transport([
        block3_device_list([block3_device($serial, false)]),
        block3_device_list([]),
    ]);
    $alreadyUnmanaged = jamf_unenroll_by_serial($serial, false, $alreadyUnmanagedTransport, $testConfig);
    block3_assert($alreadyUnmanaged['state'] === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED, 'bereits unmanaged wird read-only erkannt');
    block3_assert($alreadyUnmanaged['accepted'] === false, 'für bereits unmanaged wird kein Befehl behauptet');

    $alreadyTrashTransport = block3_transport([
        block3_device_list([]),
        block3_device_list([block3_device($serial, true)]),
    ]);
    $alreadyTrash = jamf_unenroll_by_serial($serial, false, $alreadyTrashTransport, $testConfig);
    block3_assert($alreadyTrash['state'] === DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED, 'Trash allein verifiziert nichts');
    block3_assert($alreadyTrash['response_class'] === 'already_in_trash_unverified', 'Trash erhält eindeutige sichere Klassifikation');

    $incidentTransport = block3_transport([
        block3_device_list([]),
        block3_device_list([block3_device($serial, true, [
            'modified' => '2026-10-17 16:48:02',
            'lastCheckin' => '2026-10-17 14:33:59',
        ])]),
    ]);
    $incidentPending = jamf_verify_unenroll_by_serial(
        $serial,
        '2026-10-17 16:47:00',
        false,
        false,
        $incidentTransport,
        $testConfig
    );
    block3_assert($incidentPending['observed_location'] === 'trash', 'asynchroner Incident erkennt Trash-Übergang');
    block3_assert($incidentPending['observed_is_managed'] === true, 'stale Trash-Wert bleibt für Diagnose sichtbar');
    block3_assert($incidentPending['state'] === DISOWN_JAMF_STATE_PENDING_DEVICE, 'stale Trash-Wert entscheidet nicht vorschnell');

    $freshTrashTransport = block3_transport([
        block3_device_list([]),
        block3_device_list([block3_device($serial, false, [
            'modified' => '2026-10-18 11:50:11',
            'lastCheckin' => '2026-10-18 11:50:10',
        ])]),
    ]);
    $freshTrashVerified = jamf_verify_unenroll_by_serial(
        $serial,
        '2026-10-18 11:50:09',
        false,
        false,
        $freshTrashTransport,
        $testConfig
    );
    block3_assert($freshTrashVerified['observed_location'] === 'trash', 'frischer Abschluss erkennt den Trash-Übergang');
    block3_assert($freshTrashVerified['observed_is_managed'] === false, 'frischer Abschluss enthält explizit unmanaged');
    block3_assert($freshTrashVerified['state'] === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED, 'Active-Abwesenheit plus frischer unmanaged Trash verifiziert den Abschluss');

    $staleUnmanagedTrashTransport = block3_transport([
        block3_device_list([]),
        block3_device_list([block3_device($serial, false, [
            'modified' => '2026-10-18 11:50:11',
            'lastCheckin' => '2026-10-18 11:49:00',
        ])]),
    ]);
    $staleUnmanagedTrash = jamf_verify_unenroll_by_serial(
        $serial,
        '2026-10-18 11:50:09',
        false,
        false,
        $staleUnmanagedTrashTransport,
        $testConfig
    );
    block3_assert($staleUnmanagedTrash['state'] === DISOWN_JAMF_STATE_PENDING_DEVICE, 'veraltetes Trash-isManaged=false allein verifiziert nicht');

    $incidentTerminalTransport = block3_transport([
        block3_device_list([]),
        block3_device_list([block3_device($serial, true, [
            'modified' => '2026-10-17 16:48:02',
            'lastCheckin' => '2026-10-17 14:33:59',
        ])]),
    ]);
    $incidentVerified = jamf_verify_unenroll_by_serial(
        $serial,
        '2026-10-17 16:47:00',
        true,
        false,
        $incidentTerminalTransport,
        $testConfig
    );
    block3_assert($incidentVerified['state'] === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED, 'terminaler Command-Status plus Übergang verifiziert asynchronen Abschluss');

    $pollRequests = [];
    $pollingTransport = block3_transport([
        block3_device_list([block3_device($serial, true)]),
        block3_device_list([]),
        ['http_status' => 200, 'body' => '{"message":"DeviceUnenrolled"}'],
        block3_device_list([block3_device($serial, true)]),
        block3_device_list([]),
        block3_device_list([]),
        block3_device_list([block3_device($serial, false, [
            'modified' => date('Y-m-d H:i:s', time() + 1),
            'lastCheckin' => date('Y-m-d H:i:s', time() + 1),
        ])]),
    ], $pollRequests);
    $polledVerified = jamf_unenroll_by_serial($serial, false, $pollingTransport, $testConfig, 3, 0);
    block3_assert($polledVerified['state'] === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED, 'begrenzte Nachprüfung erkennt schnellen asynchronen Abschluss ohne weiteren Klick');
    block3_assert(($polledVerified['post_command_poll_attempts'] ?? 0) === 2, 'Nachprüfung stoppt unmittelbar nach der Verifikation');
    block3_assert(count(array_filter($pollRequests, static fn (array $request): bool => $request['method'] === 'POST')) === 1, 'Nachprüfung sendet keinen zweiten Unenroll-Befehl');

    $incidentManualTransport = block3_transport([
        block3_device_list([]),
        block3_device_list([block3_device($serial, true)]),
    ]);
    $incidentManual = jamf_verify_unenroll_by_serial(
        $serial,
        '2026-10-17 16:47:00',
        false,
        true,
        $incidentManualTransport,
        $testConfig
    );
    block3_assert($incidentManual['state'] === DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED, 'unklarer Incident endet nach Retry-Limit manuell');

    $lookupFailureTransport = block3_transport([
        ['http_status' => 0, 'body' => '', 'transport_error' => 'timeout'],
        block3_device_list([]),
    ]);
    $lookupFailure = jamf_unenroll_by_serial($serial, false, $lookupFailureTransport, $testConfig);
    block3_assert($lookupFailure['state'] === DISOWN_JAMF_STATE_FAILED, 'fehlgeschlagener Active-Lookup verhindert Schreibaktion');
    block3_assert($lookupFailure['response_class'] === 'transport_error', 'Lookup-Timeout bleibt sicher klassifiziert');

    if (disown_is_dev_mode()) {
        $devPending = jamf_unenroll_by_serial('FAKE0DIANA1');
        block3_assert($devPending['state'] === DISOWN_JAMF_STATE_PENDING_DEVICE, 'Diana bildet HTTP 200 und Offline-Pending in DEV ab');
        block3_assert(!empty($devPending['dev_fixture']), 'DEV-Fixture wird eindeutig gekennzeichnet');
        $devVerified = jamf_verify_unenroll_by_serial('FAKE0DIANA1', date('Y-m-d H:i:s'));
        block3_assert($devVerified['state'] === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED, 'Diana bildet den späteren asynchronen Abschluss ab');
        $devManual = jamf_verify_unenroll_by_serial('FAKE0SPIDEY', date('Y-m-d H:i:s'), false, true);
        block3_assert($devManual['state'] === DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED, 'unklare DEV-Fixture bleibt beim read-only Retry-Limit manuell');

        $devUnexpected = jamf_unenroll_by_serial('FAKE0SCULLY');
        block3_assert($devUnexpected['state'] === DISOWN_JAMF_STATE_FAILED, 'Scully bildet unerwartetes HTTP 200 sicher als Fehler ab');
        $devImmediate = jamf_unenroll_by_serial('FAKE0MULDER');
        block3_assert($devImmediate['state'] === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED, 'Mulder bildet unmittelbare Verifikation ab');
        $devTimeout = jamf_unenroll_by_serial('FAKE0KNOPE1');
        block3_assert($devTimeout['response_class'] === 'dev_fixture_transport_timeout', 'Knope bildet Transport-Timeout ab');

        $asmDevFixture = asm_release_execute('FAKE0MULDER');
        block3_assert(!empty($asmDevFixture['success']) && !empty($asmDevFixture['dry_run']), 'ASM-DEV-Fixture bleibt ein erfolgreicher Dry-Run');
        block3_assert(!empty($asmDevFixture['details']['dev_fixture']), 'ASM-DEV-Fixture ist für lokalen Workflowabschluss markiert');
    }

    echo 'Block-3-Tests erfolgreich: ' . $block3Assertions . ' Assertions.' . PHP_EOL;
    echo 'Alle Jamf-Antworten waren injizierte Fixtures; kein externer Schreibzugriff wurde ausgeführt.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
