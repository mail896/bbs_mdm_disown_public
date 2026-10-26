<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// Exercise the actual request page with inert stand-ins, never real dependencies.
$assertions = 0;
$fixture = [];
$calls = [];

function webclip_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function disown_send_security_headers(): void
{
}

function disown_load_config(string $name): array
{
    global $fixture;
    if ($name !== 'app') {
        throw new RuntimeException('Unexpected configuration request');
    }
    return $fixture['config'];
}

function disown_serial_token_valid(string $serial, string $token, string $secret): bool
{
    global $fixture;
    return $fixture['token_valid'];
}

function jamf_lookup_by_serial(string $serial): ?array
{
    global $fixture, $calls;
    $calls['jamf_lookup']++;
    if ($serial !== 'TESTSERIAL123') {
        throw new RuntimeException('Unexpected fixture serial');
    }
    return $fixture['jamf'];
}

function jamf_device_is_school_loan(array $device): bool
{
    global $fixture;
    return $fixture['school_loan'];
}

function jamf_school_loan_reasons(array $device): array
{
    return [];
}

function jamf_device_has_apple_id_risk(array $device): bool
{
    return false;
}

function disown_render_site_footer(string $section, array $options = []): void
{
    global $calls;
    $calls['footer']++;
    echo '<footer>TEST FOOTER MUST NOT APPEAR</footer>';
}

function disown_send_new_request_push_mail($mysqli, int $requestId): void
{
    global $calls, $fixture;
    $calls['push']++;
    if (!$fixture['allow_insert'] || $requestId !== 123) {
        throw new RuntimeException('Unexpected fake push call');
    }
}

final class WebclipFakeDatabase
{
    public int $insert_id = 123;

    public function prepare(string $sql): WebclipFakeStatement
    {
        global $calls, $fixture;
        $calls['prepare']++;
        $normalized = preg_replace('/\s+/', ' ', trim($sql));
        if ($normalized === 'SELECT id, created_at, status FROM requests WHERE UPPER(serial) = UPPER(?) ORDER BY id DESC LIMIT 1') {
            return new WebclipFakeStatement('select');
        }
        if ($fixture['allow_insert'] && $normalized === 'INSERT IGNORE INTO requests (username, email, private_email, full_name, class_name, jamf_user_id, jamf_modified, serial, device_name, requested_release_date, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)') {
            return new WebclipFakeStatement('insert');
        }
        throw new RuntimeException('Unexpected SQL; only exact known statements have inert fake handlers');
    }
}

final class WebclipFakeStatement
{
    public int $affected_rows = 1;
    private array $parameters = [];

    public function __construct(private string $kind)
    {
    }

    public function bind_param(string $types, &...$parameters): void
    {
        if ($this->kind === 'select' && ($types !== 's' || $parameters !== ['TESTSERIAL123'])) {
            throw new RuntimeException('Unexpected existing-request query parameters');
        }
        if ($this->kind === 'insert' && ($types !== 'sssssissssss' || count($parameters) !== 12)) {
            throw new RuntimeException('Unexpected fake INSERT parameters');
        }
        $this->parameters = $parameters;
    }

    public function execute(): void
    {
        global $calls;
        if ($this->kind === 'insert') {
            $calls['insert']++;
            $calls['insert_parameters'] = $this->parameters;
        }
    }

    public function get_result(): self
    {
        return $this;
    }

    public function fetch_assoc(): ?array
    {
        global $fixture;
        // Default fixture prevents entry into INSERT; success uses inert fakes too.
        return $fixture['existing_request'] ? ['id' => 123, 'created_at' => '2026-01-01 12:00:00', 'status' => 'offen'] : null;
    }

    public function close(): void
    {
    }
}

function webclip_render(string $source, string $method = 'GET', array $post = [], array $overrides = [], bool $withSerial = true): array
{
    global $fixture, $calls;
    $fixture = array_replace([
        'config' => ['SERIAL_TOKEN_SECRET' => '', 'REQUIRE_SERIAL_TOKEN' => false],
        'token_valid' => true,
        'school_loan' => false,
        'existing_request' => true,
        'allow_insert' => false,
        'jamf' => [
            'username' => 'test.student', 'email' => 'school@example.invalid',
            'full_name' => 'Test Student', 'serial' => 'TESTSERIAL123',
            'device_name' => 'Test iPad', 'jamf_user_id' => 1, 'jamf_modified' => null,
        ],
    ], $overrides);
    $calls = ['jamf_lookup' => 0, 'prepare' => 0, 'insert' => 0, 'insert_parameters' => [], 'push' => 0, 'footer' => 0];
    $saved = [$_GET, $_POST, $_SERVER];
    $_GET = $withSerial ? ['serial' => 'TESTSERIAL123'] : [];
    $_POST = $post;
    $_SERVER = ['REQUEST_METHOD' => $method, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'isolated-webclip-test'];
    $mysqli = new WebclipFakeDatabase();
    ob_start();
    try {
        eval('?>' . $source);
        $html = (string) ob_get_contents();
    } finally {
        ob_end_clean();
        [$_GET, $_POST, $_SERVER] = $saved;
    }
    return [$html, $calls];
}

function webclip_dom(string $html): DOMXPath
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    return new DOMXPath($dom);
}

try {
    $path = $argv[1] ?? dirname(__DIR__) . '/index.php';
    $source = file_get_contents($path);
    webclip_assert(is_string($source), 'WebClip source can be read');
    $bootstrap = "<?php\nrequire_once __DIR__ . '/security.php';\nrequire_once __DIR__ . '/site.php';\nrequire 'db.php';\nrequire 'jamf.php';\nrequire_once __DIR__ . '/notify.php';\n";
    webclip_assert(str_starts_with($source, $bootstrap), 'Only the exact known dependency bootstrap is removed');
    $source = "<?php\n" . substr($source, strlen($bootstrap));
    $remainingIncludes = array_filter(token_get_all($source), static fn($token): bool => is_array($token) && in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true));
    webclip_assert($remainingIncludes === [], 'No real include/require remains');
    [$html, $counts] = webclip_render($source);
    $dom = webclip_dom($html);
    $email = $dom->query('//input[@name="private_email"]')->item(0);
    webclip_assert($email instanceof DOMElement, 'Private email field is rendered');
    webclip_assert($email->getAttribute('type') === 'email', 'Private email uses browser email validation');
    webclip_assert($email->hasAttribute('required'), 'Private email is required in the browser');
    $label = $dom->query('//label[@for="privateEmail"]')->item(0);
    webclip_assert($label instanceof DOMElement && trim($label->textContent) === 'Private E-Mail-Adresse (Pflichtfeld)', 'Private email is visibly labelled mandatory');
    webclip_assert(str_contains($dom->query('//div[contains(@class,"warning-box")]')->item(0)->textContent, 'Befolgen Sie unbedingt die Anweisungen aus der E-Mail, die nach der Freigabe folgt.'), 'Warning contains the completion email instruction');
    webclip_assert($counts['footer'] === 0 && $dom->query('//footer')->length === 0, 'Only the WebClip page omits the site footer');
    webclip_assert($counts['prepare'] === 0 && $counts['push'] === 0, 'GET has no request database or mail activity');
    webclip_assert(!str_contains($html, 'Private E-Mail, optional') && !str_contains($html, 'Sie müssen nichts weiter eingeben.'), 'No obsolete optional or no-input-needed wording remains');
    webclip_assert($dom->query('//form[@novalidate]')->length === 0, 'Browser validation is not disabled');

    $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    $basePost = ['class_name' => 'TEST', 'requested_release_date' => $today, 'understanding_confirmed' => '1'];
    $invalidCases = [
        'missing' => [], 'empty' => ['private_email' => ''],
        'whitespace' => ['private_email' => " \t\n "],
        'invalid' => ['private_email' => 'not-an-email'],
    ];
    foreach ($invalidCases as $name => $changes) {
        [$invalidHtml, $counts] = webclip_render($source, 'POST', array_replace($basePost, $changes));
        webclip_assert(str_contains($invalidHtml, 'Bitte geben Sie eine gültige private E-Mail-Adresse ein.'), "{$name}: server rejects missing/invalid private email");
        webclip_assert($counts['prepare'] === 0, "{$name}: server stops before any request SQL");
        webclip_assert($counts['push'] === 0, "{$name}: no push mail");
    }

    [$validHtml, $counts] = webclip_render($source, 'POST', $basePost + ['private_email' => '  private@example.invalid  ']);
    webclip_assert($counts['prepare'] === 1 && str_contains($validHtml, 'Für dieses Gerät wurde bereits ein Antrag erfasst.'), 'Valid private email reaches only the safe existing-request SELECT');
    webclip_assert($counts['push'] === 0, 'Existing-request fixture prevents INSERT and push');
    webclip_assert(webclip_dom($validHtml)->query('//input[@name="private_email"]')->item(0)->getAttribute('value') === 'private@example.invalid', 'Private email is trimmed');

    [$successHtml, $counts] = webclip_render($source, 'POST', $basePost + ['private_email' => '  private@example.invalid  '], ['existing_request' => false, 'allow_insert' => true]);
    webclip_assert(str_contains($successHtml, 'Ihr Antrag wurde gespeichert.'), 'Valid request reaches the success response through fake handlers');
    webclip_assert($counts['prepare'] === 2 && $counts['insert'] === 1, 'Valid request uses one fake SELECT and one fake INSERT');
    webclip_assert($counts['insert_parameters'][2] === 'private@example.invalid', 'Fake INSERT receives the trimmed private email');
    webclip_assert($counts['push'] === 1, 'Success invokes exactly one inert push stub');
    webclip_assert($counts['footer'] === 0 && webclip_dom($successHtml)->query('//footer')->length === 0, 'Success page also omits the WebClip footer');

    $validPost = $basePost + ['private_email' => 'private@example.invalid'];
    $guardCases = [
        ['invalid token', $validPost, ['config' => ['SERIAL_TOKEN_SECRET' => 'test-secret', 'REQUIRE_SERIAL_TOKEN' => true], 'token_valid' => false], 'Dieser WebClip ist nicht mehr gültig.'],
        ['missing device', $validPost, ['jamf' => null], 'Gerät wurde in Jamf nicht gefunden.'],
        ['school loan device', $validPost, ['school_loan' => true], 'Dieses iPad ist als schulisches Leih-/Koffergerät markiert'],
        ['missing owner', $validPost, ['jamf' => ['username' => '', 'email' => '', 'full_name' => '', 'serial' => 'TESTSERIAL123', 'device_name' => 'Test iPad']], 'Dieses iPad ist in Jamf keinem Benutzer zugeordnet.'],
        ['understanding not confirmed', array_diff_key($validPost, ['understanding_confirmed' => true]), [], 'Bitte bestätigen Sie zuerst'],
        ['class missing', array_replace($validPost, ['class_name' => '']), [], 'Bitte geben Sie Ihre Klasse'],
        ['class too long', array_replace($validPost, ['class_name' => 'TOOLONG']), [], 'Bitte geben Sie Ihre Klasse'],
        ['invalid date', array_replace($validPost, ['requested_release_date' => 'not-a-date']), [], 'Bitte wählen Sie ein Freigabedatum'],
        ['past date', array_replace($validPost, ['requested_release_date' => '2000-01-01']), [], 'Bitte wählen Sie ein Freigabedatum'],
    ];
    foreach ($guardCases as [$name, $post, $overrides, $expected]) {
        [$guardHtml, $counts] = webclip_render($source, 'POST', $post, $overrides);
        webclip_assert(str_contains($guardHtml, $expected), "{$name}: existing guard remains active");
        webclip_assert($counts['prepare'] === 0 && $counts['push'] === 0, "{$name}: no SQL or push");
        if ($name === 'invalid token') {
            webclip_assert($counts['jamf_lookup'] === 0, 'Invalid token prevents even the fake Jamf lookup');
        }
    }
    [$missingSerialHtml, $counts] = webclip_render($source, 'GET', [], [], false);
    webclip_assert(str_contains($missingSerialHtml, 'Bitte öffnen Sie den Webclip auf Ihrem iPad.'), 'Missing serial guidance remains');
    webclip_assert($counts['jamf_lookup'] === 0 && $counts['prepare'] === 0 && $counts['push'] === 0 && $counts['footer'] === 0, 'Missing serial page performs no operations or footer rendering');

    echo "OK: {$assertions} WebClip assertions; isolated stubs only, no real database, Jamf, Apple or mail operations.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
