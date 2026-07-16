<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);
$errors = 0;
$warnings = 0;

function smoke_line(string $status, string $message): void
{
    echo sprintf("[%s] %s\n", $status, $message);
}

function smoke_ok(string $message): void
{
    smoke_line('OK', $message);
}

function smoke_info(string $message): void
{
    smoke_line('INFO', $message);
}

function smoke_warn(string $message): void
{
    global $warnings;
    $warnings++;
    smoke_line('WARN', $message);
}

function smoke_fail(string $message): void
{
    global $errors;
    $errors++;
    smoke_line('FAIL', $message);
}

function smoke_readable(string $path, bool $required = true): void
{
    if (is_readable($path)) {
        smoke_ok("lesbar: {$path}");
        return;
    }

    if ($required) {
        smoke_fail("nicht lesbar: {$path}");
    } else {
        smoke_warn("nicht lesbar oder nicht vorhanden: {$path}");
    }
}

function smoke_parse_ini(string $path, bool $required = true): array
{
    if (!is_readable($path)) {
        if ($required) {
            smoke_fail("Konfiguration nicht lesbar: {$path}");
        } else {
            smoke_warn("Konfiguration nicht lesbar oder nicht vorhanden: {$path}");
        }
        return [];
    }

    $config = parse_ini_file($path);
    if (!is_array($config)) {
        smoke_fail("Konfiguration kann nicht geparst werden: {$path}");
        return [];
    }

    smoke_ok("Konfiguration geladen: {$path}");
    return $config;
}

function smoke_required_keys(array $config, array $keys, string $label): void
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $config) || trim((string) $config[$key]) === '') {
            smoke_fail("{$label}: {$key} fehlt");
        }
    }
}

function smoke_http_status(string $url, int|array $expected = 200): void
{
    if (!function_exists('curl_init')) {
        smoke_warn("curl fehlt, HTTP-Check uebersprungen: {$url}");
        return;
    }

    $curl = curl_init($url);
    if ($curl === false) {
        smoke_warn("curl konnte nicht gestartet werden: {$url}");
        return;
    }

    curl_setopt_array($curl, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    $expectedStatuses = is_array($expected) ? array_map('intval', $expected) : [(int) $expected];
    if (in_array($status, $expectedStatuses, true)) {
        smoke_ok("HTTP {$status}: {$url}");
        return;
    }

    $detail = $error !== '' ? " ({$error})" : '';
    smoke_warn("HTTP {$status}, erwartet " . implode('/', $expectedStatuses) . ": {$url}{$detail}");
}

function smoke_table_exists(mysqli $mysqli, string $table): bool
{
    $safeTable = $mysqli->real_escape_string($table);
    $result = $mysqli->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

$baseUrl = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $baseUrl = rtrim(substr($arg, 6), '/');
    }
}

echo "DISOWN Smoke Check\n";
echo "Root: {$root}\n";
echo 'Zeit: ' . date('Y-m-d H:i:s') . "\n\n";

echo "Dateien\n";
foreach ([
    'admin.php',
    'ade.php',
    'audit_log.php',
    'kuk.php',
    'settings.php',
    'auth.php',
    'jamf.php',
    'asm_release.php',
    'templates/mail_release.txt',
    'assets/search.js',
    'assets/admin.css',
    'assets/settings.css',
    'assets/admin.js',
    'assets/ade.css',
    'assets/ade.js',
    'assets/audit_log.css',
    'assets/audit_log.js',
    'assets/kuk.css',
    'assets/kuk.js',
    'tools/disown-settings-helper',
    'tools/install-settings-root-helper.sh',
] as $relativePath) {
    smoke_readable($root . '/' . $relativePath);
}

echo "\nPHP\n";
if (PHP_VERSION_ID >= 80100) {
    smoke_ok('PHP-Version ' . PHP_VERSION);
} else {
    smoke_fail('PHP 8.1 oder neuer empfohlen, gefunden: ' . PHP_VERSION);
}
foreach (['mysqli', 'curl', 'json', 'openssl'] as $extension) {
    extension_loaded($extension)
        ? smoke_ok("Extension geladen: {$extension}")
        : smoke_fail("Extension fehlt: {$extension}");
}

echo "\nSyntax\n";
foreach (['admin.php', 'ade.php', 'audit_log.php', 'kuk.php', 'settings.php', 'auth.php', 'jamf.php', 'asm_release.php'] as $relativePath) {
    $command = 'php -l ' . escapeshellarg($root . '/' . $relativePath) . ' 2>&1';
    exec($command, $output, $exitCode);
    $exitCode === 0 ? smoke_ok("php -l: {$relativePath}") : smoke_fail("php -l fehlgeschlagen: {$relativePath}");
}
foreach (['tools/disown-settings-helper', 'tools/install-settings-root-helper.sh'] as $relativePath) {
    $command = 'bash -n ' . escapeshellarg($root . '/' . $relativePath) . ' 2>&1';
    exec($command, $output, $exitCode);
    $exitCode === 0 ? smoke_ok("bash -n: {$relativePath}") : smoke_fail("bash -n fehlgeschlagen: {$relativePath}");
}

echo "\nRuntime-Konfiguration\n";
$dbConfig = smoke_parse_ini('/etc/disown/db.conf', true);
smoke_required_keys($dbConfig, ['DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'], 'db.conf');
$mailConfig = smoke_parse_ini('/etc/disown/mail.conf', false);
if ($mailConfig) {
    smoke_required_keys($mailConfig, ['MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM'], 'mail.conf');
}
smoke_parse_ini('/etc/disown/jamf.conf', false);
$brokerConfigPath = '/etc/disown/asm-release-broker.conf';
$brokerConfig = [];
if (is_readable($brokerConfigPath)) {
    $brokerConfig = smoke_parse_ini($brokerConfigPath, false);
} else {
    smoke_info("Konfiguration fuer aktuellen CLI-User nicht lesbar oder nicht vorhanden: {$brokerConfigPath}");
}
if ($brokerConfig) {
    foreach (['ASM_JAMF_MDM_SERVER_ID', 'ASM_BROKER_MDM_SERVER_ID', 'ASM_BROKER_DEP_BASE_URL'] as $key) {
        if (trim((string) ($brokerConfig[$key] ?? '')) === '') {
            smoke_warn("asm-release-broker.conf: {$key} fehlt");
        }
    }
    $apiKeyFile = trim((string) ($brokerConfig['ASM_BROKER_DEP_API_KEY_FILE'] ?? ''));
    if ($apiKeyFile !== '') {
        smoke_readable($apiKeyFile, false);
    }
}

echo "\nDatenbank\n";
if ($dbConfig) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = @new mysqli(
        (string) ($dbConfig['DB_HOST'] ?? 'localhost'),
        (string) ($dbConfig['DB_USER'] ?? ''),
        (string) ($dbConfig['DB_PASSWORD'] ?? ''),
        (string) ($dbConfig['DB_NAME'] ?? '')
    );
    if ($mysqli->connect_error) {
        smoke_fail('DB-Verbindung fehlgeschlagen: ' . $mysqli->connect_error);
    } else {
        $mysqli->set_charset('utf8mb4');
        smoke_ok('DB-Verbindung erfolgreich.');
        foreach ([
            'requests',
            'request_audit_log',
            'device_cases',
            'ade_enrollments',
            'kuk_devices',
            'kuk_owner_history',
            'kuk_device_workflow',
            'app_settings',
        ] as $table) {
            smoke_table_exists($mysqli, $table)
                ? smoke_ok("Tabelle vorhanden: {$table}")
                : smoke_warn("Tabelle fehlt: {$table}");
        }

        foreach ([
            'requests' => 'SELECT COUNT(*) AS count FROM requests',
            'audit_log' => 'SELECT COUNT(*) AS count FROM request_audit_log',
            'open_requests' => "SELECT COUNT(*) AS count FROM requests WHERE status <> 'erledigt'",
        ] as $label => $sql) {
            $result = $mysqli->query($sql);
            if ($result instanceof mysqli_result) {
                $row = $result->fetch_assoc();
                smoke_ok("DB {$label}: " . (string) ($row['count'] ?? '0'));
            } else {
                smoke_warn("DB Kennzahl nicht lesbar: {$label}");
            }
        }
        $mysqli->close();
    }
}

echo "\nRelease Broker\n";
$brokerUrl = getenv('DISOWN_NANODEP_HEALTH_URL') ?: 'http://127.0.0.1:9001/version';
smoke_http_status($brokerUrl, 200);

if ($baseUrl !== '') {
    echo "\nHTTP\n";
    foreach ([
        '/',
        '/admin.php',
        '/ade.php',
        '/audit_log.php',
        '/kuk/',
        '/settings.php',
        '/assets/search.js',
        '/assets/admin.css',
        '/assets/settings.css',
        '/assets/admin.js',
        '/assets/ade.css',
        '/assets/ade.js',
        '/assets/audit_log.css',
        '/assets/audit_log.js',
        '/assets/kuk.css',
        '/assets/kuk.js',
    ] as $path) {
        smoke_http_status($baseUrl . $path, 200);
    }
    foreach ([
        '/PROJECT_STATE.md',
        '/PROJECT_STATE.yaml',
    ] as $path) {
        smoke_http_status($baseUrl . $path, [403, 404]);
    }
    foreach ([
        '/config/db.example.conf',
        '/vendor/autoload.php',
    ] as $path) {
        smoke_http_status($baseUrl . $path, 403);
    }
}

echo "\nErgebnis\n";
if ($errors > 0) {
    smoke_line('FAIL', "{$errors} Fehler, {$warnings} Warnungen.");
    exit(1);
}

if ($warnings > 0) {
    smoke_line('WARN', "Keine harten Fehler, aber {$warnings} Warnungen.");
    exit(0);
}

smoke_ok('Smoke-Check ohne Auffaelligkeiten abgeschlossen.');
