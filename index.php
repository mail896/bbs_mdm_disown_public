<?php
require_once __DIR__ . '/security.php';
require 'db.php';
require 'jamf.php';

disown_send_security_headers();

$serial = trim($_GET['serial'] ?? $_POST['serial'] ?? '');
$serialToken = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$appConfig = disown_load_config('app');
$serialTokenSecret = trim((string) ($appConfig['SERIAL_TOKEN_SECRET'] ?? ''));
$requireSerialToken = filter_var($appConfig['REQUIRE_SERIAL_TOKEN'] ?? false, FILTER_VALIDATE_BOOL);
$serialTokenValid = $serialTokenSecret !== '' && disown_serial_token_valid($serial, $serialToken, $serialTokenSecret);
$serialTokenRequiredButMissing = $requireSerialToken && !$serialTokenValid;
$jamf = ($serial && !$serialTokenRequiredButMissing) ? jamf_lookup_by_serial($serial) : null;
$jamfHasOwner = $jamf && (
    trim((string) ($jamf['username'] ?? '')) !== ''
    || trim((string) ($jamf['email'] ?? '')) !== ''
    || trim((string) ($jamf['full_name'] ?? '')) !== ''
);
$localTimezone = new DateTimeZone('Europe/Berlin');
$todayDate = new DateTimeImmutable('today', $localTimezone);
$today = $todayDate->format('Y-m-d');
$className = trim((string) ($_POST['class_name'] ?? ''));
$privateEmail = trim((string) ($_POST['private_email'] ?? ''));
$requestedReleaseDate = trim((string) ($_POST['requested_release_date'] ?? $today));
$requestedReleaseDate = $requestedReleaseDate !== '' ? $requestedReleaseDate : $today;
$requestedReleaseDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedReleaseDate, $localTimezone);
$requestedReleaseDateValid = $requestedReleaseDateObject instanceof DateTimeImmutable
    && $requestedReleaseDateObject->format('Y-m-d') === $requestedReleaseDate;
$understandingConfirmed = isset($_POST['understanding_confirmed']);

$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($serialTokenRequiredButMissing) {
        $message = 'Dieser WebClip ist nicht mehr gültig. Bitte öffnen Sie den aktuellen iPad-Freigabe-WebClip.';
        $messageType = 'error';
    } elseif (!$jamf) {
        $message = 'Gerät wurde in Jamf nicht gefunden.';
        $messageType = 'error';
    } elseif (!$jamfHasOwner) {
        $message = 'Dieses iPad ist in Jamf keinem Benutzer zugeordnet. Bitte wenden Sie sich an das MDM-Team.';
        $messageType = 'error';
    } elseif (!$understandingConfirmed) {
        $message = 'Bitte bestätigen Sie zuerst, dass Sie die Folgen der iPad-Freigabe verstanden haben.';
        $messageType = 'error';
    } elseif ($className === '' || strlen($className) > 6) {
        $message = 'Bitte geben Sie Ihre Klasse mit maximal 6 Zeichen ein.';
        $messageType = 'error';
    } elseif ($privateEmail !== '' && !filter_var($privateEmail, FILTER_VALIDATE_EMAIL)) {
        $message = 'Bitte geben Sie eine gültige private E-Mail-Adresse ein oder lassen Sie das Feld leer.';
        $messageType = 'error';
    } elseif (!$requestedReleaseDateValid || $requestedReleaseDateObject < $todayDate) {
        $message = 'Bitte wählen Sie ein Freigabedatum aus, das nicht in der Vergangenheit liegt.';
        $messageType = 'error';
    } else {
        $requestedReleaseDate = $requestedReleaseDateObject->format('Y-m-d');
        $existingStmt = $mysqli->prepare(
            "SELECT id, created_at, status
             FROM requests
             WHERE UPPER(serial) = UPPER(?)
             ORDER BY id DESC
             LIMIT 1"
        );
        $existingRequest = null;
        if ($existingStmt) {
            $existingStmt->bind_param('s', $jamf['serial']);
            $existingStmt->execute();
            $existingRequest = $existingStmt->get_result()->fetch_assoc();
            $existingStmt->close();
        }

        if ($existingRequest) {
            $message = 'Für dieses Gerät wurde bereits ein Antrag erfasst.';
            $messageType = 'warning';
        } else {
        $stmt = $mysqli->prepare(
            "INSERT IGNORE INTO requests
             (username, email, private_email, full_name, class_name, jamf_user_id, jamf_modified, serial, device_name, requested_release_date, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if ($stmt) {
            $stmt->bind_param(
                'sssssissssss',
                $jamf['username'],
                $jamf['email'],
                $privateEmail,
                $jamf['full_name'],
                $className,
                $jamf['jamf_user_id'],
                $jamf['jamf_modified'],
                $jamf['serial'],
                $jamf['device_name'],
                $requestedReleaseDate,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            );
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $message = 'Ihr Antrag wurde gespeichert. Unser Team prüft ihn jetzt.';
                $messageType = 'success';
            } else {
                $message = 'Für dieses Gerät wurde bereits ein Antrag erfasst.';
                $messageType = 'warning';
            }
            $stmt->close();
        } else {
            $message = 'Datenbankfehler beim Speichern des Antrags.';
            $messageType = 'error';
        }
        }
    }
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<title>iPad-Freigabe</title>
<style>
:root {
    font-family: Inter, Arial, sans-serif;
    color: #111827;
    background: #f4f6fb;
}
* {
    box-sizing: border-box;
}
body {
    margin: 0;
    min-height: 100vh;
    background:
        linear-gradient(rgba(244, 246, 251, 0.70), rgba(244, 246, 251, 0.86)),
        url("images/Site-Image.png") center bottom / min(1700px, 130vw) auto no-repeat fixed;
}
.page {
    width: min(100% - 28px, 1240px);
    margin: 0 auto;
    padding: 14px 0;
}
.card {
    background: rgba(255, 255, 255, 0.94);
    border: 1px solid #e5e7eb;
    border-radius: 20px;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
    display: flex;
    flex-direction: column;
    min-height: calc(100vh - 28px);
    overflow: hidden;
    padding: 24px 30px;
    position: relative;
}
.card::before {
    background: url("images/Site-Image.png") center bottom -20px / min(1280px, 112vw) auto no-repeat;
    content: "";
    inset: 0;
    opacity: 0.30;
    pointer-events: none;
    position: absolute;
    -webkit-mask-image: linear-gradient(to bottom, transparent 0%, transparent 44%, rgba(0, 0, 0, 0.50) 56%, #000 100%);
    mask-image: linear-gradient(to bottom, transparent 0%, transparent 44%, rgba(0, 0, 0, 0.50) 56%, #000 100%);
}
.card > * {
    position: relative;
    z-index: 1;
}
.card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: clamp(14px, 2vh, 24px);
}
.site-logo {
    display: block;
    max-height: 58px;
    max-width: min(190px, 34vw);
    width: auto;
    height: auto;
    object-fit: contain;
}
.page-title {
    font-size: 1.8rem;
    margin: 0;
}
.description {
    margin: 6px 0 0;
    color: #475569;
    line-height: 1.45;
}
.warning-box {
    background: #fff1f2;
    border: 2px solid #ef4444;
    border-radius: 14px;
    color: #7f1d1d;
    font-size: 0.9rem;
    line-height: 1.35;
    margin: 0 0 clamp(14px, 2.2vh, 26px);
    padding: 10px 14px;
}
.warning-box strong {
    margin-right: 6px;
}
.field-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: clamp(10px, 1.8vh, 20px) 18px;
    margin-bottom: clamp(14px, 2.2vh, 28px);
}
.field {
    display: grid;
    gap: 5px;
}
.field-label {
    font-size: 0.88rem;
    color: #475569;
}
.field-value {
    font-size: 0.98rem;
    color: #111827;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    min-height: 44px;
    padding: 10px 13px;
}
.form-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: stretch;
    gap: clamp(12px, 1.9vh, 22px) 18px;
    margin-bottom: clamp(14px, 2vh, 24px);
}
.request-form {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-height: 250px;
}
.form-fields .field {
    align-content: start;
    min-height: clamp(106px, 11vh, 126px);
}
.field-wide {
    grid-column: 1 / -1;
}
.form-input {
    display: block;
    width: 100%;
    max-width: none;
    min-width: 0;
    height: 48px;
    padding: 10px 13px;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    background: #ffffff;
    color: #111827;
    font-size: 1rem;
}
.date-input {
    -webkit-appearance: none;
    appearance: none;
    inline-size: 100%;
    text-align: left;
}
.date-input::-webkit-date-and-time-value {
    text-align: left;
}
.field-help {
    color: #64748b;
    font-size: 0.82rem;
    line-height: 1.35;
    margin: 0;
    min-height: 2.2em;
}
.confirm-box {
    align-items: flex-start;
    display: flex;
    gap: 10px;
    font-size: 0.92rem;
    line-height: 1.35;
}
.confirm-box input {
    margin-top: 4px;
}
.button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    padding: 12px 18px;
    border-radius: 14px;
    border: none;
    font-size: 0.98rem;
    font-weight: 700;
    cursor: pointer;
    color: white;
    background: #2563eb;
    transition: background 0.2s ease;
    margin-top: clamp(2px, 1vh, 12px);
}
.request-form .button {
    margin-top: 0;
}
.form-actions {
    display: grid;
    gap: 22px;
    margin-top: auto;
}
.button:hover {
    background: #1d4ed8;
}
.button:disabled {
    background: #cbd5e1;
    color: #64748b;
    cursor: not-allowed;
}
.message {
    border-radius: 16px;
    padding: 18px 20px;
    margin-bottom: 20px;
    line-height: 1.55;
}
.message.info {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}
.message.success {
    background: #ddf7e8;
    color: #047857;
    border: 1px solid #86efac;
}
.message.warning {
    background: #fef9c3;
    color: #92400e;
    border: 1px solid #fde68a;
}
.message.error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}
.link {
    color: #2563eb;
    text-decoration: none;
}
.small-note {
    font-size: 0.82rem;
    color: #6b7280;
    margin: clamp(14px, 2vh, 24px) 0 0;
}
@media (max-width: 560px) {
    body {
        background-attachment: scroll;
        background-position: center bottom;
        font-size: 15px;
    }
    .page {
        width: auto;
        padding: 10px;
    }
    .card {
        min-height: auto;
        padding: 18px;
    }
    .card-header {
        flex-direction: column-reverse;
        gap: 16px;
    }
    .site-logo {
        max-width: 180px;
    }
    .field-list,
    .form-fields {
        grid-template-columns: 1fr;
    }
    .request-form {
        flex: none;
        min-height: 0;
    }
    .request-form .button {
        margin-top: 12px;
    }
    .form-actions {
        gap: 14px;
        margin-top: 0;
    }
    .warning-box {
        font-size: 0.86rem;
        padding: 10px 12px;
    }
    .field-value,
    .form-input {
        min-height: 46px;
    }
}
@supports (height: 100dvh) {
    body {
        min-height: 100dvh;
    }
    .card {
        min-height: calc(100dvh - 28px);
    }
    @media (max-width: 560px) {
        .card {
            min-height: auto;
        }
    }
}
</style>
</head>
<body>
<div class="page">
    <div class="card">
	        <div class="card-header">
	            <div>
	                <h1 class="page-title">iPad-Freigabe</h1>
	                <p class="description">Sie verlassen die BBS Einbeck?<br>Mit diesem Formular beantragen Sie die Freigabe Ihres schulisch verwalteten iPads.</p>
	            </div>
	            <img src="logo.png" alt="BBS Einbeck" class="site-logo">
	        </div>

            <div class="warning-box">
                <strong>Achtung:</strong><br>
                Bei der Freigabe werden schulisch bereitgestellte Apps, Profile und Einstellungen vom iPad entfernt, zum Beispiel Goodnotes, IServ, Microsoft Office und weitere schulisch verteilte Apps und WLAN Netze.<br>
                Sichern Sie wichtige Daten vorher selbst. Ein normales iCloud-iPad-Backup ist dafür nicht geeignet.
            </div>

        <?php if ($message): ?>
            <div class="message <?=htmlspecialchars($messageType)?>">
                <?=htmlspecialchars($message)?>
            </div>
        <?php endif; ?>

        <?php if (!$serial): ?>
            <div class="message info">
                Bitte öffnen Sie den Webclip auf Ihrem iPad. Die Seriennummer wird automatisch übergeben.
            </div>
        <?php elseif ($serialTokenRequiredButMissing): ?>
            <div class="message error">
                Dieser WebClip ist nicht mehr gültig. Bitte öffnen Sie den aktuellen iPad-Freigabe-WebClip.
            </div>
        <?php elseif (!$jamf): ?>
            <div class="message error">
                Dieses Gerät wurde in Jamf nicht gefunden. Bitte prüfen Sie das iPad oder kontaktieren Sie den Support.
            </div>
        <?php elseif (!$jamfHasOwner): ?>
            <div class="message error">
                Dieses iPad ist in Jamf keinem Benutzer zugeordnet. Bitte wenden Sie sich an das MDM-Team.
            </div>
        <?php endif; ?>

        <?php if ($serial && $jamf && $jamfHasOwner): ?>
            <div class="field-list">
                <div class="field">
                    <span class="field-label">IServ-Benutzer</span>
                    <span class="field-value"><?=htmlspecialchars($jamf['username'])?></span>
                </div>
                <div class="field">
                    <span class="field-label">E-Mail</span>
                    <span class="field-value"><?=htmlspecialchars($jamf['email'])?></span>
                </div>
                <div class="field">
                    <span class="field-label">Gerät</span>
                    <span class="field-value"><?=htmlspecialchars($jamf['device_name'])?></span>
                </div>
                <div class="field">
                    <span class="field-label">Seriennummer</span>
                    <span class="field-value"><?=htmlspecialchars($jamf['serial'])?></span>
                </div>
            </div>

	            <form method="post" class="request-form">
	                <input type="hidden" name="serial" value="<?=htmlspecialchars($jamf['serial'])?>">
	                <input type="hidden" name="token" value="<?=htmlspecialchars($serialToken)?>">
	                <?php if ($messageType === 'success'): ?>
	                    <button type="button" class="button" onclick="window.close()">Fenster schließen</button>
	                <?php else: ?>
                        <div class="form-fields">
                            <div class="field">
                                <label class="field-label" for="className">Klasse</label>
                                <input class="form-input" id="className" name="class_name" type="text" value="<?=htmlspecialchars($className)?>" maxlength="6" required autocomplete="off" placeholder="z. B. IO">
                                <p class="field-help">Maximal 6 Zeichen, zum Beispiel IO oder BEST2.</p>
                            </div>
                            <div class="field">
                                <label class="field-label" for="requestedReleaseDate">Gewünschtes Freigabedatum</label>
                                <input class="form-input date-input" id="requestedReleaseDate" name="requested_release_date" type="date" min="<?=htmlspecialchars($today)?>" value="<?=htmlspecialchars($requestedReleaseDate ?: $today)?>" required>
                                <p class="field-help">Der Tag, ab dem die Freigabe durchgeführt werden soll.</p>
                            </div>
                            <div class="field field-wide">
                                <label class="field-label" for="privateEmail">Private E-Mail, optional</label>
                                <input class="form-input" id="privateEmail" name="private_email" type="email" value="<?=htmlspecialchars($privateEmail)?>" autocomplete="email">
                                <p class="field-help">Nur ausfüllen, wenn die schulische E-Mail nicht mehr erreichbar ist.</p>
                            </div>
                        </div>
                        <div class="form-actions">
                            <label class="confirm-box">
                                <input id="understandingConfirmed" name="understanding_confirmed" type="checkbox" value="1" <?= $understandingConfirmed ? 'checked' : '' ?> required>
                                <span>Ich habe verstanden, dass nach der Freigabe schulische Apps, Profile und Einstellungen entfernt werden und dieser Schritt nicht einfach rückgängig gemacht werden kann.</span>
                            </label>
	                        <button id="submitRequestButton" type="submit" class="button" disabled>Freigabe beantragen</button>
                        </div>
	                <?php endif; ?>
	            </form>

            <?php if ($messageType === 'success'): ?>
                <p class="small-note">Falls sich das Fenster nicht schließt, können Sie diese Seite schließen.</p>
            <?php else: ?>
                <p class="small-note">Hinweis: Die Seriennummer wird automatisch übernommen. Sie müssen nichts weiter eingeben.</p>
            <?php endif; ?>
        <?php endif; ?>
	    </div>
	</div>
    <script>
    const understandingCheckbox = document.getElementById('understandingConfirmed');
    const submitRequestButton = document.getElementById('submitRequestButton');

    if (understandingCheckbox && submitRequestButton) {
        const updateSubmitState = () => {
            submitRequestButton.disabled = !understandingCheckbox.checked;
        };
        understandingCheckbox.addEventListener('change', updateSubmitState);
        updateSubmitState();
    }
    </script>
	</body>
	</html>
