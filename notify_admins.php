<?php
require __DIR__ . '/db.php';
require __DIR__ . '/vendor/autoload.php';

$isDevMode = basename(__DIR__) === 'disown-dev';
$notifyConfigPath = getenv('DISOWN_NOTIFY_CONFIG') ?: '/etc/disown/notify.conf';
$mailConfigPath = getenv('DISOWN_MAIL_CONFIG') ?: '/etc/disown/mail.conf';
$today = date('Y-m-d');
$arguments = $argv ?? [];
$sendTestMail = in_array('--test-mail', $arguments, true);
$previewOnly = in_array('--preview', $arguments, true);
$forceSend = in_array('--force', $arguments, true);
$markCurrentOnly = in_array('--mark-current', $arguments, true);
$appUrlDefault = $isDevMode
    ? 'https://example.org/disown-dev/admin'
    : 'https://example.org/disown/admin';
$statePathDefault = $isDevMode
    ? '/tmp/disown-dev-notify-state.json'
    : '/var/lib/disown/notify-state.json';

$notifyConfig = [];
if (is_readable($notifyConfigPath)) {
    $notifyConfig = parse_ini_file($notifyConfigPath) ?: [];
}

$appUrl = trim((string) ($notifyConfig['APP_URL'] ?? $appUrlDefault));
$recipientText = trim((string) (getenv('DISOWN_NOTIFY_TO') ?: ($notifyConfig['NOTIFY_TO'] ?? '')));
$recipients = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', $recipientText) ?: []))));
$limit = max(1, min(200, (int) ($notifyConfig['NOTIFY_LIMIT'] ?? 50)));
$statePath = trim((string) (getenv('DISOWN_NOTIFY_STATE_FILE') ?: ($notifyConfig['NOTIFY_STATE_FILE'] ?? $statePathDefault)));

if ($sendTestMail) {
    $subjectPrefix = $isDevMode ? '[DEV] ' : '';
    $subject = $subjectPrefix . 'iPad-Freigaben: Test der Admin-Benachrichtigung';
    $body = implode(PHP_EOL, [
        'iPad-Freigaben - Testmail',
        'Stand: ' . date('d.m.Y H:i'),
        '',
        'Diese Mail prüft nur den Versand der Admin-Benachrichtigung.',
        'Es wurden keine Anträge verändert.',
        '',
        'Adminportal:',
        $appUrl,
    ]);

    try {
        send_notification_mail($recipients, $subject, $body, $mailConfigPath, $notifyConfigPath);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }

    echo "Testbenachrichtigung versendet an: " . implode(', ', $recipients) . PHP_EOL;
    exit(0);
}

$openCondition = "status IS NULL OR LOWER(TRIM(status)) <> 'erledigt' OR mail_sent = 0";
$dueStmt = $mysqli->prepare(
    "SELECT id, full_name, username, class_name, email, private_email, device_name, serial,
            requested_release_date, jamf_unenrolled, asm_manual_done, mail_sent
     FROM requests
     WHERE ({$openCondition})
       AND (requested_release_date IS NULL OR requested_release_date <= CURDATE() OR jamf_unenrolled = 1 OR asm_manual_done = 1)
     ORDER BY COALESCE(requested_release_date, DATE(created_at)) ASC, created_at ASC
     LIMIT ?"
);
$scheduledStmt = $mysqli->prepare(
    "SELECT id, full_name, username, class_name, email, private_email, device_name, serial,
            requested_release_date, jamf_unenrolled, asm_manual_done, mail_sent
     FROM requests
     WHERE ({$openCondition})
       AND requested_release_date > CURDATE()
       AND jamf_unenrolled = 0
     ORDER BY requested_release_date ASC, created_at ASC
     LIMIT ?"
);

if (!$dueStmt || !$scheduledStmt) {
    fwrite(STDERR, "Datenbankfehler: " . $mysqli->error . PHP_EOL);
    exit(1);
}

$dueStmt->bind_param('i', $limit);
$dueStmt->execute();
$dueRequests = $dueStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dueStmt->close();

$scheduledStmt->bind_param('i', $limit);
$scheduledStmt->execute();
$scheduledRequests = $scheduledStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$scheduledStmt->close();

$subjectPrefix = $isDevMode ? '[DEV] ' : '';
$subject = $subjectPrefix . 'iPad-Freigaben: ' . count($dueRequests) . ' fällig, ' . count($scheduledRequests) . ' terminiert';
$body = build_notification_body($dueRequests, $scheduledRequests, $appUrl, $today);

if ($previewOnly) {
    echo "Vorschau: Es wurde keine Mail versendet und kein Status gespeichert." . PHP_EOL . PHP_EOL;
    echo "Betreff: " . $subject . PHP_EOL . PHP_EOL;
    echo $body . PHP_EOL;
    exit(0);
}

$fingerprint = build_notification_fingerprint($dueRequests, $scheduledRequests);
$stateHandle = open_state_handle($statePath);
$state = read_notification_state($stateHandle);

if (!$dueRequests && !$scheduledRequests) {
    write_notification_state($stateHandle, $statePath, [
        'fingerprint' => $fingerprint,
        'last_checked_at' => date(DATE_ATOM),
        'last_sent_at' => $state['last_sent_at'] ?? null,
    ]);
    echo "Keine offenen oder terminierten Anträge gefunden." . PHP_EOL;
    exit(0);
}

if ($markCurrentOnly) {
    write_notification_state($stateHandle, $statePath, [
        'fingerprint' => $fingerprint,
        'last_checked_at' => date(DATE_ATOM),
        'last_sent_at' => $state['last_sent_at'] ?? null,
    ]);
    echo "Aktueller Benachrichtigungsstand wurde ohne Mail gespeichert." . PHP_EOL;
    exit(0);
}

if (!$forceSend && ($state['fingerprint'] ?? '') === $fingerprint) {
    write_notification_state($stateHandle, $statePath, [
        'fingerprint' => $fingerprint,
        'last_checked_at' => date(DATE_ATOM),
        'last_sent_at' => $state['last_sent_at'] ?? null,
    ]);
    echo "Keine Änderung seit der letzten Benachrichtigung. Es wurde keine Mail versendet." . PHP_EOL;
    exit(0);
}

if ($isDevMode) {
    write_notification_state($stateHandle, $statePath, [
        'fingerprint' => $fingerprint,
        'last_checked_at' => date(DATE_ATOM),
        'last_sent_at' => date(DATE_ATOM),
    ]);
    echo "DEV-Modus: Es wurde keine Mail versendet." . PHP_EOL . PHP_EOL;
    echo "Betreff: " . $subject . PHP_EOL . PHP_EOL;
    echo $body . PHP_EOL;
    exit(0);
}

try {
    send_notification_mail($recipients, $subject, $body, $mailConfigPath, $notifyConfigPath);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

write_notification_state($stateHandle, $statePath, [
    'fingerprint' => $fingerprint,
    'last_checked_at' => date(DATE_ATOM),
    'last_sent_at' => date(DATE_ATOM),
]);
echo "Benachrichtigung versendet an: " . implode(', ', $recipients) . PHP_EOL;
exit(0);

function build_notification_body(array $dueRequests, array $scheduledRequests, string $appUrl, string $today): string
{
    $lines = [];
    $lines[] = 'iPad-Freigaben - Admin-Hinweis';
    $lines[] = 'Stand: ' . date('d.m.Y H:i');
    $lines[] = '';
    $lines[] = 'Adminportal:';
    $lines[] = $appUrl;
    $lines[] = '';
    $lines[] = 'Heute/fällig zu bearbeiten: ' . count($dueRequests);
    $lines[] = format_request_list($dueRequests, $today);
    $lines[] = '';
    $lines[] = 'Terminiert für später: ' . count($scheduledRequests);
    $lines[] = format_request_list($scheduledRequests, $today);
    $lines[] = '';
    $lines[] = 'Hinweis: Dieses Skript informiert nur. Jamf, ASM und Mail werden weiterhin im Adminportal bearbeitet.';

    return implode(PHP_EOL, $lines);
}

function send_notification_mail(array $recipients, string $subject, string $body, string $mailConfigPath, string $notifyConfigPath): void
{
    if (!$recipients) {
        throw new RuntimeException("Keine Empfänger in {$notifyConfigPath} konfiguriert. Erwartet: NOTIFY_TO=admin@example.org,...");
    }

    $invalidRecipients = array_filter($recipients, static function ($address) {
        return !filter_var($address, FILTER_VALIDATE_EMAIL);
    });
    if ($invalidRecipients) {
        throw new RuntimeException('Ungültige Empfänger: ' . implode(', ', $invalidRecipients));
    }

    $mailConfig = parse_ini_file($mailConfigPath);
    if ($mailConfig === false || empty($mailConfig['MAIL_HOST']) || empty($mailConfig['MAIL_PORT']) || empty($mailConfig['MAIL_USERNAME']) || empty($mailConfig['MAIL_PASSWORD']) || empty($mailConfig['MAIL_FROM'])) {
        throw new RuntimeException("SMTP-Konfiguration fehlt oder ist unvollständig: {$mailConfigPath}");
    }

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
        $mail->setFrom($mailConfig['MAIL_FROM'], 'Example School, Team Mobile Device Management');
        foreach ($recipients as $recipient) {
            $mail->addAddress($recipient);
        }
        $mail->Subject = $subject;
        $mail->Body = build_notification_html($body);
        $mail->AltBody = $body;
        $mail->isHTML(true);
        $mail->send();
    } catch (Throwable $e) {
        throw new RuntimeException('Mailversand fehlgeschlagen: ' . $e->getMessage(), 0, $e);
    }
}

function build_notification_html(string $body): string
{
    $lines = preg_split('/\R/', $body) ?: [];
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.45;color:#111;">';
    $preLines = [];

    $flushPre = static function () use (&$html, &$preLines): void {
        if (!$preLines) {
            return;
        }
        $html .= '<pre style="font-family:&quot;Courier New&quot;,Courier,monospace;font-size:15px;line-height:1.45;white-space:pre-wrap;margin:6px 0 18px;">'
            . htmlspecialchars(implode(PHP_EOL, $preLines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</pre>';
        $preLines = [];
    };

    foreach ($lines as $line) {
        if (preg_match('/^#\d+:/', $line)) {
            $preLines[] = $line;
            continue;
        }

        $flushPre();
        $escapedLine = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($line === '') {
            $html .= '<br>';
        } elseif (preg_match('/^https?:\/\//', $line)) {
            $html .= '<div><a href="' . $escapedLine . '">' . $escapedLine . '</a></div>';
        } else {
            $html .= '<div>' . $escapedLine . '</div>';
        }
    }

    $flushPre();
    return $html . '</div>';
}

function build_notification_fingerprint(array $dueRequests, array $scheduledRequests): string
{
    $normalize = static function (array $request): array {
        return [
            'id' => (int) ($request['id'] ?? 0),
            'date' => (string) ($request['requested_release_date'] ?? ''),
            'jamf' => (int) !empty($request['jamf_unenrolled']),
            'asm' => (int) !empty($request['asm_manual_done']),
            'mail' => (int) !empty($request['mail_sent']),
            'serial' => (string) ($request['serial'] ?? ''),
        ];
    };

    $payload = [
        'due' => array_map($normalize, $dueRequests),
        'scheduled' => array_map($normalize, $scheduledRequests),
    ];

    return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function open_state_handle(string $statePath)
{
    $stateDir = dirname($statePath);
    if (!is_dir($stateDir) && !mkdir($stateDir, 0770, true) && !is_dir($stateDir)) {
        throw new RuntimeException("Statusverzeichnis kann nicht erstellt werden: {$stateDir}");
    }

    $handle = fopen($statePath, 'c+');
    if (!$handle) {
        throw new RuntimeException("Statusdatei kann nicht geöffnet werden: {$statePath}");
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException("Statusdatei kann nicht gesperrt werden: {$statePath}");
    }

    return $handle;
}

function read_notification_state($handle): array
{
    rewind($handle);
    $contents = stream_get_contents($handle);
    if (!is_string($contents) || trim($contents) === '') {
        return [];
    }

    $state = json_decode($contents, true);
    return is_array($state) ? $state : [];
}

function write_notification_state($handle, string $statePath, array $state): void
{
    $state['updated_at'] = date(DATE_ATOM);
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Statusdaten konnten nicht als JSON kodiert werden.');
    }

    rewind($handle);
    if (!ftruncate($handle, 0)) {
        throw new RuntimeException("Statusdatei kann nicht geleert werden: {$statePath}");
    }
    if (fwrite($handle, $json . PHP_EOL) === false) {
        throw new RuntimeException("Statusdatei kann nicht geschrieben werden: {$statePath}");
    }
    fflush($handle);
}

function format_request_list(array $requests, string $today): string
{
    if (!$requests) {
        return '- keine';
    }

    $lines = [];
    foreach ($requests as $request) {
        $name = trim((string) ($request['full_name'] ?? ''));
        $serial = trim((string) ($request['serial'] ?? ''));
        $lines[] = sprintf(
            '#%d: %s, %s',
            (int) $request['id'],
            $serial !== '' ? $serial : 'keine Seriennummer',
            $name !== '' ? $name : 'unbekannt'
        );
    }

    return implode(PHP_EOL, $lines);
}

function next_step_label(array $request): string
{
    if (empty($request['jamf_unenrolled'])) {
        return 'Jamf';
    }
    if (empty($request['asm_manual_done'])) {
        return 'ASM';
    }
    if (empty($request['mail_sent'])) {
        return 'Mail';
    }

    return 'kein offener Schritt';
}
