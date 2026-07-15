<?php

declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

function disown_notify_config_path(): string
{
    return getenv('DISOWN_NOTIFY_CONFIG') ?: '/etc/disown/notify.conf';
}

function disown_mail_config_path(): string
{
    return getenv('DISOWN_MAIL_CONFIG') ?: '/etc/disown/mail.conf';
}

function disown_notify_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = disown_notify_config_path();
    if (!is_readable($path)) {
        $config = [];
        return $config;
    }

    $parsed = parse_ini_file($path);
    $config = is_array($parsed) ? $parsed : [];
    return $config;
}

function disown_notify_admin_url(): string
{
    $isDevMode = basename(__DIR__) === 'disown-dev';
    $default = $isDevMode
        ? 'https://sicher.bbs-einbeck.de/disown-dev/admin'
        : 'https://sicher.bbs-einbeck.de/disown/admin';

    return trim((string) (disown_notify_config()['APP_URL'] ?? $default));
}

function disown_notify_recipients(): array
{
    $config = disown_notify_config();
    $recipientText = trim((string) (getenv('DISOWN_PUSH_MAIL_TO') ?: ($config['PUSH_MAIL_TO'] ?? ($config['NOTIFY_TO'] ?? ''))));

    return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', $recipientText) ?: []))));
}

function disown_settings_ensure_table(mysqli $mysqli): bool
{
    return (bool) $mysqli->query(
        "CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(96) NOT NULL PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by VARCHAR(128) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function disown_setting_get(mysqli $mysqli, string $key, string $default = ''): string
{
    if (!disown_settings_ensure_table($mysqli)) {
        return $default;
    }

    $stmt = $mysqli->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (string) ($row['setting_value'] ?? $default) : $default;
}

function disown_setting_set(mysqli $mysqli, string $key, string $value, string $updatedBy): bool
{
    if (!disown_settings_ensure_table($mysqli)) {
        return false;
    }

    $stmt = $mysqli->prepare(
        "INSERT INTO app_settings (setting_key, setting_value, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)"
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('sss', $key, $value, $updatedBy);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function disown_push_mail_enabled(mysqli $mysqli): bool
{
    return disown_setting_get($mysqli, 'push_mail_enabled', '0') === '1';
}

function disown_push_mail_set_enabled(mysqli $mysqli, bool $enabled, string $updatedBy): bool
{
    return disown_setting_set($mysqli, 'push_mail_enabled', $enabled ? '1' : '0', $updatedBy);
}

function disown_push_mail_status(mysqli $mysqli): array
{
    $recipients = disown_notify_recipients();
    return [
        'enabled' => disown_push_mail_enabled($mysqli),
        'recipients' => $recipients,
        'recipient_count' => count($recipients),
        'admin_url' => disown_notify_admin_url(),
    ];
}

function disown_send_new_request_push_mail(mysqli $mysqli, int $requestId): void
{
    if ($requestId <= 0 || !disown_push_mail_enabled($mysqli)) {
        return;
    }

    $request = disown_notify_load_request($mysqli, $requestId);

    $isDevMode = basename(__DIR__) === 'disown-dev';
    if ($isDevMode && !filter_var(disown_notify_config()['PUSH_MAIL_SEND_IN_DEV'] ?? false, FILTER_VALIDATE_BOOL)) {
        disown_notify_log($mysqli, $requestId, 'PUSH_MAIL_SKIPPED_DEV', 'E-Mail-Push ist aktiv, DEV-Modus sendet aber keine echte Mail. ' . disown_notify_request_summary($request));
        return;
    }

    $recipients = disown_notify_recipients();
    if (!$recipients) {
        disown_notify_log($mysqli, $requestId, 'PUSH_MAIL_FAILED', 'Keine Empfänger in ' . disown_notify_config_path() . ' konfiguriert.');
        return;
    }

    $invalidRecipients = array_filter($recipients, static fn($address) => !filter_var($address, FILTER_VALIDATE_EMAIL));
    if ($invalidRecipients) {
        disown_notify_log($mysqli, $requestId, 'PUSH_MAIL_FAILED', 'Ungültige Empfänger: ' . implode(', ', $invalidRecipients));
        return;
    }

    $mailConfig = parse_ini_file(disown_mail_config_path());
    if ($mailConfig === false || empty($mailConfig['MAIL_HOST']) || empty($mailConfig['MAIL_PORT']) || empty($mailConfig['MAIL_USERNAME']) || empty($mailConfig['MAIL_PASSWORD']) || empty($mailConfig['MAIL_FROM'])) {
        disown_notify_log($mysqli, $requestId, 'PUSH_MAIL_FAILED', 'SMTP-Konfiguration fehlt oder ist unvollständig: ' . disown_mail_config_path());
        return;
    }

    $subjectPrefix = $isDevMode ? '[DEV] ' : '';
    $subjectName = trim((string) ($request['full_name'] ?? ''));
    $subject = $subjectPrefix . 'DISOWN: Neuer iPad-Antrag #' . $requestId . ($subjectName !== '' ? ' - ' . $subjectName : '');
    $body = disown_notify_text_body($request, $requestId);
    $htmlBody = disown_notify_html_body($request, $requestId);

    try {
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
        foreach ($recipients as $recipient) {
            $mail->addAddress($recipient);
        }
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $body;
        $mail->isHTML(true);
        $mail->send();
        disown_notify_log($mysqli, $requestId, 'PUSH_MAIL_SENT', 'E-Mail-Push versendet an ' . count($recipients) . ' Empfänger.');
    } catch (Throwable $e) {
        disown_notify_log($mysqli, $requestId, 'PUSH_MAIL_FAILED', 'Mailversand fehlgeschlagen: ' . $e->getMessage());
    }
}

function disown_notify_load_request(mysqli $mysqli, int $requestId): array
{
    $stmt = $mysqli->prepare(
        'SELECT id, full_name, username, class_name, email, private_email, device_name, serial, requested_release_date, created_at
         FROM requests
         WHERE id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row) ? $row : [];
}

function disown_notify_value($value): string
{
    $value = trim((string) $value);
    return $value !== '' ? $value : '-';
}

function disown_notify_text_body(array $request, int $requestId): string
{
    return implode(PHP_EOL, [
        'DISOWN E-Mail-Push',
        'Neuer iPad-Freigabeantrag eingegangen.',
        '',
        'Antrag: #' . $requestId,
        'Name: ' . disown_notify_value($request['full_name'] ?? ''),
        'Benutzer: ' . disown_notify_value($request['username'] ?? ''),
        'Klasse: ' . disown_notify_value($request['class_name'] ?? ''),
        'Schulische E-Mail: ' . disown_notify_value($request['email'] ?? ''),
        'Private E-Mail: ' . disown_notify_value($request['private_email'] ?? ''),
        'Gerät: ' . disown_notify_value($request['device_name'] ?? ''),
        'Seriennummer: ' . disown_notify_value($request['serial'] ?? ''),
        'Wunschdatum: ' . disown_notify_value($request['requested_release_date'] ?? ''),
        'Zeit: ' . date('d.m.Y H:i'),
        '',
        'Adminportal:',
        disown_notify_admin_url(),
    ]);
}

function disown_notify_html_body(array $request, int $requestId): string
{
    $rows = [
        'Antrag' => '#' . $requestId,
        'Name' => disown_notify_value($request['full_name'] ?? ''),
        'Benutzer' => disown_notify_value($request['username'] ?? ''),
        'Klasse' => disown_notify_value($request['class_name'] ?? ''),
        'Schulische E-Mail' => disown_notify_value($request['email'] ?? ''),
        'Private E-Mail' => disown_notify_value($request['private_email'] ?? ''),
        'Gerät' => disown_notify_value($request['device_name'] ?? ''),
        'Seriennummer' => disown_notify_value($request['serial'] ?? ''),
        'Wunschdatum' => disown_notify_value($request['requested_release_date'] ?? ''),
        'Zeit' => date('d.m.Y H:i'),
    ];

    $rowHtml = '';
    foreach ($rows as $label => $value) {
        $rowHtml .= '<tr>'
            . '<th style="padding:7px 16px 7px 0;text-align:left;vertical-align:top;color:#5b6472;font-size:14px;font-weight:700;white-space:nowrap;">' . disown_notify_html($label) . '</th>'
            . '<td style="padding:7px 0;text-align:left;vertical-align:top;color:#121826;font-size:15px;font-weight:600;">' . disown_notify_html($value) . '</td>'
            . '</tr>';
    }

    $adminUrl = disown_notify_admin_url();
    return '<!doctype html><html><body style="margin:0;padding:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#121826;">'
        . '<div style="max-width:640px;margin:0;padding:24px;">'
        . '<div style="background:#ffffff;border:1px solid #dfe6ef;border-radius:14px;padding:22px 24px;box-shadow:0 8px 24px rgba(18,24,38,.08);">'
        . '<div style="font-size:12px;line-height:1.2;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#276ef1;margin-bottom:8px;">DISOWN E-Mail-Push</div>'
        . '<div style="font-size:22px;line-height:1.25;font-weight:800;color:#121826;margin-bottom:6px;">Neuer iPad-Freigabeantrag</div>'
        . '<div style="font-size:15px;line-height:1.45;color:#4b5565;margin-bottom:18px;">Ein neuer Antrag ist eingegangen und wartet im Adminportal.</div>'
        . '<table role="presentation" cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;margin:0 0 22px 0;">'
        . $rowHtml
        . '</table>'
        . '<a href="' . disown_notify_html($adminUrl) . '" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:999px;padding:10px 16px;font-size:14px;font-weight:800;">Adminportal öffnen</a>'
        . '<div style="margin-top:14px;font-size:12px;line-height:1.4;color:#6b7280;">' . disown_notify_html($adminUrl) . '</div>'
        . '</div>'
        . '</div>'
        . '</body></html>';
}

function disown_notify_html($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function disown_notify_request_summary(array $request): string
{
    if (!$request) {
        return 'Antragsdetails konnten nicht geladen werden.';
    }

    return 'Antrag: #' . (int) ($request['id'] ?? 0)
        . '; Name: ' . disown_notify_value($request['full_name'] ?? '')
        . '; Gerät: ' . disown_notify_value($request['device_name'] ?? '')
        . '; Seriennummer: ' . disown_notify_value($request['serial'] ?? '');
}

function disown_notify_log(mysqli $mysqli, int $requestId, string $action, string $details): void
{
    $stmt = $mysqli->prepare(
        'INSERT INTO request_audit_log (request_id, action, admin_user, details)
         VALUES (?, ?, ?, ?)'
    );
    if (!$stmt) {
        return;
    }

    $adminUser = 'system';
    $stmt->bind_param('isss', $requestId, $action, $adminUser, $details);
    $stmt->execute();
    $stmt->close();
}
