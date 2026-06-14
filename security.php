<?php

declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit;
}

function disown_send_security_headers(): void
{
    if (headers_sent() || PHP_SAPI === 'cli') {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header(
        "Content-Security-Policy: default-src 'self'; " .
        "base-uri 'self'; " .
        "object-src 'none'; " .
        "frame-ancestors 'none'; " .
        "form-action 'self'; " .
        "img-src 'self' data:; " .
        "style-src 'self' 'unsafe-inline'; " .
        "script-src 'self' 'unsafe-inline'; " .
        "connect-src 'self'"
    );
}

function disown_config_file(string $name): string
{
    $envName = 'DISOWN_' . strtoupper($name) . '_CONFIG';
    $envPath = getenv($envName);
    if ($envPath) {
        return $envPath;
    }

    return '/etc/disown/' . $name . '.conf';
}

function disown_load_config(string $name): array
{
    $path = disown_config_file($name);
    if (!is_readable($path)) {
        return [];
    }

    $config = parse_ini_file($path);
    return is_array($config) ? $config : [];
}

function disown_secure_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function disown_serial_token(string $serial, string $secret): string
{
    return rtrim(strtr(base64_encode(hash_hmac('sha256', strtoupper(trim($serial)), $secret, true)), '+/', '-_'), '=');
}

function disown_serial_token_valid(string $serial, string $token, string $secret): bool
{
    if ($serial === '' || $token === '' || $secret === '') {
        return false;
    }

    return hash_equals(disown_serial_token($serial, $secret), $token);
}
