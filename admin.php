<?php
require __DIR__ . '/auth.php';
disown_require_admin();
require 'db.php';
require __DIR__ . '/jamf.php';
require __DIR__ . '/vendor/autoload.php';

$templateMessage = '';
$templateError = '';
$mailMessage = '';
$mailError = '';
$disownMessage = '';
$disownError = '';
$bulkMessage = '';
$bulkError = '';
$caseMessage = '';
$caseError = '';
$bulkAsmSerials = [];
$bulkLastIds = [];
$bulkLastStep = '';
$currentAdminUser = disown_current_admin_user();
$canWrite = disown_can_write();
$accessLabel = $canWrite ? 'Admin' : 'Nur Lesen';
$isDevMode = basename(__DIR__) === 'disown-dev';
$appVersion = $isDevMode ? '1.9-dev' : '1.9';
$appVersionDate = '8. Juli 2026';
$appBasePath = rtrim(disown_admin_base_path(), '/');
$adminPath = $appBasePath . '/admin.php';
$adePath = $appBasePath . '/ade.php';
$kukPath = $appBasePath . '/kuk/';
$auditLogPath = $appBasePath . '/audit_log.php';
$logoutPath = $appBasePath . '/logout.php';
$faviconPath = $appBasePath . '/favicon.svg';
$logoPath = $appBasePath . '/logo.png';
$jamfLogoPath = $appBasePath . '/logo_jamf.png';
$asmLogoPath = $appBasePath . '/logo_asm.png';
$siteImagePath = $appBasePath . '/images/Site-Image.png';
$validFilters = ['open', 'scheduled', 'done', 'all', 'cases'];
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
];
$jamfLicenseBaseline = [
    'taken_at' => '2026-01-01 00:00:00',
    'recurring_total' => 1000,
    'recurring_used' => 0,
    'perpetual_total' => 0,
    'perpetual_used' => 0,
];
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$perPage = 25;
$page = max(1, (int) ($_GET['page'] ?? 1));
$refreshUrl = $adminPath . '?' . http_build_query([
    'filter' => $filter,
    'q' => $searchTerm,
    'page' => $page,
]);
$whereParts = [];
$whereParams = [];
$whereTypes = '';
$doneCondition = "LOWER(TRIM(status)) = 'erledigt' AND mail_sent = 1";
$openCondition = "status IS NULL OR LOWER(TRIM(status)) <> 'erledigt' OR mail_sent = 0";
$scheduledCondition = "({$openCondition}) AND requested_release_date > CURDATE() AND jamf_unenrolled = 0";
$dueCondition = "({$openCondition}) AND NOT ({$scheduledCondition})";

if ($filter === 'open') {
    $whereParts[] = $dueCondition;
} elseif ($filter === 'scheduled') {
    $whereParts[] = $scheduledCondition;
} elseif ($filter === 'done') {
    $whereParts[] = $doneCondition;
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
                jamf_unenrolled, asm_manual_done, mail_sent
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
             WHERE jamf_unenrolled = 1
               AND asm_manual_done = 1
               AND jamf_unenrolled_at >= ?
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
$mailTemplate = null;
if (is_readable($mailTemplatePath)) {
    $mailTemplate = file_get_contents($mailTemplatePath);
}
if ($mailTemplate === false || $mailTemplate === null) {
    $mailTemplate = "Hallo {{name}},\n\n" .
        "die Bearbeitung Ihres Antrags zur Freigabe des schulisch verwalteten iPads wurde durchgeführt.\n\n" .
        "Gerät:\n{{device_name}}\n\n" .
        "Seriennummer:\n{{serial}}\n\n" .
        "Bitte beachten Sie, dass schulische Profile, Apps und Konfigurationen nach der Freigabe nicht mehr zur Verfügung stehen.\n\n" .
        "Mit freundlichen Grüßen\n\nBBS Einbeck";
}
$mailSubject = 'iPad-Freigabe BBS Einbeck';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        die('Ungültiges Formular. Bitte lade die Seite neu und versuche es erneut.');
    }
    disown_require_write();

    if (isset($_POST['template_save'])) {
        $templateContent = $_POST['template_content'] ?? '';
        if (file_put_contents($mailTemplatePath, $templateContent) === false) {
            $templateError = 'Die Vorlage konnte nicht gespeichert werden. Bitte Dateiberechtigungen prüfen.';
        } else {
            log_request_action($mysqli, 0, 'TEMPLATE_UPDATED', 'Mailvorlage aktualisiert.');
            $templateMessage = 'Vorlage erfolgreich gespeichert.';
        }
    }

    if (isset($_POST['save_device_case'])) {
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

    if (isset($_POST['bulk_action'])) {
        $bulkAction = (string) ($_POST['bulk_action'] ?? '');
        $bulkIds = normalize_bulk_ids($_POST['bulk_ids'] ?? []);
        $bulkRows = load_bulk_requests($mysqli, $bulkIds);

        if (!$bulkIds) {
            $bulkError = 'Bitte wählen Sie mindestens einen Antrag aus.';
        } elseif (!$bulkRows) {
            $bulkError = 'Die ausgewählten Anträge wurden nicht gefunden.';
        } elseif ($bulkAction === 'bulk_jamf_unenroll') {
            $successCount = 0;
            $failedCount = 0;
            $skippedCount = 0;
            $successfulSerials = [];
            $successfulIds = [];

            foreach ($bulkRows as $bulkRow) {
                $requestId = (int) $bulkRow['id'];
                $serial = trim((string) $bulkRow['serial']);
                $isHistoryImport = (($bulkRow['completed_by'] ?? '') === 'history-import');

                if ($isHistoryImport || !empty($bulkRow['jamf_unenrolled']) || !empty($bulkRow['mail_sent']) || $serial === '') {
                    $skippedCount++;
                    continue;
                }

                $result = jamf_unenroll_by_serial($serial);
                $resultMessage = $result['message'] ?? '';

                if (!empty($result['success'])) {
                    $updateStmt = $mysqli->prepare(
                        "UPDATE requests
                         SET jamf_unenrolled = 1,
                             jamf_unenrolled_at = NOW(),
                             jamf_unenroll_error = NULL
                         WHERE id = ?"
                    );
                    if ($updateStmt) {
                        $updateStmt->bind_param('i', $requestId);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }

                    log_request_action(
                        $mysqli,
                        $requestId,
                        'BULK_JAMF_UNENROLL_SUCCESS',
                        'Seriennummer: ' . $serial . '; Meldung: ' . ($resultMessage ?: 'Gerät erfolgreich aus Jamf abgemeldet.')
                    );
                    $successCount++;
                    $successfulSerials[] = $serial;
                    $successfulIds[] = $requestId;
                } else {
                    $errorText = $resultMessage ?: 'Unbekannter Fehler beim Jamf-Abruf.';
                    $updateStmt = $mysqli->prepare(
                        "UPDATE requests
                         SET jamf_unenroll_error = ?
                         WHERE id = ?"
                    );
                    if ($updateStmt) {
                        $updateStmt->bind_param('si', $errorText, $requestId);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }

                    log_request_action(
                        $mysqli,
                        $requestId,
                        'BULK_JAMF_UNENROLL_FAILED',
                        'Seriennummer: ' . $serial . '; Fehler: ' . $errorText
                    );
                    $failedCount++;
                }
            }

            $bulkMessage = "Bulk-Jamf abgeschlossen: {$successCount} erfolgreich, {$failedCount} fehlgeschlagen, {$skippedCount} übersprungen.";
            if ($successfulSerials) {
                $bulkAsmSerials = array_values(array_unique($successfulSerials));
                $bulkLastIds = normalize_bulk_ids($successfulIds);
                $_SESSION['bulk_asm_serials'] = $bulkAsmSerials;
                $_SESSION['bulk_last_ids'] = $bulkLastIds;
                $_SESSION['bulk_last_step'] = 'asm';
            }
        } elseif ($bulkAction === 'bulk_asm_done') {
            $successCount = 0;
            $skippedCount = 0;
            $successfulIds = [];

            foreach ($bulkRows as $bulkRow) {
                $requestId = (int) $bulkRow['id'];
                $serial = trim((string) $bulkRow['serial']);
                $isHistoryImport = (($bulkRow['completed_by'] ?? '') === 'history-import');

                if ($isHistoryImport || empty($bulkRow['jamf_unenrolled']) || !empty($bulkRow['asm_manual_done']) || !empty($bulkRow['mail_sent'])) {
                    $skippedCount++;
                    continue;
                }

                $updateStmt = $mysqli->prepare(
                    "UPDATE requests
                     SET asm_manual_done = 1,
                         asm_manual_done_at = NOW()
                     WHERE id = ?"
                );
                if ($updateStmt) {
                    $updateStmt->bind_param('i', $requestId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }

                log_request_action(
                    $mysqli,
                    $requestId,
                    'BULK_ASM_MANUAL_DONE',
                    'Seriennummer: ' . ($serial ?: 'unbekannt') . '; ASM-Freigabe per Bulk bestätigt.'
                );
                $successCount++;
                $successfulIds[] = $requestId;
            }

            $bulkMessage = "Bulk-ASM abgeschlossen: {$successCount} bestätigt, {$skippedCount} übersprungen.";
            if ($successfulIds) {
                $bulkAsmSerials = [];
                $bulkLastIds = normalize_bulk_ids($successfulIds);
                $bulkLastStep = 'mail';
                $_SESSION['bulk_last_ids'] = $bulkLastIds;
                $_SESSION['bulk_last_step'] = 'mail';
            }
        } elseif ($bulkAction === 'bulk_mail_send') {
            $successCount = 0;
            $failedCount = 0;
            $skippedCount = 0;
            $mailConfig = null;

            if (!$isDevMode) {
                $mailConfig = parse_ini_file('/etc/disown/mail.conf');
                if ($mailConfig === false || empty($mailConfig['MAIL_HOST']) || empty($mailConfig['MAIL_PORT']) || empty($mailConfig['MAIL_USERNAME']) || empty($mailConfig['MAIL_PASSWORD']) || empty($mailConfig['MAIL_FROM'])) {
                    $bulkError = 'SMTP-Konfiguration fehlt oder ist unvollständig. Bulk-Mail wurde nicht ausgeführt.';
                }
            }

            if ($bulkError === '') {
                foreach ($bulkRows as $bulkRow) {
                    $requestId = (int) $bulkRow['id'];
                    $serial = trim((string) $bulkRow['serial']);
                    $deviceName = trim((string) $bulkRow['device_name']);
                    $isHistoryImport = (($bulkRow['completed_by'] ?? '') === 'history-import');

                    if ($isHistoryImport || empty($bulkRow['jamf_unenrolled']) || empty($bulkRow['asm_manual_done']) || !empty($bulkRow['mail_sent'])) {
                        $skippedCount++;
                        continue;
                    }

                    $recipient = trim((string) ($bulkRow['private_email'] ?: $bulkRow['email']));
                    if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                        log_request_action(
                            $mysqli,
                            $requestId,
                            'BULK_MAIL_FAILED',
                            'Empfänger ungültig; Seriennummer: ' . ($serial ?: 'unbekannt')
                        );
                        $failedCount++;
                        continue;
                    }

                    $body = $mailTemplate;
                    $placeholders = [
                        '{{name}}' => (string) ($bulkRow['full_name'] ?? ''),
                        '{{username}}' => (string) ($bulkRow['username'] ?? ''),
                        '{{email}}' => (string) ($bulkRow['email'] ?? ''),
                        '{{private_email}}' => (string) ($bulkRow['private_email'] ?? ''),
                        '{{device_name}}' => $deviceName,
                        '{{serial}}' => $serial,
                    ];
                    $body = str_replace(array_keys($placeholders), array_values($placeholders), $body);

                    if (!$isDevMode) {
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
                            $mail->setFrom($mailConfig['MAIL_FROM'], 'BBS Einbeck, Team Mobile Device Management');
                            $mail->addAddress($recipient);
                            $mail->Subject = $mailSubject;

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
                        } catch (Exception $e) {
                            log_request_action(
                                $mysqli,
                                $requestId,
                                'BULK_MAIL_FAILED',
                                'Empfänger: ' . $recipient . '; Seriennummer: ' . ($serial ?: 'unbekannt') . '; Fehler: ' . $e->getMessage()
                            );
                            $failedCount++;
                            continue;
                        }
                    }

                    $updateStmt = $mysqli->prepare(
                        "UPDATE requests
                         SET mail_sent = 1,
                             mail_sent_at = NOW(),
                             mail_sent_to = ?,
                             status = 'erledigt',
                             completed_at = NOW(),
                             completed_by = ?
                         WHERE id = ?"
                    );
                    if ($updateStmt) {
                        $completedBy = $currentAdminUser !== '' ? $currentAdminUser : ($isDevMode ? 'dev' : 'marc');
                        $updateStmt->bind_param('ssi', $recipient, $completedBy, $requestId);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }

                    log_request_action(
                        $mysqli,
                        $requestId,
                        $isDevMode ? 'BULK_MAIL_SENT_DEV' : 'BULK_MAIL_SENT',
                        ($isDevMode ? 'DEV-Simulation; ' : '') . 'Empfänger: ' . $recipient . '; Seriennummer: ' . ($serial ?: 'unbekannt') . '; Gerät: ' . ($deviceName ?: 'unbekannt')
                    );
                    $successCount++;
                }

                $bulkMessage = "Bulk-Mail abgeschlossen: {$successCount} gesendet, {$failedCount} fehlgeschlagen, {$skippedCount} übersprungen.";
                $bulkAsmSerials = [];
                $bulkLastIds = [];
                $bulkLastStep = '';
            }
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

        if (!$sendRecipients || $invalidRecipients || $sendSubject === '' || $sendBody === '') {
            if ($sendRequestId > 0) {
                log_request_action(
                    $mysqli,
                    $sendRequestId,
                    'MAIL_FAILED',
                    'Empfänger: ' . ($sendTo ?: 'unbekannt') . '; Seriennummer: ' . ($sendSerial ?: 'unbekannt') . '; Fehler: Empfänger, Betreff oder Nachricht ungültig.'
                );
            }
            $mailError = 'E-Mail-Adresse, Betreff oder Nachricht fehlen oder sind ungültig. Bitte die Vorschau neu öffnen und erneut senden.';
        } elseif ($isDevMode) {
            if ($sendRequestId > 0) {
                $updateStmt = $mysqli->prepare(
                    "UPDATE requests
                     SET mail_sent = 1,
                         mail_sent_at = NOW(),
                         mail_sent_to = ?,
                         status = 'erledigt',
                         completed_at = NOW(),
                         completed_by = ?
                     WHERE id = ?"
                );
                if ($updateStmt) {
                    $completedBy = $currentAdminUser !== '' ? $currentAdminUser : 'dev';
                    $updateStmt->bind_param('ssi', $sendRecipientList, $completedBy, $sendRequestId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }

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
            $mailConfig = parse_ini_file('/etc/disown/mail.conf');
            if ($mailConfig === false || empty($mailConfig['MAIL_HOST']) || empty($mailConfig['MAIL_PORT']) || empty($mailConfig['MAIL_USERNAME']) || empty($mailConfig['MAIL_PASSWORD']) || empty($mailConfig['MAIL_FROM'])) {
                if ($sendRequestId > 0) {
                    log_request_action(
                        $mysqli,
                        $sendRequestId,
                        'MAIL_FAILED',
                        'Empfänger: ' . $sendRecipientList . '; Seriennummer: ' . ($sendSerial ?: 'unbekannt') . '; Fehler: SMTP-Konfiguration unvollständig.'
                    );
                }
                $mailError = 'SMTP-Konfiguration fehlt oder ist unvollständig. Bitte /etc/disown/mail.conf prüfen.';
            } else {
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
                    $mail->setFrom($mailConfig['MAIL_FROM'], 'BBS Einbeck, Team Mobile Device Management');
                    foreach ($sendRecipients as $recipient) {
                        $mail->addAddress($recipient);
                    }
                    $mail->Subject = $sendSubject;

                    $safeBody = htmlspecialchars($sendBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    if ($sendDevice !== '') {
                        $safeDevice = htmlspecialchars($sendDevice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $safeBody = str_replace($safeDevice, '<strong>' . $safeDevice . '</strong>', $safeBody);
                    }
                    if ($sendSerial !== '') {
                        $safeSerial = htmlspecialchars($sendSerial, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $safeBody = str_replace($safeSerial, '<strong>' . $safeSerial . '</strong>', $safeBody);
                    }
                    $mail->Body = nl2br($safeBody);
                    $mail->AltBody = $sendBody;
                    $mail->isHTML(true);
                    $mail->send();

                    if ($sendRequestId > 0) {
                        $updateStmt = $mysqli->prepare(
                            "UPDATE requests
                             SET mail_sent = 1,
                                 mail_sent_at = NOW(),
                                 mail_sent_to = ?,
                                 status = 'erledigt',
                                 completed_at = NOW(),
                                 completed_by = ?
                             WHERE id = ?"
                        );
                        if ($updateStmt) {
                            $completedBy = $currentAdminUser !== '' ? $currentAdminUser : 'marc';
                            $updateStmt->bind_param('ssi', $sendRecipientList, $completedBy, $sendRequestId);
                            $updateStmt->execute();
                            $updateStmt->close();
                        }
                    }

                    if ($sendRequestId > 0) {
                        log_request_action(
                            $mysqli,
                            $sendRequestId,
                            'MAIL_SENT',
                            'Empfänger: ' . $sendRecipientList . '; Seriennummer: ' . ($sendSerial ?: 'unbekannt') . '; Gerät: ' . ($sendDevice ?: 'unbekannt')
                        );
                    }

                    $_SESSION['flash_mail_message'] = 'E-Mail erfolgreich gesendet an ' . htmlspecialchars($sendRecipientList) . '.';
                    header('Location: ' . $adminPath);
                    exit;
                } catch (Exception $e) {
                    if ($sendRequestId > 0) {
                        log_request_action(
                            $mysqli,
                            $sendRequestId,
                            'MAIL_FAILED',
                            'Empfänger: ' . $sendRecipientList . '; Seriennummer: ' . ($sendSerial ?: 'unbekannt') . '; Fehler: ' . $e->getMessage()
                        );
                    }
                    $mailError = 'SMTP-Fehler: ' . htmlspecialchars($e->getMessage());
                }
            }
        }
    }

    // NEW WORKFLOW: Jamf Unenroll
    if (isset($_POST['unenroll'])) {
        $unenrollId = (int) $_POST['unenroll'];
        $unenrollSerial = trim($_POST['unenroll_serial'] ?? '');

        if ($unenrollId > 0 && $unenrollSerial !== '') {
            $result = jamf_unenroll_by_serial($unenrollSerial);
            $message = $result['message'] ?? '';

            if (!empty($result['success'])) {
                $updateStmt = $mysqli->prepare(
                    "UPDATE requests
                     SET jamf_unenrolled = 1,
                         jamf_unenrolled_at = NOW(),
                         jamf_unenroll_error = NULL
                     WHERE id = ?"
                );
                if ($updateStmt) {
                    $updateStmt->bind_param('i', $unenrollId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
                log_request_action(
                    $mysqli,
                    $unenrollId,
                    'JAMF_UNENROLL_SUCCESS',
                    'Seriennummer: ' . $unenrollSerial . '; Meldung: ' . ($message ?: 'Gerät erfolgreich aus Jamf abgemeldet.')
                );
                $disownMessage = ($message ?: 'Gerät erfolgreich aus Jamf abgemeldet.') . ' Bitte führen Sie die ASM-Freigabe manuell durch.';
            } else {
                $updateStmt = $mysqli->prepare(
                    "UPDATE requests
                     SET jamf_unenroll_error = ?
                     WHERE id = ?"
                );
                if ($updateStmt) {
                    $errorText = $message ?: 'Unbekannter Fehler beim Jamf-Abruf.';
                    $updateStmt->bind_param('si', $errorText, $unenrollId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
                log_request_action(
                    $mysqli,
                    $unenrollId,
                    'JAMF_UNENROLL_FAILED',
                    'Seriennummer: ' . $unenrollSerial . '; Fehler: ' . ($message ?: 'Unbekannter Fehler beim Jamf-Abruf.')
                );
                $disownError = 'Unenroll fehlgeschlagen: ' . htmlspecialchars($message);
            }
        } else {
            $disownError = 'Ungültige Unenroll-Anfrage. Bitte Seite neu laden und erneut versuchen.';
        }
    }

    // NEW WORKFLOW: ASM manual release confirmed
    if (isset($_POST['asm_manual_done'])) {
        $asmId = (int) $_POST['asm_manual_done'];
        $asmSerial = trim($_POST['asm_serial'] ?? '');

        if ($asmId > 0) {
            $updateStmt = $mysqli->prepare(
                "UPDATE requests
                 SET asm_manual_done = 1,
                     asm_manual_done_at = NOW()
                 WHERE id = ?"
            );
            if ($updateStmt) {
                $updateStmt->bind_param('i', $asmId);
                $updateStmt->execute();
                $updateStmt->close();
            }
            log_request_action(
                $mysqli,
                $asmId,
                'ASM_MANUAL_DONE',
                'Seriennummer: ' . ($asmSerial ?: 'unbekannt') . '; ASM-Freigabe manuell bestätigt.'
            );
            $disownMessage = 'ASM-Freigabe als abgeschlossen bestätigt.';
        } else {
            $disownError = 'Ungültige ASM-Anfrage.';
        }
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
         COALESCE(SUM(CASE WHEN ({$dueCondition}) AND jamf_unenrolled = 0 THEN 1 ELSE 0 END), 0) AS waiting_jamf,
         COALESCE(SUM(CASE WHEN jamf_unenrolled = 1 AND asm_manual_done = 0 AND mail_sent = 0 THEN 1 ELSE 0 END), 0) AS waiting_asm,
         COALESCE(SUM(CASE WHEN asm_manual_done = 1 AND mail_sent = 0 THEN 1 ELSE 0 END), 0) AS waiting_mail,
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
$openDeviceCaseCount = 0;
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
            !empty($row['asm_manual_done']) ? 'ja' : 'nein',
            !empty($row['mail_sent']) ? 'gesendet' : 'nicht gesendet',
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
         jamf_unenrolled_at,
         jamf_unenroll_error,
         asm_manual_done,
         asm_manual_done_at,
         mail_sent,
         mail_sent_at,
         mail_sent_to,
         completed_by,
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
    color-scheme: light;
    font-family: Inter, Arial, sans-serif;
    color: #1f2937;
    background: #f3f5f9;
}
* {
    box-sizing: border-box;
}
body {
    margin: 0;
    padding: 0;
    min-height: 100vh;
    background:
        linear-gradient(rgba(243, 245, 249, 0.84), rgba(243, 245, 249, 0.92)),
        url("<?=htmlspecialchars($siteImagePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>") center top / min(1717px, 118vw) auto no-repeat fixed;
}
.page {
    max-width: 1180px;
    margin: 0 auto;
    padding: 24px;
}
.header {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 16px;
    align-items: flex-start;
    margin-bottom: 20px;
}
.header > div:first-child {
    flex: 1 1 560px;
    min-width: 0;
}
.header-actions {
    display: flex;
    flex: 0 0 auto;
    flex-direction: column;
    align-items: flex-end;
    gap: 10px;
    margin-left: auto;
}
.logo-actions {
    align-items: flex-start;
    display: flex;
    gap: 10px;
}
.refresh-link {
    align-items: center;
    background: #e2e8f0;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    color: #334155;
    display: inline-flex;
    font-size: 1.05rem;
    height: 2.1rem;
    justify-content: center;
    margin-top: 4px;
    text-decoration: none;
    width: 2.1rem;
}
.refresh-link:hover {
    background: #cbd5e1;
    text-decoration: none;
}
.tool-links {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: flex-end;
}
.tool-link {
    align-items: center;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    color: #1f2937;
    display: inline-flex;
    font-size: 0.88rem;
    font-weight: 600;
    gap: 7px;
    height: 2.45rem;
    justify-content: center;
    min-height: 2.35rem;
    padding: 0.38rem 0.75rem;
    text-decoration: none;
    width: 4.8rem;
}
.tool-link:hover {
    background: #f8fafc;
    border-color: #94a3b8;
    text-decoration: none;
}
.tool-logo {
    display: block;
    height: 1.35rem;
    max-width: 3.9rem;
    object-fit: contain;
    width: auto;
}
.admin-user {
    color: #64748b;
    font-size: 0.95rem;
    text-decoration: none;
}
.admin-user:hover {
    text-decoration: underline;
}
.site-logo {
    display: block;
    max-height: 70px;
    max-width: min(220px, 40vw);
    width: auto;
    height: auto;
    object-fit: contain;
}
.page-title {
    font-size: 1.75rem;
    margin: 0;
}
.card {
    background: rgba(255, 255, 255, 0.94);
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 20px;
    overflow: hidden;
    position: relative;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
}
.card::before {
    background: url("<?=htmlspecialchars($siteImagePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>") center top / min(1500px, 115vw) auto no-repeat;
    content: "";
    inset: 0;
    opacity: 0.04;
    pointer-events: none;
    position: absolute;
}
.card > * {
    position: relative;
    z-index: 1;
}
.table-wrap {
    overflow-x: auto;
}
table {
    width: 100%;
    border-collapse: collapse;
    min-width: 0;
}
.page {
    max-width: 1280px;
    margin: 0 auto;
    padding: 24px;
}
.search-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    margin-bottom: 18px;
}
.search-label {
    color: #334155;
    font-weight: 700;
}
.search-field {
    flex: 1 1 320px;
    min-width: 220px;
    position: relative;
}
.search-input {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    color: #0f172a;
    font: inherit;
    min-height: 44px;
    padding: 0.65rem 2.6rem 0.65rem 0.85rem;
    width: 100%;
}
.clear-search {
    align-items: center;
    background: #e2e8f0;
    border-radius: 999px;
    color: #334155;
    display: inline-flex;
    font-size: 1.2rem;
    font-weight: 800;
    height: 28px;
    justify-content: center;
    line-height: 1;
    position: absolute;
    right: 8px;
    text-decoration: none;
    top: 50%;
    transform: translateY(-50%);
    width: 28px;
}
.clear-search:hover {
    background: #cbd5e1;
    text-decoration: none;
}
.dashboard {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    display: flex;
    flex-wrap: wrap;
    gap: 0;
    margin: 14px 0 18px;
    padding: 8px 10px;
}
.dashboard-stat {
    align-items: baseline;
    color: #64748b;
    display: inline-flex;
    font-size: 0.82rem;
    gap: 5px;
    line-height: 1.2;
    padding: 3px 10px;
}
.dashboard-stat + .dashboard-stat {
    border-left: 1px solid #e2e8f0;
}
.dashboard-stat-value {
    color: #334155;
    font-size: 0.95rem;
    font-weight: 700;
}
.dashboard-stat.warn .dashboard-stat-value {
    color: #c2410c;
}
.dashboard-stat.done .dashboard-stat-value {
    color: #15803d;
}
.dashboard-stat.info .dashboard-stat-value {
    color: #475569;
}
.dashboard-stat.zero,
.dashboard-stat.zero .dashboard-stat-value {
    color: #94a3b8;
}
.dashboard-stat-small {
    font-size: 0.78rem;
}
.request-trend-card {
    background: rgba(255, 255, 255, 0.94);
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    margin: -4px 0 18px;
    padding: 10px 14px 9px;
}
.request-trend-header {
    align-items: baseline;
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 6px;
}
.request-trend-title {
    color: #334155;
    font-size: 0.88rem;
    font-weight: 800;
}
.request-trend-total {
    color: #64748b;
    font-size: 0.8rem;
    font-weight: 700;
}
.request-trend-chart {
    display: grid;
    gap: 8px;
    grid-template-columns: repeat(12, minmax(56px, 1fr));
}
.request-trend-month {
    align-items: center;
    background: var(--trend-bg, #f8fafc);
    border: 1px solid var(--trend-border, #e2e8f0);
    border-radius: 8px;
    display: inline-flex;
    gap: 6px;
    justify-content: space-between;
    min-width: 0;
    padding: 7px 8px;
}
.request-trend-value {
    color: #1f2937;
    font-size: 0.8rem;
    font-weight: 800;
    line-height: 1;
}
.request-trend-label {
    color: #64748b;
    font-size: 0.72rem;
    font-weight: 800;
    line-height: 1;
}
.request-trend-month.peak .request-trend-label {
    color: #9a3412;
}
.request-trend-month.previous-year .request-trend-label,
.request-trend-month.previous-year .request-trend-value {
    color: #475569;
}
.license-dashboard {
    align-items: center;
    background: rgba(255, 255, 255, 0.92);
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    color: #64748b;
    display: flex;
    flex-wrap: wrap;
    gap: 0;
    margin: -4px 0 18px;
    padding: 8px 10px;
}
.license-dashboard-title,
.license-dashboard-item {
    align-items: baseline;
    display: inline-flex;
    font-size: 0.82rem;
    gap: 5px;
    line-height: 1.2;
    padding: 3px 10px;
}
.license-dashboard-title {
    color: #334155;
    font-weight: 700;
}
.license-dashboard-item {
    border-left: 1px solid #e2e8f0;
}
.license-dashboard-value {
    color: #334155;
    font-size: 0.95rem;
    font-weight: 700;
}
.license-dashboard-free {
    color: #15803d;
}
.license-dashboard-warn {
    color: #c2410c;
}
.license-dashboard-error {
    color: #b91c1c;
    font-weight: 700;
}
.filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 18px;
}
.bulk-toolbar {
    align-items: center;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 16px;
    padding: 10px 12px;
}
.bulk-status {
    color: #475569;
    font-size: 0.9rem;
    margin-right: auto;
}
.bulk-asm-list {
    margin-bottom: 16px;
}
.bulk-list-textarea {
    width: 100%;
    min-height: 150px;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    color: #0f172a;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    padding: 12px;
}
.filter-link {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #334155;
}
.filter-link.active {
    background: #1f2937;
    border-color: #1f2937;
    color: #ffffff;
}
.pagination {
    align-items: center;
    color: #64748b;
    display: flex;
    flex-wrap: wrap;
    font-size: 0.9rem;
    gap: 8px;
    justify-content: flex-end;
    margin: 0 0 14px;
}
.pagination-top {
    margin: 8px 0 72px;
}
.pagination-bottom {
    margin: 14px 0 0;
}
.pagination-link {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    color: #334155;
    padding: 0.42rem 0.7rem;
    text-decoration: none;
}
.pagination-link:hover {
    background: #f8fafc;
    text-decoration: none;
}
.pagination-link.disabled {
    color: #cbd5e1;
    pointer-events: none;
}
.pagination-current {
    color: #475569;
    padding: 0.42rem 0.2rem;
}
.preview-card {
    margin-top: 24px;
    padding: 20px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
}
.case-card {
    margin-top: 24px;
    padding: 20px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
}
.case-grid {
    display: grid;
    gap: 10px;
    grid-template-columns: 1fr;
}
.case-meta {
    color: #64748b;
    font-size: 0.9rem;
    margin: 0 0 8px;
}
.case-field {
    display: grid;
    gap: 6px;
    margin-bottom: 8px;
}
.case-field-full {
    grid-column: 1 / -1;
}
.case-input,
.case-select,
.case-textarea {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    color: #111827;
    font: inherit;
    padding: 0.55rem 0.7rem;
}
.case-textarea {
    min-height: 92px;
    resize: vertical;
}
.case-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: flex-end;
}
.preview-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    gap: 16px;
    margin-bottom: 16px;
}
.preview-subtitle {
    margin: 4px 0 0;
    color: #64748b;
}
.preview-input {
    width: min(100%, 420px);
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    background: #ffffff;
    color: #0f172a;
}
.recipient-options {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 8px 0 14px;
    max-width: 760px;
}
.recipient-option {
    align-items: center;
    display: flex;
    gap: 12px;
}
.recipient-option input[type="checkbox"] {
    flex: 0 0 auto;
    width: 18px;
    height: 18px;
}
.recipient-label {
    flex: 0 0 150px;
    color: #1f2937;
    font-weight: 600;
}
.recipient-option .preview-input {
    flex: 1 1 320px;
    max-width: 460px;
}
.preview-field {
    display: grid;
    gap: 8px;
    margin-top: 14px;
}
.preview-field label {
    color: #1f2937;
    font-weight: 700;
}
.preview-subject-input,
.preview-body-input {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    background: #ffffff;
    color: #0f172a;
    font: inherit;
}
.preview-subject-input {
    padding: 10px 12px;
}
.preview-body-input {
    min-height: 240px;
    padding: 16px;
    resize: vertical;
    white-space: pre-wrap;
}
.preview-content pre {
    white-space: pre-wrap;
    word-break: break-word;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 18px;
    margin: 0;
}
.hidden {
    display: none;
}
.small-button {
    padding: 10px 14px;
    font-size: 0.95rem;
}
th, td {
    padding: 14px 16px;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
    vertical-align: middle;
}
th {
    background: #f8fafc;
    font-weight: 600;
    color: #334155;
    font-size: 0.95rem;
}
tr:hover {
    background: #f8fafc;
}
.case-row-clickable {
    cursor: pointer;
}
.case-row-clickable:hover {
    background: #fffbeb;
}
.case-row-clickable .serial-case-button {
    text-decoration-color: #fbbf24;
}
.nowrap-cell,
.email-cell a,
.person-cell {
    white-space: nowrap;
}
.select-cell {
    width: 42px;
}
.select-cell input {
    height: 18px;
    width: 18px;
}
.person-subtitle {
    color: #475569;
    font-size: 0.95rem;
    line-height: 1.4;
}
.date-cell {
    white-space: normal;
}
.date-cell span {
    display: block;
}
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.45rem 0.75rem;
    border-radius: 999px;
    font-size: 0.9rem;
    font-weight: 600;
}
.status-open {
    background: #fef3c7;
    color: #92400e;
}
.status-done {
    background: #d1fae5;
    color: #0f766e;
}
.button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    min-height: 2.1rem;
    padding: 0.45rem 0.75rem;
    border-radius: 999px;
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 500;
    border: 1px solid transparent;
    cursor: pointer;
}
.button:disabled {
    cursor: not-allowed;
    opacity: 0.65;
}
.button-primary {
    background: #2563eb;
    color: white;
}
.button-primary:hover:not(:disabled) {
    background: #1d4ed8;
}
.button-secondary {
    background: #e2e8f0;
    color: #1f2937;
}
.audit-log-link {
    background: #ecfdf5;
    border: 1px solid #86efac;
    color: #14532d;
    font-size: 0.9rem;
    font-weight: 500;
    min-height: 2.1rem;
    padding: 0.45rem 0.75rem;
}
.audit-log-link:hover {
    background: #dcfce7;
    border-color: #4ade80;
    color: #14532d;
}
.hint-text {
    margin-top: 8px;
    color: #475569;
    font-size: 0.95rem;
}
.status-cell {
    min-width: 210px;
}
.nowrap-cell {
    white-space: nowrap;
}
.email-cell {
    white-space: nowrap;
}
.person-cell {
    white-space: normal;
}
.person-name,
.person-subtitle,
.person-subtitle a {
    white-space: nowrap;
}
.person-name {
    font-weight: 700;
}
.person-subtitle {
    color: #475569;
    font-size: 0.95rem;
    line-height: 1.3;
}
.serial-cell {
    white-space: nowrap;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: 0.95rem;
    color: #475569;
    margin-top: 4px;
}
.serial-case-button,
.license-case-button {
    align-items: center;
    background: transparent;
    border: 0;
    color: inherit;
    cursor: pointer;
    display: inline-flex;
    font: inherit;
    gap: 0.35rem;
    padding: 0;
    text-decoration: underline;
    text-decoration-color: #cbd5e1;
    text-underline-offset: 3px;
}
.serial-case-button:hover,
.license-case-button:hover {
    color: #2563eb;
    text-decoration-color: currentColor;
}
.case-badge {
    background: #fef3c7;
    border-radius: 999px;
    color: #92400e;
    display: inline-flex;
    font-family: Inter, Arial, sans-serif;
    font-size: 0.72rem;
    font-weight: 600;
    line-height: 1;
    padding: 0.2rem 0.38rem;
}
.device-cell {
    word-break: break-word;
    white-space: normal;
}
.mail-status {
    margin-top: 4px;
}
.process-steps {
    align-items: center;
    display: flex;
    flex-wrap: nowrap;
    gap: 0;
}
.process-step {
    align-items: center;
    color: #64748b;
    display: inline-flex;
    font-size: 0.9rem;
    gap: 6px;
    position: relative;
    white-space: nowrap;
}
.process-step + .process-step {
    margin-left: 22px;
}
.process-step + .process-step::before {
    background: #cbd5e1;
    content: "";
    height: 1px;
    left: -18px;
    position: absolute;
    top: 50%;
    width: 14px;
}
.process-step.done {
    color: #334155;
}
.process-mark {
    align-items: center;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    display: inline-flex;
    font-size: 0.72rem;
    height: 1.15rem;
    justify-content: center;
    width: 1.15rem;
}
.process-step.done .process-mark {
    background: #f0fdf4;
    border-color: #86efac;
}
.last-audit {
    color: #64748b;
    font-size: 0.76rem;
    margin-top: 6px;
}
.process-warning {
    color: #92400e;
    font-size: 0.76rem;
    margin-top: 5px;
}
.history-import-badge {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    color: #64748b;
    display: inline-flex;
    font-size: 0.72rem;
    margin-top: 6px;
    padding: 0.16rem 0.45rem;
}
.mail-status-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.55rem;
    border-radius: 999px;
    font-size: 0.85rem;
    font-weight: 600;
    letter-spacing: 0.01em;
}
.mail-status-badge.mail-sent {
    background: #d1fae5;
    color: #0f766e;
}
.mail-status-badge.mail-not-sent {
    background: #e2e8f0;
    color: #334155;
}
.action-form {
    margin: 0;
}
.action-cell {
    min-width: 220px;
}
.action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.template-textarea {
    width: 100%;
    min-height: 320px;
    border: 1px solid #cbd5e1;
    border-radius: 14px;
    padding: 16px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    background: #f8fafc;
    color: #111827;
    margin-bottom: 16px;
}
.editor-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    flex-wrap: wrap;
}
.send-form .editor-actions {
    margin-top: 16px;
}
.message {
    border-radius: 16px;
    padding: 16px 18px;
    margin-bottom: 18px;
    line-height: 1.55;
}
.message.success {
    background: #ddf7e8;
    color: #047857;
    border: 1px solid #86efac;
}
.message.error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}
.readonly-banner {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 14px;
    color: #1e40af;
    line-height: 1.45;
    margin-bottom: 16px;
    padding: 12px 14px;
}
.readonly-banner strong {
    color: #1d4ed8;
}
.status-secondary {
    background: #e2e8f0;
    color: #334155;
}
.status-muted {
    color: #64748b;
    font-size: 0.85rem;
    font-weight: 700;
}
.email-link {
    color: #2563eb;
    text-decoration: none;
}
.email-link:hover {
    text-decoration: underline;
}
.page-footer {
    margin-top: 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    color: #64748b;
    font-size: 0.9rem;
}
.page-footer a {
    color: inherit;
    text-decoration: none;
}
.page-footer a:hover {
    text-decoration: underline;
}
.footer-export-link {
    align-items: center;
    border-radius: 999px;
    color: #64748b;
    display: inline-flex;
    font-size: 1.05rem;
    height: 2rem;
    justify-content: center;
    text-decoration: none;
    width: 2rem;
}
.footer-export-link:hover {
    background: #e2e8f0;
    color: #334155;
    text-decoration: none;
}
@media (max-width: 720px) {
    .header {
        flex-direction: column;
        align-items: flex-start;
    }
    .header-actions {
        align-items: flex-start;
    }
    .tool-links {
        justify-content: flex-start;
    }
    .site-logo {
        max-width: 180px;
    }
    .dashboard-stat {
        border-left: 0;
        padding-left: 6px;
        padding-right: 8px;
    }
    .dashboard-stat + .dashboard-stat {
        border-left: 0;
    }
    .license-dashboard-title,
    .license-dashboard-item {
        border-left: 0;
        padding-left: 6px;
        padding-right: 8px;
    }
    .request-trend-card {
        overflow-x: auto;
        padding-bottom: 12px;
    }
    .request-trend-chart {
        grid-template-columns: repeat(12, minmax(58px, 1fr));
        min-width: 760px;
    }
    .case-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 640px) {
    body {
        background-attachment: scroll;
        background-position: center top;
        font-size: 15px;
    }
    .page {
        padding: 14px 10px;
    }
    .page-title {
        font-size: 1.55rem;
    }
    .header {
        gap: 12px;
        margin-bottom: 14px;
    }
    .header-actions,
    .logo-actions,
    .tool-links {
        width: 100%;
    }
    .logo-actions {
        justify-content: space-between;
    }
    .tool-links {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .tool-link,
    .audit-log-link {
        width: 100%;
    }
    .admin-user {
        overflow-wrap: anywhere;
    }
    .search-toolbar {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 8px;
        margin-bottom: 12px;
    }
    .search-label {
        display: none;
    }
    .search-input {
        flex-basis: auto;
        min-height: 42px;
        padding: 9px 2.6rem 9px 12px;
    }
    .search-toolbar button[type="submit"] {
        min-height: 42px;
        padding: 0.55rem 0.85rem;
        width: auto;
    }
    .search-toolbar button[type="button"] {
        grid-column: 1 / -1;
        min-height: 40px;
        padding: 0.55rem 0.85rem;
        width: 100%;
    }
    .bulk-toolbar {
        align-items: stretch;
        flex-direction: column;
    }
    .bulk-toolbar .button {
        min-width: 0;
        width: 100%;
    }
    .filter-bar {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .filter-link {
        width: 100%;
    }
    .dashboard {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 4px 0;
        padding: 8px;
    }
    .dashboard-stat {
        align-items: center;
        justify-content: space-between;
        padding: 7px 8px;
    }
    .pagination {
        justify-content: center;
    }
    .pagination-top {
        margin: 8px 0 18px;
    }
    .card {
        border-radius: 14px;
        padding: 12px;
    }
    .table-wrap {
        overflow-x: visible;
    }
    table,
    thead,
    tbody,
    tr,
    td {
        display: block;
        width: 100%;
    }
    table {
        border-collapse: separate;
    }
    thead {
        display: none;
    }
    tr.request-row {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        margin-bottom: 12px;
        padding: 10px 12px;
    }
    tr.request-row:hover {
        background: #ffffff;
    }
    td {
        border-bottom: 0;
        display: grid;
        gap: 6px;
        grid-template-columns: minmax(82px, 34%) minmax(0, 1fr);
        padding: 8px 0;
    }
    td > * {
        grid-column: 2;
        min-width: 0;
    }
    td::before {
        color: #64748b;
        content: attr(data-label);
        font-size: 0.78rem;
        font-weight: 800;
        text-transform: uppercase;
    }
    .person-cell,
    .device-cell,
    .status-cell,
    .action-cell {
        grid-template-columns: minmax(0, 1fr);
        gap: 7px;
        padding: 10px 0;
    }
    .person-cell::before,
    .device-cell::before,
    .status-cell::before,
    .action-cell::before,
    .person-cell > *,
    .device-cell > *,
    .status-cell > *,
    .action-cell > * {
        grid-column: 1;
    }
    .person-cell {
        row-gap: 4px;
    }
    .person-name {
        font-size: 1rem;
    }
    .person-subtitle {
        line-height: 1.25;
        max-width: 100%;
    }
    .person-subtitle a,
    .email-link {
        overflow-wrap: anywhere;
        word-break: break-word;
    }
    .device-cell {
        row-gap: 4px;
    }
    .device-cell > div:first-child {
        font-weight: 600;
    }
    .select-cell {
        align-items: center;
        display: flex;
        justify-content: flex-end;
        width: 100%;
    }
    .select-cell::before {
        content: "";
    }
    .nowrap-cell,
    .person-name,
    .person-subtitle,
    .person-subtitle a,
    .serial-cell {
        white-space: normal;
        overflow-wrap: anywhere;
    }
    .status-cell,
    .action-cell {
        min-width: 0;
    }
    .process-steps {
        flex-wrap: wrap;
        gap: 6px;
    }
    .process-step + .process-step {
        margin-left: 0;
    }
    .process-step + .process-step::before {
        display: none;
    }
    .action-buttons,
    .action-form,
    .action-buttons .button {
        width: 100%;
    }
    .preview-card {
        padding: 14px;
    }
    .recipient-option {
        grid-template-columns: auto minmax(0, 1fr);
    }
    .recipient-option .preview-input {
        grid-column: 1 / -1;
    }
    .page-footer {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
</head>
<body>
<div class="page">
    <div class="header">
        <div>
            <h1 class="page-title">iPad-Management</h1>
            <p>Offene und erledigte Anträge anzeigen. Schließe Anträge direkt hier ab.</p>
            <p class="hint-text">Der automatische Jamf-Unenroll entfernt die MDM-Verwaltung. Die ADE/ASM-Freigabe erfolgt anschließend manuell.</p>
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
            </div>
        </div>
    </div>

    <form class="search-toolbar" method="get" action="<?=htmlspecialchars($adminPath)?>">
        <input type="hidden" name="filter" value="<?=htmlspecialchars($filter)?>">
        <input type="hidden" name="page" value="1">
        <label for="searchInput" class="search-label">Suche</label>
        <div class="search-field">
            <input id="searchInput" name="q" type="search" class="search-input" placeholder="Name, Klasse, IServ-Benutzer, E-Mail oder Seriennummer" value="<?=htmlspecialchars($searchTerm)?>" autocomplete="off">
            <?php if ($searchTerm !== ''): ?>
                <a class="clear-search" href="<?=htmlspecialchars(admin_url(['q' => '', 'page' => 1, 'live' => null, 'export' => null]))?>" aria-label="Suche löschen">×</a>
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
    </div>

    <div class="dashboard" aria-label="Statistik">
        <span class="dashboard-stat warn <?= (int) $dashboard['open_requests'] === 0 ? 'zero' : '' ?>">Offen <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['open_requests'])?></span></span>
        <span class="dashboard-stat info <?= (int) $dashboard['scheduled_requests'] === 0 ? 'zero' : '' ?>">Terminiert <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['scheduled_requests'])?></span></span>
        <span class="dashboard-stat warn <?= (int) $dashboard['waiting_jamf'] === 0 ? 'zero' : '' ?>">Jamf <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['waiting_jamf'])?></span></span>
        <span class="dashboard-stat warn <?= (int) $dashboard['waiting_asm'] === 0 ? 'zero' : '' ?>">ASM <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['waiting_asm'])?></span></span>
        <span class="dashboard-stat warn <?= (int) $dashboard['waiting_mail'] === 0 ? 'zero' : '' ?>">Mail <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['waiting_mail'])?></span></span>
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
                ?>
                <div class="request-trend-month <?= $trendMonth['is_peak_season'] ? 'peak' : '' ?> <?= !$trendMonth['is_current_year'] ? 'previous-year' : '' ?>" style="--trend-bg: rgba(<?=$trendColor?>, <?=$trendBgOpacity?>); --trend-border: rgba(<?=$trendColor?>, <?=$trendBorderOpacity?>);">
                    <span class="request-trend-label"><?=htmlspecialchars($trendMonth['label'])?></span>
                    <span class="request-trend-value"><?=htmlspecialchars((string) $trendCount)?></span>
                </div>
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
                    <button type="button"
                        class="license-case-button license-dashboard-value license-dashboard-warn"
                        title="<?=htmlspecialchars(implode(', ', $jamfLicenseDashboard['waiting_serials']))?>"
                        data-case-id=""
                        data-request-id="0"
                        data-serial="<?=htmlspecialchars((string) $jamfLicenseDashboard['waiting_serials'][0])?>"
                        data-source="license-dashboard"
                        data-title="Wartet auf Jamf Trash"
                        data-status="offen"
                        data-note="Lokaler Workflow ist erledigt, das Gerät steht aber noch nicht im Jamf-Trash."
                        data-resolution-note=""
                        onclick="showDeviceCase(this)"><?=htmlspecialchars((string) $jamfLicenseDashboard['waiting_for_trash'])?></button>
                <?php else: ?>
                    <span class="license-dashboard-value"><?=htmlspecialchars((string) $jamfLicenseDashboard['waiting_for_trash'])?></span>
                <?php endif; ?>
            </span>
        <?php else: ?>
            <span class="license-dashboard-item license-dashboard-error">Jamf-Abgleich aktuell nicht verfügbar</span>
        <?php endif; ?>
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
            <button type="button" id="bulkJamfButton" class="button button-secondary" onclick="submitBulkAction('bulk_jamf_unenroll')" disabled>Jamf für Auswahl</button>
            <button type="button" id="bulkCopyButton" class="button button-secondary" onclick="copyBulkAsmList()" disabled>Liste kopieren</button>
            <button type="button" id="bulkAsmButton" class="button button-secondary" onclick="submitBulkAction('bulk_asm_done')" disabled>ASM bestätigen</button>
            <button type="button" id="bulkMailButton" class="button button-secondary" onclick="submitBulkAction('bulk_mail_send')" disabled>Mail für Auswahl</button>
        </div>
    <?php endif; ?>

    <div id="bulkAsmList" class="preview-card bulk-asm-list <?= $bulkAsmSerials ? '' : 'hidden' ?>">
        <div class="preview-header">
            <div>
                <h2>ASM-Seriennummern</h2>
                <p class="preview-subtitle">Kommagetrennt für die Apple-Suche.</p>
            </div>
            <button type="button" class="button button-secondary small-button" onclick="hideBulkAsmList()">Schließen</button>
        </div>
        <textarea id="bulkAsmListText" class="bulk-list-textarea" data-server-list="<?= $bulkAsmSerials ? '1' : '0' ?>" readonly><?=htmlspecialchars(implode(', ', $bulkAsmSerials))?></textarea>
        <div class="editor-actions">
            <button type="button" class="button button-primary" onclick="copyBulkAsmList()">Liste kopieren</button>
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

    <?php if ($canWrite): ?>
    <div id="templateEditor" class="preview-card hidden">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
            <textarea name="template_content" class="template-textarea" rows="16"><?=htmlspecialchars($mailTemplate)?></textarea>
            <div class="editor-actions">
                <button type="submit" name="template_save" class="button button-primary">Speichern</button>
                <button type="button" class="button button-secondary" onclick="toggleTemplateEditor()">Abbrechen</button>
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
                <?php while ($row = $result->fetch_assoc()): ?>
                    <?php
                        $bulkMailSent = !empty($row['mail_sent']);
                        $bulkJamfDone = !empty($row['jamf_unenrolled']);
                        $bulkAsmDone = !empty($row['asm_manual_done']);
                        $bulkIsHistoryImport = (($row['completed_by'] ?? '') === 'history-import');
                        $bulkSelectable = $canWrite && !$bulkIsHistoryImport && !$bulkMailSent;
                        $openCaseCount = (int) ($row['open_case_count'] ?? 0);
                        $latestCaseId = (int) ($row['latest_case_id'] ?? 0);
                        $latestCaseStatus = (string) ($row['latest_case_status'] ?? 'offen');
                        $defaultCaseTitle = 'Klärfall ' . (string) ($row['serial'] ?? '');
                        if (!empty($row['jamf_unenrolled']) && !empty($row['asm_manual_done']) && empty($row['mail_sent'])) {
                            $defaultCaseTitle = 'Workflow vor Mail prüfen';
                        } elseif (!empty($row['jamf_unenrolled']) && !empty($row['asm_manual_done'])) {
                            $defaultCaseTitle = 'Nach Freigabe prüfen';
                        }
                        $rowOpensCase = $canWrite && $filter === 'cases' && !empty($row['serial']);
                    ?>
                <tr class="request-row<?= $rowOpensCase ? ' case-row-clickable' : '' ?>"
                    data-id="<?=htmlspecialchars($row['id'])?>"
                    data-full-name="<?=htmlspecialchars($row['full_name'])?>"
                    data-username="<?=htmlspecialchars($row['username'])?>"
                    data-email="<?=htmlspecialchars($row['email'])?>"
                    data-private-email="<?=htmlspecialchars($row['private_email'] ?? '')?>"
                    data-serial="<?=htmlspecialchars($row['serial'])?>"
                    <?php if ($rowOpensCase): ?>
                        data-case-id="<?=htmlspecialchars((string) $latestCaseId)?>"
                        data-request-id="<?=htmlspecialchars((string) $row['id'])?>"
                        data-source="admin"
                        data-title="<?=htmlspecialchars((string) ($row['latest_case_title'] ?: $defaultCaseTitle))?>"
                        data-status="<?=htmlspecialchars($latestCaseStatus ?: 'offen')?>"
                        data-note="<?=htmlspecialchars((string) ($row['latest_case_note'] ?? ''))?>"
                        data-resolution-note="<?=htmlspecialchars((string) ($row['latest_case_resolution_note'] ?? ''))?>"
                        data-updated-at="<?=htmlspecialchars((string) ($row['latest_case_updated_at'] ?? ''))?>"
                    <?php endif; ?>>
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
                        <div><?=htmlspecialchars($row['device_name'])?></div>
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
                                    onclick="showDeviceCase(this)">
                                    <?=htmlspecialchars($row['serial'])?>
                                    <?php if ($openCaseCount > 0): ?>
                                        <span class="case-badge"><?=htmlspecialchars((string) $openCaseCount)?></span>
                                    <?php endif; ?>
                                </button>
                            <?php else: ?>
                                <?=htmlspecialchars($row['serial'])?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="status-cell" data-label="Status">
                        <?php
                            $statusRaw = (string) ($row['status'] ?? '');
                            $status = trim(strtolower($statusRaw));
                            $mailSent = !empty($row['mail_sent']);
                            $jamfUnenrolled = !empty($row['jamf_unenrolled']);
                            $asmManualDone = !empty($row['asm_manual_done']);
                            $isHistoryImport = (($row['completed_by'] ?? '') === 'history-import');
                            $jamfStepDone = $isHistoryImport || $jamfUnenrolled;
                            $asmStepDone = $isHistoryImport || $asmManualDone;
                            $mailStepDone = $isHistoryImport || $mailSent;
                            $isOpen = ($status === 'offen');
                            $lastAuditAt = $row['last_audit_at'] ?? null;
                        ?>
                        <div class="process-steps">
                            <div class="process-step done"><span class="process-mark">✓</span><span>Antrag</span></div>
                            <div class="process-step <?= $jamfStepDone ? 'done' : '' ?>"><span class="process-mark"><?= $jamfStepDone ? '✓' : '○' ?></span><span>Jamf</span></div>
                            <div class="process-step <?= $asmStepDone ? 'done' : '' ?>"><span class="process-mark"><?= $asmStepDone ? '✓' : '○' ?></span><span>ASM</span></div>
                            <div class="process-step <?= $mailStepDone ? 'done' : '' ?>"><span class="process-mark"><?= $mailStepDone ? '✓' : '○' ?></span><span>Mail</span></div>
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
                        <?php if (!$isHistoryImport && $mailSent && (!$jamfUnenrolled || !$asmManualDone)): ?>
                            <div class="process-warning">🟠 Mail vor Abschluss versendet</div>
                        <?php endif; ?>
                    </td>
                    <td class="action-cell" data-label="Aktion">
                        <div class="action-buttons">
                            <?php if (!$canWrite): ?>
                                <span class="status-muted">Nur Ansicht</span>
                            <?php endif; ?>

                            <?php if ($canWrite && !$isHistoryImport && !$jamfUnenrolled): ?>
                                <form method="post" class="action-form">
                                    <input type="hidden" name="unenroll" value="<?=htmlspecialchars($row['id'])?>">
                                    <input type="hidden" name="unenroll_serial" value="<?=htmlspecialchars($row['serial'])?>">
                                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
                                    <button type="submit" class="button button-primary">Jamf Unenroll</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($canWrite && !$isHistoryImport && $jamfUnenrolled && !$asmManualDone): ?>
                                <form method="post" class="action-form" onsubmit="openAsmBeforeSubmit()">
                                    <input type="hidden" name="asm_manual_done" value="<?=htmlspecialchars($row['id'])?>">
                                    <input type="hidden" name="asm_serial" value="<?=htmlspecialchars($row['serial'])?>">
                                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
                                    <button type="submit" class="button button-secondary">Manuell abschließen</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($canWrite && !$isHistoryImport && $asmManualDone && !$mailSent): ?>
                                <button type="button" class="button button-primary" onclick="showMailPreview(this)"
                                    data-id="<?=htmlspecialchars($row['id'])?>"
                                    data-name="<?=htmlspecialchars($row['full_name'])?>"
                                    data-username="<?=htmlspecialchars($row['username'])?>"
                                    data-email="<?=htmlspecialchars($row['email'])?>"
                                    data-private-email="<?=htmlspecialchars($row['private_email'] ?? '')?>"
                                    data-device="<?=htmlspecialchars($row['device_name'])?>"
                                    data-serial="<?=htmlspecialchars($row['serial'])?>">
                                    Mail senden
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
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
                    <button type="submit" class="button button-primary">Klärfall speichern</button>
                    <button type="button" class="button button-secondary" onclick="hideDeviceCase()">Schließen</button>
                </div>
            </form>
        </div>

        <div id="mailPreview" class="preview-card hidden">
            <div class="preview-header">
                <div>
                    <h2>Mail-Vorschau</h2>
                    <p class="preview-subtitle">Vorlage: mail_release.txt</p>
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
    <footer class="page-footer">
        <span>&copy; 2026 <a href="mailto:admin@example.org">Marc Schulz</a> · Version <?=htmlspecialchars($appVersion)?> · Stand: <?=htmlspecialchars($appVersionDate)?></span>
        <a class="footer-export-link" href="<?=htmlspecialchars(admin_url(['filter' => $filter, 'export' => 'requests_csv']))?>" title="Anträge exportieren" aria-label="Anträge exportieren">⬇</a>
    </footer>
</div>

<script>
const mailTemplate = <?= json_encode($mailTemplate, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const mailSubject = <?= json_encode($mailSubject, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

const searchInput = document.getElementById('searchInput');
const selectAllRequests = document.getElementById('selectAllRequests');
let searchDebounceTimer = null;
['sendPrivateEmail', 'sendSchoolEmail', 'previewPrivateEmailInput', 'previewSchoolEmailInput'].forEach((id) => {
    const element = document.getElementById(id);
    if (element) {
        element.addEventListener('input', updateMailRecipients);
        element.addEventListener('change', updateMailRecipients);
    }
});

document.querySelectorAll('.bulk-select').forEach((checkbox) => {
    checkbox.addEventListener('change', updateBulkSelectionStatus);
});

if (selectAllRequests) {
    selectAllRequests.addEventListener('change', () => {
        document.querySelectorAll('.bulk-select').forEach((checkbox) => {
            checkbox.checked = selectAllRequests.checked;
        });
        updateBulkSelectionStatus();
    });
}

if (searchInput) {
    searchInput.addEventListener('input', () => {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => {
            const url = new URL(window.location.href);
            const term = searchInput.value.trim();

            if (term) {
                url.searchParams.set('q', term);
            } else {
                url.searchParams.delete('q');
            }
            url.searchParams.set('page', '1');
            url.searchParams.set('live', '1');
            url.searchParams.delete('export');

            window.location.href = url.toString();
        }, 400);
    });

    if (new URL(window.location.href).searchParams.get('live') === '1') {
        searchInput.focus();
        searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
    }
}

function getSelectedBulkRows() {
    return Array.from(document.querySelectorAll('.bulk-select:checked'));
}

function getBulkStep(checkbox) {
    if (checkbox.dataset.jamf !== '1') {
        return 'jamf';
    }
    if (checkbox.dataset.asm !== '1') {
        return 'asm';
    }
    return 'mail';
}

function getSelectedBulkStep(selectedRows) {
    if (selectedRows.length === 0) {
        return '';
    }

    const steps = Array.from(new Set(selectedRows.map(getBulkStep)));
    return steps.length === 1 ? steps[0] : 'mixed';
}

function getFallbackBulkStep() {
    const container = document.getElementById('bulkLastIdsInput');
    return container ? container.dataset.lastBulkStep || '' : '';
}

function setBulkButtonActive(button, active) {
    if (!button) {
        return;
    }
    button.disabled = !active;
    button.classList.toggle('button-primary', active);
    button.classList.toggle('button-secondary', !active);
}

function setBulkButtonState(step, fallbackStep) {
    const jamfButton = document.getElementById('bulkJamfButton');
    const copyButton = document.getElementById('bulkCopyButton');
    const asmButton = document.getElementById('bulkAsmButton');
    const mailButton = document.getElementById('bulkMailButton');
    const hasAsmList = step === 'asm' || fallbackStep === 'asm';

    setBulkButtonActive(jamfButton, step === 'jamf');
    setBulkButtonActive(copyButton, hasAsmList);
    setBulkButtonActive(asmButton, hasAsmList);
    setBulkButtonActive(mailButton, step === 'mail' || fallbackStep === 'mail');
}

function updateBulkSelectionStatus() {
    const selectedRows = getSelectedBulkRows();
    const status = document.getElementById('bulkSelectionStatus');
    const fallbackIds = Array.from(document.querySelectorAll('[data-last-bulk-id]'));
    const fallbackStep = getFallbackBulkStep();
    const step = getSelectedBulkStep(selectedRows);
    if (status) {
        if (selectedRows.length > 0) {
            if (step === 'mixed') {
                status.textContent = `${selectedRows.length} Anträge ausgewählt: bitte nur denselben nächsten Schritt auswählen`;
            } else {
                const stepLabels = {
                    jamf: 'Jamf',
                    asm: 'ASM',
                    mail: 'Mail'
                };
                status.textContent = selectedRows.length === 1
                    ? `1 Antrag ausgewählt · nächster Schritt: ${stepLabels[step]}`
                    : `${selectedRows.length} Anträge ausgewählt · nächster Schritt: ${stepLabels[step]}`;
            }
        } else if (fallbackIds.length > 0) {
            const fallbackLabel = fallbackStep === 'mail' ? 'Mail' : 'ASM';
            status.textContent = fallbackIds.length === 1
                ? `Letzte Bulk-Auswahl: 1 Antrag für ${fallbackLabel} bereit`
                : `Letzte Bulk-Auswahl: ${fallbackIds.length} Anträge für ${fallbackLabel} bereit`;
        } else {
            status.textContent = '0 Anträge ausgewählt';
        }
    }
    setBulkButtonState(step, selectedRows.length === 0 && fallbackIds.length > 0 ? fallbackStep : '');

    if (selectAllRequests) {
        const selectableRows = Array.from(document.querySelectorAll('.bulk-select'));
        selectAllRequests.checked = selectableRows.length > 0 && selectedRows.length === selectableRows.length;
        selectAllRequests.indeterminate = selectedRows.length > 0 && selectedRows.length < selectableRows.length;
    }

    updateBulkAsmListFromSelection();
}

function submitBulkAction(action) {
    let selectedRows = getSelectedBulkRows();
    const step = getSelectedBulkStep(selectedRows);
    const fallbackIds = Array.from(document.querySelectorAll('[data-last-bulk-id]')).map((input) => input.value);
    const fallbackStep = getFallbackBulkStep();
    const actionCount = selectedRows.length > 0 ? selectedRows.length : fallbackIds.length;

    if (selectedRows.length > 0 && step === 'mixed') {
        alert('Bitte wählen Sie nur Anträge mit demselben nächsten Schritt aus.');
        return;
    }

    const expectedActions = {
        jamf: 'bulk_jamf_unenroll',
        asm: 'bulk_asm_done',
        mail: 'bulk_mail_send'
    };
    if (selectedRows.length > 0 && expectedActions[step] !== action) {
        alert('Diese Aktion passt nicht zum nächsten Schritt der Auswahl.');
        return;
    }

    const fallbackActions = {
        asm: 'bulk_asm_done',
        mail: 'bulk_mail_send'
    };
    if (selectedRows.length === 0 && !(fallbackIds.length > 0 && fallbackActions[fallbackStep] === action)) {
        alert('Für diese Bulk-Aktion ist in der aktuellen Auswahl kein passender Antrag vorhanden.');
        return;
    }

    if (action === 'bulk_asm_done') {
        openAsmPortal();
    }
    if (action === 'bulk_mail_send' && !confirm(`${actionCount} vorbereitete Mail(s) jetzt senden?`)) {
        return;
    }

    const idsContainer = document.getElementById('bulkIdsInput');
    idsContainer.innerHTML = '';
    const ids = selectedRows.length > 0
        ? selectedRows.map((checkbox) => checkbox.value)
        : fallbackIds;
    ids.forEach((id) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'bulk_ids[]';
        input.value = id;
        idsContainer.appendChild(input);
    });

    document.getElementById('bulkActionInput').value = action;
    document.getElementById('bulkActionForm').submit();
}

function updateBulkAsmListFromSelection() {
    const selectedRows = getSelectedBulkRows();
    const textarea = document.getElementById('bulkAsmListText');
    const panel = document.getElementById('bulkAsmList');
    if (!textarea || !panel) {
        return;
    }
    const step = getSelectedBulkStep(selectedRows);

    if (selectedRows.length === 0) {
        if (textarea.dataset.serverList !== '1') {
            textarea.value = '';
            panel.classList.add('hidden');
        }
        return;
    }

    if (step !== 'asm') {
        textarea.dataset.serverList = '0';
        textarea.value = '';
        panel.classList.add('hidden');
        return;
    }

    const serials = selectedRows
        .map((checkbox) => checkbox.dataset.serial || '')
        .filter(Boolean);
    textarea.dataset.serverList = '0';
    textarea.value = Array.from(new Set(serials)).join(', ');
    panel.classList.remove('hidden');
}

function hideBulkAsmList() {
    document.getElementById('bulkAsmList').classList.add('hidden');
}

function copyBulkAsmList() {
    const textarea = document.getElementById('bulkAsmListText');
    if (!textarea.value.trim()) {
        alert('Es gibt aktuell keine Seriennummernliste zum Kopieren.');
        return;
    }
    textarea.focus();
    textarea.select();
    navigator.clipboard.writeText(textarea.value).catch(() => {
        document.execCommand('copy');
    });
}

function toggleTemplateEditor() {
    const editor = document.getElementById('templateEditor');
    editor.classList.toggle('hidden');
}

function showDeviceCase(button) {
    const data = button.dataset || {};
    const card = document.getElementById('deviceCaseCard');
    if (!card) {
        return;
    }

    const caseId = data.caseId || '';
    const serial = data.serial || '';
    const updatedAt = data.updatedAt || '';

    document.getElementById('caseIdInput').value = caseId;
    document.getElementById('caseRequestIdInput').value = data.requestId || '0';
    document.getElementById('caseSourceInput').value = data.source || 'admin';
    document.getElementById('caseSerialInput').value = serial;
    document.getElementById('caseStatusInput').value = data.status || 'offen';
    document.getElementById('caseTitleInput').value = data.title || ('Klärfall ' + serial);
    document.getElementById('caseNoteInput').value = data.note || '';
    document.getElementById('caseResolutionInput').value = data.resolutionNote || '';
    document.getElementById('caseMeta').textContent = caseId
        ? 'Bestehender Klärfall zu ' + serial + (updatedAt ? ' · aktualisiert ' + updatedAt : '')
        : 'Neuer Klärfall zu ' + serial;

    card.classList.remove('hidden');
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function hideDeviceCase() {
    const card = document.getElementById('deviceCaseCard');
    if (card) {
        card.classList.add('hidden');
    }
}

document.querySelectorAll('.case-row-clickable').forEach((row) => {
    row.addEventListener('click', (event) => {
        if (event.target.closest('a, button, input, select, textarea, label')) {
            return;
        }
        showDeviceCase(row);
    });
});

function showMailPreview(button) {
    const data = button.dataset;
    let body = mailTemplate;
    const placeholders = {
        name: 'name',
        username: 'username',
        email: 'email',
        private_email: 'privateEmail',
        device_name: 'device',
        serial: 'serial'
    };

    Object.entries(placeholders).forEach(([placeholder, source]) => {
        const value = data[source] || '';
        body = body.replace(new RegExp(`{{${placeholder}}}`, 'g'), value);
    });

    const privateEmail = data.privateEmail || '';
    const schoolEmail = data.email || '';
    const privateRecipientRow = document.getElementById('privateRecipientRow');
    const sendPrivateEmail = document.getElementById('sendPrivateEmail');
    const sendSchoolEmail = document.getElementById('sendSchoolEmail');
    const privateEmailInput = document.getElementById('previewPrivateEmailInput');
    const schoolEmailInput = document.getElementById('previewSchoolEmailInput');

    privateRecipientRow.classList.toggle('hidden', privateEmail === '');
    privateEmailInput.value = privateEmail;
    schoolEmailInput.value = schoolEmail;
    sendPrivateEmail.checked = privateEmail !== '';
    sendPrivateEmail.disabled = privateEmail === '';
    sendSchoolEmail.checked = privateEmail === '';
    document.getElementById('previewSubjectInput').value = mailSubject;
    document.getElementById('previewBodyInput').value = body;
    updateMailRecipients();
    document.getElementById('sendSubjectInput').value = mailSubject;
    document.getElementById('sendDeviceInput').value = data.device || '';
    document.getElementById('sendSerialInput').value = data.serial || '';
    document.getElementById('sendRequestIdInput').value = data.id || '0';
    document.getElementById('sendBodyInput').value = body;
    const preview = document.getElementById('mailPreview');
    preview.classList.remove('hidden');
    preview.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function sendMail() {
    updateMailRecipients();
    document.getElementById('sendSubjectInput').value = document.getElementById('previewSubjectInput').value.trim();
    document.getElementById('sendBodyInput').value = document.getElementById('previewBodyInput').value;
    document.getElementById('sendMailForm').submit();
}

function openAsmBeforeSubmit() {
    openAsmPortal();
}

function copyAsmLinkToClipboard() {
    const asmUrl = 'https://school.apple.com';
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(asmUrl).catch(() => {});
        return;
    }

    const tempInput = document.createElement('textarea');
    tempInput.value = asmUrl;
    tempInput.setAttribute('readonly', '');
    tempInput.style.position = 'fixed';
    tempInput.style.left = '-9999px';
    document.body.appendChild(tempInput);
    tempInput.select();
    try {
        document.execCommand('copy');
    } catch (error) {
        // Best effort only.
    }
    document.body.removeChild(tempInput);
}

function openAsmPortal() {
    const asmUrl = 'https://school.apple.com';
    const isFirefox = /Firefox\//.test(navigator.userAgent);

    if (isFirefox) {
        copyAsmLinkToClipboard();
        window.location.href = 'googlechrome://navigate?url=' + encodeURIComponent(asmUrl);
        alert('Apple School Manager wird in Chrome geöffnet. Falls Chrome nicht reagiert, ist der Link in der Zwischenablage und kann in Safari oder Chrome eingefügt werden.');
        return;
    }

    window.open(asmUrl, '_blank', 'noopener');
}

function updateMailRecipients() {
    const recipients = [];
    const sendPrivateEmail = document.getElementById('sendPrivateEmail');
    const sendSchoolEmail = document.getElementById('sendSchoolEmail');
    const privateEmail = document.getElementById('previewPrivateEmailInput').value.trim();
    const schoolEmail = document.getElementById('previewSchoolEmailInput').value.trim();

    if (sendPrivateEmail.checked && privateEmail) {
        recipients.push(privateEmail);
    }
    if (sendSchoolEmail.checked && schoolEmail) {
        recipients.push(schoolEmail);
    }

    document.getElementById('sendToInput').value = recipients.join(', ');
}

function hideMailPreview() {
    document.getElementById('mailPreview').classList.add('hidden');
}

updateBulkSelectionStatus();
</script>
</body>
</html>
