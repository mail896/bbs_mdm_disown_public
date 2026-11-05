<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../jamf.php';

$checks = 0;
function school_assert(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = ['JAMF_URL' => 'https://jamf.invalid', 'JAMF_NETWORK_ID' => 'fixture', 'JAMF_API_KEY' => 'fixture'];
$serial = 'TESTSCHOOL074';
$base = ['serialNumber' => $serial, 'UDID' => 'fixture-udid', 'isManaged' => true, 'owner' => []];
$schoolDevices = [
    ['assetTag' => 'BBS #074'], ['assetTag' => 'BBS #024'], ['assetTag' => 'BBS #126'],
    ['assetTag' => 'BBS #000'], ['assetTag' => 'BBS #999'], ['assetTag' => '  bbs #074  '],
    ['asset_tag' => '', 'assetTag' => 'BBS #074'],
    ['asset_tag' => 'BBS #074', 'assetTag' => ''],
    ['asset_tag' => 'PRIVATE', 'assetTag' => 'BBS #074'],
    ['groups' => ['iPad-Koffer 1']], ['groups' => [['name' => 'iPad-Koffer 1']]],
    ['groups' => [], 'deviceGroups' => [['displayName' => 'iPad-Koffer 2']]],
];

try {
    foreach ($schoolDevices as $extra) {
        $device = array_replace($base, $extra);
        school_assert(jamf_device_is_school_loan($device), 'Raw school device must be protected: ' . json_encode($extra));
        school_assert(jamf_school_loan_reasons($device) !== [], 'Protected device has a visible reason');
        // The WebClip consumes this normalized lookup result rather than raw API data.
        school_assert(jamf_device_is_school_loan(jamf_lookup_from_device($device)), 'Lookup normalization preserves school protection');
        $methods = [];
        $transport = static function (array $request) use ($device, &$methods): array {
            $methods[] = $request['method'];
            if ($request['method'] !== 'GET') {
                throw new RuntimeException('School guard allowed an unexpected command');
            }
            return ['http_status' => 200, 'transport_error' => '', 'body' => json_encode([
                'devices' => str_contains($request['url'], 'inTrash=true') ? [] : [$device],
            ], JSON_THROW_ON_ERROR)];
        };
        $result = jamf_unenroll_by_serial($serial, false, $transport, $config);
        school_assert(($result['response_class'] ?? '') === 'school_loan_blocked', 'Normal and bulk helper reject school device');
        school_assert(empty($result['accepted']) && empty($result['success']), 'Blocked device is never reported as accepted');
        school_assert($methods === ['GET', 'GET'], 'Only read-only observations; no Unenroll POST');
    }
    foreach ([[], ['assetTag' => 'PRIVATE #074'], ['groups' => ['Students', 'Recurring iPads']]] as $extra) {
        school_assert(!jamf_device_is_school_loan(array_replace($base, $extra)), 'Unmarked privately owned fixture remains eligible');
    }

    // The explicit admin-only special path remains available, with an injected transport.
    $school = array_replace($base, ['assetTag' => 'BBS #074']);
    $calls = [];
    $transport = static function (array $request) use ($school, &$calls): array {
        $calls[] = $request['method'];
        if ($request['method'] === 'POST') {
            return ['http_status' => 200, 'transport_error' => '', 'body' => '{"message":"DeviceUnenrolled"}'];
        }
        $device = array_replace($school, ['isManaged' => count($calls) < 4]);
        return ['http_status' => 200, 'transport_error' => '', 'body' => json_encode([
            'devices' => str_contains($request['url'], 'inTrash=true') ? [] : [$device],
        ], JSON_THROW_ON_ERROR)];
    };
    $special = jamf_unenroll_by_serial($serial, true, $transport, $config, 1, 0);
    school_assert(!empty($special['accepted']), 'Explicit special release still works in the fixture');
    school_assert(count(array_filter($calls, static fn (string $method): bool => $method === 'POST')) === 1, 'Special fixture issues one simulated command');
    echo "OK: {$checks} school-device guard assertions; fixture transports only, no real database, Jamf, Apple or mail operations.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
