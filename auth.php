<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

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

    disown_oidc_start_login();
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
    $_SESSION['oidc_return_to'] = $_SERVER['REQUEST_URI'] ?? disown_admin_base_path() . '/admin.php';

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

    $idTokenClaims = [];
    if (!empty($tokenResponse['id_token'])) {
        $idTokenClaims = disown_decode_jwt_payload((string) $tokenResponse['id_token']);
        disown_validate_id_token_claims($idTokenClaims, $config);
    }

    $claims = array_merge($idTokenClaims, $userinfo);
    $user = disown_normalize_oidc_user($claims);
    if (!disown_oidc_user_allowed($user, $config)) {
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
    $_SESSION['oidc_user'] = $user + ['authorized' => true];
    disown_log_auth_event(
        'AUTH_LOGIN_SUCCESS',
        (string) $user['display'],
        'method=oidc; roles=' . implode(',', $user['roles'] ?? [])
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
    $allowedEmails = array_map('strtolower', $config['OIDC_ALLOWED_EMAILS'] ?? []);
    if ($allowedEmails && in_array(strtolower((string) ($user['email'] ?? '')), $allowedEmails, true)) {
        return true;
    }

    $allowedRoles = $config['OIDC_ALLOWED_ROLES'] ?? [];
    if ($allowedRoles && array_intersect($allowedRoles, $user['roles'] ?? [])) {
        return true;
    }

    return !$allowedEmails && !$allowedRoles;
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
    if (count($parts) < 2) {
        return [];
    }

    $payload = base64_decode(strtr($parts[1], '-_', '+/'));
    $claims = json_decode((string) $payload, true);
    return is_array($claims) ? $claims : [];
}

function disown_base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
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
