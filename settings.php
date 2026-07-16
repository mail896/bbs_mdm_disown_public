<?php
require __DIR__ . '/auth.php';
disown_require_admin();
require __DIR__ . '/db.php';
require_once __DIR__ . '/notify.php';

$currentAdminUser = disown_current_admin_user();
$canWrite = disown_can_write();
$isDevMode = basename(__DIR__) === 'disown-dev';
$appVersion = $isDevMode ? '2.4-dev' : '2.4';
$sourceRepoUrl = 'https://github.com/mail896/bbs_mdm_disown_public';
$appBasePath = rtrim(disown_admin_base_path(), '/');
$adminPath = $appBasePath . '/admin.php';
$adePath = $appBasePath . '/ade.php';
$kukPath = $appBasePath . '/kuk/';
$auditLogPath = $appBasePath . '/audit_log.php';
$settingsPath = $appBasePath . '/settings.php';
$logoutPath = $appBasePath . '/logout.php';
$faviconPath = $appBasePath . '/favicon.svg';
$siteImagePath = $appBasePath . '/images/Site-Image.png';
$settingsCssUrl = disown_asset_url($appBasePath, 'assets/settings.css');
$maintenancePermissionsScript = __DIR__ . '/scripts/maintenance_permissions.sh';
$criticalPasswordScript = __DIR__ . '/scripts/set_critical_password.php';
$rootHelperInstaller = __DIR__ . '/tools/install-settings-root-helper.sh';
$rootHelperSource = __DIR__ . '/tools/disown-settings-helper';
$rootHelperPath = '/usr/local/sbin/disown-settings-helper';
$sudoBinary = '/usr/bin/sudo';
$smokeCheckScript = __DIR__ . '/scripts/smoke_check.php';
$screenshotScript = __DIR__ . '/scripts/update_screenshots.sh';
$promoteScreenshotsScript = __DIR__ . '/scripts/promote_screenshots.sh';
$phpCliBinary = is_file('/usr/bin/php') ? '/usr/bin/php' : (PHP_BINARY ?: 'php');
$bashBinary = is_file('/usr/bin/bash') ? '/usr/bin/bash' : 'bash';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

function settings_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function settings_url(array $params = []): string
{
    global $settingsPath;
    $clean = [];
    foreach ($params as $key => $value) {
        if ($value !== null && $value !== '') {
            $clean[$key] = $value;
        }
    }
    return $settingsPath . ($clean ? '?' . http_build_query($clean) : '');
}

function settings_current_url_without_tool_result(): string
{
    $params = $_GET;
    unset($params['tool_result']);
    if (!isset($params['tab'])) {
        $params['tab'] = 'system';
    }
    return settings_url($params);
}

function settings_file_status(string $path): array
{
    return [
        'path' => $path,
        'exists' => is_file($path),
        'readable' => is_readable($path),
        'mtime' => is_file($path) ? filemtime($path) : null,
    ];
}

function settings_status_badge(bool $ok, string $okText = 'OK', string $warnText = 'Prüfen'): string
{
    return '<span class="status-badge ' . ($ok ? 'ok' : 'warn') . '">' . settings_h($ok ? $okText : $warnText) . '</span>';
}

function settings_build_mail_config_from_post(array $currentConfig): array
{
    $config = $currentConfig;
    foreach (['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_FROM', 'MAIL_ENCRYPTION'] as $key) {
        $config[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $password = trim((string) ($_POST['MAIL_PASSWORD'] ?? ''));
    if ($password !== '') {
        $config['MAIL_PASSWORD'] = $password;
    }

    return $config;
}

function settings_validate_mail_config(array $config): array
{
    $errors = [];
    if (trim((string) ($config['MAIL_HOST'] ?? '')) === '') {
        $errors[] = 'SMTP-Host fehlt.';
    }
    $port = (int) ($config['MAIL_PORT'] ?? 0);
    if ($port < 1 || $port > 65535) {
        $errors[] = 'SMTP-Port ist ungültig.';
    }
    if (trim((string) ($config['MAIL_USERNAME'] ?? '')) === '') {
        $errors[] = 'SMTP-Benutzer fehlt.';
    }
    if (trim((string) ($config['MAIL_PASSWORD'] ?? '')) === '') {
        $errors[] = 'SMTP-Passwort fehlt oder ist noch nicht hinterlegt.';
    }
    $from = trim((string) ($config['MAIL_FROM'] ?? ''));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Absenderadresse ist ungültig.';
    }
    if (!in_array((string) ($config['MAIL_ENCRYPTION'] ?? ''), ['', 'tls', 'ssl'], true)) {
        $errors[] = 'Verschlüsselung ist ungültig.';
    }

    return $errors;
}

function settings_send_smtp_test_mail(array $mailConfig, string $recipient): void
{
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $mailConfig['MAIL_HOST'];
    $mail->Port = (int) $mailConfig['MAIL_PORT'];
    $mail->SMTPAuth = true;
    $mail->Username = $mailConfig['MAIL_USERNAME'];
    $mail->Password = $mailConfig['MAIL_PASSWORD'];
    if (($mailConfig['MAIL_ENCRYPTION'] ?? '') === 'ssl') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } elseif (($mailConfig['MAIL_ENCRYPTION'] ?? '') === 'tls') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }
    $mail->CharSet = 'UTF-8';
    $mail->setFrom($mailConfig['MAIL_FROM'], 'BBS Einbeck, iPad-Management');
    $mail->addAddress($recipient);
    $mail->Subject = 'DISOWN SMTP-Test';
    $mail->Body = '<strong>DISOWN SMTP-Test</strong><br>Diese Testmail wurde aus den Einstellungen gesendet.';
    $mail->AltBody = "DISOWN SMTP-Test\nDiese Testmail wurde aus den Einstellungen gesendet.";
    $mail->isHTML(true);
    $mail->send();
}

function settings_display_time(?int $timestamp): string
{
    return $timestamp ? date('d.m.Y H:i', $timestamp) : '-';
}

function settings_log_access_label(array $status): string
{
    if (!empty($status['readable'])) {
        return 'Lesbar';
    }
    if (str_starts_with((string) ($status['path'] ?? ''), '/var/log/disown/')) {
        return 'Kein Zugriff';
    }
    return !empty($status['exists']) ? 'Nicht lesbar' : 'Fehlt';
}

function settings_file_tail(string $path, int $maxLines = 3): array
{
    if (!is_readable($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return [];
    }
    $lines = array_values(array_filter($lines, static fn($line) => trim((string) $line) !== ''));
    return array_slice($lines, -$maxLines);
}

function settings_cron_entries(array $files): array
{
    $entries = [];
    foreach ($files as $file) {
        if (!is_readable($file)) {
            continue;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            continue;
        }
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, 'disown')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 7);
            if (!$parts || count($parts) < 7) {
                continue;
            }
            $command = $parts[6];
            $script = '';
            if (preg_match('~/var/www/[^ >]+/([^/ ]+\.php)~', $command, $scriptMatch)) {
                $script = $scriptMatch[1];
            }
            $logPath = '';
            if (preg_match('~>>\s*([^ ]+)~', $command, $logMatch)) {
                $logPath = $logMatch[1];
            }
            $environment = str_contains($command, '/disown-dev/') ? 'DEV' : (str_contains($command, '/disown/') ? 'PROD' : 'System');
            $entries[] = [
                'file' => $file,
                'schedule' => implode(' ', array_slice($parts, 0, 5)),
                'user' => $parts[5],
                'command' => $command,
                'environment' => $environment,
                'script' => $script,
                'log_path' => $logPath,
            ];
        }
    }
    return $entries;
}

function settings_run_readonly_command(array $command, string $cwd): string
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
    if (!is_resource($process)) {
        return '';
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return trim((string) ($stdout !== '' ? $stdout : $stderr));
}

function settings_run_command_result(array $command, string $cwd): array
{
    if (trim((string) ($command[0] ?? '')) === '') {
        return ['exit_code' => 127, 'output' => 'Kommando ist unvollständig: Programmname fehlt.'];
    }

    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    try {
        $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
    } catch (Throwable $e) {
        return ['exit_code' => 127, 'output' => 'Kommando konnte nicht gestartet werden: ' . $e->getMessage()];
    }
    if (!is_resource($process)) {
        return ['exit_code' => 127, 'output' => 'Kommando konnte nicht gestartet werden.'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $output = trim((string) $stdout . ((string) $stderr !== '' ? "\n" . (string) $stderr : ''));
    if (strlen($output) > 12000) {
        $output = substr($output, 0, 12000) . "\n[Ausgabe gekürzt]";
    }
    return ['exit_code' => (int) $exitCode, 'output' => $output !== '' ? $output : 'Keine Ausgabe.'];
}

function settings_git_command(string $root, array $args): string
{
    $command = array_merge(['git', '-C', $root, '-c', 'safe.directory=' . $root], $args);
    $output = settings_run_readonly_command($command, $root);
    return str_starts_with($output, 'fatal:') ? '' : $output;
}

function settings_git_info(string $root): array
{
    $branch = settings_git_command($root, ['rev-parse', '--abbrev-ref', 'HEAD']);
    $commit = settings_git_command($root, ['rev-parse', '--short', 'HEAD']);
    $status = settings_git_command($root, ['status', '--short']);

    return [
        'branch' => $branch,
        'commit' => $commit,
        'dirty' => $status !== '',
        'available' => $branch !== '' && $commit !== '',
    ];
}

function settings_critical_active(): bool
{
    global $currentAdminUser;

    $active = !empty($_SESSION['settings_critical_until'])
        && (int) $_SESSION['settings_critical_until'] > time()
        && hash_equals((string) ($_SESSION['settings_critical_user'] ?? ''), (string) $currentAdminUser);
    if (!$active) {
        unset($_SESSION['settings_critical_until'], $_SESSION['settings_critical_user']);
    }
    return $active;
}

function settings_critical_remaining(): int
{
    return max(0, ((int) ($_SESSION['settings_critical_until'] ?? 0)) - time());
}

function settings_root_helper_current(string $sourcePath, string $installedPath): bool
{
    if (!is_readable($sourcePath) || !is_readable($installedPath)) {
        return false;
    }
    $sourceHash = hash_file('sha256', $sourcePath);
    $installedHash = hash_file('sha256', $installedPath);
    return is_string($sourceHash) && is_string($installedHash) && hash_equals($sourceHash, $installedHash);
}

$tabs = [
    'overview' => 'Übersicht',
    'push' => 'E-Mail-Push',
    'jobs' => 'Jobs',
    'interfaces' => 'Schnittstellen',
    'security' => 'Sicherheit',
    'system' => 'System',
];
$activeTab = (string) ($_GET['tab'] ?? 'overview');
if (!isset($tabs[$activeTab])) {
    $activeTab = 'overview';
}

$notice = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
        http_response_code(400);
        exit('Ungültiges Formular. Bitte lade die Seite neu und versuche es erneut.');
    }
    disown_require_write();

    $action = (string) ($_POST['settings_action'] ?? '');
    if ($action === 'save_push') {
        $pushEnabled = ($_POST['push_mail_enabled'] ?? '') === '1';
        $names = $_POST['recipient_name'] ?? [];
        $emails = $_POST['recipient_email'] ?? [];
        $enabledFlags = $_POST['recipient_enabled'] ?? [];
        $records = [];
        $invalid = [];

        if (is_array($emails)) {
            foreach ($emails as $index => $emailValue) {
                $email = strtolower(trim((string) $emailValue));
                $name = is_array($names) ? trim((string) ($names[$index] ?? '')) : '';
                if ($email === '') {
                    continue;
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $invalid[] = $email;
                    continue;
                }
                $records[] = [
                    'name' => $name,
                    'email' => $email,
                    'enabled' => is_array($enabledFlags) && isset($enabledFlags[$index]),
                ];
            }
        }

        if ($invalid) {
            $error = 'Ungültige E-Mail-Adresse: ' . implode(', ', $invalid);
            $activeTab = 'push';
        } else {
            $okRecipients = disown_push_mail_set_recipient_records($mysqli, $records, $currentAdminUser ?: 'admin');
            $okEnabled = disown_push_mail_set_enabled($mysqli, $pushEnabled, $currentAdminUser ?: 'admin');
            if ($okRecipients && $okEnabled) {
                disown_log_audit_action($mysqli, 0, 'SETTINGS_PUSH_UPDATED', $currentAdminUser, 'E-Mail-Push-Einstellungen aktualisiert; aktive Empfänger: ' . count(array_filter($records, static fn($record) => !empty($record['enabled']))));
                $_SESSION['settings_notice'] = 'E-Mail-Push-Einstellungen wurden gespeichert.';
                header('Location: ' . settings_url(['tab' => 'push']));
                exit;
            }
            $error = 'E-Mail-Push-Einstellungen konnten nicht gespeichert werden.';
            $activeTab = 'push';
        }
    } elseif ($action === 'save_smtp' || $action === 'test_smtp') {
        $activeTab = 'interfaces';
        $currentMailConfig = disown_mail_config($mysqli);
        $mailConfig = settings_build_mail_config_from_post($currentMailConfig);
        $validationErrors = settings_validate_mail_config($mailConfig);

        if ($validationErrors) {
            $error = implode(' ', $validationErrors);
        } elseif ($action === 'save_smtp') {
            if (disown_mail_set_config($mysqli, $mailConfig, $currentAdminUser ?: 'admin')) {
                disown_log_audit_action($mysqli, 0, 'SETTINGS_SMTP_UPDATED', $currentAdminUser, 'SMTP-Konfiguration aktualisiert; Host: ' . (string) ($mailConfig['MAIL_HOST'] ?? ''));
                $_SESSION['settings_notice'] = 'SMTP-Konfiguration wurde gespeichert.';
                header('Location: ' . settings_url(['tab' => 'interfaces']));
                exit;
            }
            $error = 'SMTP-Konfiguration konnte nicht gespeichert werden.';
        } else {
            $testRecipient = trim((string) ($_POST['smtp_test_recipient'] ?? $currentAdminUser));
            if ($testRecipient === '' || !filter_var($testRecipient, FILTER_VALIDATE_EMAIL)) {
                $error = 'Testempfänger ist ungültig.';
            } else {
                try {
                    settings_send_smtp_test_mail($mailConfig, $testRecipient);
                    disown_log_audit_action($mysqli, 0, 'SETTINGS_SMTP_TEST_SENT', $currentAdminUser, 'SMTP-Testmail gesendet an ' . $testRecipient);
                    $_SESSION['settings_notice'] = 'SMTP-Testmail wurde an ' . $testRecipient . ' gesendet.';
                    header('Location: ' . settings_url(['tab' => 'interfaces']));
                    exit;
                } catch (Throwable $e) {
                    disown_log_audit_action($mysqli, 0, 'SETTINGS_SMTP_TEST_FAILED', $currentAdminUser, 'SMTP-Testmail fehlgeschlagen: ' . $e->getMessage());
                    $error = 'SMTP-Testmail fehlgeschlagen: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'unlock_critical') {
        $activeTab = 'security';
        $password = (string) ($_POST['critical_unlock_password'] ?? '');
        $hash = disown_setting_get($mysqli, 'critical_password_hash', '');
        $blockedUntil = (int) ($_SESSION['settings_critical_blocked_until'] ?? 0);
        if ($blockedUntil > time()) {
            $error = 'Zu viele Fehlversuche. Bitte warte noch ' . (string) ($blockedUntil - time()) . ' Sekunden.';
        } elseif ($hash === '') {
            $error = 'Es ist noch kein Critical-Mode-Kennwort gesetzt.';
        } elseif (!password_verify($password, $hash)) {
            $failedAttempts = (int) ($_SESSION['settings_critical_failed_attempts'] ?? 0) + 1;
            $_SESSION['settings_critical_failed_attempts'] = $failedAttempts;
            if ($failedAttempts >= 5) {
                $_SESSION['settings_critical_blocked_until'] = time() + 60;
                $_SESSION['settings_critical_failed_attempts'] = 0;
            }
            disown_log_audit_action($mysqli, 0, 'SETTINGS_CRITICAL_UNLOCK_FAILED', $currentAdminUser, 'Critical-Mode-Entsperrung fehlgeschlagen.');
            $error = $failedAttempts >= 5 ? 'Zu viele Fehlversuche. Critical Mode ist für 60 Sekunden gesperrt.' : 'Critical-Mode-Kennwort ist falsch.';
        } else {
            session_regenerate_id(true);
            $_SESSION['settings_critical_until'] = time() + 600;
            $_SESSION['settings_critical_user'] = (string) $currentAdminUser;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
            unset($_SESSION['settings_critical_failed_attempts'], $_SESSION['settings_critical_blocked_until']);
            disown_log_audit_action($mysqli, 0, 'SETTINGS_CRITICAL_UNLOCKED', $currentAdminUser, 'Critical Mode fuer 10 Minuten entsperrt.');
            $_SESSION['settings_notice'] = 'Critical Mode ist für 10 Minuten entsperrt.';
            header('Location: ' . settings_url(['tab' => 'security']));
            exit;
        }
    } elseif ($action === 'lock_critical') {
        unset($_SESSION['settings_critical_until'], $_SESSION['settings_critical_user']);
        disown_log_audit_action($mysqli, 0, 'SETTINGS_CRITICAL_LOCKED', $currentAdminUser, 'Critical Mode manuell gesperrt.');
        $_SESSION['settings_notice'] = 'Critical Mode wurde gesperrt.';
        header('Location: ' . settings_url(['tab' => 'security']));
        exit;
    } elseif ($action === 'run_smoke_check') {
        $activeTab = 'system';
        $result = settings_run_command_result([$phpCliBinary, $smokeCheckScript], __DIR__);
        disown_log_audit_action($mysqli, 0, 'SETTINGS_TOOL_RUN', $currentAdminUser, 'Smoke-Check ausgeführt; Exit-Code: ' . (string) $result['exit_code']);
        $_SESSION['settings_tool_result'] = [
            'title' => 'Smoke-Check',
            'ok' => $result['exit_code'] === 0,
            'output' => $result['output'],
        ];
        header('Location: ' . settings_url(['tab' => 'system', 'tool_result' => '1']));
        exit;
    } elseif ($action === 'run_permissions_check') {
        $activeTab = 'system';
        $result = settings_run_command_result([$bashBinary, $maintenancePermissionsScript, 'check'], __DIR__);
        disown_log_audit_action($mysqli, 0, 'SETTINGS_TOOL_RUN', $currentAdminUser, 'Rechtecheck ausgeführt; Exit-Code: ' . (string) $result['exit_code']);
        $_SESSION['settings_tool_result'] = [
            'title' => 'Rechte prüfen',
            'ok' => $result['exit_code'] === 0,
            'output' => $result['output'],
        ];
        header('Location: ' . settings_url(['tab' => 'system', 'tool_result' => '1']));
        exit;
    } elseif ($action === 'run_backup_status') {
        $activeTab = 'system';
        if (!settings_root_helper_current($rootHelperSource, $rootHelperPath) || !is_executable($sudoBinary)) {
            $error = 'Der Root-Helper muss zuerst installiert oder aktualisiert werden.';
        } else {
            $result = settings_run_command_result([$sudoBinary, '-n', $rootHelperPath, 'backup-status'], __DIR__);
            disown_log_audit_action($mysqli, 0, 'SETTINGS_BACKUP_STATUS', $currentAdminUser, 'Backup-Status geprüft; Exit-Code: ' . (string) $result['exit_code']);
            $_SESSION['settings_tool_result'] = [
                'title' => 'Backup-Status',
                'ok' => $result['exit_code'] === 0,
                'output' => $result['output'],
            ];
            header('Location: ' . settings_url(['tab' => 'system', 'tool_result' => '1']));
            exit;
        }
    } elseif ($action === 'create_backup') {
        $activeTab = 'system';
        if (!settings_critical_active()) {
            disown_log_audit_action($mysqli, 0, 'SETTINGS_ROOT_ACTION_DENIED', $currentAdminUser, 'Backup ohne aktiven Critical Mode abgewiesen.');
            $error = 'Critical Mode ist nicht aktiv oder abgelaufen.';
        } elseif (($_POST['confirm_root_action'] ?? '') !== '1') {
            $error = 'Bitte bestätige die Backup-Erstellung ausdrücklich.';
        } elseif (!settings_root_helper_current($rootHelperSource, $rootHelperPath) || !is_executable($sudoBinary)) {
            $error = 'Der Root-Helper muss zuerst installiert oder aktualisiert werden.';
        } else {
            $result = settings_run_command_result([$sudoBinary, '-n', $rootHelperPath, 'create-backup'], __DIR__);
            $auditAction = $result['exit_code'] === 0 ? 'SETTINGS_BACKUP_CREATED' : 'SETTINGS_BACKUP_FAILED';
            disown_log_audit_action($mysqli, 0, $auditAction, $currentAdminUser, 'Root-Helper Backup-Erstellung; Exit-Code: ' . (string) $result['exit_code']);
            $_SESSION['settings_tool_result'] = [
                'title' => 'Backup erstellen',
                'ok' => $result['exit_code'] === 0,
                'output' => $result['output'],
            ];
            header('Location: ' . settings_url(['tab' => 'system', 'tool_result' => '1']));
            exit;
        }
    } elseif ($action === 'repair_log_permissions') {
        $activeTab = 'jobs';
        if (!settings_critical_active()) {
            disown_log_audit_action($mysqli, 0, 'SETTINGS_ROOT_ACTION_DENIED', $currentAdminUser, 'Log-Rechte-Reparatur ohne aktiven Critical Mode abgewiesen.');
            $error = 'Critical Mode ist nicht aktiv oder abgelaufen.';
        } elseif (($_POST['confirm_root_action'] ?? '') !== '1') {
            $error = 'Bitte bestätige die Root-Aktion ausdrücklich.';
        } elseif (!is_executable($rootHelperPath) || !is_executable($sudoBinary)) {
            $error = 'Der Root-Helper ist noch nicht vollständig installiert.';
        } else {
            $result = settings_run_command_result([$sudoBinary, '-n', $rootHelperPath, 'repair-log-permissions'], __DIR__);
            $auditAction = $result['exit_code'] === 0 ? 'SETTINGS_ROOT_ACTION_OK' : 'SETTINGS_ROOT_ACTION_FAILED';
            disown_log_audit_action($mysqli, 0, $auditAction, $currentAdminUser, 'Root-Helper Log-Rechte-Reparatur; Exit-Code: ' . (string) $result['exit_code']);
            $_SESSION['settings_tool_result'] = [
                'title' => 'Log-Rechte reparieren',
                'ok' => $result['exit_code'] === 0,
                'output' => $result['output'],
            ];
            header('Location: ' . settings_url(['tab' => 'jobs', 'tool_result' => '1']));
            exit;
        }
    }
}

if (!empty($_SESSION['settings_notice'])) {
    $notice = (string) $_SESSION['settings_notice'];
    unset($_SESSION['settings_notice']);
}
$toolResult = null;
if (($_GET['tool_result'] ?? '') === '1' && !empty($_SESSION['settings_tool_result']) && is_array($_SESSION['settings_tool_result'])) {
    $toolResult = $_SESSION['settings_tool_result'];
}

$pushStatus = disown_push_mail_status($mysqli);
$pushRecipientRecords = $pushStatus['recipient_records'];
for ($i = count($pushRecipientRecords); $i < 4; $i++) {
    $pushRecipientRecords[] = ['name' => '', 'email' => '', 'enabled' => false, 'source' => ''];
}

$mailConfigStatus = settings_file_status(disown_mail_config_path());
$notifyConfigStatus = settings_file_status(disown_notify_config_path());
$cronCandidates = [
    '/etc/cron.d/disown-ade-sync',
    '/etc/cron.d/disown-kuk-sync',
    '/etc/cron.d/disown',
    '/etc/cron.d/disown-notify',
    '/var/spool/cron/crontabs/www-data',
];
$cronStatuses = array_map('settings_file_status', $cronCandidates);
$hasCronHint = (bool) array_filter($cronStatuses, static fn($status) => $status['exists']);
$cronEntries = settings_cron_entries($cronCandidates);
$jobLogs = [
    'ADE DEV' => '/var/log/disown/ade-sync-dev.log',
    'ADE PROD' => '/var/log/disown/ade-sync-prod.log',
    'KUK DEV' => '/var/log/disown/kuk-sync-dev.log',
    'KUK PROD' => '/var/log/disown/kuk-sync-prod.log',
    'NanoDEP Health' => '/var/log/disown/nanodep-health.log',
];
$jobLogStatuses = [];
foreach ($jobLogs as $label => $path) {
    $status = settings_file_status($path);
    $status['label'] = $label;
    $status['tail'] = settings_file_tail($path, 3);
    $jobLogStatuses[] = $status;
}
$smtpConfig = isset($mailConfig) && is_array($mailConfig) ? $mailConfig : disown_mail_config($mysqli);
$smtpDbConfig = disown_mail_db_config($mysqli);
$smtpComplete = disown_mail_config_is_complete($smtpConfig);
$criticalPasswordConfigured = disown_setting_get($mysqli, 'critical_password_hash', '') !== '';
$criticalActive = settings_critical_active();
$criticalRemaining = settings_critical_remaining();
$rootHelperInstalled = is_executable($rootHelperPath) && is_executable($sudoBinary);
$rootHelperCurrent = $rootHelperInstalled && settings_root_helper_current($rootHelperSource, $rootHelperPath);
$gitInfo = settings_git_info(__DIR__);
$stateRoot = dirname(__DIR__) . '/disown';
$readableLogCount = count(array_filter($jobLogStatuses, static fn($status) => !empty($status['readable'])));
$systemToolFiles = [
    'Smoke-Check' => $smokeCheckScript,
    'Screenshots aktualisieren' => $screenshotScript,
    'Screenshots promoten' => $promoteScreenshotsScript,
    'Wartung Rechte' => $maintenancePermissionsScript,
    'Critical-Kennwort setzen' => $criticalPasswordScript,
    'Root-Helper installieren' => $rootHelperInstaller,
];
$systemFiles = [
    'Projekt DEV' => __DIR__,
    'PROJECT_STATE.md' => $stateRoot . '/PROJECT_STATE.md',
    'PROJECT_STATE.yaml' => $stateRoot . '/PROJECT_STATE.yaml',
];
$systemToolStatuses = [];
foreach ($systemToolFiles as $label => $path) {
    $status = settings_file_status($path);
    $status['label'] = $label;
    $systemToolStatuses[] = $status;
}
$systemFileStatuses = [];
foreach ($systemFiles as $label => $path) {
    $status = settings_file_status($path);
    $status['label'] = $label;
    $systemFileStatuses[] = $status;
}
$notifyConfig = disown_notify_config();
$brokerTokenUntil = trim((string) ($notifyConfig['RELEASE_BROKER_TOKEN_EXPIRES'] ?? $notifyConfig['TOKEN_EXPIRES'] ?? ''));
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<?php if (defined('DISOWN_BASE_HREF')): ?>
<base href="<?=settings_h(DISOWN_BASE_HREF)?>">
<?php endif; ?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="<?=settings_h($faviconPath)?>">
<title>Einstellungen</title>
<style>
:root {
    --disown-site-image: url("<?=settings_h($siteImagePath)?>");
}
</style>
<link rel="stylesheet" href="<?=settings_h($settingsCssUrl)?>">
</head>
<body>
<div class="page">
    <header class="header">
        <div>
            <h1 class="page-title">Einstellungen</h1>
            <p class="hint-text">Konfiguration, Status und spätere Admin-Werkzeuge an einem Ort.</p>
        </div>
        <div class="header-actions">
            <a class="admin-user" href="<?=settings_h($logoutPath)?>">👤 <?=settings_h($currentAdminUser ?: 'Admin')?></a>
            <div class="actions-row">
                <a class="button button-secondary admin-nav-link admin-home-link" href="<?=settings_h($adminPath)?>">Adminportal</a>
                <a class="button button-secondary admin-nav-link" href="<?=settings_h($adePath)?>">ADE-Aufnahmen</a>
                <a class="button button-secondary admin-nav-link" href="<?=settings_h($kukPath)?>">KUK-Geräte</a>
                <a class="button button-secondary admin-nav-link" href="<?=settings_h($auditLogPath)?>">Audit-Log</a>
            </div>
        </div>
    </header>

    <?php if (!$canWrite): ?>
        <div class="notice warn"><strong>Nur-Lese-Zugriff:</strong> Einstellungen können angesehen, aber nicht geändert werden.</div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <div class="notice ok"><?=settings_h($notice)?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="notice danger"><?=settings_h($error)?></div>
    <?php endif; ?>

    <nav class="settings-tabs" aria-label="Einstellungen">
        <?php foreach ($tabs as $tabKey => $tabLabel): ?>
            <a class="tab <?=$activeTab === $tabKey ? 'active' : ''?>" href="<?=settings_h(settings_url(['tab' => $tabKey]))?>"><?=settings_h($tabLabel)?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($activeTab === 'overview'): ?>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Systemübersicht</h2>
                    <p>Ein schneller Blick auf die Schalter und Abhängigkeiten, die im Alltag wichtig sind.</p>
                </div>
            </div>
            <div class="status-grid">
                <a class="status-card status-card-link" href="<?=settings_h(settings_url(['tab' => 'push']))?>">
                    <span class="status-label">E-Mail-Push</span>
                    <strong><?=$pushStatus['enabled'] ? 'Aktiv' : 'Aus'?></strong>
                    <span><?=settings_h((string) $pushStatus['recipient_count'])?> aktive Empfänger</span>
                    <?=settings_status_badge($pushStatus['enabled'] && $pushStatus['recipient_count'] > 0)?>
                </a>
                <a class="status-card status-card-link" href="<?=settings_h(settings_url(['tab' => 'interfaces']))?>">
                    <span class="status-label">SMTP</span>
                    <strong><?=$smtpComplete ? 'Konfiguriert' : 'Unvollständig'?></strong>
                    <span><?=settings_h($smtpDbConfig ? 'DB-Override aktiv' : $mailConfigStatus['path'])?></span>
                    <?=settings_status_badge($smtpComplete)?>
                </a>
                <a class="status-card status-card-link" href="<?=settings_h(settings_url(['tab' => 'push']))?>">
                    <span class="status-label">Notify-Konfig</span>
                    <strong><?=$notifyConfigStatus['readable'] ? 'Lesbar' : 'Nicht lesbar'?></strong>
                    <span>Stand <?=settings_h(settings_display_time($notifyConfigStatus['mtime']))?></span>
                    <?=settings_status_badge($notifyConfigStatus['readable'])?>
                </a>
                <a class="status-card status-card-link" href="<?=settings_h(settings_url(['tab' => 'jobs']))?>">
                    <span class="status-label">Cron</span>
                    <strong><?=settings_h((string) count($cronEntries))?> Jobs</strong>
                    <span><?=settings_h((string) count(array_filter($jobLogStatuses, static fn($status) => $status['readable'])))?> lesbare Logs</span>
                    <?=settings_status_badge(count($cronEntries) > 0, 'Gefunden', 'Prüfen')?>
                </a>
                <a class="status-card status-card-link" href="<?=settings_h(settings_url(['tab' => 'interfaces']))?>">
                    <span class="status-label">Release Broker</span>
                    <strong><?=settings_h($brokerTokenUntil !== '' ? 'Token-Hinweis' : 'Status extern')?></strong>
                    <span><?=settings_h($brokerTokenUntil !== '' ? $brokerTokenUntil : 'Wird später tiefer angebunden')?></span>
                    <?=settings_status_badge(true)?>
                </a>
                <a class="status-card status-card-link" href="<?=settings_h(settings_url(['tab' => 'system']))?>">
                    <span class="status-label">Version</span>
                    <strong><?=settings_h($appVersion)?></strong>
                    <span>Systemdetails und Werkzeuge</span>
                    <?=settings_status_badge(true)?>
                </a>
            </div>
        </section>
    <?php elseif ($activeTab === 'push'): ?>
        <form class="panel" method="post">
            <input type="hidden" name="csrf_token" value="<?=settings_h($_SESSION['csrf_token'])?>">
            <input type="hidden" name="settings_action" value="save_push">
            <div class="panel-heading">
                <div>
                    <h2>E-Mail-Push</h2>
                    <p>Empfänger für neue WebClip-Anträge verwalten. Leere Zeilen werden ignoriert.</p>
                </div>
                <label class="switch-card">
                    <input type="checkbox" name="push_mail_enabled" value="1" <?=$pushStatus['enabled'] ? 'checked' : ''?> <?=$canWrite ? '' : 'disabled'?>>
                    <span class="switch-visual"></span>
                    <span>Global <?=$pushStatus['enabled'] ? 'ein' : 'aus'?></span>
                </label>
            </div>

            <div class="recipient-list">
                <div class="recipient-row recipient-head">
                    <span>Name</span>
                    <span>E-Mail</span>
                    <span>Push</span>
                </div>
                <?php foreach ($pushRecipientRecords as $index => $record): ?>
                    <div class="recipient-row">
                        <input name="recipient_name[<?=$index?>]" value="<?=settings_h((string) ($record['name'] ?? ''))?>" placeholder="z. B. Marc" <?=$canWrite ? '' : 'disabled'?>>
                        <input name="recipient_email[<?=$index?>]" type="email" value="<?=settings_h((string) ($record['email'] ?? ''))?>" placeholder="name@bbs-einbeck.de" <?=$canWrite ? '' : 'disabled'?>>
                        <label class="mini-switch" title="Empfänger erhält Push-Mails">
                            <input type="checkbox" name="recipient_enabled[<?=$index?>]" <?=!empty($record['enabled']) ? 'checked' : ''?> <?=$canWrite ? '' : 'disabled'?>>
                            <span></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="panel-actions">
                <span class="subtle">Fallback: <?=settings_h(disown_notify_config_path())?> bleibt aktiv, solange keine DB-Liste gespeichert ist.</span>
                <button class="button button-primary" type="submit" <?=$canWrite ? '' : 'disabled'?>>Speichern</button>
            </div>
        </form>
    <?php elseif ($activeTab === 'jobs'): ?>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Jobs</h2>
                    <p>Aktuell Anzeige und Logstatus. Bearbeiten und Neustart folgen mit dem geschützten Root-Helper.</p>
                </div>
            </div>
            <?php if ($cronEntries): ?>
                <div class="job-list job-grid">
                    <?php foreach ($cronEntries as $entry): ?>
                        <div class="job-row">
                            <div>
                                <strong><?=settings_h($entry['environment'])?> · <?=settings_h($entry['script'] ?: 'Cronjob')?></strong>
                                <span><?=settings_h($entry['schedule'])?> · <?=settings_h($entry['user'])?> · <?=settings_h(basename($entry['file']))?></span>
                            </div>
                            <div class="job-meta">
                                <?php if ($entry['log_path'] !== ''): ?>
                                    <span>Log: <?=settings_h($entry['log_path'])?></span>
                                <?php endif; ?>
                                <details>
                                    <summary>Kommando</summary>
                                    <code><?=settings_h($entry['command'])?></code>
                                </details>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="notice warn">Keine DISOWN-Cronzeilen gefunden.</div>
            <?php endif; ?>
        </section>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Job-Logs</h2>
                    <p>Letzte lesbare Zeilen je Logdatei. Das reicht für einen schnellen Statuscheck ohne SSH.</p>
                </div>
            </div>
            <div class="file-list log-grid">
                <?php foreach ($jobLogStatuses as $status): ?>
                    <div class="log-row">
                        <div class="log-row-head">
                            <div>
                                <strong><?=settings_h($status['label'])?></strong>
                                <span><?=settings_h($status['path'])?></span>
                            </div>
                            <div>
                                <?=settings_status_badge($status['readable'], 'Lesbar', settings_log_access_label($status))?>
                                <small>Stand <?=settings_h(settings_display_time($status['mtime']))?></small>
                            </div>
                        </div>
                        <?php if ($status['tail']): ?>
                            <pre><?php foreach ($status['tail'] as $line): ?><?=settings_h($line) . "\n"?><?php endforeach; ?></pre>
                        <?php else: ?>
                            <span class="subtle"><?=str_starts_with((string) $status['path'], '/var/log/disown/') && !$status['readable'] ? 'Der Webserver hat noch keine Leserechte auf /var/log/disown.' : 'Keine lesbaren Logzeilen.'?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Wartung</h2>
                    <p>Projektbezogene Rechte prüfen und reparieren. Der Block ist bewusst an den Critical-Mode gekoppelt.</p>
                </div>
                <?=settings_status_badge($criticalActive, $criticalActive ? 'Critical aktiv' : 'Gesperrt', 'Gesperrt')?>
            </div>
            <div class="maintenance-card">
                <div>
                    <strong>Log-Leserechte für den Webserver reparieren</strong>
                    <span>Setzt ACLs, damit Apache/PHP die DISOWN-Logs lesen kann, ohne die Logs öffentlich zu machen.</span>
                </div>
                <div class="maintenance-command">
                    <code>sudo <?=settings_h($rootHelperPath)?> repair-log-permissions</code>
                    <?php if ($criticalActive && $rootHelperInstalled): ?>
                        <form method="post" class="critical-root-form">
                            <input type="hidden" name="csrf_token" value="<?=settings_h($_SESSION['csrf_token'])?>">
                            <label class="confirmation-check">
                                <input type="checkbox" name="confirm_root_action" value="1" required>
                                <span>Root-Aktion für die DISOWN-Log-ACLs bestätigen</span>
                            </label>
                            <button class="button button-primary" type="submit" name="settings_action" value="repair_log_permissions" <?=$canWrite ? '' : 'disabled'?>>Jetzt reparieren</button>
                        </form>
                        <span class="subtle">Critical Mode ist noch <?=settings_h((string) ceil($criticalRemaining / 60))?> Min. aktiv.</span>
                    <?php elseif ($criticalActive): ?>
                        <span class="subtle">Root-Helper fehlt. Installiere ihn einmalig serverseitig.</span>
                        <code>sudo <?=settings_h($rootHelperInstaller)?> install</code>
                    <?php else: ?>
                        <a class="button button-secondary" href="<?=settings_h(settings_url(['tab' => 'security']))?>">Critical Mode entsperren</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($toolResult && ($toolResult['title'] ?? '') === 'Log-Rechte reparieren'): ?>
                <div class="tool-result <?=$toolResult['ok'] ? 'ok' : 'warn'?>">
                    <div class="tool-result-head">
                        <strong><?=settings_h((string) $toolResult['title'])?></strong>
                        <?=settings_status_badge(!empty($toolResult['ok']), 'OK', 'Fehler')?>
                    </div>
                    <pre><?=settings_h((string) ($toolResult['output'] ?? 'Keine Ausgabe.'))?></pre>
                </div>
            <?php endif; ?>
        </section>
    <?php elseif ($activeTab === 'interfaces'): ?>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Schnittstellen</h2>
                    <p>SMTP kann bereits gespeichert und getestet werden. Jamf und ASM/Broker folgen im geschützten Critical-Bereich.</p>
                </div>
            </div>
            <div class="status-grid compact">
                <div class="status-card"><span class="status-label">Mail</span><strong><?=$smtpComplete ? 'Bereit' : 'Prüfen'?></strong><span><?=settings_h($smtpDbConfig ? 'DB-Override aktiv' : disown_mail_config_path())?></span></div>
                <div class="status-card"><span class="status-label">Jamf</span><strong>Status folgt</strong><span>API-Testbutton geplant</span></div>
                <div class="status-card"><span class="status-label">ASM/ADE</span><strong>Broker</strong><span>Tokenstatus wird später direkt gelesen</span></div>
            </div>
        </section>
        <form class="panel" method="post">
            <input type="hidden" name="csrf_token" value="<?=settings_h($_SESSION['csrf_token'])?>">
            <div class="panel-heading">
                <div>
                    <h2>SMTP-Server</h2>
                    <p>Werte übersteuern die bestehende Datei. Leeres Passwort bedeutet: vorhandenes Passwort behalten.</p>
                </div>
            </div>
            <div class="form-grid">
                <label class="field">
                    <span>Host</span>
                    <input name="MAIL_HOST" value="<?=settings_h((string) ($smtpConfig['MAIL_HOST'] ?? ''))?>" placeholder="smtp.example.org" <?=$canWrite ? '' : 'disabled'?>>
                </label>
                <label class="field">
                    <span>Port</span>
                    <input name="MAIL_PORT" type="number" min="1" max="65535" value="<?=settings_h((string) ($smtpConfig['MAIL_PORT'] ?? ''))?>" placeholder="587" <?=$canWrite ? '' : 'disabled'?>>
                </label>
                <label class="field">
                    <span>Verschlüsselung</span>
                    <select name="MAIL_ENCRYPTION" <?=$canWrite ? '' : 'disabled'?>>
                        <?php $encryption = (string) ($smtpConfig['MAIL_ENCRYPTION'] ?? ''); ?>
                        <option value="" <?=$encryption === '' ? 'selected' : ''?>>Keine/Auto</option>
                        <option value="tls" <?=$encryption === 'tls' ? 'selected' : ''?>>STARTTLS</option>
                        <option value="ssl" <?=$encryption === 'ssl' ? 'selected' : ''?>>SSL/TLS</option>
                    </select>
                </label>
                <label class="field">
                    <span>Benutzer</span>
                    <input name="MAIL_USERNAME" value="<?=settings_h((string) ($smtpConfig['MAIL_USERNAME'] ?? ''))?>" autocomplete="username" <?=$canWrite ? '' : 'disabled'?>>
                </label>
                <label class="field">
                    <span>Passwort</span>
                    <input name="MAIL_PASSWORD" type="password" placeholder="<?=!empty($smtpConfig['MAIL_PASSWORD']) ? 'vorhandenes Passwort behalten' : 'Passwort fehlt'?>" autocomplete="new-password" <?=$canWrite ? '' : 'disabled'?>>
                </label>
                <label class="field">
                    <span>Absender</span>
                    <input name="MAIL_FROM" type="email" value="<?=settings_h((string) ($smtpConfig['MAIL_FROM'] ?? ''))?>" placeholder="mdm@bbs-einbeck.de" <?=$canWrite ? '' : 'disabled'?>>
                </label>
                <label class="field field-wide">
                    <span>Testempfänger</span>
                    <input name="smtp_test_recipient" type="email" value="<?=settings_h($currentAdminUser ?: '')?>" placeholder="name@bbs-einbeck.de" <?=$canWrite ? '' : 'disabled'?>>
                </label>
            </div>
            <div class="panel-actions">
                <span class="subtle">Quelle: <?=settings_h($smtpDbConfig ? 'Datenbank überschreibt Datei' : disown_mail_config_path())?></span>
                <div class="action-group">
                    <button class="button button-secondary" type="submit" name="settings_action" value="test_smtp" <?=$canWrite ? '' : 'disabled'?>>Testmail senden</button>
                    <button class="button button-primary" type="submit" name="settings_action" value="save_smtp" <?=$canWrite ? '' : 'disabled'?>>SMTP speichern</button>
                </div>
            </div>
        </form>
    <?php elseif ($activeTab === 'security'): ?>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Sicherheit</h2>
                    <p>Kritische Einstellungen bekommen eine eigene zeitlich begrenzte Entsperrung.</p>
                </div>
                <?=settings_status_badge($criticalActive, $criticalActive ? 'Entsperrt' : 'Gesperrt', 'Gesperrt')?>
            </div>
            <div class="status-grid compact security-status-grid">
                <div class="status-card">
                    <span class="status-label">Critical Mode</span>
                    <strong><?=$criticalActive ? 'Aktiv' : 'Gesperrt'?></strong>
                    <span><?=$criticalActive ? 'Noch ca. ' . settings_h((string) ceil($criticalRemaining / 60)) . ' Minuten' : 'Keine kritischen Aktionen freigegeben'?></span>
                    <?=settings_status_badge($criticalActive, 'Aktiv', 'Gesperrt')?>
                </div>
                <div class="status-card">
                    <span class="status-label">Kennwort</span>
                    <strong><?=$criticalPasswordConfigured ? 'Gesetzt' : 'Fehlt'?></strong>
                    <span>Separat zum IServ/Adminlogin</span>
                    <?=settings_status_badge($criticalPasswordConfigured, 'Bereit', 'Einrichten')?>
                </div>
                <div class="status-card">
                    <span class="status-label">Gültigkeit</span>
                    <strong>10 Min</strong>
                    <span>Danach sperrt sich der Modus automatisch</span>
                    <?=settings_status_badge(true)?>
                </div>
                <div class="status-card">
                    <span class="status-label">Root-Helper</span>
                    <strong><?=$rootHelperCurrent ? 'Installiert' : ($rootHelperInstalled ? 'Update nötig' : 'Fehlt')?></strong>
                    <span><?=$rootHelperInstalled ? settings_h($rootHelperPath) : 'Einmalig per Root-CLI installieren'?></span>
                    <?=settings_status_badge($rootHelperCurrent, 'Bereit', $rootHelperInstalled ? 'Aktualisieren' : 'Einrichten')?>
                </div>
            </div>
        </section>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Critical Mode</h2>
                    <p>Für Rechte-Reparatur, künftige Cron-Neustarts, API-Secrets und andere riskante Änderungen.</p>
                </div>
            </div>
            <?php if (!$criticalPasswordConfigured): ?>
                <div class="security-callout">
                    <strong>Critical Mode ist noch nicht eingerichtet.</strong>
                    <span>Das Kennwort wird bewusst nicht im Web gesetzt. Dadurch kann ein eingeloggter Admin es nicht einfach selbst erzeugen, wenn der Adminzugang kompromittiert wäre.</span>
                </div>
                <div class="maintenance-card">
                    <div>
                        <strong>Serverseitig einrichten</strong>
                        <span>Einmalig als root / Server-Admin ausführen. Danach kann hier nur noch entsperrt werden.</span>
                    </div>
                    <div class="maintenance-command">
                        <code>sudo php <?=settings_h($criticalPasswordScript)?></code>
                    </div>
                </div>
            <?php elseif ($criticalActive): ?>
                <div class="security-callout ok">
                    <strong>Critical Mode ist aktiv.</strong>
                    <span>Du kannst die vorbereiteten Wartungsbereiche jetzt nutzen. Root-Aktionen bleiben zusätzlich auf die fest erlaubten Helper-Kommandos begrenzt.</span>
                </div>
                <form method="post" class="panel-actions no-border">
                    <input type="hidden" name="csrf_token" value="<?=settings_h($_SESSION['csrf_token'])?>">
                    <span class="subtle">Automatische Sperre in ca. <?=settings_h((string) ceil($criticalRemaining / 60))?> Minuten.</span>
                    <button class="button button-secondary" type="submit" name="settings_action" value="lock_critical" <?=$canWrite ? '' : 'disabled'?>>Jetzt sperren</button>
                </form>
            <?php else: ?>
                <form method="post" class="critical-form">
                    <input type="hidden" name="csrf_token" value="<?=settings_h($_SESSION['csrf_token'])?>">
                    <input type="hidden" name="settings_action" value="unlock_critical">
                    <div class="form-grid">
                        <label class="field field-wide">
                            <span>Bestätigungskennwort</span>
                            <input name="critical_unlock_password" type="password" autocomplete="current-password" <?=$canWrite ? '' : 'disabled'?>>
                        </label>
                    </div>
                    <div class="panel-actions">
                        <span class="subtle">Entsperrt kritische Aktionen für 10 Minuten und schreibt einen Audit-Log-Eintrag.</span>
                        <button class="button button-primary" type="submit" <?=$canWrite ? '' : 'disabled'?>>Critical Mode entsperren</button>
                    </div>
                </form>
                <div class="security-callout muted">
                    <strong>Kennwort ändern</strong>
                    <span>Nur serverseitig: <code>sudo php <?=settings_h($criticalPasswordScript)?></code></span>
                </div>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>System</h2>
                    <p>Betriebsstatus auf einen Blick. Technische Details bleiben einklappbar.</p>
                </div>
            </div>
            <div class="status-grid compact">
                <div class="status-card">
                    <span class="status-label">Version</span>
                    <strong><?=settings_h($appVersion)?></strong>
                    <span><a href="<?=settings_h($sourceRepoUrl)?>" target="_blank" rel="noopener noreferrer">Public GitHub</a></span>
                    <?=settings_status_badge(true)?>
                </div>
                <div class="status-card">
                    <span class="status-label">Betrieb</span>
                    <strong><?=$isDevMode ? 'DEV' : 'PROD'?></strong>
                    <span><?=settings_h((string) count($cronEntries))?> Cronjobs · <?=settings_h((string) $readableLogCount)?> lesbare Logs</span>
                    <?=settings_status_badge(count($cronEntries) > 0 && $readableLogCount > 0, 'OK', 'Prüfen')?>
                </div>
                <div class="status-card">
                    <span class="status-label">SMTP</span>
                    <strong><?=$smtpComplete ? 'Bereit' : 'Prüfen'?></strong>
                    <span><?=settings_h($smtpDbConfig ? 'DB-Override aktiv' : 'Datei-Basis')?></span>
                    <?=settings_status_badge($smtpComplete)?>
                </div>
            </div>
        </section>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Backups</h2>
                    <p>Code und Datenbank werden gemeinsam unter <code>/root/disown-backups</code> gesichert.</p>
                </div>
                <?=settings_status_badge($rootHelperCurrent, 'Bereit', $rootHelperInstalled ? 'Helper aktualisieren' : 'Helper fehlt')?>
            </div>
            <div class="maintenance-card">
                <div>
                    <strong>DISOWN-Sicherung</strong>
                    <span>Status prüfen oder nach ausdrücklicher Freigabe ein neues Code- und DB-Backup erzeugen.</span>
                </div>
                <div class="maintenance-command">
                    <?php if (!$rootHelperCurrent): ?>
                        <code>sudo <?=settings_h($rootHelperInstaller)?> install</code>
                    <?php else: ?>
                        <form method="post" class="inline-tool-form">
                            <input type="hidden" name="csrf_token" value="<?=settings_h($_SESSION['csrf_token'])?>">
                            <button class="button button-secondary" type="submit" name="settings_action" value="run_backup_status" <?=$canWrite ? '' : 'disabled'?>>Backup-Status prüfen</button>
                        </form>
                        <?php if ($criticalActive): ?>
                            <form method="post" class="critical-root-form">
                                <input type="hidden" name="csrf_token" value="<?=settings_h($_SESSION['csrf_token'])?>">
                                <label class="confirmation-check">
                                    <input type="checkbox" name="confirm_root_action" value="1" required>
                                    <span>Neues Code- und Datenbank-Backup bestätigen</span>
                                </label>
                                <button class="button button-primary" type="submit" name="settings_action" value="create_backup" <?=$canWrite ? '' : 'disabled'?>>Backup jetzt erstellen</button>
                            </form>
                        <?php else: ?>
                            <a class="button button-secondary" href="<?=settings_h(settings_url(['tab' => 'security']))?>">Critical Mode entsperren</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Werkzeuge</h2>
                    <p>Nur Dinge, die im Betrieb oder für Wartung wirklich relevant sind.</p>
                </div>
            </div>
            <div class="file-list">
                <?php foreach ($systemToolStatuses as $status): ?>
                    <div class="file-row">
                        <span><?=settings_h($status['label'])?></span>
                        <?=settings_status_badge($status['readable'] || is_dir($status['path']), is_dir($status['path']) ? 'Ordner' : 'Lesbar', $status['exists'] ? 'Nicht lesbar' : 'Fehlt')?>
                        <small><?=settings_h($status['path'])?> · Stand <?=settings_h(settings_display_time($status['mtime']))?></small>
                        <?php if ($status['label'] === 'Smoke-Check'): ?>
                            <form method="post" class="inline-tool-form">
                                <input type="hidden" name="csrf_token" value="<?=settings_h($_SESSION['csrf_token'])?>">
                                <button class="button button-secondary" type="submit" name="settings_action" value="run_smoke_check" <?=$canWrite && $status['readable'] ? '' : 'disabled'?>>Ausführen</button>
                            </form>
                        <?php elseif ($status['label'] === 'Wartung Rechte'): ?>
                            <form method="post" class="inline-tool-form">
                                <input type="hidden" name="csrf_token" value="<?=settings_h($_SESSION['csrf_token'])?>">
                                <button class="button button-secondary" type="submit" name="settings_action" value="run_permissions_check" <?=$canWrite && $status['readable'] ? '' : 'disabled'?>>Prüfen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="file-row"><span>Mail-Konfig</span><?=settings_status_badge($mailConfigStatus['readable'])?><small><?=settings_h($mailConfigStatus['path'])?></small></div>
                <div class="file-row"><span>Notify-Konfig</span><?=settings_status_badge($notifyConfigStatus['readable'])?><small><?=settings_h($notifyConfigStatus['path'])?></small></div>
            </div>
            <?php if ($toolResult): ?>
                <div class="tool-result <?=$toolResult['ok'] ? 'ok' : 'warn'?>">
                    <div class="tool-result-head">
                        <strong><?=settings_h((string) ($toolResult['title'] ?? 'Werkzeug'))?></strong>
                        <div class="tool-result-actions">
                            <?=settings_status_badge(!empty($toolResult['ok']), 'OK', 'Prüfen')?>
                            <a class="button button-secondary tool-close-button" href="<?=settings_h(settings_current_url_without_tool_result())?>">Schließen</a>
                        </div>
                    </div>
                    <pre><?=settings_h((string) ($toolResult['output'] ?? 'Keine Ausgabe.'))?></pre>
                </div>
            <?php endif; ?>
            <details class="technical-details">
                <summary>Technische Details anzeigen</summary>
                <div class="file-list">
                    <div class="file-row">
                        <span>Git</span>
                        <?=settings_status_badge($gitInfo['available'], $gitInfo['dirty'] ? 'Dirty' : 'Lesbar', 'CLI-only')?>
                        <small><?=$gitInfo['available'] ? settings_h($gitInfo['branch'] . ' · ' . $gitInfo['commit']) : 'Git-Stand wird bei Bedarf per CLI geprüft'?></small>
                    </div>
                    <?php foreach ($systemFileStatuses as $status): ?>
                        <div class="file-row">
                            <span><?=settings_h($status['label'])?></span>
                            <?=settings_status_badge($status['readable'] || is_dir($status['path']), is_dir($status['path']) ? 'Ordner' : 'Optional', 'Optional')?>
                            <small><?=settings_h($status['path'])?> · Stand <?=settings_h(settings_display_time($status['mtime']))?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        </section>
    <?php endif; ?>

    <footer class="footer">
        © 2026 Marc Schulz · <a href="<?=settings_h($sourceRepoUrl)?>" target="_blank" rel="noopener noreferrer">Version <?=settings_h($appVersion)?></a> · Einstellungen
    </footer>
</div>
</body>
</html>
