<?php
require __DIR__ . '/auth.php';
disown_require_admin();
require 'db.php';
require __DIR__ . '/jamf.php';
require __DIR__ . '/workflow_store.php';
require_once __DIR__ . '/jamf_cleanup.php';
require_once __DIR__ . '/workflow_mail.php';
require __DIR__ . '/asm_release.php';
require_once __DIR__ . '/notify.php';
require __DIR__ . '/vendor/autoload.php';

$templateMessage = '';
$templateError = '';
$mailMessage = '';
$mailError = '';
$disownMessage = '';
$disownError = '';
$bulkMessage = '';
$bulkError = '';
$adminDirectMessage = '';
$adminDirectError = '';
$adminDirectPreview = null;
$adminDirectForm = [
    'serial' => '',
    'full_name' => '',
    'email' => '',
    'private_email' => '',
    'note' => '',
];
$caseMessage = '';
$caseError = '';
$pushMailMessage = '';
$pushMailError = '';
$asmReleasePreview = null;
$asmReleaseRequest = null;
$bulkAsmSerials = [];
$bulkLastIds = [];
$bulkLastStep = '';
$currentAdminUser = disown_current_admin_user();
$canWrite = disown_can_write();
$accessLabel = $canWrite ? 'Admin' : 'Nur Lesen';
$appBasePath = rtrim(disown_admin_base_path(), '/');
$adminPath = $appBasePath . '/admin.php';
$adePath = $appBasePath . '/ade.php';
$kukPath = $appBasePath . '/kuk/';
$auditLogPath = $appBasePath . '/audit_log.php';
$settingsPath = $appBasePath . '/settings.php';
$logoutPath = $appBasePath . '/logout.php';
$faviconPath = $appBasePath . '/favicon.svg';
$logoPath = $appBasePath . '/logo.png';
$jamfLogoPath = $appBasePath . '/logo_jamf.png';
$asmLogoPath = $appBasePath . '/logo_asm.png';
$siteImagePath = $appBasePath . '/images/Site-Image.png';
$searchJsUrl = disown_asset_url($appBasePath, 'assets/search.js');
$adminCssUrl = disown_asset_url($appBasePath, 'assets/admin.css');
$adminJsUrl = disown_asset_url($appBasePath, 'assets/admin.js');
$validFilters = ['open', 'scheduled', 'done', 'all', 'cases', 'cleanup'];
$filter = (string) ($_GET['filter'] ?? 'open');
if (!in_array($filter, $validFilters, true)) {
    $filter = 'open';
}
$filterLabels = [
    'open' => 'Offen',
    'scheduled' => 'Terminiert',
    'done' => 'Erledigt',
    'all' => 'Alle',
    'cases' => 'Klärfälle',
    'cleanup' => 'Jamf-Nacharbeit',
];
$jamfLicenseBaseline = [
    'taken_at' => '2026-10-18 14:24:48',
    'recurring_total' => 1400,
    'recurring_used' => 1314,
    'perpetual_total' => 378,
    'perpetual_used' => 370,
];
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$monthFilter = trim((string) ($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $monthFilter)) {
    $monthFilter = '';
}
$monthFilterLabel = '';
if ($monthFilter !== '') {
    $monthFilterDate = DateTimeImmutable::createFromFormat('!Y-m', $monthFilter);
    if ($monthFilterDate instanceof DateTimeImmutable && $monthFilterDate->format('Y-m') === $monthFilter) {
        $monthNamesLong = [
            1 => 'Januar',
            2 => 'Februar',
            3 => 'März',
            4 => 'April',
            5 => 'Mai',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'August',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Dezember',
        ];
        $monthFilterLabel = $monthNamesLong[(int) $monthFilterDate->format('n')] . ' ' . $monthFilterDate->format('Y');
    } else {
        $monthFilter = '';
    }
}
$perPage = 25;
$page = max(1, (int) ($_GET['page'] ?? 1));
$refreshUrl = $adminPath . '?' . http_build_query([
    'filter' => $filter,
    'q' => $searchTerm,
    'month' => $monthFilter,
    'page' => $page,
]);
$whereParts = [];
$whereParams = [];
$whereTypes = '';
$doneCondition = "LOWER(TRIM(status)) = 'erledigt' AND mail_sent <> 0";
$openCondition = "status IS NULL OR LOWER(TRIM(status)) <> 'erledigt' OR mail_sent = 0";
$scheduledCondition = "({$openCondition}) AND requested_release_date > CURDATE() AND jamf_unenrolled = 0";
$dueCondition = "({$openCondition}) AND NOT ({$scheduledCondition})";

if ($filter === 'open') {
    $whereParts[] = $dueCondition;
} elseif ($filter === 'scheduled') {
    $whereParts[] = $scheduledCondition;
} elseif ($filter === 'done') {
    $whereParts[] = $doneCondition;
} elseif ($filter === 'cleanup') {
    $whereParts[] = disown_jamf_cleanup_open_sql();
} elseif ($filter === 'cases') {
    $whereParts[] = "EXISTS (
        SELECT 1
        FROM device_cases dc_filter
        WHERE dc_filter.serial = requests.serial
    )";
}

if ($searchTerm !== '') {
    $whereParts[] = "(
        full_name LIKE ?
        OR username LIKE ?
        OR class_name LIKE ?
        OR email LIKE ?
        OR private_email LIKE ?
        OR requested_release_date LIKE ?
        OR serial LIKE ?
        OR device_name LIKE ?
        OR mail_sent_to LIKE ?
        OR jamf_unenroll_error LIKE ?
        OR EXISTS (
            SELECT 1
            FROM request_audit_log ral_search
            WHERE ral_search.request_id = requests.id
              AND ral_search.details LIKE ?
        )
    )";
    $searchLike = '%' . $searchTerm . '%';
    for ($i = 0; $i < 11; $i++) {
        $whereParams[] = $searchLike;
        $whereTypes .= 's';
    }
}

if ($monthFilter !== '') {
    $whereParts[] = "created_at >= STR_TO_DATE(CONCAT(?, '-01'), '%Y-%m-%d')
        AND created_at < DATE_ADD(STR_TO_DATE(CONCAT(?, '-01'), '%Y-%m-%d'), INTERVAL 1 MONTH)";
    $whereParams[] = $monthFilter;
    $whereParams[] = $monthFilter;
    $whereTypes .= 'ss';
}

$whereSql = $whereParts ? 'WHERE ' . implode(' AND ', array_map(static function ($part) {
    return '(' . $part . ')';
}, $whereParts)) : '';

function admin_url(array $params = []): string
{
    $query = array_merge($_GET, $params);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }

    $basePath = rtrim(disown_admin_base_path(), '/');
    return $basePath . '/admin.php' . ($query ? '?' . http_build_query($query) : '');
}

function log_request_action($mysqli, int $requestId, string $action, ?string $details = null): void
{
    disown_log_audit_action($mysqli, $requestId, $action, disown_current_admin_user(), $details);
}

/** @param array<string, mixed> $result */
function audit_jamf_workflow_result(mysqli $mysqli, int $requestId, array $result, string $prefix = ''): void
{
    $state = (string) ($result['state'] ?? DISOWN_JAMF_STATE_FAILED);
    $event = match ($state) {
        DISOWN_JAMF_STATE_VERIFIED_UNMANAGED => 'JAMF_UNMANAGED_VERIFIED',
        DISOWN_JAMF_STATE_PENDING_DEVICE, DISOWN_JAMF_STATE_COMMAND_ACCEPTED => 'JAMF_UNENROLL_PENDING',
        DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED => 'JAMF_MANUAL_INTERVENTION_REQUIRED',
        default => 'JAMF_UNENROLL_FAILED',
    };
    $httpStatus = isset($result['http_status']) ? (string) (int) $result['http_status'] : 'n/a';
    $responseClass = substr(trim((string) ($result['response_class'] ?? 'unknown')), 0, 64);
    $details = ($prefix !== '' ? $prefix . '; ' : '')
        . 'HTTP: ' . $httpStatus
        . '; Klasse: ' . $responseClass
        . '; Zustand: ' . $state;
    log_request_action($mysqli, $requestId, $event, $details);
    if (!empty($result['accepted'])) {
        log_request_action(
            $mysqli,
            $requestId,
            'JAMF_UNENROLL_COMMAND_ACCEPTED',
            ($prefix !== '' ? $prefix . '; ' : '') . 'HTTP: ' . $httpStatus . '; Klasse: ' . $responseClass
        );
    }
}

function load_request_for_action($mysqli, int $requestId): ?array
{
    $stmt = $mysqli->prepare(
        "SELECT id, serial, full_name, username, email, private_email, class_name, device_name,
                jamf_unenrolled, jamf_unenroll_state, jamf_command_accepted_at, jamf_check_attempts,
                jamf_command_status, jamf_observed_location, asm_manual_done, mail_sent, completed_by, user_agent
         FROM requests
         WHERE id = ?"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function load_open_request_by_serial(mysqli $mysqli, string $serial): ?array
{
    $stmt = $mysqli->prepare(
        "SELECT id, serial, full_name, username, email, private_email, class_name, device_name,
                jamf_unenrolled, jamf_unenroll_state, jamf_command_accepted_at, jamf_check_attempts,
                jamf_command_status, jamf_observed_location, asm_manual_done, mail_sent, completed_by, user_agent
         FROM requests
         WHERE UPPER(serial) = UPPER(?)
           AND status = 'offen'
         ORDER BY id DESC
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $serial);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function create_admin_direct_request(mysqli $mysqli, array $jamf, array $input, string $adminUser): ?int
{
    $serial = strtoupper(trim((string) ($jamf['serial'] ?? $input['serial'] ?? '')));
    if ($serial === '') {
        return null;
    }

    $username = trim((string) ($jamf['username'] ?? ''));
    $schoolEmail = trim((string) ($input['email'] ?? ''));
    if ($schoolEmail === '') {
        $schoolEmail = trim((string) ($jamf['email'] ?? ''));
    }
    if ($schoolEmail === '' && $username !== '' && str_contains($username, '.')) {
        $schoolEmail = $username . '@bbs-einbeck.de';
    }
    $privateEmail = trim((string) ($input['private_email'] ?? ''));
    $fullName = trim((string) ($input['full_name'] ?? ''));
    if ($fullName === '') {
        $fullName = trim((string) ($jamf['full_name'] ?? ''));
    }
    if ($fullName === '') {
        $fullName = 'Admin-Sonderfreigabe';
    }

    $className = 'ADMIN';
    $jamfUserId = isset($jamf['jamf_user_id']) && $jamf['jamf_user_id'] !== '' ? (int) $jamf['jamf_user_id'] : null;
    $jamfModified = trim((string) ($jamf['jamf_modified'] ?? ''));
    $jamfModified = $jamfModified !== '' ? $jamfModified : null;
    $deviceName = trim((string) ($jamf['device_name'] ?? ''));
    $requestedReleaseDate = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = 'Admin-Sonderfreigabe durch ' . ($adminUser !== '' ? $adminUser : 'admin');
    $jamfUnenrolled = 0;

    $stmt = $mysqli->prepare(
        "INSERT INTO requests
         (username, email, private_email, full_name, class_name, jamf_user_id, jamf_modified, serial, device_name, requested_release_date, ip, user_agent, jamf_unenrolled, jamf_unenrolled_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? = 1, NOW(), NULL))"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param(
        'sssssissssssii',
        $username,
        $schoolEmail,
        $privateEmail,
        $fullName,
        $className,
        $jamfUserId,
        $jamfModified,
        $serial,
        $deviceName,
        $requestedReleaseDate,
        $ip,
        $userAgent,
        $jamfUnenrolled,
        $jamfUnenrolled
    );

    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $requestId = (int) $mysqli->insert_id;
    $stmt->close();

    return $requestId > 0 ? $requestId : null;
}

function request_is_admin_direct(array $request): bool
{
    return (string) ($request['class_name'] ?? '') === 'ADMIN'
        && str_starts_with((string) ($request['user_agent'] ?? ''), 'Admin-Sonderfreigabe durch ');
}

function normalize_bulk_ids($rawIds): array
{
    if (!is_array($rawIds)) {
        return [];
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), static function ($id) {
        return $id > 0;
    })));
    sort($ids);

    return $ids;
}

function load_bulk_requests($mysqli, array $ids): array
{
    if (!$ids) {
        return [];
    }

    $idList = implode(',', $ids);
    $result = $mysqli->query(
        "SELECT id, serial, full_name, class_name, username, email, private_email, device_name, completed_by,
                jamf_unenrolled, jamf_unenroll_state, jamf_command_accepted_at, jamf_check_attempts,
                jamf_command_status, jamf_observed_location, asm_manual_done, mail_sent
         FROM requests
         WHERE id IN ({$idList})
         ORDER BY created_at DESC, id DESC"
    );
    if (!$result) {
        return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function request_mail_recipients(array $row): array
{
    $recipients = [];
    foreach (['private_email', 'email'] as $field) {
        $address = trim((string) ($row[$field] ?? ''));
        if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = $address;
        }
    }

    return array_values(array_unique($recipients));
}

function complete_request_with_mail_failure($mysqli, int $requestId, string $recipientList, string $completedBy, string $serial, string $deviceName, string $error): void
{
    persist_completion_mail_result($mysqli, $requestId, 2, $recipientList, $completedBy);

    log_request_action(
        $mysqli,
        $requestId,
        'MAIL_FAILED_COMPLETED',
        'Empfänger: ' . ($recipientList ?: 'unbekannt') . '; Seriennummer: ' . ($serial ?: 'unbekannt') . '; Gerät: ' . ($deviceName ?: 'unbekannt') . '; Fehler: ' . $error
    );
}

/**
 * Runs the admin-authorized ASM/ADE follow-up after verified OR accepted Jamf.
 * The separate ASM button remains available only when this automatic step fails.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function execute_asm_release_and_persist(mysqli $mysqli, array $row, string $auditPrefix = '', bool $bulk = false, ?callable $releaseExecutor = null): array
{
    $requestId = (int) ($row['id'] ?? 0);
    $serial = strtoupper(trim((string) ($row['serial'] ?? '')));
    if ($requestId <= 0 || $serial === '' || !disown_request_allows_asm($row)) {
        return [
            'success' => false,
            'local_completed' => false,
            'message' => 'ASM/ADE konnte für diesen Workflowzustand nicht gestartet werden.',
        ];
    }

    $result = ($releaseExecutor ?? 'asm_release_execute')($serial);
    $detailText = ($auditPrefix !== '' ? $auditPrefix . '; ' : '')
        . 'Seriennummer: ' . $serial . '; ' . ($result['message'] ?? '');
    $assignmentAttempts = (int) ($result['details']['assignment_attempts'] ?? 1);
    if ($assignmentAttempts > 1) {
        $detailText .= '; ASM-Zuweisungsprüfungen: ' . $assignmentAttempts;
    }

    $isDevFixture = !empty($result['details']['dev_fixture']);
    $localCompleted = !empty($result['success']) && (empty($result['dry_run'])
        || ($isDevFixture && disown_is_dev_mode()));
    if ($localCompleted) {
        $stmt = $mysqli->prepare(
            "UPDATE requests
             SET asm_manual_done = 1,
                 asm_manual_done_at = NOW()
             WHERE id = ? AND UPPER(serial) = ? AND asm_manual_done = 0"
        );
        if (!$stmt) {
            $result['success'] = false;
            $result['local_completed'] = false;
            $result['message'] = 'ASM/ADE war erfolgreich, der lokale Status konnte aber nicht gespeichert werden.';
            return $result;
        }
        $stmt->bind_param('is', $requestId, $serial);
        $stored = $stmt->execute() && $stmt->affected_rows === 1;
        $stmt->close();
        if (!$stored) {
            $result['success'] = false;
            $result['local_completed'] = false;
            $result['message'] = 'ASM/ADE war erfolgreich, der lokale Status konnte aber nicht gespeichert werden.';
            return $result;
        }

        $alreadyReleased = !empty($result['details']['already_released']);
        log_request_action(
            $mysqli,
            $requestId,
            $bulk
                ? ($isDevFixture
                    ? 'BULK_ASM_DEV_SIMULATED'
                    : ($alreadyReleased ? 'BULK_ASM_ALREADY_RELEASED_CONFIRMED' : 'BULK_ASM_BROKER_RELEASE_SUCCESS'))
                : ($isDevFixture
                    ? 'ASM_DEV_SIMULATED'
                    : ($alreadyReleased ? 'ASM_ALREADY_RELEASED_CONFIRMED' : 'ASM_BROKER_RELEASE_SUCCESS')),
            $detailText
        );
    } else {
        log_request_action(
            $mysqli,
            $requestId,
            $bulk
                ? (!empty($result['success']) ? 'BULK_ASM_BROKER_RELEASE_DRYRUN' : 'BULK_ASM_BROKER_RELEASE_FAILED')
                : (!empty($result['success']) ? 'ASM_BROKER_RELEASE_DRYRUN' : 'ASM_BROKER_RELEASE_FAILED'),
            $detailText
        );
    }

    $result['local_completed'] = $localCompleted;
    return $result;
}

function format_mail_delivery_status(array $successfulRecipients, array $failedRecipients, bool $includeErrorDetails = false): string
{
    $parts = [];
    if ($successfulRecipients) {
        $parts[] = 'OK: ' . implode(', ', array_values(array_unique($successfulRecipients)));
    }
    if ($failedRecipients) {
        $failedParts = [];
        foreach ($failedRecipients as $address => $error) {
            $failedParts[] = $address . ($includeErrorDetails && $error !== '' ? ' (' . $error . ')' : '');
        }
        $parts[] = 'FEHLER: ' . implode(', ', $failedParts);
    }

    return implode('; ', $parts);
}

function send_release_mail(array $mailConfig, string $recipient, string $subject, string $body, string $deviceName, string $serial): void
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
    $mail->setFrom($mailConfig['MAIL_FROM'], 'BBS Einbeck, Team Mobile Device Management');
    $mail->addAddress($recipient);
    $mail->Subject = $subject;

    $safeBody = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if ($deviceName !== '') {
        $safeDevice = htmlspecialchars($deviceName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
}

function mail_status_label($value): string
{
    $state = (int) $value;
    if ($state === 2) {
        return 'fehlgeschlagen';
    }
    if ($state === 1) {
        return 'gesendet';
    }

    return 'nicht gesendet';
}

function jamf_trash_serials(): array
{
    $cfg = jamf_config();
    if (!$cfg) {
        throw new RuntimeException('Jamf-Konfiguration fehlt.');
    }

    $url = rtrim($cfg['JAMF_URL'], '/') . '/api/devices?inTrash=true';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $cfg['JAMF_NETWORK_ID'] . ':' . $cfg['JAMF_API_KEY'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_FAILONERROR => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
    ]);

    $raw = curl_exec($ch);
    $error = curl_errno($ch) ? curl_error($ch) : '';
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false || $error !== '' || (int) $status !== 200) {
        throw new RuntimeException('Jamf-Trash-Abgleich fehlgeschlagen.');
    }

    $json = json_decode((string) $raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
        throw new RuntimeException('Jamf-Trash-Abgleich lieferte kein gueltiges JSON.');
    }

    $devices = $json['devices'] ?? $json['data'] ?? [];
    if (!is_array($devices)) {
        return [];
    }

    $serials = [];
    foreach ($devices as $device) {
        if (!is_array($device)) {
            continue;
        }
        $serial = strtoupper(trim((string) ($device['serialNumber'] ?? $device['serial'] ?? '')));
        if ($serial !== '') {
            $serials[$serial] = true;
        }
    }

    return array_keys($serials);
}

function load_jamf_license_dashboard(mysqli $mysqli, array $baseline): array
{
    $stats = [
        'available' => false,
        'error' => '',
        'baseline_at' => $baseline['taken_at'],
        'recurring_total' => (int) $baseline['recurring_total'],
        'recurring_used_baseline' => (int) $baseline['recurring_used'],
        'recurring_used_estimated' => (int) $baseline['recurring_used'],
        'recurring_free_estimated' => max(0, (int) $baseline['recurring_total'] - (int) $baseline['recurring_used']),
        'trash_confirmed' => 0,
        'waiting_for_trash' => 0,
        'waiting_serials' => [],
        'trash_confirmed_serials' => [],
        'trash_total' => null,
    ];

    try {
        $trashSerials = array_flip(jamf_trash_serials());
        $stats['trash_total'] = count($trashSerials);

        $stmt = $mysqli->prepare(
            "SELECT serial
             FROM requests
             WHERE (jamf_unenroll_state = 'verified_unmanaged' OR jamf_command_accepted_at IS NOT NULL)
               AND asm_manual_done = 1
               AND COALESCE(jamf_command_accepted_at, jamf_unenrolled_at) >= ?
               AND serial IS NOT NULL
               AND TRIM(serial) <> ''"
        );
        if (!$stmt) {
            throw new RuntimeException('Lizenzabfrage konnte nicht vorbereitet werden.');
        }

        $stmt->bind_param('s', $baseline['taken_at']);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Lizenzabfrage konnte nicht ausgefuehrt werden.');
        }

        $localSerials = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $serial = strtoupper(trim((string) ($row['serial'] ?? '')));
            if ($serial !== '') {
                $localSerials[$serial] = true;
            }
        }
        $stmt->close();

        foreach (array_keys($localSerials) as $serial) {
            if (isset($trashSerials[$serial])) {
                $stats['trash_confirmed']++;
                $stats['trash_confirmed_serials'][] = $serial;
            } else {
                $stats['waiting_for_trash']++;
                $stats['waiting_serials'][] = $serial;
            }
        }

        $stats['recurring_used_estimated'] = max(0, (int) $baseline['recurring_used'] - $stats['trash_confirmed']);
        $stats['recurring_free_estimated'] = max(0, (int) $baseline['recurring_total'] - $stats['recurring_used_estimated']);
        $stats['available'] = true;
    } catch (Throwable $e) {
        $stats['error'] = $e->getMessage();
    }

    return $stats;
}

function load_release_broker_health(): array
{
    $health = [
        'ok' => false,
        'message' => 'Release Broker wurde nicht erreicht.',
        'version' => '',
    ];

    if (!function_exists('curl_init')) {
        $health['message'] = 'PHP-cURL ist nicht verfuegbar.';
        return $health;
    }

    $url = getenv('DISOWN_NANODEP_HEALTH_URL') ?: 'http://127.0.0.1:9001/version';
    $ch = curl_init($url);
    if (!$ch) {
        $health['message'] = 'Healthcheck konnte nicht vorbereitet werden.';
        return $health;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 300,
        CURLOPT_TIMEOUT_MS => 700,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false || $status !== 200) {
        $health['message'] = $error !== '' ? $error : 'HTTP ' . $status;
        return $health;
    }

    $json = json_decode((string) $raw, true);
    $version = is_array($json) ? trim((string) ($json['version'] ?? '')) : '';
    $health['ok'] = true;
    $health['version'] = $version;
    $health['message'] = $version !== ''
        ? 'NanoDEP Release Broker erreichbar (' . $version . ').'
        : 'NanoDEP Release Broker erreichbar.';

    return $health;
}

function read_simple_env_file(string $path): array
{
    if ($path === '' || !is_readable($path)) {
        return [];
    }

    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        $values[$key] = $value;
    }

    return $values;
}

function load_release_broker_token_expiry(): array
{
    $result = [
        'available' => false,
        'label' => '',
        'message' => 'Apple ADE Token-Ablaufdatum ist nicht bekannt.',
        'severity' => 'muted',
    ];

    $configPath = getenv('DISOWN_ASM_BROKER_CONFIG') ?: '/etc/disown/asm-release-broker.conf';
    $config = read_simple_env_file($configPath);

    $expiry = trim((string) (getenv('DISOWN_NANODEP_TOKEN_EXPIRY') ?: ''));
    $source = 'Umgebung';
    if ($expiry === '') {
        foreach (['DISOWN_NANODEP_TOKEN_EXPIRY', 'ASM_BROKER_TOKEN_EXPIRY', 'NANODEP_TOKEN_EXPIRY', 'TOKEN_EXPIRY'] as $key) {
            if (!empty($config[$key])) {
                $expiry = trim((string) $config[$key]);
                $source = basename($configPath);
                break;
            }
        }
    }

    $tokenFile = trim((string) (getenv('DISOWN_NANODEP_TOKEN_FILE') ?: ''));
    if ($tokenFile === '') {
        foreach (['DISOWN_NANODEP_TOKEN_FILE', 'ASM_BROKER_TOKEN_FILE', 'NANODEP_TOKEN_FILE', 'TOKEN_FILE'] as $key) {
            if (!empty($config[$key])) {
                $tokenFile = trim((string) $config[$key]);
                break;
            }
        }
    }
    if ($tokenFile === '') {
        $tokenFile = '/srv/disown-protected/asm-release-broker/asm-release-broker-token.plist';
    }

    if ($expiry === '' && is_readable($tokenFile)) {
        $contents = file_get_contents($tokenFile);
        if (is_string($contents) && preg_match('/"access_token_expiry"\s*:\s*"([^"]+)"/', $contents, $matches)) {
            $expiry = trim($matches[1]);
            $source = basename($tokenFile);
        }
    }

    if ($expiry === '') {
        $expiry = '2027-07-09T12:12:55Z';
        $source = 'Fallback';
    }

    try {
        $expiryDate = new DateTimeImmutable($expiry);
    } catch (Exception $e) {
        $result['available'] = true;
        $result['label'] = 'Token ungueltig';
        $result['message'] = 'Apple ADE Token-Ablaufdatum ist ungueltig: ' . $expiry;
        $result['severity'] = 'error';
        return $result;
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $localExpiry = $expiryDate->setTimezone(new DateTimeZone('Europe/Berlin'));
    $secondsLeft = $expiryDate->getTimestamp() - $now->getTimestamp();

    $result['available'] = true;
    $result['label'] = 'Token bis ' . $localExpiry->format('d.m.Y');
    $result['message'] = 'Apple ADE Token gueltig bis ' . $localExpiry->format('d.m.Y H:i') . ' Uhr. Quelle: ' . $source . '.';
    $result['severity'] = $secondsLeft <= 0 ? 'error' : ($secondsLeft <= 30 * 86400 ? 'warn' : 'free');

    if ($secondsLeft <= 0) {
        $result['label'] = 'Token abgelaufen';
        $result['message'] = 'Apple ADE Token ist seit ' . $localExpiry->format('d.m.Y H:i') . ' Uhr abgelaufen. Quelle: ' . $source . '.';
    }

    return $result;
}

function normalize_device_case_status(string $status): string
{
    $status = trim($status);
    return $status === 'geklaert' ? 'geklaert' : 'offen';
}

function display_device_case_status(string $status): string
{
    return match ($status) {
        'geklaert' => 'geklärt',
        default => 'offen',
    };
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

if (!empty($_SESSION['flash_mail_message'])) {
    $mailMessage = $_SESSION['flash_mail_message'];
    unset($_SESSION['flash_mail_message']);
}
if (!empty($_SESSION['flash_mail_error'])) {
    $mailError = $_SESSION['flash_mail_error'];
    unset($_SESSION['flash_mail_error']);
}
if (!empty($_SESSION['flash_disown_message'])) {
    $disownMessage = (string) $_SESSION['flash_disown_message'];
    unset($_SESSION['flash_disown_message']);
}
if (!empty($_SESSION['bulk_asm_serials']) && is_array($_SESSION['bulk_asm_serials'])) {
    $bulkAsmSerials = array_values(array_filter(array_map('strval', $_SESSION['bulk_asm_serials'])));
    unset($_SESSION['bulk_asm_serials']);
}
if (!empty($_SESSION['bulk_last_ids']) && is_array($_SESSION['bulk_last_ids'])) {
    $bulkLastIds = normalize_bulk_ids($_SESSION['bulk_last_ids']);
    unset($_SESSION['bulk_last_ids']);
}
if (!empty($_SESSION['bulk_last_step'])) {
    $bulkLastStep = (string) $_SESSION['bulk_last_step'];
    unset($_SESSION['bulk_last_step']);
}

$mailTemplatePath = __DIR__ . '/templates/mail_release.txt';
$mailTemplate = disown_release_mail_template($mysqli, $mailTemplatePath);
$mailTemplatePending = disown_pending_release_mail_template($mysqli);
$mailSubject = 'iPad-Freigabe BBS Einbeck';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        die('Ungültiges Formular. Bitte lade die Seite neu und versuche es erneut.');
    }
    disown_require_write();

    if (isset($_POST['template_save'])) {
        $templateContent = (string) ($_POST['template_content'] ?? '');
        $pendingTemplateSave = ($_POST['template_variant'] ?? '') === 'pending';
        if ($pendingTemplateSave) {
            $mailTemplatePending = $templateContent;
        } else {
            $mailTemplate = $templateContent;
        }
        if (!disown_release_mail_template_is_valid($templateContent)) {
            $templateError = 'Die Vorlage darf nicht leer und höchstens 60.000 Zeichen groß sein.';
        } elseif (!($pendingTemplateSave
            ? disown_setting_set($mysqli, disown_release_mail_template_key() . '_pending', $templateContent, $currentAdminUser ?: 'admin')
            : disown_release_mail_template_set($mysqli, $templateContent, $currentAdminUser ?: 'admin'))) {
            $templateError = 'Die Vorlage konnte nicht in den Einstellungen gespeichert werden.';
        } else {
            log_request_action($mysqli, 0, 'TEMPLATE_UPDATED', 'Mailvorlage aktualisiert: ' . ($pendingTemplateSave ? 'Jamf-Bestätigung ausstehend' : 'Jamf verifiziert'));
            $templateMessage = 'Vorlage erfolgreich gespeichert.';
        }
    }

    if (isset($_POST['toggle_push_mail'])) {
        $enablePushMail = ($_POST['push_mail_enabled'] ?? '') === '1';
        if (disown_push_mail_set_enabled($mysqli, $enablePushMail, $currentAdminUser ?: 'admin')) {
            log_request_action(
                $mysqli,
                0,
                $enablePushMail ? 'PUSH_MAIL_ENABLED' : 'PUSH_MAIL_DISABLED',
                'E-Mail-Push für neue Anträge wurde ' . ($enablePushMail ? 'aktiviert.' : 'deaktiviert.')
            );
            $pushMailMessage = 'E-Mail-Push wurde ' . ($enablePushMail ? 'aktiviert.' : 'deaktiviert.') . '.';
        } else {
            $pushMailError = 'E-Mail-Push konnte nicht umgeschaltet werden.';
        }
    }

    if (isset($_POST['delete_device_case'])) {
        $caseId = (int) ($_POST['case_id'] ?? 0);
        $caseRequestId = (int) ($_POST['case_request_id'] ?? 0);
        $caseSerial = strtoupper(trim((string) ($_POST['case_serial'] ?? '')));
        $caseTitle = trim((string) ($_POST['case_title'] ?? ''));

        if ($caseId <= 0) {
            $caseError = 'Klärfall konnte nicht gelöscht werden.';
        } else {
            $deleteStmt = $mysqli->prepare("DELETE FROM device_cases WHERE id = ?");
            if ($deleteStmt) {
                $deleteStmt->bind_param('i', $caseId);
                if ($deleteStmt->execute()) {
                    $caseMessage = 'Klärfall gelöscht.';
                    log_request_action(
                        $mysqli,
                        $caseRequestId > 0 ? $caseRequestId : 0,
                        'DEVICE_CASE_DELETED',
                        'Seriennummer: ' . ($caseSerial ?: 'unbekannt') . '; Klärfall: ' . ($caseTitle ?: '#' . $caseId)
                    );
                } else {
                    $caseError = 'Klärfall konnte nicht gelöscht werden.';
                }
                $deleteStmt->close();
            } else {
                $caseError = 'Klärfall konnte nicht vorbereitet werden.';
            }
        }
    } elseif (isset($_POST['save_device_case'])) {
        $caseId = (int) ($_POST['case_id'] ?? 0);
        $caseRequestId = (int) ($_POST['case_request_id'] ?? 0);
        $caseSerial = strtoupper(trim((string) ($_POST['case_serial'] ?? '')));
        $caseSource = trim((string) ($_POST['case_source'] ?? 'admin'));
        $caseTitle = trim((string) ($_POST['case_title'] ?? ''));
        $caseStatus = normalize_device_case_status((string) ($_POST['case_status'] ?? 'offen'));
        $caseNote = trim((string) ($_POST['case_note'] ?? ''));
        $caseResolutionNote = trim((string) ($_POST['case_resolution_note'] ?? ''));
        $caseSource = in_array($caseSource, ['admin', 'license-dashboard'], true) ? $caseSource : 'admin';
        $caseUpdatedBy = $currentAdminUser !== '' ? $currentAdminUser : 'admin';
        $caseRequestIdOrNull = $caseRequestId > 0 ? $caseRequestId : null;

        if ($caseSerial === '' || $caseTitle === '') {
            $caseError = 'Seriennummer und Titel sind für einen Klärfall erforderlich.';
        } elseif ($caseId > 0) {
            $sql = "UPDATE device_cases
                    SET serial = ?,
                        request_id = ?,
                        source = ?,
                        title = ?,
                        status = ?,
                        note = ?,
                        resolution_note = ?,
                        updated_by = ?,
                        closed_by = CASE WHEN ? = 'geklaert' THEN ? ELSE NULL END,
                        closed_at = CASE WHEN ? = 'geklaert' THEN COALESCE(closed_at, NOW()) ELSE NULL END
                    WHERE id = ?";
            $stmt = $mysqli->prepare($sql);
            if ($stmt) {
                $stmt->bind_param(
                    'sisssssssssi',
                    $caseSerial,
                    $caseRequestIdOrNull,
                    $caseSource,
                    $caseTitle,
                    $caseStatus,
                    $caseNote,
                    $caseResolutionNote,
                    $caseUpdatedBy,
                    $caseStatus,
                    $caseUpdatedBy,
                    $caseStatus,
                    $caseId
                );
                if ($stmt->execute()) {
                    $caseMessage = 'Klärfall aktualisiert.';
                } else {
                    $caseError = 'Klärfall konnte nicht gespeichert werden.';
                }
                $stmt->close();
            } else {
                $caseError = 'Klärfall konnte nicht vorbereitet werden.';
            }
        } else {
            $sql = "INSERT INTO device_cases
                        (serial, request_id, source, title, status, note, resolution_note, created_by, updated_by, closed_by, closed_at)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, CASE WHEN ? = 'geklaert' THEN ? ELSE NULL END, CASE WHEN ? = 'geklaert' THEN NOW() ELSE NULL END)";
            $stmt = $mysqli->prepare($sql);
            if ($stmt) {
                $stmt->bind_param(
                    'sissssssssss',
                    $caseSerial,
                    $caseRequestIdOrNull,
                    $caseSource,
                    $caseTitle,
                    $caseStatus,
                    $caseNote,
                    $caseResolutionNote,
                    $caseUpdatedBy,
                    $caseUpdatedBy,
                    $caseStatus,
                    $caseUpdatedBy,
                    $caseStatus
                );
                if ($stmt->execute()) {
                    $caseMessage = 'Klärfall angelegt.';
                    $caseId = (int) $mysqli->insert_id;
                } else {
                    $caseError = 'Klärfall konnte nicht angelegt werden.';
                }
                $stmt->close();
            } else {
                $caseError = 'Klärfall konnte nicht vorbereitet werden.';
            }
        }

        if ($caseError === '' && $caseRequestIdOrNull !== null) {
            log_request_action(
                $mysqli,
                $caseRequestIdOrNull,
                'DEVICE_CASE_SAVED',
                'Seriennummer: ' . $caseSerial . '; Klärfall: ' . $caseTitle . '; Status: ' . display_device_case_status($caseStatus)
            );
        }
    }

    if (isset($_POST['admin_direct_preview']) || isset($_POST['admin_direct_create'])) {
        $isAdminDirectExecution = isset($_POST['admin_direct_create']);
        $directSerial = strtoupper(trim((string) ($_POST['admin_direct_serial'] ?? '')));
        $directFullName = trim((string) ($_POST['admin_direct_full_name'] ?? ''));
        $directSchoolEmail = trim((string) ($_POST['admin_direct_email'] ?? ''));
        $directPrivateEmail = trim((string) ($_POST['admin_direct_private_email'] ?? ''));
        $directNote = trim((string) ($_POST['admin_direct_note'] ?? ''));
        $adminDirectForm = [
            'serial' => $directSerial,
            'full_name' => $directFullName,
            'email' => $directSchoolEmail,
            'private_email' => $directPrivateEmail,
            'note' => $directNote,
        ];

        if ($directSerial === '') {
            $adminDirectError = 'Bitte eine Seriennummer eintragen.';
        } elseif ($directSchoolEmail !== '' && !filter_var($directSchoolEmail, FILTER_VALIDATE_EMAIL)) {
            $adminDirectError = 'Die schulische E-Mail-Adresse ist ungültig.';
        } elseif ($directPrivateEmail !== '' && !filter_var($directPrivateEmail, FILTER_VALIDATE_EMAIL)) {
            $adminDirectError = 'Die private E-Mail-Adresse ist ungültig.';
        } else {
            $jamfDevice = jamf_lookup_by_serial($directSerial);
            if (!$jamfDevice) {
                $jamfDevice = jamf_lookup_in_trash_by_serial($directSerial);
            }
            if (!$jamfDevice) {
                $adminDirectError = 'Gerät wurde in Jamf weder aktiv noch im Papierkorb gefunden.';
            } else {
                $jamfState = (string) ($jamfDevice['jamf_state'] ?? 'active');
                $directSerial = strtoupper(trim((string) ($jamfDevice['serial'] ?? $directSerial)));
                $adminDirectForm['serial'] = $directSerial;
                if ($adminDirectForm['full_name'] === '') {
                    $adminDirectForm['full_name'] = trim((string) ($jamfDevice['full_name'] ?? ''));
                }
                if ($adminDirectForm['email'] === '') {
                    $adminDirectForm['email'] = trim((string) ($jamfDevice['email'] ?? ''));
                }
                $schoolLoanReasons = jamf_school_loan_reasons($jamfDevice);
                $adminDirectPreview = [
                    'serial' => $directSerial,
                    'device_name' => trim((string) ($jamfDevice['device_name'] ?? '')),
                    'full_name' => trim((string) ($jamfDevice['full_name'] ?? '')),
                    'username' => trim((string) ($jamfDevice['username'] ?? '')),
                    'email' => trim((string) ($jamfDevice['email'] ?? '')),
                    'asset_tag' => trim((string) ($jamfDevice['asset_tag'] ?? '')),
                    'jamf_state' => $jamfState,
                    'school_loan_reasons' => $schoolLoanReasons,
                ];

                if ($isAdminDirectExecution && $jamfState === 'trash' && ($_POST['admin_direct_confirm_trash'] ?? '') !== '1') {
                    $adminDirectError = 'Bitte bestätige ausdrücklich, dass der Trash-Datensatz nur als unbestätigte Beobachtung übernommen wird und manuell verifiziert werden muss.';
                }

                if (!$isAdminDirectExecution) {
                    $adminDirectMessage = 'Jamf-Daten für ' . $directSerial . ' geladen. Bitte prüfen und dann den Antrag anlegen.';
                }
            }
        }

        if ($isAdminDirectExecution && $adminDirectError === '') {
            if (!$adminDirectPreview) {
                $adminDirectError = 'Bitte Seriennummer zuerst prüfen.';
            } else {
                $directInput = [
                    'serial' => $directSerial,
                    'full_name' => $directFullName,
                    'email' => $directSchoolEmail,
                    'private_email' => $directPrivateEmail,
                ];
                $request = load_open_request_by_serial($mysqli, $directSerial);
                $requestWasCreated = false;

                if (!$request) {
                    $requestId = create_admin_direct_request(
                        $mysqli,
                        $jamfDevice,
                        $directInput,
                        $currentAdminUser
                    );
                    if ($requestId === null) {
                        $adminDirectError = 'Admin-Sonderfreigabe konnte nicht angelegt werden.';
                    } else {
                        $requestWasCreated = true;
                        $request = load_request_for_action($mysqli, $requestId);
                        log_request_action(
                            $mysqli,
                            $requestId,
                            'ADMIN_DIRECT_REQUEST_CREATED',
                            'Seriennummer: ' . $directSerial . '; Schulgeräte-Check bewusst nur für diesen Admin-Sonderweg übersprungen.' . ($directNote !== '' ? ' Hinweis: ' . $directNote : '')
                        );
                    }
                }

                if ($request && $adminDirectError === '') {
                    $requestId = (int) $request['id'];
                    if (($jamfDevice['jamf_state'] ?? '') === 'trash' && empty($request['jamf_unenrolled'])) {
                        persist_jamf_workflow_result($mysqli, $requestId, [
                            'state' => DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED,
                            'success' => false,
                            'accepted' => false,
                            'response_class' => 'already_in_trash_unverified',
                            'message' => 'Trash-Datensatz vorhanden; Profilentfernung ist nicht verifiziert.',
                            'observed_location' => 'trash',
                            'observed_is_managed' => null,
                            'active_absent' => true,
                            'trash_observed' => true,
                        ]);
                        log_request_action(
                            $mysqli,
                            $requestId,
                            'ADMIN_DIRECT_JAMF_TRASH_OBSERVED',
                            'Jamf-Status: Trash beobachtet; Profilentfernung nicht verifiziert; kein Jamf-Schreibzugriff ausgeführt.'
                        );
                        $request = load_request_for_action($mysqli, $requestId);
                    }
                }

                if ($request && $adminDirectError === '') {
                    $requestId = (int) $request['id'];
                    if (($request['completed_by'] ?? '') === 'history-import') {
                        $adminDirectError = 'Historische Importe können nicht per Admin-Sonderweg verarbeitet werden.';
                    } elseif (!empty($request['mail_sent'])) {
                        $adminDirectError = 'Dieser Vorgang hat bereits einen Mailstatus und wird nicht erneut gestartet.';
                    } elseif (!empty($request['jamf_unenrolled'])) {
                        $adminDirectMessage = 'Offener Vorgang #' . $requestId . ' war bereits in Jamf abgemeldet. Nächster Schritt: ASM/ADE prüfen.';
                        $adminDirectPreview = null;
                        $adminDirectForm = [
                            'serial' => '',
                            'full_name' => '',
                            'email' => '',
                            'private_email' => '',
                            'note' => '',
                        ];
                    } elseif (disown_jamf_state_from_row($request) === DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED) {
                        $adminDirectMessage = 'Vorgang #' . $requestId . ' wurde mit unbestätigtem Jamf-Trash-Status angelegt. ASM/ADE und Mail bleiben gesperrt; manuelle Verifizierung ist erforderlich.';
                        $adminDirectPreview = null;
                    } else {
                        if (!$requestWasCreated) {
                            log_request_action(
                                $mysqli,
                                $requestId,
                                'ADMIN_DIRECT_REQUEST_REUSED',
                                'Seriennummer: ' . $directSerial . '; vorhandener offener Admin-Sonderweg weiterverwendet.' . ($directNote !== '' ? ' Hinweis: ' . $directNote : '')
                            );
                        }
                        $adminDirectMessage = ($requestWasCreated ? 'Admin-Sonderantrag #' . $requestId . ' angelegt.' : 'Offener Antrag #' . $requestId . ' weiterverwendet.') . ' Nächster Schritt: Jamf in der Tabellenzeile starten.';
                        $adminDirectPreview = null;
                        $adminDirectForm = [
                            'serial' => '',
                            'full_name' => '',
                            'email' => '',
                            'private_email' => '',
                            'note' => '',
                        ];
                    }
                }
            }
        }
    }

    if (isset($_POST['bulk_action'])) {
        $bulkAction = (string) ($_POST['bulk_action'] ?? '');
        $bulkIds = normalize_bulk_ids($_POST['bulk_ids'] ?? []);
        $bulkRows = load_bulk_requests($mysqli, $bulkIds);

        if (!$bulkIds) {
            $bulkError = 'Bitte wählen Sie mindestens einen Antrag aus.';
        } elseif (!$bulkRows) {
            $bulkError = 'Die ausgewählten Anträge wurden nicht gefunden.';
        } elseif ($bulkAction === 'bulk_jamf_unenroll') {
            $verifiedCount = 0;
            $asmSuccessCount = 0;
            $asmFailedCount = 0;
            $pendingCount = 0;
            $failedCount = 0;
            $skippedCount = 0;
            $mailReadyIds = [];

            foreach ($bulkRows as $bulkRow) {
                $requestId = (int) $bulkRow['id'];
                $serial = trim((string) $bulkRow['serial']);
                $isHistoryImport = (($bulkRow['completed_by'] ?? '') === 'history-import');
                $storedJamfState = disown_jamf_state_from_row($bulkRow);

                if (
                    $isHistoryImport
                    || $storedJamfState === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED
                    || !empty($bulkRow['mail_sent'])
                    || $serial === ''
                ) {
                    $skippedCount++;
                    continue;
                }

                if (in_array($storedJamfState, [DISOWN_JAMF_STATE_COMMAND_ACCEPTED, DISOWN_JAMF_STATE_PENDING_DEVICE, DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED], true)) {
                    log_request_action($mysqli, $requestId, 'JAMF_UNENROLL_RECHECK_REQUESTED', 'Quelle: Bulk; nur read-only');
                    $result = jamf_verify_unenroll_by_serial(
                        $serial,
                        $bulkRow['jamf_command_accepted_at'] ?? null,
                        false,
                        $storedJamfState === DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED
                            || (int) ($bulkRow['jamf_check_attempts'] ?? 0) >= 5
                    );
                    $result['http_status'] = $result['observation']['active_http_status'] ?? null;
                    $result['response_class'] = 'postcondition_recheck';
                    $result['accepted'] = false;
                } else {
                    log_request_action($mysqli, $requestId, 'JAMF_UNENROLL_REQUESTED', 'Quelle: Bulk');
                    $result = jamf_unenroll_by_serial($serial);
                }
                $state = persist_jamf_workflow_result($mysqli, $requestId, $result);
                audit_jamf_workflow_result($mysqli, $requestId, $result, 'Quelle: Bulk');

                $verifiedRow = load_request_for_action($mysqli, $requestId);
                if ($state === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED) {
                    $verifiedCount++;
                } elseif (disown_request_has_release_basis($verifiedRow ?? [])) {
                    $pendingCount++;
                } else {
                    $failedCount++;
                }
                if ($verifiedRow && (disown_request_allows_asm($verifiedRow)
                    || (!empty($verifiedRow['asm_manual_done']) && disown_request_has_release_basis($verifiedRow)))) {
                    if ($verifiedRow && !empty($verifiedRow['asm_manual_done'])) {
                        $asmSuccessCount++;
                        $mailReadyIds[] = $requestId;
                    } elseif ($verifiedRow) {
                        $asmResult = execute_asm_release_and_persist(
                            $mysqli,
                            $verifiedRow,
                            'Adminfreigabe nach Jamf-Verifikation oder angenommener Abmeldung; Quelle: Bulk',
                            true
                        );
                        if (!empty($asmResult['local_completed'])) {
                            $asmSuccessCount++;
                            $mailReadyIds[] = $requestId;
                        } else {
                            $asmFailedCount++;
                        }
                    } else {
                        $asmFailedCount++;
                    }
                }
            }

            $bulkMessage = "Bulk abgeschlossen: {$verifiedCount} in Jamf verifiziert, {$pendingCount} mit angenommener Abmeldung noch ohne Gerätebestätigung, {$asmSuccessCount} in ASM/ADE abgeschlossen, {$asmFailedCount} ASM-Fehler, {$failedCount} Jamf-Prüffälle, {$skippedCount} übersprungen. Mail bleibt manuell; Jamf-Nacharbeit läuft im Hintergrund.";
            if ($mailReadyIds) {
                $bulkAsmSerials = [];
                $bulkLastIds = normalize_bulk_ids($mailReadyIds);
                $_SESSION['bulk_last_ids'] = $bulkLastIds;
                $_SESSION['bulk_last_step'] = 'mail';
            }
        } elseif ($bulkAction === 'bulk_asm_release') {
            $successCount = 0;
            $failedCount = 0;
            $dryRunCount = 0;
            $skippedCount = 0;
            $successfulIds = [];
            $successfulSerials = [];

            foreach ($bulkRows as $bulkRow) {
                $requestId = (int) $bulkRow['id'];
                $serial = trim((string) $bulkRow['serial']);
                $isHistoryImport = (($bulkRow['completed_by'] ?? '') === 'history-import');

                if (!disown_request_allows_asm($bulkRow) || $serial === '') {
                    $skippedCount++;
                    continue;
                }

                $asmBulkResult = execute_asm_release_and_persist($mysqli, $bulkRow, 'Quelle: Bulk-Wiederholung', true);
                if (!empty($asmBulkResult['local_completed'])) {
                    $successCount++;
                    $successfulIds[] = $requestId;
                    $successfulSerials[] = strtoupper($serial);
                } elseif (!empty($asmBulkResult['success']) && !empty($asmBulkResult['dry_run'])) {
                    $dryRunCount++;
                } else {
                    $failedCount++;
                }
            }

            $bulkMessage = "Bulk-ASM/ADE abgeschlossen: {$successCount} automatisch freigegeben, {$failedCount} fehlgeschlagen, {$dryRunCount} nur geprüft, {$skippedCount} übersprungen.";
            if ($successfulIds) {
                $bulkAsmSerials = [];
                $bulkLastIds = normalize_bulk_ids($successfulIds);
                $bulkLastStep = 'mail';
                log_request_action(
                    $mysqli,
                    0,
                    'BULK_ASM_BROKER_RELEASE_SUMMARY',
                    'Automatisch freigegebene Seriennummern: ' . implode(', ', array_values(array_unique($successfulSerials)))
                    . '; Antraege: ' . implode(', ', array_map('strval', $bulkLastIds))
                );
                $_SESSION['bulk_last_ids'] = $bulkLastIds;
                $_SESSION['bulk_last_step'] = 'mail';
            }
        } elseif ($bulkAction === 'bulk_mail_send') {
            $successCount = 0;
            $failedCount = 0;
            $skippedCount = 0;
            $mailConfig = null;
            $mailConfigComplete = true;

            if (!$isDevMode) {
                $mailConfig = disown_mail_config($mysqli);
                $mailConfigComplete = disown_mail_config_is_complete($mailConfig);
            }

            foreach ($bulkRows as $bulkRow) {
                    $requestId = (int) $bulkRow['id'];
                    $serial = trim((string) $bulkRow['serial']);
                    $deviceName = trim((string) $bulkRow['device_name']);
                    $isHistoryImport = (($bulkRow['completed_by'] ?? '') === 'history-import');

                    if (!disown_request_allows_completion_mail($bulkRow)) {
                        $skippedCount++;
                        continue;
                    }

                    $recipients = request_mail_recipients($bulkRow);
                    $recipient = implode(', ', $recipients);
                    if (!$recipients) {
                        $completedBy = $currentAdminUser !== '' ? $currentAdminUser : ($isDevMode ? 'dev' : 'marc');
                        complete_request_with_mail_failure(
                            $mysqli,
                            $requestId,
                            '',
                            $completedBy,
                            $serial,
                            $deviceName,
                            'Keine gültige Empfängeradresse.'
                        );
                        $failedCount++;
                        continue;
                    }

                    if (!$mailConfigComplete) {
                        $completedBy = $currentAdminUser !== '' ? $currentAdminUser : 'marc';
                        complete_request_with_mail_failure(
                            $mysqli,
                            $requestId,
                            $recipient,
                            $completedBy,
                            $serial,
                            $deviceName,
                            'SMTP-Konfiguration unvollständig.'
                        );
                        $failedCount++;
                        continue;
                    }

                    $body = disown_release_mail_template_for_row($mailTemplate, $bulkRow, $mailTemplatePending);
                    $placeholders = [
                        '{{name}}' => (string) ($bulkRow['full_name'] ?? ''),
                        '{{username}}' => (string) ($bulkRow['username'] ?? ''),
                        '{{email}}' => (string) ($bulkRow['email'] ?? ''),
                        '{{private_email}}' => (string) ($bulkRow['private_email'] ?? ''),
                        '{{device_name}}' => $deviceName,
                        '{{serial}}' => $serial,
                    ];
                    $body = str_replace(array_keys($placeholders), array_values($placeholders), $body);

                    $successfulRecipients = [];
                    $failedRecipients = [];
                    if ($isDevMode) {
                        $successfulRecipients = $recipients;
                    } else {
                        foreach ($recipients as $mailRecipient) {
                            try {
                                send_release_mail($mailConfig, $mailRecipient, $mailSubject, $body, $deviceName, $serial);
                                $successfulRecipients[] = $mailRecipient;
                            } catch (Exception $e) {
                                $failedRecipients[$mailRecipient] = $e->getMessage();
                            }
                        }
                    }

                    $deliveryStatus = format_mail_delivery_status($successfulRecipients, $failedRecipients);
                    $auditDeliveryStatus = format_mail_delivery_status($successfulRecipients, $failedRecipients, true);
                    $mailState = $failedRecipients ? 2 : 1;
                    $completedBy = $currentAdminUser !== '' ? $currentAdminUser : ($isDevMode ? 'dev' : 'marc');
                    persist_completion_mail_result($mysqli, $requestId, $mailState, $deliveryStatus, $completedBy);

                    $logAction = $isDevMode ? 'BULK_MAIL_SENT_DEV' : 'BULK_MAIL_SENT';
                    if (!$isDevMode && $failedRecipients && $successfulRecipients) {
                        $logAction = 'BULK_MAIL_PARTIAL_FAILED_COMPLETED';
                    } elseif (!$isDevMode && $failedRecipients) {
                        $logAction = 'BULK_MAIL_FAILED_COMPLETED';
                    }
                    log_request_action(
                        $mysqli,
                        $requestId,
                        $logAction,
                        ($isDevMode ? 'DEV-Simulation; ' : '') . 'Zustellung: ' . ($auditDeliveryStatus ?: $recipient) . '; Seriennummer: ' . ($serial ?: 'unbekannt') . '; Gerät: ' . ($deviceName ?: 'unbekannt')
                    );
                    if ($failedRecipients) {
                        $failedCount++;
                    } else {
                        $successCount++;
                    }
            }

            $bulkMessage = "Bulk-Mail abgeschlossen: {$successCount} gesendet, {$failedCount} fehlgeschlagen, {$skippedCount} übersprungen. Mailfehler halten den fachlichen Abschluss nicht offen.";
            $bulkAsmSerials = [];
            $bulkLastIds = [];
            $bulkLastStep = '';
        } else {
            $bulkError = 'Unbekannte Bulk-Aktion.';
        }
    }

    if (isset($_POST['send_mail'])) {
        $sendTo = trim($_POST['send_to'] ?? '');
        $sendSubject = trim($_POST['send_subject'] ?? '');
        $sendBody = trim($_POST['send_body'] ?? '');
        $sendRecipients = array_values(array_unique(array_filter(array_map(
            static fn($address) => trim((string) $address),
            preg_split('/[,\n;]+/', $sendTo) ?: []
        ))));
        $invalidRecipients = array_filter($sendRecipients, static function ($address) {
            return !filter_var($address, FILTER_VALIDATE_EMAIL);
        });
        $sendRecipientList = implode(', ', $sendRecipients);

        $sendDevice = trim($_POST['send_device'] ?? '');
        $sendSerial = trim($_POST['send_serial'] ?? '');
        $sendRequestId = (int) ($_POST['send_request_id'] ?? 0);
        $mailRequest = null;
        $mailWorkflowAllowed = false;
        if ($sendRequestId > 0) {
            $mailRequest = load_request_for_action($mysqli, $sendRequestId);
            if ($mailRequest) {
                $mailWorkflowAllowed = disown_request_allows_completion_mail($mailRequest);
                $sendSerial = (string) ($mailRequest['serial'] ?? '');
                $sendDevice = (string) ($mailRequest['device_name'] ?? '');
                $requestRecipients = request_mail_recipients($mailRequest);
                if ($sendRecipients) {
                    $allowedRecipients = array_map('strtolower', $requestRecipients);
                    $unauthorizedRecipients = array_filter(
                        $sendRecipients,
                        static fn($address) => !in_array(strtolower((string) $address), $allowedRecipients, true)
                    );
                    if ($unauthorizedRecipients) {
                        $invalidRecipients = array_merge($invalidRecipients, $unauthorizedRecipients);
                    }
                }
            }
        }

        if (!$mailWorkflowAllowed) {
            if ($sendRequestId > 0) {
                log_request_action($mysqli, $sendRequestId, 'COMPLETION_MAIL_DENIED', 'Keine gültige Jamf-Befehlsannahme/Verifikation oder ASM/ADE nicht abgeschlossen.');
            }
            $mailError = 'Abschlussmail gesperrt: Es braucht eine gültige Jamf-Befehlsannahme oder Verifikation und eine bestätigte ASM/ADE-Freigabe.';
        } elseif (!$sendRecipients || $invalidRecipients || $sendSubject === '' || $sendBody === '') {
            if ($sendRequestId > 0) {
                $completedBy = $currentAdminUser !== '' ? $currentAdminUser : ($isDevMode ? 'dev' : 'marc');
                complete_request_with_mail_failure(
                    $mysqli,
                    $sendRequestId,
                    $sendRecipientList,
                    $completedBy,
                    $sendSerial,
                    $sendDevice,
                    'Empfänger, Betreff oder Nachricht ungültig.'
                );
            }
            $mailError = 'E-Mail-Adresse, Betreff oder Nachricht ungültig. Der Vorgang wurde trotzdem abgeschlossen; der Mailfehler ist protokolliert.';
        } elseif ($isDevMode) {
            if ($sendRequestId > 0) {
                $completedBy = $currentAdminUser !== '' ? $currentAdminUser : 'dev';
                persist_completion_mail_result($mysqli, $sendRequestId, 1, $sendRecipientList, $completedBy);

                log_request_action(
                    $mysqli,
                    $sendRequestId,
                    'MAIL_SENT',
                    'DEV-Simulation; Empfänger: ' . $sendRecipientList . '; Seriennummer: ' . ($sendSerial ?: 'unbekannt') . '; Gerät: ' . ($sendDevice ?: 'unbekannt')
                );
            }

            $_SESSION['flash_mail_message'] = 'DEV-Modus: E-Mail wurde nicht versendet, aber als gesendet markiert für ' . htmlspecialchars($sendRecipientList) . '.';
            header('Location: ' . $adminPath);
            exit;
        } else {
            $mailConfig = disown_mail_config($mysqli);
            if (!disown_mail_config_is_complete($mailConfig)) {
                if ($sendRequestId > 0) {
                    $completedBy = $currentAdminUser !== '' ? $currentAdminUser : 'marc';
                    complete_request_with_mail_failure(
                        $mysqli,
                        $sendRequestId,
                        $sendRecipientList,
                        $completedBy,
                        $sendSerial,
                        $sendDevice,
                        'SMTP-Konfiguration unvollständig.'
                    );
                }
                $mailError = 'SMTP-Konfiguration fehlt oder ist unvollständig. Der Vorgang wurde trotzdem abgeschlossen; der Mailfehler ist protokolliert.';
            } else {
                try {
                    $successfulRecipients = [];
                    $failedRecipients = [];
                    foreach ($sendRecipients as $recipient) {
                        try {
                            send_release_mail($mailConfig, $recipient, $sendSubject, $sendBody, $sendDevice, $sendSerial);
                            $successfulRecipients[] = $recipient;
                        } catch (Exception $e) {
                            $failedRecipients[$recipient] = $e->getMessage();
                        }
                    }

                    $deliveryStatus = format_mail_delivery_status($successfulRecipients, $failedRecipients);
                    $auditDeliveryStatus = format_mail_delivery_status($successfulRecipients, $failedRecipients, true);
                    $mailState = $failedRecipients ? 2 : 1;
                    if ($sendRequestId > 0) {
                        $completedBy = $currentAdminUser !== '' ? $currentAdminUser : 'marc';
                        persist_completion_mail_result($mysqli, $sendRequestId, $mailState, $deliveryStatus, $completedBy);
                    }

                    if ($sendRequestId > 0) {
                        $logAction = 'MAIL_SENT';
                        if ($failedRecipients && $successfulRecipients) {
                            $logAction = 'MAIL_PARTIAL_FAILED_COMPLETED';
                        } elseif ($failedRecipients) {
                            $logAction = 'MAIL_FAILED_COMPLETED';
                        }
                        log_request_action(
                            $mysqli,
                            $sendRequestId,
                            $logAction,
                            'Zustellung: ' . ($auditDeliveryStatus ?: $sendRecipientList) . '; Seriennummer: ' . ($sendSerial ?: 'unbekannt') . '; Gerät: ' . ($sendDevice ?: 'unbekannt')
                        );
                    }

                    if ($failedRecipients) {
                        $_SESSION['flash_mail_error'] = 'Mailversand teilweise oder vollständig fehlgeschlagen. Details stehen in der betroffenen Zeile.';
                    } else {
                        $_SESSION['flash_mail_message'] = 'E-Mail erfolgreich gesendet an ' . htmlspecialchars($sendRecipientList) . '.';
                    }
                    header('Location: ' . $adminPath);
                    exit;
                } catch (Exception $e) {
                    if ($sendRequestId > 0) {
                        $completedBy = $currentAdminUser !== '' ? $currentAdminUser : 'marc';
                        complete_request_with_mail_failure(
                            $mysqli,
                            $sendRequestId,
                            $sendRecipientList,
                            $completedBy,
                            $sendSerial,
                            $sendDevice,
                            $e->getMessage()
                        );
                    }
                    $mailError = 'SMTP-Fehler: ' . htmlspecialchars($e->getMessage()) . ' Der Vorgang wurde trotzdem abgeschlossen; der Mailfehler ist protokolliert.';
                }
            }
        }
    }

    // NEW WORKFLOW: Jamf Unenroll
    if (isset($_POST['unenroll'])) {
        $unenrollId = (int) $_POST['unenroll'];
        $postedUnenrollSerial = strtoupper(trim((string) ($_POST['unenroll_serial'] ?? '')));

        if ($unenrollId > 0) {
            $unenrollRequest = load_request_for_action($mysqli, $unenrollId);
            $unenrollSerial = strtoupper(trim((string) ($unenrollRequest['serial'] ?? '')));
            if (!$unenrollRequest || $unenrollSerial === '') {
                $disownError = 'Antrag oder gespeicherte Seriennummer wurde nicht gefunden.';
            } elseif ($postedUnenrollSerial !== '' && $postedUnenrollSerial !== $unenrollSerial) {
                log_request_action($mysqli, $unenrollId, 'JAMF_UNENROLL_REQUEST_REJECTED', 'Browser-Seriennummer stimmt nicht mit dem Antrag überein.');
                $disownError = 'Die Seriennummer des Formulars stimmt nicht mit dem Antrag überein. Bitte Seite neu laden.';
            } else {
                $allowSchoolLoan = request_is_admin_direct($unenrollRequest);
                $storedJamfState = disown_jamf_state_from_row($unenrollRequest);
                if ($storedJamfState === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED) {
                    $result = [
                        'state' => DISOWN_JAMF_STATE_VERIFIED_UNMANAGED,
                        'success' => true,
                        'accepted' => false,
                        'response_class' => 'already_verified',
                        'message' => 'Jamf-Abmeldung ist bereits verifiziert.',
                    ];
                } elseif (in_array($storedJamfState, [DISOWN_JAMF_STATE_COMMAND_ACCEPTED, DISOWN_JAMF_STATE_PENDING_DEVICE, DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED], true)) {
                    log_request_action($mysqli, $unenrollId, 'JAMF_UNENROLL_RECHECK_REQUESTED', 'Einzelaktion; nur read-only');
                    $result = jamf_verify_unenroll_by_serial(
                        $unenrollSerial,
                        $unenrollRequest['jamf_command_accepted_at'] ?? null,
                        false,
                        $storedJamfState === DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED
                            || (int) ($unenrollRequest['jamf_check_attempts'] ?? 0) >= 5
                    );
                    $result['http_status'] = $result['observation']['active_http_status'] ?? null;
                    $result['response_class'] = 'postcondition_recheck';
                    $result['accepted'] = false;
                } else {
                    log_request_action($mysqli, $unenrollId, 'JAMF_UNENROLL_REQUESTED', $allowSchoolLoan ? 'Admin-Sonderweg' : 'Einzelaktion');
                    $result = jamf_unenroll_by_serial($unenrollSerial, $allowSchoolLoan);
                }
                $state = persist_jamf_workflow_result($mysqli, $unenrollId, $result);
                audit_jamf_workflow_result($mysqli, $unenrollId, $result, $allowSchoolLoan ? 'Admin-Sonderweg' : 'Einzelaktion');
                $message = (string) ($result['message'] ?? '');

                $verifiedRequest = load_request_for_action($mysqli, $unenrollId);
                if ($verifiedRequest && disown_request_has_release_basis($verifiedRequest)
                    && (disown_request_allows_asm($verifiedRequest) || !empty($verifiedRequest['asm_manual_done']))) {
                    if ($verifiedRequest && !empty($verifiedRequest['asm_manual_done'])) {
                        $disownMessage = $message . ' ASM/ADE ist bereits abgeschlossen; die Abschlussmail kann vorbereitet werden.';
                    } elseif ($verifiedRequest) {
                        $automaticAsmResult = execute_asm_release_and_persist($mysqli, $verifiedRequest, 'Adminfreigabe nach Jamf-Verifikation oder angenommener Abmeldung');
                        if (!empty($automaticAsmResult['local_completed'])) {
                            $disownMessage = $message . ' ASM/ADE-Freigabe bestätigt. Mail bleibt manuell; verbleibende Jamf-Nacharbeit wird im Hintergrund geprüft.';
                        } else {
                            $disownError = $message . ' ASM/ADE konnte nicht automatisch abgeschlossen werden: '
                                . (string) ($automaticAsmResult['message'] ?? 'unbekannter Fehler')
                                . ' Der ASM-Schritt kann separat erneut versucht werden.';
                        }
                    } else {
                        $disownError = $message . ' Jamf ist verifiziert, aber der Antrag konnte für den automatischen ASM-Schritt nicht erneut geladen werden.';
                    }
                } elseif (in_array($state, [DISOWN_JAMF_STATE_COMMAND_ACCEPTED, DISOWN_JAMF_STATE_PENDING_DEVICE], true)) {
                    $disownError = $message . ' Die aktuelle Jamf-Zuordnung konnte nicht sicher gelesen werden. Bitte erneut prüfen; ASM/ADE wurde nicht freigegeben.';
                } else {
                    $disownError = $message !== '' ? $message : 'Jamf-Abmeldung konnte nicht bestätigt werden.';
                }
            }
        } else {
            $disownError = 'Ungültige Unenroll-Anfrage. Bitte Seite neu laden und erneut versuchen.';
        }
    }

    // NEW WORKFLOW: ASM/ADE automatic release broker preview
    if (isset($_POST['asm_release_preview'])) {
        $asmReleaseId = (int) $_POST['asm_release_preview'];
        $row = load_request_for_action($mysqli, $asmReleaseId);

        if (!$row) {
            $disownError = 'Antrag wurde nicht gefunden.';
        } elseif (($row['completed_by'] ?? '') === 'history-import') {
            $disownError = 'Historische Importe können nicht automatisch freigegeben werden.';
        } elseif (!disown_request_allows_asm($row)) {
            $disownError = 'ASM/ADE ist für diesen Zustand nicht freigegeben. Jamf-Befehlsannahme und aktuelle Zuordnung zuerst prüfen.';
        } elseif (!empty($row['asm_manual_done'])) {
            $disownError = 'ASM/ADE ist für diesen Antrag bereits abgeschlossen.';
        } else {
            $asmReleaseRequest = $row;
            $asmReleasePreview = asm_release_preview((string) $row['serial']);
        }
    }

    // NEW WORKFLOW: ASM/ADE automatic release broker execution
    if (isset($_POST['asm_release_confirm'])) {
        $asmReleaseId = (int) $_POST['asm_release_confirm'];
        $row = load_request_for_action($mysqli, $asmReleaseId);

        if (!$row) {
            $disownError = 'Antrag wurde nicht gefunden.';
        } elseif (($row['completed_by'] ?? '') === 'history-import') {
            $disownError = 'Historische Importe können nicht automatisch freigegeben werden.';
        } elseif (!disown_request_allows_asm($row)) {
            $disownError = 'ASM/ADE ist für diesen Zustand nicht freigegeben. Jamf-Befehlsannahme und aktuelle Zuordnung zuerst prüfen.';
        } elseif (!empty($row['asm_manual_done'])) {
            $disownError = 'ASM/ADE ist für diesen Antrag bereits abgeschlossen.';
        } else {
            $asmReleaseRequest = $row;
            $asmReleasePreview = asm_release_execute((string) $row['serial']);
            $detailText = 'Seriennummer: ' . strtoupper((string) $row['serial']) . '; ' . ($asmReleasePreview['message'] ?? '');
            $assignmentAttempts = (int) ($asmReleasePreview['details']['assignment_attempts'] ?? 1);
            if ($assignmentAttempts > 1) {
                $detailText .= '; ASM-Zuweisungsprüfungen: ' . $assignmentAttempts;
            }

            $isDevFixture = !empty($asmReleasePreview['details']['dev_fixture']);
            if (!empty($asmReleasePreview['success']) && (empty($asmReleasePreview['dry_run']) || $isDevFixture)) {
                $updateStmt = $mysqli->prepare(
                    "UPDATE requests
                     SET asm_manual_done = 1,
                         asm_manual_done_at = NOW()
                     WHERE id = ?"
                );
                if ($updateStmt) {
                    $updateStmt->bind_param('i', $asmReleaseId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
                $alreadyReleased = !empty($asmReleasePreview['details']['already_released']);
                log_request_action(
                    $mysqli,
                    $asmReleaseId,
                    $isDevFixture
                        ? 'ASM_DEV_SIMULATED'
                        : ($alreadyReleased ? 'ASM_ALREADY_RELEASED_CONFIRMED' : 'ASM_BROKER_RELEASE_SUCCESS'),
                    $detailText
                );
                $disownMessage = $isDevFixture
                    ? 'DEV-Simulation erfolgreich: ASM/ADE wurde nur lokal als abgeschlossen markiert; extern wurde nichts geändert.'
                    : ($alreadyReleased
                    ? 'Die bereits erfolgte ASM/ADE-Freigabe wurde bestätigt und lokal abgeschlossen.'
                    : 'ASM/ADE-Freigabe automatisch abgeschlossen.');
            } elseif (!empty($asmReleasePreview['success']) && !empty($asmReleasePreview['dry_run'])) {
                $disownMessage = 'DEV-Dry-Run erfolgreich: In DEV wurde nichts in ASM/ADE geändert.';
            } else {
                log_request_action($mysqli, $asmReleaseId, 'ASM_BROKER_RELEASE_FAILED', $detailText);
                $disownError = (string) ($asmReleasePreview['message'] ?? 'ASM/ADE-Freigabe fehlgeschlagen.');
            }
        }
    }

    // NEW WORKFLOW: ASM manual release confirmed
    if (isset($_POST['asm_manual_done'])) {
        $asmId = (int) $_POST['asm_manual_done'];
        log_request_action($mysqli, max(0, $asmId), 'ASM_OVERRIDE_DENIED', 'Ungeschützter Notfallabschluss ist in DISOWN 2.6 deaktiviert.');
        $disownError = 'Der bisherige Notfallabschluss ist deaktiviert. Ein Override wird erst mit Critical Mode, Begründung und ausdrücklicher Bestätigung verfügbar.';
    }

}

$currentYear = (int) date('Y');
$currentMonth = (int) date('n');
$schoolYearStartYear = $currentMonth >= 8 ? $currentYear : $currentYear - 1;
$schoolYearEndYear = $schoolYearStartYear + 1;
$schoolYearLabel = $schoolYearStartYear . '/' . $schoolYearEndYear;
$schoolYearStart = $schoolYearStartYear . '-08-01 00:00:00';
$schoolYearEnd = $schoolYearEndYear . '-08-01 00:00:00';

$dashboard = [
    'open_requests' => 0,
    'scheduled_requests' => 0,
    'done_requests' => 0,
    'waiting_jamf' => 0,
    'waiting_asm' => 0,
    'waiting_mail' => 0,
    'reenrollment_alerts' => 0,
    'jamf_unenrolled' => 0,
    'asm_done' => 0,
    'mail_sent' => 0,
    'avg_admin_processing_seconds' => null,
    'avg_student_response_seconds' => null,
    'school_year_total' => 0,
    'school_year_done' => 0,
    'school_year_open' => 0,
];
$dashboardStmt = $mysqli->prepare(
    "SELECT
         COALESCE(SUM(CASE WHEN {$dueCondition} THEN 1 ELSE 0 END), 0) AS open_requests,
         COALESCE(SUM(CASE WHEN {$scheduledCondition} THEN 1 ELSE 0 END), 0) AS scheduled_requests,
         COALESCE(SUM(CASE WHEN {$doneCondition} THEN 1 ELSE 0 END), 0) AS done_requests,
         COALESCE(SUM(CASE WHEN ({$dueCondition}) AND jamf_unenrolled = 0 AND jamf_command_accepted_at IS NULL AND asm_manual_done = 0 THEN 1 ELSE 0 END), 0) AS waiting_jamf,
         COALESCE(SUM(CASE WHEN (jamf_unenrolled = 1 OR jamf_command_accepted_at IS NOT NULL) AND asm_manual_done = 0 AND mail_sent = 0 THEN 1 ELSE 0 END), 0) AS waiting_asm,
         COALESCE(SUM(CASE WHEN asm_manual_done = 1 AND mail_sent = 0 THEN 1 ELSE 0 END), 0) AS waiting_mail,
         COALESCE(SUM(CASE WHEN jamf_reenrollment_detected_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS reenrollment_alerts,
         COALESCE(SUM(CASE WHEN jamf_unenrolled = 1 THEN 1 ELSE 0 END), 0) AS jamf_unenrolled,
         COALESCE(SUM(CASE WHEN asm_manual_done = 1 THEN 1 ELSE 0 END), 0) AS asm_done,
         COALESCE(SUM(CASE WHEN mail_sent = 1 THEN 1 ELSE 0 END), 0) AS mail_sent,
         AVG(CASE
             WHEN {$doneCondition}
                  AND (completed_by IS NULL OR completed_by <> 'history-import')
                  AND jamf_unenrolled_at IS NOT NULL
                  AND asm_manual_done_at IS NOT NULL
             THEN TIMESTAMPDIFF(SECOND, jamf_unenrolled_at, asm_manual_done_at)
             ELSE NULL
         END) AS avg_admin_processing_seconds,
         AVG(CASE
             WHEN {$doneCondition}
                  AND (completed_by IS NULL OR completed_by <> 'history-import')
                  AND COALESCE(completed_at, mail_sent_at) IS NOT NULL
             THEN TIMESTAMPDIFF(SECOND, created_at, COALESCE(completed_at, mail_sent_at))
             ELSE NULL
         END) AS avg_student_response_seconds,
         COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) AS school_year_total,
         COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? AND {$doneCondition} THEN 1 ELSE 0 END), 0) AS school_year_done,
         COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? AND ({$dueCondition}) THEN 1 ELSE 0 END), 0) AS school_year_open
     FROM requests"
);
if ($dashboardStmt) {
    $dashboardStmt->bind_param('ssssss', $schoolYearStart, $schoolYearEnd, $schoolYearStart, $schoolYearEnd, $schoolYearStart, $schoolYearEnd);
    if ($dashboardStmt->execute()) {
        $dashboardResult = $dashboardStmt->get_result();
        $dashboard = array_merge($dashboard, $dashboardResult->fetch_assoc() ?: []);
    }
    $dashboardStmt->close();
}

$requestTrend = [];
$requestTrendByMonth = [];
$trendMonthNames = [
    1 => 'Jan',
    2 => 'Feb',
    3 => 'Mrz',
    4 => 'Apr',
    5 => 'Mai',
    6 => 'Jun',
    7 => 'Jul',
    8 => 'Aug',
    9 => 'Sep',
    10 => 'Okt',
    11 => 'Nov',
    12 => 'Dez',
];
$trendEnd = (new DateTimeImmutable('first day of next month'))->format('Y-m-d 00:00:00');
$trendStart = (new DateTimeImmutable('first day of this month'))->modify('-11 months')->format('Y-m-d 00:00:00');
$trendStmt = $mysqli->prepare(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_key, COUNT(*) AS total
     FROM requests
     WHERE created_at >= ? AND created_at < ?
     GROUP BY month_key
     ORDER BY month_key"
);
if ($trendStmt) {
    $trendStmt->bind_param('ss', $trendStart, $trendEnd);
    if ($trendStmt->execute()) {
        $trendResult = $trendStmt->get_result();
        while ($trendRow = $trendResult->fetch_assoc()) {
            $requestTrendByMonth[(string) $trendRow['month_key']] = (int) $trendRow['total'];
        }
    }
    $trendStmt->close();
}

$trendCursor = new DateTimeImmutable($trendStart);
$requestTrendMax = 1;
for ($i = 0; $i < 12; $i++) {
    $monthKey = $trendCursor->format('Y-m');
    $count = $requestTrendByMonth[$monthKey] ?? 0;
    $requestTrendMax = max($requestTrendMax, $count);
    $requestTrend[] = [
        'label' => $trendMonthNames[(int) $trendCursor->format('n')],
        'month_key' => $monthKey,
        'count' => $count,
        'is_current_year' => (int) $trendCursor->format('Y') === $currentYear,
        'is_peak_season' => in_array($trendCursor->format('n'), ['6', '7', '8'], true),
    ];
    $trendCursor = $trendCursor->modify('+1 month');
}

function format_duration_seconds($seconds): string
{
    if ($seconds === null) {
        return '–';
    }

    $durationSeconds = max(0, (int) round((float) $seconds));
    $avgDays = intdiv($durationSeconds, 86400);
    $avgHours = intdiv($durationSeconds % 86400, 3600);
    $avgMinutes = intdiv($durationSeconds % 3600, 60);
    if ($avgDays > 0) {
        return $avgDays . ' T ' . $avgHours . ' Std';
    }
    if ($avgHours > 0) {
        return $avgHours . ' Std ' . $avgMinutes . ' Min';
    }

    return max(1, $avgMinutes) . ' Min';
}
$avgAdminProcessingText = format_duration_seconds($dashboard['avg_admin_processing_seconds']);
$avgStudentResponseText = format_duration_seconds($dashboard['avg_student_response_seconds']);
$jamfLicenseDashboard = load_jamf_license_dashboard($mysqli, $jamfLicenseBaseline);
$releaseBrokerHealth = load_release_broker_health();
$releaseBrokerToken = load_release_broker_token_expiry();
$releaseBrokerTokenClassMap = [
    'free' => 'license-dashboard-free',
    'warn' => 'license-dashboard-warn',
    'error' => 'license-dashboard-error',
    'muted' => 'license-dashboard-muted',
];
$releaseBrokerTokenClass = $releaseBrokerTokenClassMap[$releaseBrokerToken['severity'] ?? 'muted'] ?? 'license-dashboard-muted';
$releaseBrokerDashboardTitle = trim(($releaseBrokerHealth['message'] ?? '') . ' ' . ($releaseBrokerToken['message'] ?? ''));
if ($releaseBrokerDashboardTitle === '') {
    $releaseBrokerDashboardTitle = 'Release Broker Status';
}
$pushMailStatus = disown_push_mail_status($mysqli);
$openDeviceCaseCount = 0;
$cleanupScope = disown_jamf_cleanup_open_sql();
$cleanupCounts = $mysqli->query("SELECT COUNT(*) AS total,
    COALESCE(SUM(jamf_cleanup_state = 'manual_trash_required'), 0) AS trash_needed
    FROM requests WHERE {$cleanupScope}")->fetch_assoc();
$openCaseResult = $mysqli->query("SELECT COUNT(*) AS total FROM device_cases WHERE status <> 'geklaert'");
if ($openCaseResult) {
    $openDeviceCaseCount = (int) (($openCaseResult->fetch_assoc()['total'] ?? 0));
}

$countStmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM requests {$whereSql}");
if (!$countStmt) {
    die('Datenbankfehler: ' . htmlspecialchars($mysqli->error));
}
if ($whereParams) {
    $countStmt->bind_param($whereTypes, ...$whereParams);
}
if (!$countStmt->execute()) {
    die('Datenbankfehler: ' . htmlspecialchars($countStmt->error));
}
$countResult = $countStmt->get_result();
$totalRows = (int) (($countResult->fetch_assoc()['total'] ?? 0));
$countStmt->close();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

if (($_GET['export'] ?? '') === 'requests_csv') {
    $exportStmt = $mysqli->prepare(
        "SELECT
             id,
             created_at,
             full_name,
             username,
             class_name,
             email,
             private_email,
             device_name,
             requested_release_date,
             serial,
             status,
             jamf_unenrolled,
             jamf_reenrollment_detected_at,
             asm_manual_done,
             mail_sent
         FROM requests
         {$whereSql}
         ORDER BY created_at DESC"
    );

    if (!$exportStmt) {
        http_response_code(500);
        echo 'Datenbankfehler';
        exit;
    }
    if ($whereParams) {
        $exportStmt->bind_param($whereTypes, ...$whereParams);
    }
    if (!$exportStmt->execute()) {
        http_response_code(500);
        echo 'Datenbankfehler';
        exit;
    }
    $exportResult = $exportStmt->get_result();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="disown-requests-' . date('Ymd') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'ID',
        'Datum',
        'Name',
        'IServ-Benutzer',
        'Klasse',
        'Schulische E-Mail',
        'Private E-Mail',
        'Gerät',
        'Wunschtermin',
        'Seriennummer',
        'Antrag-Status',
        'Jamf-Unenroll',
        'Jamf-Wiederanmeldung erkannt',
        'ASM-manuell',
        'Mailstatus',
    ]);

    while ($row = $exportResult->fetch_assoc()) {
        fputcsv($output, [
            $row['id'],
            $row['created_at'],
            $row['full_name'],
            $row['username'],
            $row['class_name'],
            $row['email'],
            $row['private_email'],
            $row['device_name'],
            $row['requested_release_date'],
            $row['serial'],
            $row['status'],
            !empty($row['jamf_unenrolled']) ? 'ja' : 'nein',
            $row['jamf_reenrollment_detected_at'] ?? '',
            !empty($row['asm_manual_done']) ? 'ja' : 'nein',
            mail_status_label($row['mail_sent']),
        ]);
    }
    fclose($output);
    $exportStmt->close();
    exit;
}

$result = $mysqli->prepare(
    "SELECT
         id,
         created_at,
         full_name,
         username,
         class_name,
         email,
         private_email,
         device_name,
         requested_release_date,
         serial,
         status,
         jamf_unenrolled,
         jamf_unenroll_state,
         jamf_command_accepted_at,
         jamf_check_attempts,
         jamf_command_status,
         jamf_observed_location,
         jamf_cleanup_state,
         jamf_cleanup_checked_at,
         jamf_reenrollment_detected_at,
         jamf_unenrolled_at,
         jamf_unenroll_error,
         asm_manual_done,
         asm_manual_done_at,
         mail_sent,
         mail_sent_at,
         mail_sent_to,
         completed_by,
         completed_at,
         asm_disowned,
         asm_disowned_at,
         asm_task_id,
         asm_error,
         (
             SELECT COUNT(*)
             FROM device_cases dc_open
             WHERE dc_open.serial = requests.serial
               AND dc_open.status <> 'geklaert'
         ) AS open_case_count,
         (
             SELECT dc_latest.id
             FROM device_cases dc_latest
             WHERE dc_latest.serial = requests.serial
             ORDER BY FIELD(dc_latest.status, 'offen', 'geklaert'), dc_latest.updated_at DESC, dc_latest.id DESC
             LIMIT 1
         ) AS latest_case_id,
         (
             SELECT dc_latest.title
             FROM device_cases dc_latest
             WHERE dc_latest.serial = requests.serial
             ORDER BY FIELD(dc_latest.status, 'offen', 'geklaert'), dc_latest.updated_at DESC, dc_latest.id DESC
             LIMIT 1
         ) AS latest_case_title,
         (
             SELECT dc_latest.status
             FROM device_cases dc_latest
             WHERE dc_latest.serial = requests.serial
             ORDER BY FIELD(dc_latest.status, 'offen', 'geklaert'), dc_latest.updated_at DESC, dc_latest.id DESC
             LIMIT 1
         ) AS latest_case_status,
         (
             SELECT dc_latest.note
             FROM device_cases dc_latest
             WHERE dc_latest.serial = requests.serial
             ORDER BY FIELD(dc_latest.status, 'offen', 'geklaert'), dc_latest.updated_at DESC, dc_latest.id DESC
             LIMIT 1
         ) AS latest_case_note,
         (
             SELECT dc_latest.resolution_note
             FROM device_cases dc_latest
             WHERE dc_latest.serial = requests.serial
             ORDER BY FIELD(dc_latest.status, 'offen', 'geklaert'), dc_latest.updated_at DESC, dc_latest.id DESC
             LIMIT 1
         ) AS latest_case_resolution_note,
         (
             SELECT dc_latest.updated_at
             FROM device_cases dc_latest
             WHERE dc_latest.serial = requests.serial
             ORDER BY FIELD(dc_latest.status, 'offen', 'geklaert'), dc_latest.updated_at DESC, dc_latest.id DESC
             LIMIT 1
         ) AS latest_case_updated_at,
         (
             SELECT ral.admin_user
             FROM request_audit_log ral
             WHERE ral.request_id = requests.id
             ORDER BY ral.created_at DESC, ral.id DESC
             LIMIT 1
         ) AS last_audit_user,
         (
             SELECT ral.created_at
             FROM request_audit_log ral
             WHERE ral.request_id = requests.id
             ORDER BY ral.created_at DESC, ral.id DESC
             LIMIT 1
         ) AS last_audit_at
         ,(
             SELECT COUNT(*)
             FROM request_audit_log ral_count
             WHERE ral_count.request_id = requests.id
         ) AS audit_count
         ,(
             SELECT COUNT(*)
             FROM request_audit_log ral_created
             WHERE ral_created.request_id = requests.id
               AND ral_created.action = 'REQUEST_CREATED'
         ) AS creation_audit_count
         ,(
             SELECT COUNT(*)
             FROM device_cases dc_request
             WHERE dc_request.request_id = requests.id
         ) AS request_case_count
     FROM requests
     {$whereSql}
     ORDER BY created_at DESC
     LIMIT ? OFFSET ?"
);

if (!$result) {
    die('Datenbankfehler: ' . htmlspecialchars($mysqli->error));
}
$listParams = $whereParams;
$listTypes = $whereTypes . 'ii';
$listParams[] = $perPage;
$listParams[] = $offset;
if (!$result->bind_param($listTypes, ...$listParams) || !$result->execute()) {
    die('Datenbankfehler: ' . htmlspecialchars($result->error));
}
$result = $result->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);
$casesBySerial = [];
$pageSerials = array_values(array_unique(array_filter(array_map(static function (array $row): string {
    return strtoupper(trim((string) ($row['serial'] ?? '')));
}, $rows))));
if ($pageSerials) {
    $casePlaceholders = implode(',', array_fill(0, count($pageSerials), '?'));
    $caseTypes = str_repeat('s', count($pageSerials));
    $caseStmt = $mysqli->prepare(
        "SELECT id, serial, request_id, source, title, status, note, resolution_note, updated_at
         FROM device_cases
         WHERE serial IN ({$casePlaceholders})
         ORDER BY serial ASC, FIELD(status, 'offen', 'geklaert'), updated_at DESC, id DESC"
    );
    if ($caseStmt && $caseStmt->bind_param($caseTypes, ...$pageSerials) && $caseStmt->execute()) {
        $caseResult = $caseStmt->get_result();
        while ($caseRow = $caseResult->fetch_assoc()) {
            $caseSerial = strtoupper(trim((string) ($caseRow['serial'] ?? '')));
            $casesBySerial[$caseSerial][] = $caseRow;
        }
    }
    if ($caseStmt) {
        $caseStmt->close();
    }
}

?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<?php if (defined('DISOWN_BASE_HREF')): ?>
<base href="<?=htmlspecialchars(DISOWN_BASE_HREF)?>">
<?php endif; ?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="<?=htmlspecialchars($faviconPath)?>">
<title>iPad-Management</title>
<style>
:root {
    --disown-site-image: url("<?=htmlspecialchars($siteImagePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>");
}
</style>
<link rel="stylesheet" href="<?=htmlspecialchars($adminCssUrl)?>">
</head>
<body>
<div class="page">
    <div class="header">
        <div>
            <h1 class="page-title">iPad-Management</h1>
            <p>Offene und erledigte Anträge anzeigen. Schließe Anträge direkt hier ab.</p>
            <p class="hint-text">Der automatische Jamf-Unenroll entfernt die MDM-Verwaltung. Die ADE/ASM-Freigabe erfolgt anschließend automatisch über den Release Broker.</p>
        </div>
        <div class="header-actions">
            <div class="logo-actions">
                <img src="<?=htmlspecialchars($logoPath)?>" alt="BBS Einbeck" class="site-logo">
                <a class="refresh-link" href="<?=htmlspecialchars($refreshUrl)?>" title="Seite aktualisieren" aria-label="Seite aktualisieren">↻</a>
            </div>
            <a class="admin-user" href="<?=htmlspecialchars($logoutPath)?>">👤 <?=htmlspecialchars($currentAdminUser)?> · <?=htmlspecialchars($accessLabel)?></a>
            <div class="tool-links" aria-label="Admin-Werkzeuge">
                <a class="tool-link" href="https://bbseinbeck.jamfcloud.com/" target="_blank" rel="noopener noreferrer" title="Jamf in neuem Fenster öffnen">
                    <img class="tool-logo" src="<?=htmlspecialchars($jamfLogoPath)?>" alt="Jamf">
                </a>
                <a class="tool-link" href="https://school.apple.com" onclick="openAsmPortal(); return false;" title="Apple School Manager öffnen">
                    <img class="tool-logo" src="<?=htmlspecialchars($asmLogoPath)?>" alt="ASM">
                </a>
                <a class="button button-secondary audit-log-link" href="<?=htmlspecialchars($adePath)?>">ADE-Aufnahmen</a>
                <a class="button button-secondary audit-log-link" href="<?=htmlspecialchars($kukPath)?>">KUK-Geräte</a>
                <a class="button button-secondary audit-log-link" href="<?=htmlspecialchars($auditLogPath)?>">Audit-Log</a>
                <a class="tool-link settings-tool-link" href="<?=htmlspecialchars($settingsPath)?>" title="Einstellungen öffnen" aria-label="Einstellungen öffnen">
                    <svg class="settings-tool-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M4 7h8"></path>
                        <path d="M16 7h4"></path>
                        <circle cx="14" cy="7" r="2"></circle>
                        <path d="M4 12h3"></path>
                        <path d="M11 12h9"></path>
                        <circle cx="9" cy="12" r="2"></circle>
                        <path d="M4 17h10"></path>
                        <path d="M18 17h2"></path>
                        <circle cx="16" cy="17" r="2"></circle>
                    </svg>
                </a>
            </div>
        </div>
    </div>

    <form class="search-toolbar" method="get" action="<?=htmlspecialchars($adminPath)?>">
        <input type="hidden" name="filter" value="<?=htmlspecialchars($filter)?>">
        <?php if ($monthFilter !== ''): ?>
            <input type="hidden" name="month" value="<?=htmlspecialchars($monthFilter)?>">
        <?php endif; ?>
        <input type="hidden" name="page" value="1">
        <label for="searchInput" class="search-label">Suche</label>
        <div class="search-field">
            <span class="search-icon" aria-hidden="true">⌕</span>
            <input id="searchInput" name="q" type="search" class="search-input" placeholder="Name, Klasse, IServ-Benutzer, E-Mail oder Seriennummer" value="<?=htmlspecialchars($searchTerm)?>" autocomplete="off">
            <?php if ($searchTerm !== ''): ?>
                <a class="clear-search" href="<?=htmlspecialchars(admin_url(['q' => '', 'page' => 1, 'search_focus' => null, 'export' => null]))?>" aria-label="Suche löschen">×</a>
            <?php endif; ?>
        </div>
        <button type="submit" class="button button-secondary">Suchen</button>
        <?php if ($canWrite): ?>
            <button type="button" class="button button-secondary" onclick="toggleTemplateEditor()">Vorlage bearbeiten</button>
        <?php endif; ?>
    </form>

    <?php if (!$canWrite): ?>
        <div class="readonly-banner">
            <strong>Nur-Lese-Zugriff:</strong> Sie können Anträge, ADE-Aufnahmen und Audit-Log ansehen, filtern und exportieren. Jamf-, ASM-, Mail-, Bulk- und Vorlagenaktionen sind deaktiviert.
        </div>
    <?php endif; ?>

    <div class="filter-bar">
        <a class="button filter-link <?= $filter === 'open' ? 'active' : '' ?>" href="<?=htmlspecialchars(admin_url(['filter' => 'open', 'page' => 1, 'export' => null]))?>">Offen</a>
        <a class="button filter-link <?= $filter === 'scheduled' ? 'active' : '' ?>" href="<?=htmlspecialchars(admin_url(['filter' => 'scheduled', 'page' => 1, 'export' => null]))?>">Terminiert</a>
        <a class="button filter-link <?= $filter === 'done' ? 'active' : '' ?>" href="<?=htmlspecialchars(admin_url(['filter' => 'done', 'page' => 1, 'export' => null]))?>">Erledigt</a>
        <a class="button filter-link <?= $filter === 'all' ? 'active' : '' ?>" href="<?=htmlspecialchars(admin_url(['filter' => 'all', 'page' => 1, 'export' => null]))?>">Alle</a>
        <a class="button filter-link <?= $filter === 'cases' ? 'active' : '' ?>" href="<?=htmlspecialchars(admin_url(['filter' => 'cases', 'page' => 1, 'export' => null]))?>">Klärfälle (<?=htmlspecialchars((string) $openDeviceCaseCount)?> offen)</a>
        <a class="button filter-link <?= $filter === 'cleanup' ? 'active' : '' ?>" href="<?=htmlspecialchars(admin_url(['filter' => 'cleanup', 'page' => 1, 'export' => null]))?>">Jamf-Nacharbeit (<?=htmlspecialchars((string) ($cleanupCounts['total'] ?? 0))?>)</a>
        <?php if ($monthFilter !== ''): ?>
            <a class="button filter-link month-filter-chip active" href="<?=htmlspecialchars(admin_url(['month' => null, 'page' => 1, 'export' => null]))?>">Monat: <?=htmlspecialchars($monthFilterLabel)?> ×</a>
        <?php endif; ?>
    </div>

    <div class="dashboard" aria-label="Statistik">
        <span class="dashboard-stat warn <?= (int) $dashboard['open_requests'] === 0 ? 'zero' : '' ?>">Offen <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['open_requests'])?></span></span>
        <span class="dashboard-stat info <?= (int) $dashboard['scheduled_requests'] === 0 ? 'zero' : '' ?>">Terminiert <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['scheduled_requests'])?></span></span>
        <span class="dashboard-stat warn <?= (int) $dashboard['waiting_jamf'] === 0 ? 'zero' : '' ?>">Jamf <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['waiting_jamf'])?></span></span>
        <span class="dashboard-stat warn <?= (int) $dashboard['waiting_asm'] === 0 ? 'zero' : '' ?>">ASM <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['waiting_asm'])?></span></span>
        <span class="dashboard-stat warn <?= (int) $dashboard['waiting_mail'] === 0 ? 'zero' : '' ?>">Mail <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['waiting_mail'])?></span></span>
        <span class="dashboard-stat danger <?= (int) $dashboard['reenrollment_alerts'] === 0 ? 'zero' : '' ?>">Wiederanmeldung <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['reenrollment_alerts'])?></span></span>
        <span class="dashboard-stat info <?= (int) $dashboard['school_year_total'] === 0 ? 'zero' : '' ?>">Anträge aktuelles Schuljahr <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['school_year_total'])?></span></span>
        <span class="dashboard-stat done <?= (int) $dashboard['done_requests'] === 0 ? 'zero' : '' ?>">Erledigt <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['done_requests'])?></span></span>
        <span class="dashboard-stat info <?= $avgAdminProcessingText === '–' ? 'zero' : '' ?>"><span class="dashboard-stat-small">Ø Admin-Zeit</span> <span class="dashboard-stat-value"><?=htmlspecialchars($avgAdminProcessingText)?></span></span>
        <span class="dashboard-stat info <?= $avgStudentResponseText === '–' ? 'zero' : '' ?>"><span class="dashboard-stat-small">Ø Schüler-Response</span> <span class="dashboard-stat-value"><?=htmlspecialchars($avgStudentResponseText)?></span></span>
    </div>

    <div class="request-trend-card" aria-label="Anträge im Schuljahr">
        <div class="request-trend-header">
            <span class="request-trend-title">Anträge der letzten 12 Monate</span>
        </div>
        <div class="request-trend-chart">
            <?php foreach ($requestTrend as $trendMonth): ?>
                <?php
                    $trendCount = (int) $trendMonth['count'];
                    $trendStrength = $trendCount > 0 ? sqrt($trendCount / $requestTrendMax) : 0;
                    $trendBgOpacity = number_format(0.08 + ($trendStrength * 0.22), 2, '.', '');
                    $trendBorderOpacity = number_format(0.16 + ($trendStrength * 0.26), 2, '.', '');
                    $trendColor = !$trendMonth['is_current_year'] ? '100, 116, 139' : ($trendMonth['is_peak_season'] ? '249, 115, 22' : '37, 99, 235');
                    $isActiveTrendMonth = $monthFilter === $trendMonth['month_key'];
                ?>
                <a class="request-trend-month <?= $trendMonth['is_peak_season'] ? 'peak' : '' ?> <?= !$trendMonth['is_current_year'] ? 'previous-year' : '' ?> <?= $isActiveTrendMonth ? 'active' : '' ?>"
                    href="<?=htmlspecialchars(admin_url(['filter' => 'done', 'month' => $trendMonth['month_key'], 'page' => 1, 'export' => null]))?>"
                    aria-label="Anträge aus <?=htmlspecialchars($trendMonth['label'])?> anzeigen"
                    style="--trend-bg: rgba(<?=$trendColor?>, <?=$trendBgOpacity?>); --trend-border: rgba(<?=$trendColor?>, <?=$trendBorderOpacity?>);">
                    <span class="request-trend-label"><?=htmlspecialchars($trendMonth['label'])?></span>
                    <span class="request-trend-value"><?=htmlspecialchars((string) $trendCount)?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="license-dashboard" aria-label="Jamf-Lizenzschätzung" title="Schätzung aus Baseline <?=htmlspecialchars(date('d.m.Y H:i', strtotime((string) $jamfLicenseDashboard['baseline_at'])))?> und aktuellem Jamf-Trash-Abgleich. Jamf liefert hier keine direkte Lizenz-API.">
        <span class="license-dashboard-title">Jamf-Lizenzen</span>
        <?php if ($jamfLicenseDashboard['available']): ?>
            <span class="license-dashboard-item">Recurring <span class="license-dashboard-value"><?=htmlspecialchars((string) $jamfLicenseDashboard['recurring_used_estimated'])?> / <?=htmlspecialchars((string) $jamfLicenseDashboard['recurring_total'])?></span> belegt</span>
            <span class="license-dashboard-item">frei <span class="license-dashboard-value license-dashboard-free"><?=htmlspecialchars((string) $jamfLicenseDashboard['recurring_free_estimated'])?></span></span>
            <span class="license-dashboard-item">seit Baseline im Trash <span class="license-dashboard-value"><?=htmlspecialchars((string) $jamfLicenseDashboard['trash_confirmed'])?></span></span>
            <span class="license-dashboard-item">wartet auf Trash
                <?php if ((int) $jamfLicenseDashboard['waiting_for_trash'] > 0 && !empty($jamfLicenseDashboard['waiting_serials'])): ?>
                    <a href="<?=htmlspecialchars(admin_url(['filter' => 'cleanup', 'page' => 1, 'export' => null]))?>"
                        class="license-case-button license-dashboard-value license-dashboard-warn"
                        title="<?=htmlspecialchars(implode(', ', $jamfLicenseDashboard['waiting_serials']))?>"><?=htmlspecialchars((string) $jamfLicenseDashboard['waiting_for_trash'])?></a>
                <?php else: ?>
                    <span class="license-dashboard-value"><?=htmlspecialchars((string) $jamfLicenseDashboard['waiting_for_trash'])?></span>
                <?php endif; ?>
            </span>
        <?php else: ?>
            <span class="license-dashboard-item license-dashboard-error">Jamf-Abgleich aktuell nicht verfügbar</span>
        <?php endif; ?>
        <span class="license-dashboard-item" title="<?=htmlspecialchars($releaseBrokerDashboardTitle)?>">Release Broker
            <?php if ($releaseBrokerHealth['ok']): ?>
                <span class="license-dashboard-value license-dashboard-free">OK</span>
                <?php if ($releaseBrokerHealth['version'] !== ''): ?>
                    <span class="license-dashboard-muted"><?=htmlspecialchars($releaseBrokerHealth['version'])?></span>
                <?php endif; ?>
            <?php else: ?>
                <span class="license-dashboard-value license-dashboard-error">Fehler</span>
            <?php endif; ?>
            <?php if (($releaseBrokerToken['label'] ?? '') !== ''): ?>
                <span class="license-dashboard-token <?=htmlspecialchars($releaseBrokerTokenClass)?>"><?=htmlspecialchars($releaseBrokerToken['label'])?></span>
            <?php endif; ?>
        </span>
    </div>

    <?php if ($canWrite): ?>
        <form method="post" id="bulkActionForm" class="hidden">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
            <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
            <div id="bulkIdsInput"></div>
            <div id="bulkLastIdsInput" data-last-bulk-step="<?=htmlspecialchars($bulkLastStep)?>">
                <?php foreach ($bulkLastIds as $bulkLastId): ?>
                    <input type="hidden" data-last-bulk-id value="<?=htmlspecialchars((string) $bulkLastId)?>">
                <?php endforeach; ?>
            </div>
        </form>

        <div class="bulk-toolbar" aria-label="Massenverarbeitung">
            <span class="bulk-status" id="bulkSelectionStatus">Keine Anträge ausgewählt</span>
            <button type="button" id="bulkJamfButton" class="button button-secondary" onclick="submitBulkAction('bulk_jamf_unenroll')" disabled>Auswahl freigeben</button>
            <button type="button" id="bulkCopyButton" class="button button-secondary bulk-serials-button" onclick="showBulkAsmList()" title="Nur für manuelle Kontrolle" disabled>Seriennummern</button>
            <button type="button" id="bulkAsmButton" class="button button-secondary" onclick="submitBulkAction('bulk_asm_release')" disabled>ASM/ADE für Auswahl</button>
            <button type="button" id="bulkMailButton" class="button button-secondary" onclick="submitBulkAction('bulk_mail_send')" disabled>Mail für Auswahl</button>
        </div>
        <div id="bulkWorkingMessage" class="bulk-working hidden" role="status" aria-live="polite"></div>
    <?php endif; ?>

    <div id="bulkAsmList" class="preview-card bulk-asm-list hidden">
        <div class="preview-header">
            <div>
                <h2>Notfall-Seriennummern</h2>
                <p class="preview-subtitle">Normaler Ablauf: ASM/ADE läuft automatisch über den Release Broker. Diese Liste ist nur für manuelle Kontrolle.</p>
            </div>
            <button type="button" class="button button-secondary small-button" onclick="hideBulkAsmList()">Schließen</button>
        </div>
        <textarea id="bulkAsmListText" class="bulk-list-textarea" data-server-list="<?= $bulkAsmSerials ? '1' : '0' ?>" data-auto-copy="0" readonly><?=htmlspecialchars(implode(', ', $bulkAsmSerials))?></textarea>
        <div class="editor-actions">
            <span id="bulkCopyStatus" class="bulk-copy-status"></span>
            <button type="button" class="button button-secondary" onclick="copyBulkAsmList()">Seriennummern kopieren</button>
        </div>
    </div>

    <nav class="pagination pagination-top" aria-label="Seitennavigation oben">
        <a class="pagination-link <?= $page <= 1 ? 'disabled' : '' ?>" data-page-link href="<?=htmlspecialchars(admin_url(['page' => 1, 'export' => null]))?>">« Erste</a>
        <a class="pagination-link <?= $page <= 1 ? 'disabled' : '' ?>" data-page-link href="<?=htmlspecialchars(admin_url(['page' => max(1, $page - 1), 'export' => null]))?>">‹ Zurück</a>
        <span class="pagination-current">Seite <?=htmlspecialchars((string) $page)?> von <?=htmlspecialchars((string) $totalPages)?></span>
        <a class="pagination-link <?= $page >= $totalPages ? 'disabled' : '' ?>" data-page-link href="<?=htmlspecialchars(admin_url(['page' => min($totalPages, $page + 1), 'export' => null]))?>">Weiter ›</a>
        <a class="pagination-link <?= $page >= $totalPages ? 'disabled' : '' ?>" data-page-link href="<?=htmlspecialchars(admin_url(['page' => $totalPages, 'export' => null]))?>">Letzte »</a>
    </nav>

    <?php if ($templateMessage): ?>
        <div class="message success"><?=htmlspecialchars($templateMessage)?></div>
    <?php endif; ?>
    <?php if ($templateError): ?>
        <div class="message error"><?=htmlspecialchars($templateError)?></div>
    <?php endif; ?>
    <?php if ($mailMessage): ?>
        <div class="message success"><?=htmlspecialchars($mailMessage)?></div>
    <?php endif; ?>
    <?php if ($mailError): ?>
        <div class="message error"><?=htmlspecialchars($mailError)?></div>
    <?php endif; ?>
    <?php if ($disownMessage): ?>
        <div class="message success"><?=htmlspecialchars($disownMessage)?></div>
    <?php endif; ?>
    <?php if ($disownError): ?>
        <div class="message error"><?=htmlspecialchars($disownError)?></div>
    <?php endif; ?>
    <?php if ($bulkMessage): ?>
        <div class="message success"><?=htmlspecialchars($bulkMessage)?></div>
    <?php endif; ?>
    <?php if ($bulkError): ?>
        <div class="message error"><?=htmlspecialchars($bulkError)?></div>
    <?php endif; ?>
    <?php if ($caseMessage): ?>
        <div class="message success"><?=htmlspecialchars($caseMessage)?></div>
    <?php endif; ?>
    <?php if ($caseError): ?>
        <div class="message error"><?=htmlspecialchars($caseError)?></div>
    <?php endif; ?>
    <?php if ($pushMailMessage): ?>
        <div class="message success"><?=htmlspecialchars($pushMailMessage)?></div>
    <?php endif; ?>
    <?php if ($pushMailError): ?>
        <div class="message error"><?=htmlspecialchars($pushMailError)?></div>
    <?php endif; ?>

    <?php if ($asmReleasePreview && $asmReleaseRequest): ?>
        <div class="preview-card asm-release-card">
            <div class="preview-header">
                <div>
                    <h2>ASM/ADE-Freigabe</h2>
                    <p class="preview-subtitle">
                        <?=!empty($asmReleasePreview['dry_run']) ? 'Dry-Run' : 'Ausführung'?>
                        für <?=htmlspecialchars((string) ($asmReleaseRequest['full_name'] ?? ''))?>
                        · <?=htmlspecialchars(strtoupper((string) ($asmReleaseRequest['serial'] ?? '')))?>
                    </p>
                </div>
            </div>
            <div class="message <?=!empty($asmReleasePreview['success']) ? 'success' : 'error'?>">
                <?=htmlspecialchars((string) ($asmReleasePreview['message'] ?? ''))?>
            </div>
            <div class="asm-release-steps">
                <?php foreach (($asmReleasePreview['steps'] ?? []) as $step): ?>
                    <?php
                        $stepStatus = preg_replace('/[^a-z]/', '', (string) ($step['status'] ?? ''));
                        if ($stepStatus === '') {
                            $stepStatus = 'warning';
                        }
                    ?>
                    <div class="asm-release-step <?=htmlspecialchars($stepStatus)?>">
                        <strong><?=htmlspecialchars((string) ($step['label'] ?? 'Schritt'))?></strong>
                        <span class="asm-release-step-detail"><?=htmlspecialchars((string) ($step['detail'] ?? ''))?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($asmReleasePreview['success']) && empty($asmReleasePreview['dry_run'])): ?>
                <p class="preview-subtitle">Die Freigabe wurde abgeschlossen. Danach kann die Abschlussmail versendet werden.</p>
            <?php elseif (!empty($asmReleasePreview['success'])): ?>
                <div class="asm-release-actions">
                    <form method="post" onsubmit="showSingleWorking('ASM/ADE-Freigabe läuft. Bitte warten und die Seite nicht neu laden.')">
                        <input type="hidden" name="asm_release_confirm" value="<?=htmlspecialchars((string) ($asmReleaseRequest['id'] ?? 0))?>">
                        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
                        <button type="submit" class="button button-primary">
                            <?= $isDevMode ? 'Dry-Run erneut testen' : 'Jetzt ASM/ADE freigeben' ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($canWrite): ?>
    <div id="templateEditor" class="preview-card hidden">
        <form method="post">
            <h2>Mailvorlage: Jamf verifiziert</h2>
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
            <textarea name="template_content" class="template-textarea" rows="16"><?=htmlspecialchars($mailTemplate)?></textarea>
            <div class="editor-actions">
                <button type="submit" name="template_save" class="button button-primary">Speichern</button>
                <button type="button" class="button button-secondary" onclick="toggleTemplateEditor()">Abbrechen</button>
            </div>
        </form>
        <form method="post">
            <h2>Mailvorlage: Apple freigegeben, Jamf-Bestätigung ausstehend</h2>
            <p class="preview-subtitle">Diese Variante bestätigt die Apple-Freigabe, ohne eine Entfernung des Jamf-Profils zu behaupten.</p>
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
            <input type="hidden" name="template_variant" value="pending">
            <textarea name="template_content" class="template-textarea" rows="16"><?=htmlspecialchars($mailTemplatePending)?></textarea>
            <div class="editor-actions">
                <button type="submit" name="template_save" class="button button-primary">Wartende Variante speichern</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="card table-wrap">
        <table id="requestsTable">
            <thead>
                <tr>
                    <th class="select-cell">
                        <?php if ($canWrite): ?>
                            <input type="checkbox" id="selectAllRequests" aria-label="Alle sichtbaren Anträge auswählen">
                        <?php endif; ?>
                    </th>
                    <th>ID</th>
                    <th>Datum</th>
                    <th>Person</th>
                    <th>Gerät</th>
                    <th>Status</th>
                    <th>Aktion</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr class="empty-row">
                        <td colspan="7">
                            <div class="empty-state">
                                <div class="empty-state-icon" aria-hidden="true">✓</div>
                                <div>
                                    <strong>Keine passenden Anträge</strong>
                                    <span>Filter, Suche oder Monat liefern aktuell keine Treffer.</span>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                        $bulkMailSent = (int) ($row['mail_sent'] ?? 0) === 1;
                        $bulkJamfDone = disown_request_allows_asm($row) || !empty($row['asm_manual_done']);
                        $bulkAsmDone = !empty($row['asm_manual_done']);
                        $bulkIsHistoryImport = (($row['completed_by'] ?? '') === 'history-import');
                        $bulkSelectable = $canWrite && !$bulkIsHistoryImport && !$bulkMailSent;
                        $bulkKeepSelected = in_array((int) $row['id'], $bulkLastIds, true);
                        $openCaseCount = (int) ($row['open_case_count'] ?? 0);
                        $latestCaseId = (int) ($row['latest_case_id'] ?? 0);
                        $latestCaseStatus = normalize_device_case_status((string) ($row['latest_case_status'] ?? 'offen'));
                        $defaultCaseTitle = 'Klärfall ' . (string) ($row['serial'] ?? '');
                        if (!empty($row['jamf_unenrolled']) && !empty($row['asm_manual_done']) && empty($row['mail_sent'])) {
                            $defaultCaseTitle = 'Workflow vor Mail prüfen';
                        } elseif (!empty($row['jamf_unenrolled']) && !empty($row['asm_manual_done'])) {
                            $defaultCaseTitle = 'Nach Freigabe prüfen';
                        }
                        $rowSerial = strtoupper(trim((string) ($row['serial'] ?? '')));
                        $rowCases = $casesBySerial[$rowSerial] ?? [];
                        $rowHasCase = $latestCaseId > 0 || count($rowCases) > 0;
                        $deviceCaseTooltip = $rowHasCase ? 'Notizen zum Gerät öffnen' : 'Notiz zum Gerät anlegen';
                    ?>
                <tr class="request-row"
                    data-id="<?=htmlspecialchars($row['id'])?>"
                    data-full-name="<?=htmlspecialchars($row['full_name'])?>"
                    data-username="<?=htmlspecialchars($row['username'])?>"
                    data-email="<?=htmlspecialchars($row['email'])?>"
                    data-private-email="<?=htmlspecialchars($row['private_email'] ?? '')?>"
                    data-serial="<?=htmlspecialchars($row['serial'])?>">
                    <td class="select-cell" data-label="">
                        <?php if ($bulkSelectable): ?>
                            <input type="checkbox"
                                class="bulk-select"
                                value="<?=htmlspecialchars($row['id'])?>"
                                data-name="<?=htmlspecialchars($row['full_name'])?>"
                                data-class="<?=htmlspecialchars($row['class_name'] ?? '')?>"
                                data-device="<?=htmlspecialchars($row['device_name'])?>"
                                data-serial="<?=htmlspecialchars($row['serial'])?>"
                                data-jamf="<?=$bulkJamfDone ? '1' : '0'?>"
                                data-asm="<?=$bulkAsmDone ? '1' : '0'?>"
                                <?=$bulkKeepSelected ? 'checked' : ''?>
                                aria-label="Antrag <?=htmlspecialchars($row['id'])?> auswählen">
                        <?php endif; ?>
                    </td>
                    <td class="nowrap-cell" data-label="ID"><?=htmlspecialchars($row['id'])?></td>
                    <td class="date-cell" data-label="Datum">
                        <span><?=date('d.m.Y', strtotime($row['created_at']))?></span>
                        <span><?=date('H:i', strtotime($row['created_at']))?></span>
                            <?php if (!empty($row['requested_release_date'])): ?>
                                <span class="status-secondary">Wunsch: <?=htmlspecialchars(date('d.m.Y', strtotime($row['requested_release_date'])))?></span>
                            <?php endif; ?>
                    </td>
                    <td class="person-cell" data-label="Person">
                        <div class="person-name"><?=htmlspecialchars($row['full_name'])?></div>
                        <div class="person-subtitle"><?=htmlspecialchars($row['username'])?></div>
                            <?php if (!empty($row['class_name'])): ?>
                                <div class="person-subtitle">Klasse: <?=htmlspecialchars($row['class_name'])?></div>
                            <?php endif; ?>
                        <div class="person-subtitle"><a class="email-link" href="mailto:<?=rawurlencode($row['email'])?>"><?=htmlspecialchars($row['email'])?></a></div>
                            <?php if (!empty($row['private_email'])): ?>
                                <div class="person-subtitle">Privat: <a class="email-link" href="mailto:<?=rawurlencode($row['private_email'])?>"><?=htmlspecialchars($row['private_email'])?></a></div>
                            <?php endif; ?>
                    </td>
                    <td class="device-cell" data-label="Gerät">
                        <div class="device-case-label"><?=htmlspecialchars($row['device_name'])?></div>
                        <div class="serial-cell">
                        <?php if ($canWrite && !empty($row['serial'])): ?>
                            <button type="button"
                                class="serial-case-button"
                                data-case-id="<?=htmlspecialchars((string) $latestCaseId)?>"
                                data-request-id="<?=htmlspecialchars((string) $row['id'])?>"
                                data-serial="<?=htmlspecialchars($row['serial'])?>"
                                data-source="admin"
                                data-title="<?=htmlspecialchars((string) ($row['latest_case_title'] ?: $defaultCaseTitle))?>"
                                data-status="<?=htmlspecialchars($latestCaseStatus ?: 'offen')?>"
                                data-note="<?=htmlspecialchars((string) ($row['latest_case_note'] ?? ''))?>"
                                data-resolution-note="<?=htmlspecialchars((string) ($row['latest_case_resolution_note'] ?? ''))?>"
                                data-updated-at="<?=htmlspecialchars((string) ($row['latest_case_updated_at'] ?? ''))?>"
                                title="<?=htmlspecialchars($deviceCaseTooltip)?>"
                                aria-label="<?=htmlspecialchars($deviceCaseTooltip . ': ' . (string) $row['serial'])?>"
                                onclick="showDeviceCase(this)">
                                <?=htmlspecialchars($row['serial'])?>
                            </button>
                        <?php else: ?>
                            <?=htmlspecialchars($row['serial'])?>
                        <?php endif; ?>
                        </div>
                        <?php if ($canWrite && $rowHasCase): ?>
                            <div class="case-row-badge-line">
                                <?php if ($openCaseCount > 0): ?>
                                    <span class="case-status-badge case-status-open"><?=htmlspecialchars($openCaseCount > 1 ? 'Klärfälle ' . $openCaseCount : 'Klärfall')?></span>
                                <?php else: ?>
                                    <span class="case-status-badge case-status-closed">geklärt</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($canWrite && count($rowCases) > 1): ?>
                            <div class="case-chip-list" aria-label="Klärfälle zu dieser Seriennummer">
                            <?php foreach ($rowCases as $caseIndex => $deviceCase): ?>
                                <?php
                                    $caseStatus = normalize_device_case_status((string) ($deviceCase['status'] ?? 'offen'));
                                    $caseTitle = (string) ($deviceCase['title'] ?: $defaultCaseTitle);
                                    $caseId = (string) ($deviceCase['id'] ?? ($caseIndex + 1));
                                    $caseTooltip = trim($caseTitle . ' · ' . display_device_case_status($caseStatus) . ' · ' . (string) ($deviceCase['updated_at'] ?? ''));
                                ?>
                                <button type="button"
                                    class="case-chip <?= $caseStatus === 'geklaert' ? 'case-chip-closed' : '' ?>"
                                    title="<?=htmlspecialchars($caseTooltip)?>"
                                    data-case-id="<?=htmlspecialchars($caseId)?>"
                                    data-request-id="<?=htmlspecialchars((string) ($deviceCase['request_id'] ?? $row['id']))?>"
                                    data-serial="<?=htmlspecialchars($row['serial'])?>"
                                    data-source="<?=htmlspecialchars((string) ($deviceCase['source'] ?? 'admin'))?>"
                                    data-title="<?=htmlspecialchars($caseTitle)?>"
                                    data-status="<?=htmlspecialchars($caseStatus)?>"
                                    data-note="<?=htmlspecialchars((string) ($deviceCase['note'] ?? ''))?>"
                                    data-resolution-note="<?=htmlspecialchars((string) ($deviceCase['resolution_note'] ?? ''))?>"
                                    data-updated-at="<?=htmlspecialchars((string) ($deviceCase['updated_at'] ?? ''))?>"
                                    onclick="showDeviceCase(this)">Fall #<?=htmlspecialchars($caseId)?></button>
                            <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="status-cell" data-label="Status">
                        <?php
                            $statusRaw = (string) ($row['status'] ?? '');
                            $status = trim(strtolower($statusRaw));
                            $mailState = (int) ($row['mail_sent'] ?? 0);
                            $mailSent = $mailState !== 0;
                            $mailFailed = $mailState === 2;
                            $jamfUnenrolled = !empty($row['jamf_unenrolled']);
                            $jamfWorkflowState = disown_jamf_state_from_row($row);
                            $jamfPending = in_array($jamfWorkflowState, [DISOWN_JAMF_STATE_COMMAND_ACCEPTED, DISOWN_JAMF_STATE_PENDING_DEVICE], true);
                            $jamfManual = $jamfWorkflowState === DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED;
                            $jamfFailed = $jamfWorkflowState === DISOWN_JAMF_STATE_FAILED;
                            $asmManualDone = !empty($row['asm_manual_done']);
                            $isHistoryImport = (($row['completed_by'] ?? '') === 'history-import');
                            $jamfStepDone = $isHistoryImport || $jamfWorkflowState === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED;
                            $asmStepDone = $isHistoryImport || $asmManualDone;
                            $mailStepDone = $isHistoryImport || $mailSent;
                            $isOpen = ($status === 'offen');
                            $lastAuditAt = $row['last_audit_at'] ?? null;
                        ?>
                        <div class="process-steps">
                            <div class="process-step done"><span class="process-mark">✓</span><span>Antrag</span></div>
                            <div class="process-step <?= $jamfStepDone ? 'done' : '' ?>"><span class="process-mark"><?= $jamfStepDone ? '✓' : '○' ?></span><span>Jamf</span></div>
                            <div class="process-step <?= $asmStepDone ? 'done' : '' ?>"><span class="process-mark"><?= $asmStepDone ? '✓' : '○' ?></span><span>ASM</span></div>
                            <div class="process-step <?= $mailFailed ? 'failed' : ($mailStepDone ? 'done' : '') ?>"><span class="process-mark"><?= $mailFailed ? '!' : ($mailStepDone ? '✓' : '○') ?></span><span>Mail</span></div>
                        </div>
                        <?php if ($isHistoryImport): ?>
                            <span class="history-import-badge">Import</span>
                        <?php endif; ?>
                        <div class="last-audit">
                            <?php if (!empty($row['last_audit_user']) && $lastAuditAt): ?>
                                👤 <?=htmlspecialchars($row['last_audit_user'])?> · <?=htmlspecialchars(date('d.m.Y H:i', strtotime($lastAuditAt)))?>
                            <?php else: ?>
                                👤 –
                            <?php endif; ?>
                        </div>
                        <?php if (!$isHistoryImport && $mailSent && (!disown_request_has_release_basis($row) || !$asmManualDone)): ?>
                            <div class="process-warning">🟠 Mail vor Abschluss versendet</div>
                        <?php endif; ?>
                        <?php if (!$isHistoryImport && $asmManualDone && disown_request_has_release_basis($row)): ?>
                            <div class="<?= ($row['jamf_cleanup_state'] ?? '') === 'trashed' ? 'status-muted' : 'process-warning' ?>"><?=htmlspecialchars(disown_jamf_cleanup_label((string) ($row['jamf_cleanup_state'] ?? 'not_checked')))?></div>
                            <?php if (!empty($row['jamf_cleanup_checked_at'])): ?>
                                <div class="status-muted">Geprüft: <?=htmlspecialchars(date('d.m.Y H:i', strtotime($row['jamf_cleanup_checked_at'])))?></div>
                            <?php endif; ?>
                        <?php elseif (!$isHistoryImport && disown_request_has_release_basis($row) && !$jamfStepDone): ?>
                            <div class="process-warning">Jamf-Befehl angenommen · Apple-Freigabe kann fortgesetzt werden</div>
                        <?php elseif (!$isHistoryImport && $jamfManual): ?>
                            <div class="process-error">Jamf: manuelle Verifizierung erforderlich</div>
                        <?php elseif (!$isHistoryImport && $jamfFailed): ?>
                            <div class="process-error">Jamf: Anfrage fehlgeschlagen</div>
                        <?php endif; ?>
                        <?php if (!empty($row['jamf_reenrollment_detected_at'])): ?>
                            <div class="process-error">Jamf: spätere Wiederanmeldung erkannt · keine automatische Aktion</div>
                        <?php endif; ?>
                        <?php if (!$isHistoryImport && $mailFailed): ?>
                            <div class="process-error">Mailversand fehlgeschlagen</div>
                            <?php if (!empty($row['mail_sent_to'])): ?>
                                <?php
                                    $mailDeliveryText = (string) $row['mail_sent_to'];
                                    $mailOkText = null;
                                    $mailFailText = null;
                                    if (preg_match('/(?:^|;\s*)OK:\s*([^;]+)/', $mailDeliveryText, $match)) {
                                        $mailOkText = trim($match[1]);
                                    }
                                    if (preg_match('/(?:^|;\s*)FEHLER:\s*([^;]+)/', $mailDeliveryText, $match)) {
                                        $mailFailText = trim($match[1]);
                                    }
                                ?>
                                <?php if ($mailOkText !== null && $mailOkText !== ''): ?>
                                    <div class="process-delivery-ok">OK: <?=htmlspecialchars($mailOkText)?></div>
                                <?php endif; ?>
                                <?php if ($mailFailText !== null && $mailFailText !== ''): ?>
                                    <div class="process-delivery-error">FEHLER: <?=htmlspecialchars($mailFailText)?></div>
                                <?php elseif ($mailOkText === null): ?>
                                    <div class="process-error-detail"><?=htmlspecialchars($mailDeliveryText)?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="action-cell" data-label="Aktion">
                        <div class="action-buttons">
                            <?php if (!$canWrite): ?>
                                <span class="status-muted">Nur Ansicht</span>
                            <?php endif; ?>

                            <?php if ($canWrite && !$isHistoryImport && !$asmManualDone && !$jamfStepDone): ?>
                                <form method="post" class="action-form" onsubmit="showSingleWorking('Jamf-Prüfung und gegebenenfalls automatische ASM/ADE-Freigabe laufen. Bitte warten und die Seite nicht neu laden.')">
                                    <input type="hidden" name="unenroll" value="<?=htmlspecialchars($row['id'])?>">
                                    <input type="hidden" name="unenroll_serial" value="<?=htmlspecialchars($row['serial'])?>">
                                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
                                    <button type="submit" class="button button-primary" title="Jamf kurz prüfen und anschließend die autorisierte Apple-Freigabe ausführen; Mail bleibt manuell"><?= disown_request_has_release_basis($row) ? 'Freigabe fortsetzen' : (($jamfPending || $jamfManual) ? 'Status prüfen' : 'Freigeben') ?></button>
                                </form>
                            <?php endif; ?>

                            <?php if ($canWrite && !$isHistoryImport && $jamfWorkflowState === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED && !$asmManualDone): ?>
                                <form method="post" class="action-form">
                                    <input type="hidden" name="asm_release_preview" value="<?=htmlspecialchars($row['id'])?>">
                                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
                                    <button type="submit" class="button button-primary">ASM prüfen</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($canWrite && disown_request_allows_completion_mail($row)): ?>
                                <button type="button" class="button button-primary" onclick="showMailPreview(this)"
                                    data-id="<?=htmlspecialchars($row['id'])?>"
                                    data-name="<?=htmlspecialchars($row['full_name'])?>"
                                    data-username="<?=htmlspecialchars($row['username'])?>"
                                    data-email="<?=htmlspecialchars($row['email'])?>"
                                    data-private-email="<?=htmlspecialchars($row['private_email'] ?? '')?>"
                                    data-device="<?=htmlspecialchars($row['device_name'])?>"
                                    data-jamf-verified="<?=$jamfStepDone ? '1' : '0'?>"
                                    data-serial="<?=htmlspecialchars($row['serial'])?>">
                                    Mail senden
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <nav class="pagination pagination-bottom" aria-label="Seitennavigation unten">
            <a class="pagination-link <?= $page <= 1 ? 'disabled' : '' ?>" data-page-link href="<?=htmlspecialchars(admin_url(['page' => 1, 'export' => null]))?>">« Erste</a>
            <a class="pagination-link <?= $page <= 1 ? 'disabled' : '' ?>" data-page-link href="<?=htmlspecialchars(admin_url(['page' => max(1, $page - 1), 'export' => null]))?>">‹ Zurück</a>
            <span class="pagination-current">Seite <?=htmlspecialchars((string) $page)?> von <?=htmlspecialchars((string) $totalPages)?></span>
            <a class="pagination-link <?= $page >= $totalPages ? 'disabled' : '' ?>" data-page-link href="<?=htmlspecialchars(admin_url(['page' => min($totalPages, $page + 1), 'export' => null]))?>">Weiter ›</a>
            <a class="pagination-link <?= $page >= $totalPages ? 'disabled' : '' ?>" data-page-link href="<?=htmlspecialchars(admin_url(['page' => $totalPages, 'export' => null]))?>">Letzte »</a>
        </nav>

        <?php if ($canWrite): ?>
        <div id="deviceCaseCard" class="case-card hidden">
            <div class="preview-header">
                <div>
                    <h2>Klärfall</h2>
                    <p class="case-meta" id="caseMeta">Lokale operative Notiz</p>
                </div>
                <button type="button" class="button button-secondary small-button" onclick="hideDeviceCase()">Schließen</button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
                <input type="hidden" name="save_device_case" value="1">
                <input type="hidden" name="case_id" id="caseIdInput" value="">
                <input type="hidden" name="case_request_id" id="caseRequestIdInput" value="0">
                <input type="hidden" name="case_source" id="caseSourceInput" value="admin">
                <input type="hidden" name="case_serial" id="caseSerialInput" value="">
                <input type="hidden" name="case_title" id="caseTitleInput" value="">
                <div class="case-grid">
                    <label class="case-field">
                        <span>Status</span>
                        <select class="case-select" name="case_status" id="caseStatusInput">
                            <option value="offen">offen</option>
                            <option value="geklaert">geklärt</option>
                        </select>
                    </label>
                    <label class="case-field case-field-full">
                        <span>Notiz</span>
                        <textarea class="case-textarea" name="case_note" id="caseNoteInput" placeholder="Was ist auffällig? Was soll geprüft werden?"></textarea>
                    </label>
                    <label class="case-field case-field-full">
                        <span>Abschluss / Lösung</span>
                        <textarea class="case-textarea" name="case_resolution_note" id="caseResolutionInput" placeholder="Was wurde gemacht? Warum ist der Fall geklärt?"></textarea>
                    </label>
                </div>
                <div class="case-actions">
                    <button type="submit" name="delete_device_case" value="1" id="deleteCaseButton" class="button button-danger hidden" onclick="return confirm('Diesen Klärfall wirklich löschen?')">Klärfall löschen</button>
                    <button type="submit" class="button button-primary">Klärfall speichern</button>
                    <button type="button" class="button button-secondary" onclick="hideDeviceCase()">Schließen</button>
                </div>
            </form>
        </div>

        <div id="mailPreview" class="preview-card hidden">
            <div class="preview-header">
                <div>
                    <h2>Mail-Vorschau</h2>
                    <p class="preview-subtitle">Passende Vorlage zum Jamf-Status; Versand erst nach „Mail senden“.</p>
                </div>
                <button type="button" class="button button-secondary small-button" onclick="hideMailPreview()">Schließen</button>
            </div>
            <div class="preview-content">
                <p><strong>An:</strong></p>
                <div class="recipient-options">
                    <label class="recipient-option" id="privateRecipientRow">
                        <input type="checkbox" id="sendPrivateEmail">
                        <span class="recipient-label">Private E-Mail</span>
                        <input type="email" id="previewPrivateEmailInput" class="preview-input" value="">
                    </label>
                    <label class="recipient-option">
                        <input type="checkbox" id="sendSchoolEmail">
                        <span class="recipient-label">Schulische E-Mail</span>
                        <input type="email" id="previewSchoolEmailInput" class="preview-input" value="">
                    </label>
                </div>
                <div class="preview-field">
                    <label for="previewSubjectInput">Betreff</label>
                    <input type="text" id="previewSubjectInput" class="preview-subject-input" value="">
                </div>
                <div class="preview-field">
                    <label for="previewBodyInput">Nachricht</label>
                    <textarea id="previewBodyInput" class="preview-body-input"></textarea>
                </div>
            </div>
            <form method="post" id="sendMailForm" class="send-form">
                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
                <input type="hidden" name="send_mail" value="1">
                <input type="hidden" name="send_to" id="sendToInput" value="">
                <input type="hidden" name="send_subject" id="sendSubjectInput" value="">
                <input type="hidden" name="send_device" id="sendDeviceInput" value="">
                <input type="hidden" name="send_serial" id="sendSerialInput" value="">
                <input type="hidden" name="send_request_id" id="sendRequestIdInput" value="0">
                <textarea name="send_body" id="sendBodyInput" class="hidden"></textarea>
                <div class="editor-actions">
                    <button type="button" class="button button-primary" onclick="sendMail()">Mail senden</button>
                    <button type="button" class="button button-secondary" onclick="hideMailPreview()">Schließen</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($canWrite): ?>
        <?php if ($adminDirectMessage): ?>
            <div class="message success admin-direct-message"><?=htmlspecialchars($adminDirectMessage)?></div>
        <?php endif; ?>
        <?php if ($adminDirectError): ?>
            <div class="message error admin-direct-message"><?=htmlspecialchars($adminDirectError)?></div>
        <?php endif; ?>
        <details class="admin-direct-card" <?=($adminDirectPreview || $adminDirectError || $adminDirectForm['serial'] !== '') ? 'open' : ''?>>
            <summary>Admin-Sonderfreigabe für Defektgerät</summary>
            <form method="post" class="admin-direct-form">
                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
                <input type="hidden" name="admin_direct_preview" value="1">
                <div class="admin-direct-grid">
                    <label>
                        <span>Seriennummer</span>
                        <input class="preview-input" name="admin_direct_serial" type="text" required autocomplete="new-password" autocapitalize="characters" spellcheck="false" placeholder="z. B. DMRDJ9NHQ1GC" value="<?=htmlspecialchars($adminDirectForm['serial'])?>">
                    </label>
                    <label>
                        <span>Hinweis optional</span>
                        <input class="preview-input" name="admin_direct_note" type="text" autocomplete="new-password" spellcheck="false" placeholder="z. B. defektes Gerät, Freigabe durch Admin gestartet" value="<?=htmlspecialchars($adminDirectForm['note'])?>">
                    </label>
                </div>
                <div class="admin-direct-actions">
                    <span class="preview-subtitle">Erst Jamf-Daten laden, danach einen Antrag anlegen.</span>
                    <button type="submit" class="button button-secondary">Seriennummer prüfen</button>
                </div>
            </form>

            <?php if ($adminDirectPreview): ?>
                <div class="admin-direct-preview">
                    <div class="admin-direct-preview-title">Jamf-Daten gefunden</div>
                    <div class="admin-direct-facts">
                        <span><strong>Gerät</strong><?=htmlspecialchars($adminDirectPreview['device_name'] ?: 'unbekannt')?></span>
                        <span><strong>Seriennummer</strong><?=htmlspecialchars($adminDirectPreview['serial'])?></span>
                        <span><strong>Owner</strong><?=htmlspecialchars($adminDirectPreview['full_name'] ?: 'nicht gesetzt')?></span>
                        <span><strong>IServ</strong><?=htmlspecialchars($adminDirectPreview['username'] ?: 'nicht gesetzt')?></span>
                        <span><strong>E-Mail</strong><?=htmlspecialchars($adminDirectPreview['email'] ?: 'nicht gesetzt')?></span>
                        <span><strong>Asset-Tag</strong><?=htmlspecialchars($adminDirectPreview['asset_tag'] ?: 'nicht gesetzt')?></span>
                        <span><strong>Jamf-Status</strong><?=($adminDirectPreview['jamf_state'] ?? '') === 'trash' ? 'Papierkorb' : 'Aktiv'?></span>
                    </div>
                    <?php if (!empty($adminDirectPreview['school_loan_reasons'])): ?>
                        <div class="admin-direct-warning">Schulgeräte-Erkennung: <?=htmlspecialchars(implode(', ', $adminDirectPreview['school_loan_reasons']))?>. Dieser Sonderweg darf das bewusst umgehen.</div>
                    <?php endif; ?>
                </div>

                <form method="post" class="admin-direct-form admin-direct-confirm-form">
                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
                    <input type="hidden" name="admin_direct_create" value="1">
                    <input type="hidden" name="admin_direct_serial" value="<?=htmlspecialchars($adminDirectForm['serial'])?>">
                    <input type="hidden" name="admin_direct_note" value="<?=htmlspecialchars($adminDirectForm['note'])?>">
                    <div class="admin-direct-grid">
                        <label>
                            <span>Name</span>
                            <input class="preview-input" name="admin_direct_full_name" type="text" autocomplete="new-password" spellcheck="false" value="<?=htmlspecialchars($adminDirectForm['full_name'])?>">
                        </label>
                        <label>
                            <span>Schulische E-Mail</span>
                            <input class="preview-input" name="admin_direct_email" type="text" inputmode="email" autocomplete="new-password" autocapitalize="none" spellcheck="false" value="<?=htmlspecialchars($adminDirectForm['email'])?>">
                        </label>
                        <label>
                            <span>Private E-Mail optional</span>
                            <input class="preview-input" name="admin_direct_private_email" type="text" inputmode="email" autocomplete="new-password" autocapitalize="none" spellcheck="false" value="<?=htmlspecialchars($adminDirectForm['private_email'])?>">
                        </label>
                    </div>
                    <?php if (($adminDirectPreview['jamf_state'] ?? '') === 'trash'): ?>
                        <label class="admin-direct-trash-confirmation">
                            <input type="checkbox" name="admin_direct_confirm_trash" value="1" required>
                            <span>Trash-Datensatz als unbestätigte Beobachtung übernehmen; der Jamf-Schritt bleibt bis zur manuellen Verifizierung gesperrt.</span>
                        </label>
                    <?php endif; ?>
                    <div class="admin-direct-actions">
                        <span class="preview-subtitle"><?=(($adminDirectPreview['jamf_state'] ?? '') === 'trash') ? 'Der Antrag wird als manuell zu verifizieren angelegt. ASM/ADE und Mail bleiben gesperrt.' : 'Der Antrag erscheint danach unten in der Tabelle. Jamf, ASM/ADE und Mail laufen über die normalen Admin-Schritte.'?></span>
                        <span class="admin-direct-action-buttons">
                            <a class="button button-secondary" href="<?=htmlspecialchars(admin_url(['page' => 1, 'export' => null]))?>">Abbrechen</a>
                            <button type="submit" class="button button-primary">Antrag anlegen</button>
                        </span>
                    </div>
                </form>
            <?php else: ?>
                <div class="admin-direct-actions">
                    <span class="preview-subtitle">Nur dieser Admin-Sonderweg überspringt den Schulgeräte-Check. WebClip und Bulk bleiben geschützt.</span>
                </div>
            <?php endif; ?>
        </details>
    <?php endif; ?>
    <?php
        $footerExportUrl = htmlspecialchars(admin_url(['filter' => $filter, 'export' => 'requests_csv']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        disown_render_site_footer('Adminportal', [
            'class' => 'page-footer',
            'extra_html' => '<a class="footer-export-link" href="' . $footerExportUrl . '" title="Anträge exportieren" aria-label="Anträge exportieren">⬇</a>',
        ]);
    ?>
</div>

<script>
window.disownAdminConfig = {
    mailTemplate: <?= json_encode($mailTemplate, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    mailTemplatePending: <?= json_encode($mailTemplatePending, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    mailSubject: <?= json_encode($mailSubject, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
};
</script>
<script src="<?=htmlspecialchars($searchJsUrl)?>" defer></script>
<script src="<?=htmlspecialchars($adminJsUrl)?>" defer></script>
</body>
</html>
