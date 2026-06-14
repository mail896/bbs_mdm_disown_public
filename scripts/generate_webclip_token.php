<?php

declare(strict_types=1);

require __DIR__ . '/../security.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur per CLI nutzbar.');
}

$serial = strtoupper(trim((string) ($argv[1] ?? '')));
if ($serial === '') {
    fwrite(STDERR, "Nutzung: php scripts/generate_webclip_token.php SERIENNUMMER\n");
    exit(1);
}

$config = disown_load_config('app');
$secret = trim((string) ($config['SERIAL_TOKEN_SECRET'] ?? ''));
if ($secret === '' || $secret === 'change-me-long-random-secret') {
    fwrite(STDERR, "SERIAL_TOKEN_SECRET fehlt oder ist noch der Platzhalter in /etc/disown/app.conf.\n");
    exit(1);
}

echo disown_serial_token($serial, $secret), PHP_EOL;
