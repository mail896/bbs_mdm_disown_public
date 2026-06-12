<?php
session_start();

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
$bulkAsmSerials = [];
$bulkLastIds = [];
$bulkLastStep = '';
$currentAdminUser = disown_current_admin_user();
$isDevMode = basename(__DIR__) === 'disown-dev';
$appVersion = $isDevMode ? '1.3-dev' : '1.3';
$appVersionDate = '11. Juni 2026';
$validFilters = ['open', 'scheduled', 'done', 'all'];
$filter = (string) ($_GET['filter'] ?? 'open');
if (!in_array($filter, $validFilters, true)) {
    $filter = 'open';
}
$filterLabels = [
    'open' => 'Offen',
    'scheduled' => 'Terminiert',
    'done' => 'Erledigt',
    'all' => 'Alle',
];
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$perPage = 25;
$page = max(1, (int) ($_GET['page'] ?? 1));
$refreshUrl = 'admin.php?' . http_build_query([
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

    return 'admin.php' . ($query ? '?' . http_build_query($query) : '');
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

    if (isset($_POST['template_save'])) {
        $templateContent = $_POST['template_content'] ?? '';
        if (file_put_contents($mailTemplatePath, $templateContent) === false) {
            $templateError = 'Die Vorlage konnte nicht gespeichert werden. Bitte Dateiberechtigungen prüfen.';
        } else {
            log_request_action($mysqli, 0, 'TEMPLATE_UPDATED', 'Mailvorlage aktualisiert.');
            $templateMessage = 'Vorlage erfolgreich gespeichert.';
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
            header('Location: admin.php');
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
                    header('Location: admin.php');
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
         COALESCE(SUM(CASE WHEN {$openCondition} THEN 1 ELSE 0 END), 0) AS open_requests,
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
         COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? AND ({$openCondition}) THEN 1 ELSE 0 END), 0) AS school_year_open
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
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<title>iPad-Freigaben</title>
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
    background: #f3f5f9;
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
    align-items: center;
    margin-bottom: 20px;
}
.header-actions {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 10px;
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
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 20px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
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
    font-weight: 600;
    color: #334155;
}
.search-input {
    flex: 1 1 320px;
    min-width: 220px;
    padding: 12px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    background: #ffffff;
    color: #0f172a;
}
.dashboard {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    display: flex;
    flex-wrap: wrap;
    gap: 0;
    margin-top: 14px;
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
    padding: 0.65rem 1rem;
    border-radius: 999px;
    text-decoration: none;
    font-weight: 600;
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
    font-size: 0.9rem;
    font-weight: 500;
    padding: 0.45rem 0.75rem;
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
.status-secondary {
    background: #e2e8f0;
    color: #334155;
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
}
</style>
</head>
<body>
<div class="page">
    <div class="header">
        <div>
            <h1 class="page-title">iPad-Freigaben</h1>
            <p>Offene und erledigte Anträge anzeigen. Schließe Anträge direkt hier ab.</p>
            <p class="hint-text">Der automatische Jamf-Unenroll entfernt die MDM-Verwaltung. Die ADE/ASM-Freigabe erfolgt anschließend manuell.</p>
        </div>
        <div class="header-actions">
            <div class="logo-actions">
                <img src="logo.png" alt="BBS Einbeck" class="site-logo">
                <a class="refresh-link" href="<?=htmlspecialchars($refreshUrl)?>" title="Seite aktualisieren" aria-label="Seite aktualisieren">↻</a>
            </div>
            <a class="admin-user" href="logout.php">👤 <?=htmlspecialchars($currentAdminUser)?></a>
            <div class="tool-links" aria-label="Admin-Werkzeuge">
                <a class="tool-link" href="https://bbseinbeck.jamfcloud.com/" target="_blank" rel="noopener noreferrer" title="Jamf in neuem Fenster öffnen">
                    <img class="tool-logo" src="logo_jamf.png" alt="Jamf">
                </a>
                <a class="tool-link" href="https://school.apple.com" target="_blank" rel="noopener noreferrer" title="Apple School Manager in neuem Fenster öffnen">
                    <img class="tool-logo" src="logo_asm.png" alt="ASM">
                </a>
                <a class="button button-secondary audit-log-link" href="audit_log.php">Audit-Log</a>
            </div>
        </div>
    </div>

    <form class="search-toolbar" method="get" action="admin.php">
        <input type="hidden" name="filter" value="<?=htmlspecialchars($filter)?>">
        <input type="hidden" name="page" value="1">
        <label for="searchInput" class="search-label">Suche</label>
        <input id="searchInput" name="q" type="search" class="search-input" placeholder="Name, Klasse, IServ-Benutzer, E-Mail oder Seriennummer" value="<?=htmlspecialchars($searchTerm)?>">
        <button type="submit" class="button button-secondary">Suchen</button>
        <button type="button" class="button button-secondary" onclick="toggleTemplateEditor()">Vorlage bearbeiten</button>
    </form>

    <div class="filter-bar">
        <a class="button filter-link <?= $filter === 'open' ? 'active' : '' ?>" href="<?=htmlspecialchars(admin_url(['filter' => 'open', 'page' => 1, 'export' => null]))?>">Offen</a>
        <a class="button filter-link <?= $filter === 'scheduled' ? 'active' : '' ?>" href="<?=htmlspecialchars(admin_url(['filter' => 'scheduled', 'page' => 1, 'export' => null]))?>">Terminiert</a>
        <a class="button filter-link <?= $filter === 'done' ? 'active' : '' ?>" href="<?=htmlspecialchars(admin_url(['filter' => 'done', 'page' => 1, 'export' => null]))?>">Erledigt</a>
        <a class="button filter-link <?= $filter === 'all' ? 'active' : '' ?>" href="<?=htmlspecialchars(admin_url(['filter' => 'all', 'page' => 1, 'export' => null]))?>">Alle</a>
    </div>

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

    <nav class="pagination" aria-label="Seitennavigation oben">
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

    <div class="card table-wrap">
        <table id="requestsTable">
            <thead>
                <tr>
                    <th class="select-cell"><input type="checkbox" id="selectAllRequests" aria-label="Alle sichtbaren Anträge auswählen"></th>
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
                <tr class="request-row"
                    data-id="<?=htmlspecialchars($row['id'])?>"
                    data-full-name="<?=htmlspecialchars($row['full_name'])?>"
                    data-username="<?=htmlspecialchars($row['username'])?>"
                    data-email="<?=htmlspecialchars($row['email'])?>"
                    data-private-email="<?=htmlspecialchars($row['private_email'] ?? '')?>"
                    data-serial="<?=htmlspecialchars($row['serial'])?>">
                    <?php
                        $bulkMailSent = !empty($row['mail_sent']);
                        $bulkJamfDone = !empty($row['jamf_unenrolled']);
                        $bulkAsmDone = !empty($row['asm_manual_done']);
                        $bulkIsHistoryImport = (($row['completed_by'] ?? '') === 'history-import');
                        $bulkSelectable = !$bulkIsHistoryImport && !$bulkMailSent;
                    ?>
                    <td class="select-cell">
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
                    <td class="nowrap-cell"><?=htmlspecialchars($row['id'])?></td>
                    <td class="date-cell">
                        <span><?=date('d.m.Y', strtotime($row['created_at']))?></span>
                        <span><?=date('H:i', strtotime($row['created_at']))?></span>
                            <?php if (!empty($row['requested_release_date'])): ?>
                                <span class="status-secondary">Wunsch: <?=htmlspecialchars(date('d.m.Y', strtotime($row['requested_release_date'])))?></span>
                            <?php endif; ?>
                    </td>
                    <td class="person-cell">
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
                    <td class="device-cell">
                        <div><?=htmlspecialchars($row['device_name'])?></div>
                        <div class="serial-cell"><?=htmlspecialchars($row['serial'])?></div>
                    </td>
                    <td class="status-cell">
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
                    <td class="action-cell">
                        <div class="action-buttons">
                            <?php if (!$isHistoryImport && !$jamfUnenrolled): ?>
                                <form method="post" class="action-form">
                                    <input type="hidden" name="unenroll" value="<?=htmlspecialchars($row['id'])?>">
                                    <input type="hidden" name="unenroll_serial" value="<?=htmlspecialchars($row['serial'])?>">
                                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
                                    <button type="submit" class="button button-primary">Jamf Unenroll</button>
                                </form>
                            <?php endif; ?>

                            <?php if (!$isHistoryImport && $jamfUnenrolled && !$asmManualDone): ?>
                                <form method="post" class="action-form" onsubmit="openAsmBeforeSubmit()">
                                    <input type="hidden" name="asm_manual_done" value="<?=htmlspecialchars($row['id'])?>">
                                    <input type="hidden" name="asm_serial" value="<?=htmlspecialchars($row['serial'])?>">
                                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
                                    <button type="submit" class="button button-secondary">Manuell abschließen</button>
                                </form>
                            <?php endif; ?>

                            <?php if (!$isHistoryImport && $asmManualDone && !$mailSent): ?>
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

        <div class="dashboard" aria-label="Statistik">
            <span class="dashboard-stat warn <?= (int) $dashboard['open_requests'] === 0 ? 'zero' : '' ?>">Offen <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['open_requests'])?></span></span>
            <span class="dashboard-stat info <?= (int) $dashboard['scheduled_requests'] === 0 ? 'zero' : '' ?>">Terminiert <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['scheduled_requests'])?></span></span>
            <span class="dashboard-stat warn <?= (int) $dashboard['waiting_jamf'] === 0 ? 'zero' : '' ?>">Jamf <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['waiting_jamf'])?></span></span>
            <span class="dashboard-stat warn <?= (int) $dashboard['waiting_asm'] === 0 ? 'zero' : '' ?>">ASM <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['waiting_asm'])?></span></span>
            <span class="dashboard-stat warn <?= (int) $dashboard['waiting_mail'] === 0 ? 'zero' : '' ?>">Mail <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['waiting_mail'])?></span></span>
            <span class="dashboard-stat done <?= (int) $dashboard['done_requests'] === 0 ? 'zero' : '' ?>">Erledigt <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['done_requests'])?></span></span>
            <span class="dashboard-stat info <?= (int) $dashboard['school_year_total'] === 0 ? 'zero' : '' ?>">Schuljahr <?=htmlspecialchars($schoolYearLabel)?> <span class="dashboard-stat-value"><?=htmlspecialchars((string) $dashboard['school_year_total'])?></span></span>
            <span class="dashboard-stat info <?= $avgAdminProcessingText === '–' ? 'zero' : '' ?>"><span class="dashboard-stat-small">Ø Admin-Zeit</span> <span class="dashboard-stat-value"><?=htmlspecialchars($avgAdminProcessingText)?></span></span>
            <span class="dashboard-stat info <?= $avgStudentResponseText === '–' ? 'zero' : '' ?>"><span class="dashboard-stat-small">Ø Schüler-Response</span> <span class="dashboard-stat-value"><?=htmlspecialchars($avgStudentResponseText)?></span></span>
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
    </div>
    <footer class="page-footer">
        <span>&copy; 2026 <a href="mailto:marc.schulz@bbs-einbeck.de">Marc Schulz</a> · Version <?=htmlspecialchars($appVersion)?> · Stand: <?=htmlspecialchars($appVersionDate)?></span>
        <a class="footer-export-link" href="admin.php?filter=<?=htmlspecialchars(rawurlencode($filter))?>&amp;export=requests_csv" title="Anträge exportieren" aria-label="Anträge exportieren">⬇</a>
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
        window.open('https://school.apple.com', '_blank', 'noopener');
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
    window.open('https://school.apple.com', '_blank', 'noopener');
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
