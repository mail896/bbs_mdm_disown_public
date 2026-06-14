<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);
$errors = 0;
$warnings = 0;

function line(string $status, string $message): void
{
    echo sprintf("[%s] %s\n", $status, $message);
}

function ok(string $message): void
{
    line('OK', $message);
}

function warn(string $message): void
{
    global $warnings;
    $warnings++;
    line('WARN', $message);
}

function fail(string $message): void
{
    global $errors;
    $errors++;
    line('FAIL', $message);
}

function check_file_readable(string $path, bool $required = true): void
{
    if (is_readable($path)) {
        ok("lesbar: {$path}");
        return;
    }

    if ($required) {
        fail("nicht lesbar: {$path}");
    } else {
        warn("nicht lesbar oder nicht vorhanden: {$path}");
    }
}

echo "BBS MDM Disown - Installationscheck\n";
echo "Root: {$root}\n\n";

if (PHP_VERSION_ID >= 80100) {
    ok('PHP-Version ' . PHP_VERSION);
} else {
    fail('PHP 8.1 oder neuer empfohlen, gefunden: ' . PHP_VERSION);
}

foreach (['mysqli', 'curl', 'json', 'openssl', 'mbstring'] as $extension) {
    if (extension_loaded($extension)) {
        ok("PHP-Extension {$extension}");
    } else {
        fail("PHP-Extension fehlt: {$extension}");
    }
}

check_file_readable($root . '/vendor/autoload.php', false);
check_file_readable($root . '/templates/mail_release.txt');
check_file_readable($root . '/logo.png', false);
check_file_readable($root . '/logo_jamf.png', false);
check_file_readable($root . '/logo_asm.png', false);

$runtimeConfigs = [
    '/etc/disown/app.conf' => false,
    '/etc/disown/db.conf' => true,
    '/etc/disown/mail.conf' => false,
    '/etc/disown/jamf.conf' => false,
    '/etc/disown/notify.conf' => false,
    '/etc/disown/oidc.conf' => false,
    '/etc/disown/asm.conf' => false,
];

echo "\nRuntime-Konfiguration\n";
foreach ($runtimeConfigs as $path => $required) {
    check_file_readable($path, $required);
}

$appConfigPath = '/etc/disown/app.conf';
if (is_readable($appConfigPath)) {
    $appConfig = parse_ini_file($appConfigPath);
    if (!is_array($appConfig)) {
        warn('app.conf kann nicht gelesen werden.');
    } else {
        $requireSerialToken = filter_var($appConfig['REQUIRE_SERIAL_TOKEN'] ?? false, FILTER_VALIDATE_BOOL);
        $serialSecret = trim((string) ($appConfig['SERIAL_TOKEN_SECRET'] ?? ''));
        if ($requireSerialToken && $serialSecret === '') {
            fail('REQUIRE_SERIAL_TOKEN ist aktiv, aber SERIAL_TOKEN_SECRET fehlt.');
        } elseif ($serialSecret === '' || $serialSecret === 'change-me-long-random-secret') {
            warn('SERIAL_TOKEN_SECRET fehlt oder ist noch der Platzhalter. Alte WebClips funktionieren weiter, solange REQUIRE_SERIAL_TOKEN=0 ist.');
        } else {
            ok('WebClip-Token-Secret konfiguriert.');
        }
    }
}

echo "\nDatenbank\n";
$dbConfigPath = '/etc/disown/db.conf';
if (is_readable($dbConfigPath)) {
    $dbConfig = parse_ini_file($dbConfigPath);
    if (!is_array($dbConfig)) {
        fail('db.conf kann nicht gelesen werden.');
    } else {
        foreach (['DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'] as $key) {
            if (!array_key_exists($key, $dbConfig) || (string) $dbConfig[$key] === '') {
                fail("db.conf unvollstaendig: {$key}");
            }
        }

        if ($errors === 0 || !empty($dbConfig['DB_NAME'])) {
            mysqli_report(MYSQLI_REPORT_OFF);
            $mysqli = @new mysqli(
                (string) ($dbConfig['DB_HOST'] ?? 'localhost'),
                (string) ($dbConfig['DB_USER'] ?? ''),
                (string) ($dbConfig['DB_PASSWORD'] ?? ''),
                (string) ($dbConfig['DB_NAME'] ?? '')
            );

            if ($mysqli->connect_error) {
                fail('DB-Verbindung fehlgeschlagen: ' . $mysqli->connect_error);
            } else {
                ok('DB-Verbindung erfolgreich.');
                foreach (['requests', 'request_audit_log', 'ade_enrollments'] as $table) {
                    $safeTable = $mysqli->real_escape_string($table);
                    $result = $mysqli->query("SHOW TABLES LIKE '{$safeTable}'");
                    if ($result && $result->num_rows > 0) {
                        ok("Tabelle vorhanden: {$table}");
                    } else {
                        warn("Tabelle fehlt oder noch nicht migriert: {$table}");
                    }
                }
                $mysqli->close();
            }
        }
    }
}

echo "\nSchreib-/Betriebspfade\n";
foreach (['/var/lib/disown', '/var/log/disown'] as $path) {
    if (is_dir($path) && is_writable($path)) {
        ok("beschreibbar: {$path}");
    } elseif (is_dir($path)) {
        warn("vorhanden, aber fuer aktuellen Benutzer nicht beschreibbar: {$path}");
    } else {
        warn("nicht vorhanden: {$path}");
    }
}

echo "\nErgebnis\n";
if ($errors > 0) {
    line('FAIL', "{$errors} Fehler, {$warnings} Warnungen.");
    exit(1);
}

if ($warnings > 0) {
    line('WARN', "Keine harten Fehler, aber {$warnings} Warnungen.");
    exit(0);
}

ok('Alles Wesentliche sieht gut aus.');
