<?php

require_once __DIR__ . '/security.php';

disown_send_security_headers();
disown_secure_session_start();

function disown_is_dev_mode(): bool
{
    return basename(__DIR__) === 'disown-dev';
}

function disown_config_path(string $name): string
{
    $envName = 'DISOWN_' . strtoupper($name) . '_CONFIG';
    $envPath = getenv($envName);
    if ($envPath) {
        return $envPath;
    }

    if ($name === 'oidc' && disown_is_dev_mode()) {
        return '/etc/disown/oidc-dev.conf';
    }

    return '/etc/disown/' . $name . '.conf';
}

function disown_load_ini_config(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }

    $config = parse_ini_file($path);
    return is_array($config) ? $config : [];
}

function disown_oidc_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = disown_load_ini_config(disown_config_path('oidc'));
    $config['OIDC_ENABLED'] = filter_var($config['OIDC_ENABLED'] ?? false, FILTER_VALIDATE_BOOL);
    $config['OIDC_ISSUER'] = rtrim((string) ($config['OIDC_ISSUER'] ?? ''), '/');
    $config['OIDC_CLIENT_ID'] = trim((string) ($config['OIDC_CLIENT_ID'] ?? ''));
    $config['OIDC_CLIENT_SECRET'] = trim((string) ($config['OIDC_CLIENT_SECRET'] ?? ''));
    $config['OIDC_SCOPES'] = trim((string) ($config['OIDC_SCOPES'] ?? 'openid email profile'));
    $config['OIDC_ALLOWED_EMAILS'] = disown_split_config_list((string) ($config['OIDC_ALLOWED_EMAILS'] ?? ''));
    $config['OIDC_ALLOWED_ROLES'] = disown_split_config_list((string) ($config['OIDC_ALLOWED_ROLES'] ?? ''));
    $config['OIDC_VIEWER_ROLES'] = disown_split_config_list((string) ($config['OIDC_VIEWER_ROLES'] ?? ''));
    if (!$config['OIDC_VIEWER_ROLES']) {
        $config['OIDC_VIEWER_ROLES'] = ['IPAD_MDM_VIEWERS', 'ROLE_IPAD_MDM_VIEWERS'];
    }

    return $config;
}

function disown_split_config_list(string $value): array
{
    return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\n\r]+/', $value) ?: []))));
}

function disown_oidc_enabled(): bool
{
    $config = disown_oidc_config();
    return !empty($config['OIDC_ENABLED'])
        && $config['OIDC_ISSUER'] !== ''
        && $config['OIDC_CLIENT_ID'] !== ''
        && $config['OIDC_CLIENT_SECRET'] !== '';
}

function disown_current_admin_user(): string
{
    if (!empty($_SESSION['oidc_user']['display'])) {
        return (string) $_SESSION['oidc_user']['display'];
    }

    foreach (['REMOTE_USER', 'REDIRECT_REMOTE_USER', 'PHP_AUTH_USER', 'REDIRECT_PHP_AUTH_USER'] as $key) {
        $value = trim((string) ($_SERVER[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function disown_current_access_level(): string
{
    return (string) ($_SESSION['oidc_user']['access_level'] ?? 'admin');
}

function disown_current_user_roles(): array
{
    $roles = $_SESSION['oidc_user']['roles'] ?? [];
    return is_array($roles) ? $roles : [];
}

function disown_can_write(): bool
{
    if (!disown_oidc_enabled()) {
        return true;
    }

    return disown_current_access_level() === 'admin';
}

function disown_require_write(): void
{
    if (disown_can_write()) {
        return;
    }

    disown_log_auth_event(
        'AUTH_WRITE_DENIED',
        disown_current_admin_user(),
        'method=oidc; roles=' . implode(',', disown_current_user_roles())
    );
    http_response_code(403);
    exit('Nur lesender Zugriff. Diese Aktion ist nicht erlaubt.');
}

function disown_log_audit_action($mysqli, int $requestId, string $action, string $adminUser = '', ?string $details = null): void
{
    $adminUser = trim($adminUser) !== '' ? trim($adminUser) : disown_current_admin_user();
    if ($adminUser === '') {
        $adminUser = 'unknown';
    }

    $action = substr(trim($action), 0, 64);
    $adminUser = substr($adminUser, 0, 64);

    if ($requestId < 0 || $action === '') {
        return;
    }

    try {
        $stmt = $mysqli->prepare(
            "INSERT INTO request_audit_log (request_id, action, admin_user, details)
             VALUES (?, ?, ?, ?)"
        );
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('isss', $requestId, $action, $adminUser, $details);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        return;
    }
}

function disown_log_auth_event(string $action, string $adminUser = '', ?string $details = null): void
{
    global $mysqli;

    try {
        if (!isset($mysqli)) {
            require_once __DIR__ . '/db.php';
        }
        if (isset($mysqli)) {
            disown_log_audit_action($mysqli, 0, $action, $adminUser, $details);
        }
    } catch (Throwable $e) {
        return;
    }
}

function disown_require_admin(): void
{
    if (!disown_oidc_enabled()) {
        return;
    }

    if (!empty($_SESSION['oidc_user']['authorized'])) {
        $_SERVER['REMOTE_USER'] = disown_current_admin_user();
        return;
    }

    if (($_GET['login'] ?? '') !== '1') {
        disown_render_login_page();
    }

    disown_oidc_start_login();
}

function disown_render_login_page(): void
{
    $loginUrl = disown_login_url();
    $basePath = rtrim(disown_admin_base_path(), '/');
    $logoUrl = $basePath . '/logo.png';
    $siteImageUrl = $basePath . '/images/Site-Image.png';
    $faviconUrl = $basePath . '/favicon.svg';
    ?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="<?=htmlspecialchars($faviconUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
<title>iPad-Management · Anmeldung</title>
<style>
:root {
    color-scheme: light;
    font-family: Inter, Arial, sans-serif;
    color: #111827;
}
* {
    box-sizing: border-box;
}
body {
    margin: 0;
    min-height: 100vh;
    background:
        linear-gradient(90deg, rgba(248, 250, 252, 0.96) 0%, rgba(248, 250, 252, 0.84) 42%, rgba(248, 250, 252, 0.48) 100%),
        url("<?=htmlspecialchars($siteImageUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>") center center / cover no-repeat fixed;
}
.login-page {
    align-items: stretch;
    display: grid;
    min-height: 100vh;
    padding: clamp(24px, 5vw, 72px);
}
.login-panel {
    align-self: center;
    max-width: 520px;
}
.site-logo {
    display: block;
    height: auto;
    margin-bottom: clamp(28px, 6vh, 56px);
    max-width: min(300px, 58vw);
}
.login-title {
    font-size: clamp(2.4rem, 6vw, 4.8rem);
    line-height: 1;
    margin: 0;
}
.login-copy {
    color: #475569;
    font-size: clamp(1.05rem, 2vw, 1.35rem);
    line-height: 1.45;
    margin: 18px 0 32px;
}
.login-button {
    align-items: center;
    background: #2563eb;
    border-radius: 999px;
    box-shadow: 0 16px 36px rgba(37, 99, 235, 0.24);
    color: #ffffff;
    display: inline-flex;
    font-size: 1.05rem;
    font-weight: 800;
    justify-content: center;
    min-height: 56px;
    padding: 0 1.45rem;
    text-decoration: none;
}
.login-button:hover {
    background: #1d4ed8;
}
.login-note {
    color: #64748b;
    font-size: 0.9rem;
    margin-top: 18px;
}
@media (max-width: 760px) {
    body {
        background:
            linear-gradient(to bottom, rgba(248, 250, 252, 0.96) 0%, rgba(248, 250, 252, 0.84) 54%, rgba(248, 250, 252, 0.56) 100%),
            url("<?=htmlspecialchars($siteImageUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>") center bottom / auto 78vh no-repeat fixed;
    }
    .login-page {
        padding: 28px 22px;
    }
}
</style>
</head>
<body>
    <main class="login-page">
        <section class="login-panel" aria-label="Anmeldung">
            <img class="site-logo" src="<?=htmlspecialchars($logoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" alt="BBS Einbeck">
            <h1 class="login-title">iPad-Management</h1>
            <p class="login-copy">Melden Sie sich mit Ihrem IServ-Konto an, um Anträge, ADE-Aufnahmen und Audit-Log einzusehen.</p>
            <a class="login-button" href="<?=htmlspecialchars($loginUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">Mit IServ anmelden</a>
            <p class="login-note">Die Berechtigungen werden über IServ-Rollen gesteuert.</p>
        </section>
    </main>
</body>
</html>
<?php
    exit;
}

function disown_login_url(): string
{
    $params = $_GET;
    $params['login'] = '1';
    return strtok((string) ($_SERVER['REQUEST_URI'] ?? 'admin.php'), '?') . '?' . http_build_query($params);
}

function disown_return_url_without_login(): string
{
    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? disown_admin_base_path() . '/admin.php'), '?');
    $params = $_GET;
    unset($params['login']);

    return $path . ($params ? '?' . http_build_query($params) : '');
}

function disown_oidc_start_login(): void
{
    $config = disown_oidc_config();
    $metadata = disown_oidc_metadata($config['OIDC_ISSUER']);
    $authorizationEndpoint = $metadata['authorization_endpoint'] ?? '';
    if ($authorizationEndpoint === '') {
        http_response_code(500);
        exit('OIDC authorization_endpoint fehlt.');
    }

    $state = disown_base64url(random_bytes(32));
    $nonce = disown_base64url(random_bytes(32));
    $verifier = disown_base64url(random_bytes(64));
    $challenge = disown_base64url(hash('sha256', $verifier, true));

    $_SESSION['oidc_state'] = $state;
    $_SESSION['oidc_nonce'] = $nonce;
    $_SESSION['oidc_code_verifier'] = $verifier;
    $_SESSION['oidc_return_to'] = disown_return_url_without_login();

    $query = http_build_query([
        'response_type' => 'code',
        'client_id' => $config['OIDC_CLIENT_ID'],
        'redirect_uri' => disown_oidc_redirect_uri(),
        'scope' => $config['OIDC_SCOPES'],
        'state' => $state,
        'nonce' => $nonce,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]);

    header('Location: ' . $authorizationEndpoint . '?' . $query);
    exit;
}

function disown_oidc_handle_callback(): void
{
    if (!disown_oidc_enabled()) {
        http_response_code(500);
        exit('OIDC ist nicht aktiviert.');
    }

    $state = (string) ($_GET['state'] ?? '');
    $code = (string) ($_GET['code'] ?? '');
    if ($state === '' || $code === '' || !hash_equals((string) ($_SESSION['oidc_state'] ?? ''), $state)) {
        disown_log_auth_event('AUTH_LOGIN_ERROR', 'unknown', 'method=oidc; reason=invalid_state');
        http_response_code(400);
        exit('OIDC-Status ungültig.');
    }

    $config = disown_oidc_config();
    $metadata = disown_oidc_metadata($config['OIDC_ISSUER']);
    $tokenEndpoint = $metadata['token_endpoint'] ?? '';
    $userinfoEndpoint = $metadata['userinfo_endpoint'] ?? '';
    if ($tokenEndpoint === '' || $userinfoEndpoint === '') {
        http_response_code(500);
        exit('OIDC-Endpunkte fehlen.');
    }

    $tokenResponse = disown_http_post_form($tokenEndpoint, [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => disown_oidc_redirect_uri(),
        'client_id' => $config['OIDC_CLIENT_ID'],
        'client_secret' => $config['OIDC_CLIENT_SECRET'],
        'code_verifier' => (string) ($_SESSION['oidc_code_verifier'] ?? ''),
    ]);

    $accessToken = (string) ($tokenResponse['access_token'] ?? '');
    if ($accessToken === '') {
        disown_log_auth_event('AUTH_LOGIN_ERROR', 'unknown', 'method=oidc; reason=missing_access_token');
        http_response_code(401);
        exit('OIDC access_token fehlt.');
    }

    $userinfo = disown_http_get_json($userinfoEndpoint, [
        'Authorization: Bearer ' . $accessToken,
    ]);

    $idToken = (string) ($tokenResponse['id_token'] ?? '');
    if ($idToken === '') {
        disown_log_auth_event('AUTH_LOGIN_ERROR', 'unknown', 'method=oidc; reason=missing_id_token');
        http_response_code(401);
        exit('OIDC id_token fehlt.');
    }

    disown_validate_id_token_signature($idToken, $metadata);
    $idTokenClaims = disown_decode_jwt_payload($idToken);
    disown_validate_id_token_claims($idTokenClaims, $config);

    $claims = array_merge($idTokenClaims, $userinfo);
    $user = disown_normalize_oidc_user($claims);
    $accessLevel = disown_oidc_user_access_level($user, $config);
    if ($accessLevel === null) {
        unset($_SESSION['oidc_user']);
        disown_log_auth_event(
            'AUTH_LOGIN_DENIED',
            (string) ($user['display'] ?? 'unknown'),
            'method=oidc; roles=' . implode(',', $user['roles'] ?? [])
        );
        http_response_code(403);
        exit('Dieser IServ-Benutzer ist für DISOWN nicht berechtigt.');
    }

    session_regenerate_id(true);
    $_SESSION['oidc_user'] = $user + ['authorized' => true, 'access_level' => $accessLevel];
    disown_log_auth_event(
        'AUTH_LOGIN_SUCCESS',
        (string) $user['display'],
        'method=oidc; access=' . $accessLevel . '; roles=' . implode(',', $user['roles'] ?? [])
    );
    unset($_SESSION['oidc_state'], $_SESSION['oidc_nonce'], $_SESSION['oidc_code_verifier']);

    $returnTo = (string) ($_SESSION['oidc_return_to'] ?? disown_admin_base_path() . '/admin.php');
    unset($_SESSION['oidc_return_to']);
    header('Location: ' . disown_safe_local_return_url($returnTo));
    exit;
}

function disown_oidc_logout_url(): ?string
{
    if (!disown_oidc_enabled()) {
        return null;
    }

    $metadata = disown_oidc_metadata(disown_oidc_config()['OIDC_ISSUER']);
    $logoutEndpoint = $metadata['end_session_endpoint'] ?? '';
    if ($logoutEndpoint === '') {
        return null;
    }

    return $logoutEndpoint . '?' . http_build_query([
        'post_logout_redirect_uri' => disown_absolute_url(disown_admin_base_path() . '/admin.php'),
    ]);
}

function disown_oidc_metadata(string $issuer): array
{
    static $metadataCache = [];
    if (isset($metadataCache[$issuer])) {
        return $metadataCache[$issuer];
    }

    $metadata = disown_http_get_json($issuer . '/.well-known/openid-configuration');
    if (($metadata['issuer'] ?? '') !== $issuer) {
        http_response_code(500);
        exit('OIDC issuer stimmt nicht überein.');
    }

    return $metadataCache[$issuer] = $metadata;
}


function disown_validate_id_token_signature(string $jwt, array $metadata): void
{
    $header = disown_decode_jwt_header($jwt);
    $algorithm = (string) ($header['alg'] ?? '');
    if ($algorithm !== 'RS256') {
        http_response_code(401);
        exit('OIDC id_token Signaturalgorithmus ist ungültig.');
    }

    $jwksUri = (string) ($metadata['jwks_uri'] ?? '');
    if ($jwksUri === '') {
        http_response_code(500);
        exit('OIDC jwks_uri fehlt.');
    }

    $key = disown_oidc_find_jwk(disown_http_get_json($jwksUri), (string) ($header['kid'] ?? ''));
    if (!$key) {
        http_response_code(401);
        exit('OIDC Signaturschlüssel wurde nicht gefunden.');
    }

    $publicKey = disown_rsa_jwk_to_pem($key);
    if ($publicKey === '') {
        http_response_code(401);
        exit('OIDC Signaturschlüssel ist ungültig.');
    }

    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        http_response_code(401);
        exit('OIDC id_token ist ungültig.');
    }

    $signature = disown_base64url_decode($parts[2]);
    $valid = openssl_verify($parts[0] . '.' . $parts[1], $signature, $publicKey, OPENSSL_ALGO_SHA256);
    if ($valid !== 1) {
        http_response_code(401);
        exit('OIDC id_token Signatur ist ungültig.');
    }
}

function disown_oidc_find_jwk(array $jwks, string $kid): ?array
{
    $keys = $jwks['keys'] ?? [];
    if (!is_array($keys)) {
        return null;
    }

    $rsaKeys = [];
    foreach ($keys as $key) {
        if (!is_array($key) || ($key['kty'] ?? '') !== 'RSA') {
            continue;
        }
        if ($kid !== '' && ($key['kid'] ?? '') === $kid) {
            return $key;
        }
        $rsaKeys[] = $key;
    }

    return $kid === '' && count($rsaKeys) === 1 ? $rsaKeys[0] : null;
}

function disown_rsa_jwk_to_pem(array $jwk): string
{
    if (empty($jwk['n']) || empty($jwk['e']) || !is_string($jwk['n']) || !is_string($jwk['e'])) {
        return '';
    }

    $modulus = disown_base64url_decode($jwk['n']);
    $exponent = disown_base64url_decode($jwk['e']);
    if ($modulus === '' || $exponent === '') {
        return '';
    }

    $rsaPublicKey = disown_der_sequence(
        disown_der_integer($modulus) .
        disown_der_integer($exponent)
    );
    $algorithmIdentifier = disown_der_sequence(
        "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01" .
        "\x05\x00"
    );
    $subjectPublicKeyInfo = disown_der_sequence($algorithmIdentifier . disown_der_bit_string($rsaPublicKey));

    return "-----BEGIN PUBLIC KEY-----\n" .
        chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n") .
        "-----END PUBLIC KEY-----\n";
}

function disown_der_sequence(string $value): string
{
    return "\x30" . disown_der_length(strlen($value)) . $value;
}

function disown_der_integer(string $value): string
{
    $value = ltrim($value, "\x00");
    if ($value === '') {
        $value = "\x00";
    }
    if ((ord($value[0]) & 0x80) !== 0) {
        $value = "\x00" . $value;
    }

    return "\x02" . disown_der_length(strlen($value)) . $value;
}

function disown_der_bit_string(string $value): string
{
    return "\x03" . disown_der_length(strlen($value) + 1) . "\x00" . $value;
}

function disown_der_length(int $length): string
{
    if ($length < 0x80) {
        return chr($length);
    }

    $bytes = '';
    while ($length > 0) {
        $bytes = chr($length & 0xff) . $bytes;
        $length >>= 8;
    }

    return chr(0x80 | strlen($bytes)) . $bytes;
}

function disown_decode_jwt_header(string $jwt): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return [];
    }

    $header = json_decode(disown_base64url_decode($parts[0]), true);
    return is_array($header) ? $header : [];
}

function disown_normalize_oidc_user(array $claims): array
{
    $email = strtolower(trim((string) ($claims['email'] ?? '')));
    $name = trim((string) ($claims['name'] ?? $claims['preferred_username'] ?? $email));
    $uuid = trim((string) ($claims['iserv:uuid'] ?? $claims['uuid'] ?? $claims['sub'] ?? ''));
    $roles = disown_claim_to_list($claims['iserv:roles'] ?? $claims['roles'] ?? []);
    $display = $email !== '' ? $email : ($name !== '' ? $name : $uuid);

    return [
        'email' => $email,
        'name' => $name,
        'uuid' => $uuid,
        'roles' => $roles,
        'display' => $display,
    ];
}

function disown_validate_id_token_claims(array $claims, array $config): void
{
    if (($claims['iss'] ?? '') !== $config['OIDC_ISSUER']) {
        http_response_code(401);
        exit('OIDC issuer im id_token ist ungültig.');
    }

    $audience = $claims['aud'] ?? [];
    $audiences = is_array($audience) ? $audience : [$audience];
    if (!in_array($config['OIDC_CLIENT_ID'], $audiences, true)) {
        http_response_code(401);
        exit('OIDC audience im id_token ist ungültig.');
    }

    if (!empty($claims['exp']) && (int) $claims['exp'] < time()) {
        http_response_code(401);
        exit('OIDC id_token ist abgelaufen.');
    }

    $expectedNonce = (string) ($_SESSION['oidc_nonce'] ?? '');
    if (($claims['nonce'] ?? '') !== $expectedNonce) {
        http_response_code(401);
        exit('OIDC nonce ist ungültig.');
    }
}

function disown_claim_to_list($claim): array
{
    if (is_string($claim)) {
        return disown_split_config_list($claim);
    }
    if (!is_array($claim)) {
        return [];
    }

    $values = [];
    foreach ($claim as $item) {
        if (is_string($item)) {
            $values[] = $item;
        } elseif (is_array($item)) {
            foreach (['name', 'role', 'id'] as $key) {
                if (!empty($item[$key]) && is_string($item[$key])) {
                    $values[] = $item[$key];
                }
            }
        }
    }

    return array_values(array_unique(array_filter(array_map('trim', $values))));
}

function disown_oidc_user_allowed(array $user, array $config): bool
{
    return disown_oidc_user_access_level($user, $config) !== null;
}

function disown_oidc_user_access_level(array $user, array $config): ?string
{
    $allowedEmails = array_map('strtolower', $config['OIDC_ALLOWED_EMAILS'] ?? []);
    if ($allowedEmails && in_array(strtolower((string) ($user['email'] ?? '')), $allowedEmails, true)) {
        return 'admin';
    }

    $allowedRoles = $config['OIDC_ALLOWED_ROLES'] ?? [];
    if ($allowedRoles && array_intersect($allowedRoles, $user['roles'] ?? [])) {
        return 'admin';
    }

    $viewerRoles = $config['OIDC_VIEWER_ROLES'] ?? [];
    if ($viewerRoles && array_intersect($viewerRoles, $user['roles'] ?? [])) {
        return 'viewer';
    }

    return !$allowedEmails && !$allowedRoles ? 'admin' : null;
}

function disown_http_get_json(string $url, array $headers = []): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    return disown_decode_json_response($body, $http_response_header ?? [], $url);
}

function disown_http_post_form(string $url, array $data): array
{
    $body = http_build_query($data);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json",
            'content' => $body,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $response = file_get_contents($url, false, $context);
    return disown_decode_json_response($response, $http_response_header ?? [], $url);
}

function disown_decode_json_response($body, array $headers, string $url): array
{
    $status = 0;
    if (!empty($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches)) {
        $status = (int) $matches[1];
    }
    if (!is_string($body) || $body === '' || $status >= 400) {
        http_response_code(502);
        exit('OIDC-HTTP-Fehler bei ' . htmlspecialchars($url));
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        http_response_code(502);
        exit('OIDC-Antwort ist kein JSON.');
    }

    return $json;
}

function disown_decode_jwt_payload(string $jwt): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return [];
    }

    $claims = json_decode(disown_base64url_decode($parts[1]), true);
    return is_array($claims) ? $claims : [];
}

function disown_base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function disown_base64url_decode(string $value): string
{
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return is_string($decoded) ? $decoded : '';
}

function disown_admin_base_path(): string
{
    return disown_is_dev_mode() ? '/disown-dev' : '/disown';
}

function disown_oidc_redirect_uri(): string
{
    return disown_absolute_url(disown_admin_base_path() . '/oidc_callback.php');
}

function disown_absolute_url(string $path): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'example.org';
    return 'https://' . $host . $path;
}

function disown_safe_local_return_url(string $url): string
{
    if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')) {
        return disown_admin_base_path() . '/admin.php';
    }

    return $url;
}
