<?php
require __DIR__ . '/auth.php';
disown_require_admin();
require __DIR__ . '/db.php';
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/notify.php';

$currentAdminUser = disown_current_admin_user();
$canWrite = disown_can_write();
$appBasePath = rtrim(disown_admin_base_path(), '/');
$adminPath = $appBasePath . '/admin.php';
$adePath = $appBasePath . '/ade.php';
$kukPath = $appBasePath . '/kuk/';
$auditLogPath = $appBasePath . '/audit_log.php';
$logoutPath = $appBasePath . '/logout.php';
$faviconPath = $appBasePath . '/favicon.svg';
$searchJsUrl = disown_asset_url($appBasePath, 'assets/search.js');
$kukCssUrl = disown_asset_url($appBasePath, 'assets/kuk.css');
$kukJsUrl = disown_asset_url($appBasePath, 'assets/kuk.js');

$syncMessage = '';
$syncError = '';
$mailMessage = '';
$mailError = '';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

if (!empty($_SESSION['kuk_sync_message'])) {
    $syncMessage = (string) $_SESSION['kuk_sync_message'];
    unset($_SESSION['kuk_sync_message']);
}
if (!empty($_SESSION['kuk_sync_error'])) {
    $syncError = (string) $_SESSION['kuk_sync_error'];
    unset($_SESSION['kuk_sync_error']);
}
if (!empty($_SESSION['kuk_mail_message'])) {
    $mailMessage = (string) $_SESSION['kuk_mail_message'];
    unset($_SESSION['kuk_mail_message']);
}
if (!empty($_SESSION['kuk_mail_error'])) {
    $mailError = (string) $_SESSION['kuk_mail_error'];
    unset($_SESSION['kuk_mail_error']);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['send_kuk_inactivity_mail'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        die('Ungültiges Formular. Bitte lade die Seite neu und versuche es erneut.');
    }
    disown_require_write();

    $returnTo = (string) ($_POST['return_to'] ?? $kukPath);
    if (!str_starts_with($returnTo, $appBasePath . '/')) {
        $returnTo = $kukPath;
    }

    $recipient = trim((string) ($_POST['mail_to'] ?? ''));
    $subject = trim((string) ($_POST['mail_subject'] ?? ''));
    $body = trim((string) ($_POST['mail_body'] ?? ''));
    $serial = strtoupper(trim((string) ($_POST['mail_serial'] ?? '')));
    $deviceLabel = trim((string) ($_POST['mail_device'] ?? ''));
    $mailKind = trim((string) ($_POST['mail_kind'] ?? 'inactivity'));
    if (!in_array($mailKind, ['inactivity', 'ios'], true)) {
        $mailKind = 'inactivity';
    }

    if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL) || $subject === '' || $body === '') {
        $_SESSION['kuk_mail_error'] = 'E-Mail-Adresse, Betreff oder Nachricht fehlen oder sind ungültig.';
        header('Location: ' . $returnTo);
        exit;
    }

    if ($isDevMode) {
        kuk_record_device_mail($mysqli, $serial, $mailKind, $recipient, $currentAdminUser !== '' ? $currentAdminUser : 'dev');
        $_SESSION['kuk_mail_message'] = 'DEV-Modus: KUK-Mail wurde nicht versendet. Vorschau für ' . kuk_h($recipient) . ' wurde verarbeitet und lokal protokolliert.';
        header('Location: ' . kuk_mail_success_return_url($returnTo, $kukPath));
        exit;
    }

    $mailConfig = disown_mail_config($mysqli);
    if (!disown_mail_config_is_complete($mailConfig)) {
        $_SESSION['kuk_mail_error'] = 'SMTP-Konfiguration fehlt oder ist unvollständig. Bitte Einstellungen prüfen.';
        header('Location: ' . $returnTo);
        exit;
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
        $mail->setFrom($mailConfig['MAIL_FROM'], 'MDM Team');
        $mail->addAddress($recipient);
        $mail->Subject = $subject;

        $safeBody = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($deviceLabel !== '') {
            $safeDevice = htmlspecialchars($deviceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeBody = str_replace($safeDevice, '<strong>' . $safeDevice . '</strong>', $safeBody);
        }
        if ($serial !== '') {
            $safeSerial = htmlspecialchars($serial, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeBody = str_replace($safeSerial, '<strong>' . $safeSerial . '</strong>', $safeBody);
        }
        $mail->Body = nl2br($safeBody);
        $mail->AltBody = $body;
        $mail->isHTML(true);
        $mail->send();

        kuk_record_device_mail($mysqli, $serial, $mailKind, $recipient, $currentAdminUser !== '' ? $currentAdminUser : 'admin');
        $_SESSION['kuk_mail_message'] = 'KUK-Mail erfolgreich gesendet an ' . kuk_h($recipient) . '.';
        $returnTo = kuk_mail_success_return_url($returnTo, $kukPath);
    } catch (Exception $e) {
        $_SESSION['kuk_mail_error'] = 'KUK-Mail konnte nicht gesendet werden: ' . $e->getMessage();
    }

    header('Location: ' . $returnTo);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['sync_kuk'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        die('Ungültiges Formular. Bitte lade die Seite neu und versuche es erneut.');
    }
    disown_require_write();

    $phpCli = '/usr/bin/php';
    if (!is_executable($phpCli)) {
        $phpCli = PHP_BINDIR . '/php';
    }
    if (!is_executable($phpCli)) {
        $phpCli = PHP_BINARY;
    }

    $command = escapeshellarg($phpCli) . ' ' . escapeshellarg(__DIR__ . '/sync_kuk_devices.php') . ' 2>&1';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    if ($exitCode === 0) {
        $_SESSION['kuk_sync_message'] = trim(implode("\n", $output)) ?: 'KUK-Sync abgeschlossen.';
    } else {
        $_SESSION['kuk_sync_error'] = trim(implode("\n", $output)) ?: 'KUK-Sync fehlgeschlagen.';
    }

    header('Location: ' . $kukPath);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$filter = trim((string) ($_GET['filter'] ?? 'all'));
$validFilters = ['all', 'old-ios', 'no-owner', 'stale', 'problem'];
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}
$contact = trim((string) ($_GET['contact'] ?? 'all'));
$validContactFilters = ['all', 'pending', 'sent'];
if (!in_array($contact, $validContactFilters, true)) {
    $contact = 'all';
}
if (!in_array($filter, ['old-ios', 'stale'], true)) {
    $contact = 'all';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$fromSql = 'FROM kuk_devices d LEFT JOIN kuk_device_workflow w ON w.serial = d.serial';
$where = [];
$params = [];
$types = '';

if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(d.device_name LIKE ? OR d.serial LIKE ? OR d.asset_tag LIKE ? OR d.owner_name LIKE ? OR d.owner_username LIKE ? OR d.owner_email LIKE ? OR d.model_name LIKE ? OR d.os_version LIKE ?)';
    for ($i = 0; $i < 8; $i++) {
        $params[] = $like;
        $types .= 's';
    }
}

if ($filter === 'no-owner') {
    $where[] = "(COALESCE(d.owner_name, '') = '' AND COALESCE(d.owner_username, '') = '' AND COALESCE(d.owner_email, '') = '')";
}
if ($filter === 'problem') {
    $where[] = "(d.last_checkin IS NULL OR d.last_checkin < DATE_SUB(NOW(), INTERVAL 30 DAY) OR COALESCE(d.owner_email, '') = '' OR CAST(SUBSTRING_INDEX(d.os_version, '.', 1) AS UNSIGNED) < 26)";
}

if ($contact === 'sent') {
    if ($filter === 'old-ios') {
        $where[] = 'w.ios_mail_sent_at IS NOT NULL';
    } elseif ($filter === 'stale') {
        $where[] = 'w.inactivity_mail_sent_at IS NOT NULL';
    } else {
        $where[] = '(w.ios_mail_sent_at IS NOT NULL OR w.inactivity_mail_sent_at IS NOT NULL)';
    }
} elseif ($contact === 'pending') {
    if ($filter === 'old-ios') {
        $where[] = 'w.ios_mail_sent_at IS NULL';
    } elseif ($filter === 'stale') {
        $where[] = 'w.inactivity_mail_sent_at IS NULL';
    } else {
        $where[] = '(w.ios_mail_sent_at IS NULL AND w.inactivity_mail_sent_at IS NULL)';
    }
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$orderSql = 'ORDER BY COALESCE(d.last_checkin, d.enrollment_date, d.first_seen_at) DESC, d.asset_tag ASC, d.serial ASC';
if ($filter === 'stale') {
    $orderSql = 'ORDER BY d.last_checkin IS NOT NULL ASC, d.last_checkin ASC, d.asset_tag ASC, d.serial ASC';
} elseif ($filter === 'old-ios') {
    $orderSql = "ORDER BY
        d.os_version IS NULL ASC,
        CAST(SUBSTRING_INDEX(d.os_version, '.', 1) AS UNSIGNED) ASC,
        CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT(d.os_version, '.0'), '.', 2), '.', -1) AS UNSIGNED) ASC,
        CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT(d.os_version, '.0.0'), '.', 3), '.', -1) AS UNSIGNED) ASC,
        d.asset_tag ASC,
        d.serial ASC";
} elseif ($filter === 'problem') {
    $orderSql = "ORDER BY
        d.last_checkin IS NOT NULL ASC,
        d.last_checkin ASC,
        CAST(SUBSTRING_INDEX(d.os_version, '.', 1) AS UNSIGNED) ASC,
        d.asset_tag ASC,
        d.serial ASC";
}
$baseParams = ['q' => $q, 'filter' => $filter, 'contact' => $contact];
$exportUrl = kuk_url(array_merge($baseParams, ['export' => 'csv']));
$problemUrl = kuk_url(['filter' => 'problem']);

if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $mysqli->prepare("SELECT d.*, w.inactivity_mail_sent_at, w.inactivity_mail_sent_by, w.inactivity_mail_sent_to, w.ios_mail_sent_at, w.ios_mail_sent_by, w.ios_mail_sent_to {$fromSql} {$whereSql} {$orderSql}");
    if (!$stmt) {
        http_response_code(500);
        exit('Datenbankfehler');
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kuk-geraete-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'device_name',
        'serial',
        'asset_tag',
        'owner_name',
        'owner_username',
        'owner_email',
        'model_name',
        'os_version',
        'last_checkin',
        'enrollment_date',
        'matched_by_asset',
        'matched_by_group',
        'jamf_groups',
        'jamf_notes',
        'last_sync_at',
        'inactivity_mail_sent_at',
        'inactivity_mail_sent_by',
        'inactivity_mail_sent_to',
        'ios_mail_sent_at',
        'ios_mail_sent_by',
        'ios_mail_sent_to',
    ]);
    while ($row = $result->fetch_assoc()) {
        fputcsv($out, [
            $row['device_name'],
            $row['serial'],
            $row['asset_tag'],
            $row['owner_name'],
            $row['owner_username'],
            $row['owner_email'],
            $row['model_name'],
            $row['os_version'],
            $row['last_checkin'],
            $row['enrollment_date'],
            (int) $row['matched_by_asset'],
            (int) $row['matched_by_group'],
            $row['jamf_groups'],
            kuk_jamf_notes($row),
            $row['last_sync_at'],
            $row['inactivity_mail_sent_at'] ?? '',
            $row['inactivity_mail_sent_by'] ?? '',
            $row['inactivity_mail_sent_to'] ?? '',
            $row['ios_mail_sent_at'] ?? '',
            $row['ios_mail_sent_by'] ?? '',
            $row['ios_mail_sent_to'] ?? '',
        ]);
    }
    fclose($out);
    $stmt->close();
    exit;
}

if (($_GET['export'] ?? '') === 'problem_csv') {
    $problemWhere = $where;
    $problemWhere[] = "(d.last_checkin IS NULL OR d.last_checkin < DATE_SUB(NOW(), INTERVAL 30 DAY) OR COALESCE(d.owner_email, '') = '' OR CAST(SUBSTRING_INDEX(d.os_version, '.', 1) AS UNSIGNED) < 26)";
    $problemWhereSql = 'WHERE ' . implode(' AND ', $problemWhere);
    $stmt = $mysqli->prepare("SELECT d.*, w.inactivity_mail_sent_at, w.ios_mail_sent_at {$fromSql} {$problemWhereSql} {$orderSql}");
    if (!$stmt) {
        http_response_code(500);
        exit('Datenbankfehler');
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kuk-problemgeraete-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['asset_tag', 'serial', 'owner_name', 'owner_email', 'model_name', 'os_version', 'last_checkin', 'inactivity_mail_sent_at', 'ios_mail_sent_at', 'problem']);
    while ($row = $result->fetch_assoc()) {
        fputcsv($out, [
            $row['asset_tag'],
            $row['serial'],
            $row['owner_name'],
            $row['owner_email'],
            $row['model_name'],
            $row['os_version'],
            $row['last_checkin'],
            $row['inactivity_mail_sent_at'] ?? '',
            $row['ios_mail_sent_at'] ?? '',
            kuk_problem_label($row),
        ]);
    }
    fclose($out);
    $stmt->close();
    exit;
}

$countStmt = $mysqli->prepare("SELECT COUNT(*) AS count {$fromSql} {$whereSql}");
if (!$countStmt) {
    die('Datenbankfehler: ' . kuk_h($mysqli->error));
}
if ($params) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRows = (int) ($countStmt->get_result()->fetch_assoc()['count'] ?? 0);
$countStmt->close();

$stmt = $mysqli->prepare("SELECT d.*, w.inactivity_mail_sent_at, w.inactivity_mail_sent_by, w.inactivity_mail_sent_to, w.ios_mail_sent_at, w.ios_mail_sent_by, w.ios_mail_sent_to {$fromSql} {$whereSql} {$orderSql} LIMIT ? OFFSET ?");
if (!$stmt) {
    die('Datenbankfehler: ' . kuk_h($mysqli->error));
}
$queryParams = $params;
$queryTypes = $types . 'ii';
$queryParams[] = $perPage;
$queryParams[] = $offset;
$stmt->bind_param($queryTypes, ...$queryParams);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$ownerHistoryBySerial = [];
$pageSerials = array_values(array_unique(array_filter(array_map(static fn (array $row): string => (string) ($row['serial'] ?? ''), $rows))));
if ($pageSerials) {
    $placeholders = implode(',', array_fill(0, count($pageSerials), '?'));
    $historyStmt = $mysqli->prepare(
        "SELECT serial, owner_name, owner_username, owner_email, first_seen_at, last_seen_at
         FROM kuk_owner_history
         WHERE serial IN ({$placeholders})
         ORDER BY serial ASC, first_seen_at DESC, id DESC"
    );
    if ($historyStmt) {
        $historyTypes = str_repeat('s', count($pageSerials));
        $historyStmt->bind_param($historyTypes, ...$pageSerials);
        $historyStmt->execute();
        $historyRows = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $historyStmt->close();
        foreach ($historyRows as $historyRow) {
            $ownerHistoryBySerial[(string) $historyRow['serial']][] = $historyRow;
        }
    }
}

$summary = [
    'total' => 0,
    'asset_match' => 0,
    'group_match' => 0,
    'no_owner' => 0,
    'stale' => 0,
    'last_sync_at' => null,
];
$summaryResult = $mysqli->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN matched_by_asset = 1 THEN 1 ELSE 0 END), 0) AS asset_match,
            COALESCE(SUM(CASE WHEN matched_by_group = 1 THEN 1 ELSE 0 END), 0) AS group_match,
            COALESCE(SUM(CASE WHEN COALESCE(owner_name, '') = '' AND COALESCE(owner_username, '') = '' AND COALESCE(owner_email, '') = '' THEN 1 ELSE 0 END), 0) AS no_owner,
            COALESCE(SUM(CASE WHEN last_checkin IS NULL OR last_checkin < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS stale,
            MAX(last_sync_at) AS last_sync_at
     FROM kuk_devices"
);
if ($summaryResult) {
    $summary = array_merge($summary, $summaryResult->fetch_assoc() ?: []);
}

$totalPages = max(1, (int) ceil($totalRows / $perPage));

function kuk_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function kuk_record_device_mail(mysqli $mysqli, string $serial, string $kind, string $recipient, string $actor): void
{
    if ($serial === '') {
        return;
    }

    if ($kind === 'ios') {
        $stmt = $mysqli->prepare(
            "INSERT INTO kuk_device_workflow (serial, ios_mail_sent_at, ios_mail_sent_by, ios_mail_sent_to)
             VALUES (?, NOW(), ?, ?)
             ON DUPLICATE KEY UPDATE
                 ios_mail_sent_at = NOW(),
                 ios_mail_sent_by = VALUES(ios_mail_sent_by),
                 ios_mail_sent_to = VALUES(ios_mail_sent_to)"
        );
    } else {
        $stmt = $mysqli->prepare(
            "INSERT INTO kuk_device_workflow (serial, inactivity_mail_sent_at, inactivity_mail_sent_by, inactivity_mail_sent_to)
             VALUES (?, NOW(), ?, ?)
             ON DUPLICATE KEY UPDATE
                 inactivity_mail_sent_at = NOW(),
                 inactivity_mail_sent_by = VALUES(inactivity_mail_sent_by),
                 inactivity_mail_sent_to = VALUES(inactivity_mail_sent_to)"
        );
    }

    if (!$stmt) {
        throw new RuntimeException($mysqli->error);
    }
    $stmt->bind_param('sss', $serial, $actor, $recipient);
    $stmt->execute();
    $stmt->close();
}

function kuk_display_datetime(?string $value): string
{
    if (!$value) {
        return 'n/a';
    }
    try {
        return (new DateTimeImmutable($value))->format('d.m.Y H:i');
    } catch (Exception) {
        return $value;
    }
}

function kuk_display_date(?string $value): string
{
    if (!$value) {
        return 'n/a';
    }
    try {
        return (new DateTimeImmutable($value))->format('d.m.Y');
    } catch (Exception) {
        return $value;
    }
}

function kuk_display_value(?string $value): string
{
    $value = trim((string) $value);
    return $value === '' ? 'n/a' : $value;
}

function kuk_asset_label(?string $assetTag): string
{
    $assetTag = trim((string) $assetTag);
    return $assetTag === '' ? '' : $assetTag;
}

function kuk_device_name_without_asset(?string $deviceName, ?string $assetTag): string
{
    $deviceName = trim((string) $deviceName);
    $assetTag = trim((string) $assetTag);
    if ($deviceName === '' || $assetTag === '') {
        return $deviceName;
    }

    $patterns = [
        '/,?\s*' . preg_quote($assetTag, '/') . '$/i',
        '/\s*\(' . preg_quote($assetTag, '/') . '\)$/i',
    ];

    return trim((string) preg_replace($patterns, '', $deviceName));
}

function kuk_checkin_is_older_than_months(?string $value, int $months): bool
{
    if (!$value) {
        return false;
    }

    try {
        $checkin = new DateTimeImmutable($value);
        $threshold = (new DateTimeImmutable('now'))->modify('-' . $months . ' months');
        return $checkin < $threshold;
    } catch (Exception) {
        return false;
    }
}

function kuk_checkin_chip_class(?string $value): string
{
    if (!$value || kuk_checkin_is_older_than_months($value, 3)) {
        return ' asset-chip-stale';
    }
    if (kuk_checkin_is_older_than_days($value, 30)) {
        return ' asset-chip-warn';
    }

    return '';
}

function kuk_checkin_is_older_than_days(?string $value, int $days): bool
{
    if (!$value) {
        return false;
    }

    try {
        $checkin = new DateTimeImmutable($value);
        $threshold = (new DateTimeImmutable('now'))->modify('-' . $days . ' days');
        return $checkin < $threshold;
    } catch (Exception) {
        return false;
    }
}

function kuk_major_ios(array $row): int
{
    $version = trim((string) ($row['os_version'] ?? ''));
    if ($version === '') {
        return 0;
    }

    return (int) explode('.', $version)[0];
}

function kuk_problem_label(array $row): string
{
    $problems = [];
    if (trim((string) ($row['owner_email'] ?? '')) === '') {
        $problems[] = 'ohne-owner';
    }
    if (!$row['last_checkin'] || kuk_checkin_is_older_than_days($row['last_checkin'] ?? null, 30)) {
        $problems[] = 'checkin-alt';
    }
    if (kuk_major_ios($row) > 0 && kuk_major_ios($row) < 26) {
        $problems[] = 'ios-alt';
    }

    return implode(',', $problems);
}

function kuk_mail_link(?string $email, ?string $label = null): string
{
    $email = trim((string) $email);
    $label = trim((string) ($label ?? $email));
    if ($label === '') {
        return 'n/a';
    }
    if ($email === '') {
        return kuk_h($label);
    }

    return '<a class="inline-link" href="mailto:' . kuk_h($email) . '">' . kuk_h($label) . '</a>';
}

function kuk_inactivity_mail_link(array $row, ?string $label = null): string
{
    return kuk_action_mail_link(
        $row,
        kuk_inactivity_mail_subject($row),
        kuk_inactivity_mail_intro($row),
        kuk_inactivity_mail_outro(),
        'inactivity',
        $label
    );
}

function kuk_old_ios_mail_link(array $row, ?string $label = null): string
{
    return kuk_action_mail_link(
        $row,
        kuk_old_ios_mail_subject($row),
        kuk_old_ios_mail_intro($row),
        kuk_old_ios_mail_outro(),
        'ios',
        $label
    );
}

function kuk_action_mail_link(array $row, string $subject, string $intro, string $outro, string $kind, ?string $label = null): string
{
    $email = trim((string) ($row['owner_email'] ?? ''));
    $label = trim((string) ($label ?? $email));
    if ($label === '') {
        return 'n/a';
    }
    if ($email === '') {
        return kuk_h($label);
    }

    $asset = kuk_display_value($row['asset_tag'] ?? null);
    $serial = kuk_display_value($row['serial'] ?? null);
    $details = kuk_inactivity_mail_details($row);

    return '<button class="inline-button kuk-mail-trigger" type="button"'
        . ' data-to="' . kuk_h($email) . '"'
        . ' data-subject="' . kuk_h($subject) . '"'
        . ' data-intro="' . kuk_h($intro) . '"'
        . ' data-details="' . kuk_h($details) . '"'
        . ' data-outro="' . kuk_h($outro) . '"'
        . ' data-kind="' . kuk_h($kind) . '"'
        . ' data-serial="' . kuk_h($serial) . '"'
        . ' data-device="' . kuk_h($asset) . '">'
        . kuk_h($label)
        . '</button>';
}

function kuk_inactivity_mail_subject(array $row): string
{
    $asset = kuk_display_value($row['asset_tag'] ?? null);
    return 'KUK-iPad ' . $asset . ': längere Inaktivität';
}

function kuk_old_ios_mail_subject(array $row): string
{
    $asset = kuk_display_value($row['asset_tag'] ?? null);
    return 'KUK-iPad ' . $asset . ': iOS-Version prüfen';
}

function kuk_inactivity_mail_body(array $row): string
{
    return kuk_inactivity_mail_intro($row) . "\n\n"
        . kuk_inactivity_mail_details($row) . "\n\n"
        . kuk_inactivity_mail_outro();
}

function kuk_inactivity_mail_intro(array $row): string
{
    $ownerName = kuk_display_value($row['owner_name'] ?? null);
    if ($ownerName === 'n/a') {
        $ownerName = '';
    }
    $greeting = $ownerName !== '' ? 'Liebe(r) ' . $ownerName . ',' : 'Liebe Kollegin, lieber Kollege,';

    return $greeting . "\n\n"
        . "Ihr iPad meldet sich laut Jamf School seit längerer Zeit nicht mehr.";
}

function kuk_old_ios_mail_intro(array $row): string
{
    $ownerName = kuk_display_value($row['owner_name'] ?? null);
    if ($ownerName === 'n/a') {
        $ownerName = '';
    }
    $greeting = $ownerName !== '' ? 'Liebe(r) ' . $ownerName . ',' : 'Liebe Kollegin, lieber Kollege,';

    return $greeting . "\n\n"
        . "Ihr iPad nutzt laut Jamf School eine ältere iOS-Version.";
}

function kuk_inactivity_mail_details(array $row): string
{
    $asset = kuk_display_value($row['asset_tag'] ?? null);
    $serial = kuk_display_value($row['serial'] ?? null);
    $lastCheckin = kuk_display_datetime($row['last_checkin'] ?? null);
    $model = kuk_display_value($row['model_name'] ?? null);
    $osVersion = kuk_display_value($row['os_version'] ?? null);
    $details = [
        ['Gerät', $asset],
        ['Seriennummer', $serial],
        ['Modell', $model],
        ['iOS', $osVersion],
        ['Letzter Check-in', $lastCheckin],
    ];

    $body = "Gerätedetails:\n";

    foreach ($details as [$label, $value]) {
        $body .= str_pad($label . ':', 18) . "\t" . $value . "\n";
    }

    return rtrim($body);
}

function kuk_inactivity_mail_outro(): string
{
    return "Bitte geben Sie kurz Rückmeldung, ob das Gerät aktuell bei Ihnen ist und genutzt wird.\n\n"
        . "Viele Grüße\n"
        . "Team Mobile Devicemanagement";
}

function kuk_old_ios_mail_outro(): string
{
    return "Bitte aktualisieren Sie das iPad zeitnah auf eine aktuelle iOS-Version oder geben Sie kurz Rückmeldung, falls die Aktualisierung nicht möglich ist.\n\n"
        . "Viele Grüße\n"
        . "Team Mobile Devicemanagement";
}

function kuk_jamf_notes(array $row): string
{
    $rawJson = (string) ($row['raw_json'] ?? '');
    if ($rawJson === '') {
        return '';
    }

    $decoded = json_decode($rawJson, true);
    if (!is_array($decoded)) {
        return '';
    }

    return trim((string) ($decoded['notes'] ?? ''));
}

function kuk_distinct_owner_count(array $historyRows): int
{
    $seen = [];
    foreach ($historyRows as $historyRow) {
        $key = implode('|', [
            trim((string) ($historyRow['owner_name'] ?? '')),
            trim((string) ($historyRow['owner_username'] ?? '')),
            trim((string) ($historyRow['owner_email'] ?? '')),
        ]);
        if ($key !== '||') {
            $seen[$key] = true;
        }
    }

    return count($seen);
}

function kuk_mail_success_return_url(string $returnTo, string $fallback): string
{
    $parts = parse_url($returnTo);
    $path = (string) ($parts['path'] ?? $fallback);
    parse_str((string) ($parts['query'] ?? ''), $query);

    if (in_array((string) ($query['filter'] ?? ''), ['old-ios', 'stale'], true)) {
        $query['contact'] = 'sent';
        $query['page'] = 1;
        return $path . '?' . http_build_query($query);
    }

    return $returnTo !== '' ? $returnTo : $fallback;
}

function kuk_url(array $params): string
{
    $basePath = rtrim(disown_admin_base_path(), '/');
    return $basePath . '/kuk/?' . http_build_query(array_filter($params, static fn ($value) => $value !== '' && $value !== null));
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="<?=kuk_h($faviconPath)?>">
<title>KUK-Geräte</title>
<link rel="stylesheet" href="<?=kuk_h($kukCssUrl)?>">
</head>
<body>
<div class="page">
    <header class="header">
        <div>
            <h1 class="page-title">KUK-Geräte</h1>
            <p class="hint-text">Kolleginnen-/Kollegen-iPads aus Jamf School, gefiltert nach LK-Asset-Tag.</p>
        </div>
        <div class="header-actions">
            <a class="admin-user" href="<?=kuk_h($logoutPath)?>">👤 <?=kuk_h($currentAdminUser ?: 'Admin')?></a>
            <div class="actions-row">
                <a class="button button-secondary admin-nav-link admin-home-link" href="<?=kuk_h($adminPath)?>">Adminportal</a>
                <a class="button button-secondary admin-nav-link" href="<?=kuk_h($adePath)?>">ADE-Aufnahmen</a>
                <a class="button button-secondary admin-nav-link" href="<?=kuk_h($auditLogPath)?>">Audit-Log</a>
                <a class="button button-secondary" href="<?=kuk_h($exportUrl)?>">CSV exportieren</a>
                <a class="button button-secondary" href="<?=kuk_h($problemUrl)?>">Problemgeräte</a>
                <?php if ($canWrite): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?=kuk_h($_SESSION['csrf_token'])?>">
                        <button class="button button-secondary" type="submit" name="sync_kuk" value="1">SYNC</button>
                    </form>
                <?php else: ?>
                    <span class="button button-secondary" aria-disabled="true">Sync nur Admin</span>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <?php if ($syncMessage !== ''): ?>
        <section class="notice notice-ok"><pre><?=kuk_h($syncMessage)?></pre></section>
    <?php endif; ?>
    <?php if ($syncError !== ''): ?>
        <section class="notice notice-error"><pre><?=kuk_h($syncError)?></pre></section>
    <?php endif; ?>
    <?php if ($mailMessage !== ''): ?>
        <section class="notice notice-ok"><pre><?=kuk_h($mailMessage)?></pre></section>
    <?php endif; ?>
    <?php if ($mailError !== ''): ?>
        <section class="notice notice-error"><pre><?=kuk_h($mailError)?></pre></section>
    <?php endif; ?>
    <section class="stats" aria-label="Zusammenfassung">
        <span class="stat">Gesamt <strong><?=kuk_h((string) $summary['total'])?></strong></span>
        <span class="stat">LK-Asset <strong><?=kuk_h((string) $summary['asset_match'])?></strong></span>
        <span class="stat" title="Geräte, die in Jamf School exakt der Gruppe LK - Leihgeräte zugeordnet sind. Dient nur als Abgleich zur LK-Asset-Zählung.">LK-Gruppe <strong><?=kuk_h((string) $summary['group_match'])?></strong></span>
        <span class="stat warn">Ohne Owner <strong><?=kuk_h((string) $summary['no_owner'])?></strong></span>
        <span class="stat warn">Inaktiv &gt; 30 Tage <strong><?=kuk_h((string) $summary['stale'])?></strong></span>
        <span class="stat">Letzter Sync <strong><?=kuk_h(kuk_display_datetime($summary['last_sync_at'] ?? null))?></strong></span>
    </section>

    <form class="toolbar" id="kukSearchForm" method="get">
        <div class="search">
            <label for="q">Suche</label>
            <div class="search-field">
                <input id="q" name="q" value="<?=kuk_h($q)?>" placeholder="Name, Seriennummer, Asset Tag, Owner, Modell oder OS" autocomplete="off">
                <?php if ($q !== ''): ?>
                    <a class="clear-search" href="<?=kuk_h(kuk_url(array_merge($baseParams, ['q' => '', 'page' => 1])))?>" aria-label="Suche löschen">×</a>
                <?php endif; ?>
            </div>
            <input type="hidden" name="filter" value="<?=kuk_h($filter)?>">
            <input type="hidden" name="contact" value="<?=kuk_h($contact)?>">
            <input type="hidden" name="page" value="1">
            <button class="button-secondary" type="submit">Suchen</button>
            <a class="button button-secondary" href="<?=kuk_h($kukPath)?>">Zurücksetzen</a>
        </div>
    </form>

    <nav class="tabs" aria-label="Filter">
        <a class="tab <?=$filter === 'all' ? 'active' : ''?>" href="<?=kuk_h(kuk_url(array_merge($baseParams, ['filter' => 'all', 'page' => 1])))?>">Alle</a>
        <a class="tab <?=$filter === 'old-ios' ? 'active' : ''?>" href="<?=kuk_h(kuk_url(array_merge($baseParams, ['filter' => 'old-ios', 'page' => 1])))?>">Ältestes iOS</a>
        <a class="tab <?=$filter === 'no-owner' ? 'active' : ''?>" href="<?=kuk_h(kuk_url(array_merge($baseParams, ['filter' => 'no-owner', 'page' => 1])))?>">Ohne Owner</a>
        <a class="tab <?=$filter === 'stale' ? 'active' : ''?>" href="<?=kuk_h(kuk_url(array_merge($baseParams, ['filter' => 'stale', 'page' => 1])))?>">Älteste Check-ins</a>
    </nav>

    <?php if (in_array($filter, ['old-ios', 'stale'], true)): ?>
        <nav class="contact-tabs" aria-label="Kontaktstatus">
            <a class="contact-tab <?=$contact === 'all' ? 'active' : ''?>" href="<?=kuk_h(kuk_url(array_merge($baseParams, ['contact' => 'all', 'page' => 1])))?>">Alle Kontakte</a>
            <a class="contact-tab <?=$contact === 'pending' ? 'active' : ''?>" href="<?=kuk_h(kuk_url(array_merge($baseParams, ['contact' => 'pending', 'page' => 1])))?>">Noch nicht angeschrieben</a>
            <a class="contact-tab <?=$contact === 'sent' ? 'active' : ''?>" href="<?=kuk_h(kuk_url(array_merge($baseParams, ['contact' => 'sent', 'page' => 1])))?>">Angeschrieben</a>
        </nav>
    <?php endif; ?>

    <section class="table-wrap">
        <?php if (!$rows): ?>
            <div class="empty">Keine KUK-Geräte gefunden.</div>
        <?php else: ?>
            <table>
                <colgroup>
                    <col class="col-device">
                    <col class="col-serial">
                    <col class="col-owner">
                    <col class="col-model">
                    <col class="col-jamf">
                </colgroup>
                <thead>
                    <tr>
                        <th>Gerät</th>
                        <th>Seriennummer</th>
                        <th>Owner</th>
                        <th>Modell/OS</th>
                        <th>Jamf-Daten</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $assetLabel = kuk_asset_label($row['asset_tag'] ?? null);
                            $deviceLabel = $assetLabel !== '' ? $assetLabel : (string) ($row['serial'] ?? '');
                            $ownerHistory = $ownerHistoryBySerial[(string) ($row['serial'] ?? '')] ?? [];
                            $jamfNotes = kuk_jamf_notes($row);
                            $staleChipClass = kuk_checkin_chip_class($row['last_checkin'] ?? null);
                        ?>
                        <tr>
                            <td data-label="Gerät">
                                <?php if (str_starts_with(strtoupper($deviceLabel), 'LK-')): ?>
                                    <span class="asset-chip<?=$staleChipClass?>"><?=kuk_h($deviceLabel)?></span>
                                <?php else: ?>
                                    <strong class="device-title"><?=kuk_h(kuk_display_value($deviceLabel))?></strong>
                                <?php endif; ?>
                            </td>
                            <td data-label="Seriennummer">
                                <div class="mono"><?=kuk_h($row['serial'])?></div>
                            </td>
                            <td data-label="Owner">
                                <div class="owner-lines">
                                    <strong><?=kuk_h(kuk_display_value($row['owner_name'] ?? null))?></strong>
                                    <div class="subline">
                                        <?php if ($filter === 'stale' && $canWrite): ?>
                                            <?=kuk_inactivity_mail_link($row)?>
                                        <?php elseif ($filter === 'old-ios' && $canWrite): ?>
                                            <?=kuk_old_ios_mail_link($row)?>
                                        <?php else: ?>
                                            <?=kuk_mail_link($row['owner_email'] ?? null)?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (kuk_distinct_owner_count($ownerHistory) > 1): ?>
                                        <details class="owner-history">
                                            <summary>Lokale Owner-Historie (<?=kuk_h((string) count($ownerHistory))?>)</summary>
                                            <?php foreach ($ownerHistory as $historyRow): ?>
                                                <div class="history-entry">
                                                    <?=kuk_h(kuk_display_value($historyRow['owner_name'] ?? null))?>
                                                    <?php if (!empty($historyRow['owner_email'])): ?>
                                                        · <?=kuk_mail_link($historyRow['owner_email'] ?? null)?>
                                                    <?php endif; ?>
                                                    <br>
                                                    <?=kuk_h(kuk_display_date($historyRow['first_seen_at'] ?? null))?>
                                                    bis <?=kuk_h(kuk_display_date($historyRow['last_seen_at'] ?? null))?>
                                                </div>
                                            <?php endforeach; ?>
                                        </details>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td data-label="Modell/OS" class="model-cell">
                                <span class="model-name"><?=kuk_h(kuk_display_value($row['model_name'] ?? null))?></span>
                                <div class="subline">OS: <?=kuk_h(kuk_display_value($row['os_version'] ?? null))?></div>
                                <?php if (!empty($row['enrollment_date'])): ?>
                                    <div class="subline">Enrollment: <?=kuk_h(kuk_display_date($row['enrollment_date'] ?? null))?></div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Jamf" class="jamf-cell">
                                <div>Last Check-in: <?=kuk_h(kuk_display_datetime($row['last_checkin'] ?? null))?></div>
                                <div class="subline">Gruppen: <?=kuk_h(kuk_display_value($row['jamf_groups'] ?? null))?></div>
                                <?php if (!empty($row['inactivity_mail_sent_at']) || !empty($row['ios_mail_sent_at'])): ?>
                                    <div class="mail-log">
                                        <?php if (!empty($row['inactivity_mail_sent_at'])): ?>
                                            <div class="mail-log-row">
                                                <span class="mail-log-badge mail-log-badge-inactive">Inaktiv-Mail</span>
                                                <span class="mail-log-date"><?=kuk_h(kuk_display_datetime($row['inactivity_mail_sent_at'] ?? null))?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($row['ios_mail_sent_at'])): ?>
                                            <div class="mail-log-row">
                                                <span class="mail-log-badge">iOS-Mail</span>
                                                <span class="mail-log-date"><?=kuk_h(kuk_display_datetime($row['ios_mail_sent_at'] ?? null))?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($jamfNotes !== ''): ?>
                                    <details class="jamf-notes">
                                        <summary>Jamf-Notiz</summary>
                                        <div class="subline notes-line"><?=kuk_h($jamfNotes)?></div>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <div class="pagination">
            <?php $prevParams = array_merge($baseParams, ['page' => max(1, $page - 1)]); ?>
            <?php $nextParams = array_merge($baseParams, ['page' => min($totalPages, $page + 1)]); ?>
            <a class="button button-secondary" href="<?=kuk_h(kuk_url($prevParams))?>">‹ Zurück</a>
            <span>Seite <?=kuk_h((string) $page)?> von <?=kuk_h((string) $totalPages)?></span>
            <a class="button button-secondary" href="<?=kuk_h(kuk_url($nextParams))?>">Weiter ›</a>
        </div>
    </section>

    <?php if ($canWrite): ?>
        <div id="kukMailPreview" class="preview-card hidden">
            <div class="preview-header">
                <div>
                    <h2>Mail-Vorschau</h2>
                    <p class="preview-subtitle">Vorlage: KUK-Inaktivität</p>
                </div>
                <button type="button" class="button button-secondary" onclick="hideKukMailPreview()">Schließen</button>
            </div>
            <div class="preview-content">
                <div class="preview-meta">
                    <div class="preview-meta-row">
                        <span class="preview-meta-label">An:</span>
                        <span class="preview-meta-value" id="kukPreviewEmailText"></span>
                    </div>
                    <div class="preview-meta-row">
                        <span class="preview-meta-label">Betreff:</span>
                        <span class="preview-meta-value" id="kukPreviewSubjectText"></span>
                    </div>
                </div>
                <div class="preview-field">
                    <label>Nachricht</label>
                    <div class="preview-fixed-message">
                        <div id="kukPreviewIntroText" class="preview-fixed-intro"></div>
                        <pre id="kukPreviewDetailsText" class="preview-fixed-details"></pre>
                    </div>
                </div>
                <div class="preview-field">
                    <label for="kukPreviewOutroInput">Abschluss</label>
                    <textarea id="kukPreviewOutroInput" class="preview-body-input"></textarea>
                </div>
            </div>
            <form method="post" id="kukSendMailForm">
                <input type="hidden" name="csrf_token" value="<?=kuk_h($_SESSION['csrf_token'])?>">
                <input type="hidden" name="send_kuk_inactivity_mail" value="1">
                <input type="hidden" name="return_to" value="<?=kuk_h((string) ($_SERVER['REQUEST_URI'] ?? $kukPath))?>">
                <input type="hidden" name="mail_to" id="kukSendToInput" value="">
                <input type="hidden" name="mail_subject" id="kukSendSubjectInput" value="">
                <input type="hidden" name="mail_serial" id="kukSendSerialInput" value="">
                <input type="hidden" name="mail_device" id="kukSendDeviceInput" value="">
                <input type="hidden" name="mail_kind" id="kukSendKindInput" value="inactivity">
                <textarea name="mail_body" id="kukSendBodyInput" class="hidden"></textarea>
                <div class="editor-actions">
                    <button type="button" class="button button-primary" onclick="sendKukMail()">Mail senden</button>
                    <button type="button" class="button button-secondary" onclick="hideKukMailPreview()">Schließen</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php disown_render_site_footer('KUK-Geräte', ['data_status' => kuk_display_datetime($summary['last_sync_at'] ?? null)]); ?>
</div>
<script src="<?=kuk_h($searchJsUrl)?>" defer></script>
<script src="<?=kuk_h($kukJsUrl)?>" defer></script>
</body>
</html>
