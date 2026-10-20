<?php

declare(strict_types=1);

require_once __DIR__ . '/workflow_state.php';

/** Exact default wording, also used if the versioned template is unreadable. */
function disown_pending_release_mail_default_template(): string
{
    return <<<'DISOWN_PENDING_MAIL'
Hallo {{name}},

Ihr iPad wurde von der Schule freigegeben.

Die Abmeldung aus der schulischen Geräteverwaltung (Jamf School) wurde veranlasst.
Die Freigabe aus der schulischen Registrierung bei Apple (Apple School Manager / ASM/ADE) ist bereits abgeschlossen.

Gerät: {{device_name}}
Seriennummer: {{serial}}

Falls noch nicht geschehen, löschen Sie das iPad vollständig und richten Sie es anschließend als neues iPad ein. Ist es bereits zurückgesetzt, können Sie mit der Einrichtung fortfahren.

Verwenden Sie dabei kein altes Komplett-Backup aus der Zeit der schulischen Verwaltung.
Verzichten Sie auch auf Schnellstart oder eine vollständige Geräteübertragung von einem zuvor schulisch verwalteten Gerät. Persönliche Daten können Sie anschließend gezielt über Ihre privaten Cloud-Dienste synchronisieren.

Team Mobile Devicemanagemt

--- English ---

Hello {{name}},

Your iPad has been released by the school.

Unenrollment from the school's mobile device management system (Jamf School) has been initiated.
Release from the school's registration with Apple (Apple School Manager / ASM/ADE) has already been completed.

Device: {{device_name}}
Serial number: {{serial}}

If you have not already done so, erase the iPad completely and then set it up as a new iPad. If it has already been reset, you can continue with setup.

Do not restore an old full backup from when the iPad was managed by the school.
Also avoid Quick Start or a full device-to-device transfer from a previously school-managed device. You can then selectively synchronize your personal data using your private cloud services.

Mobile Device Management Team
DISOWN_PENDING_MAIL
        . "\n";
}

function disown_pending_release_mail_template(?mysqli $mysqli = null): string
{
    if ($mysqli && function_exists('disown_setting_get')) {
        $stored = disown_setting_get($mysqli, disown_release_mail_template_key() . '_pending', '');
        if (disown_release_mail_template_is_valid($stored)) {
            return $stored;
        }
    }
    $path = __DIR__ . '/templates/mail_release_pending.txt';
    $template = is_readable($path) ? file_get_contents($path) : false;
    return is_string($template) && trim($template) !== '' ? $template
        : disown_pending_release_mail_default_template();
}

/** Keep the admin's configured template for verified devices unchanged. */
function disown_release_mail_template_for_row(string $configuredTemplate, array $row, ?string $pendingTemplate = null): string
{
    return disown_jamf_state_from_row($row) === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED
        ? $configuredTemplate : ($pendingTemplate ?? disown_pending_release_mail_template());
}
