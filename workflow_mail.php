<?php

declare(strict_types=1);

require_once __DIR__ . '/workflow_state.php';

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
        : "Hallo {{name}},\n\nDie Freigabe Ihres iPads {{serial}} aus Apple School Manager/ADE ist bestätigt. Die Entfernung des Jamf-Verwaltungsprofils ist noch nicht bestätigt. Bitte löschen Sie das iPad vollständig und richten Sie es als neues Gerät ein, ohne ein altes Komplett-Backup einzuspielen.\n\nYour iPad {{serial}} was released from Apple School Manager/ADE. Removal of the Jamf management profile is not yet confirmed. Please erase it and set it up as a new device without restoring an old full backup.\n";
}

/** Keep the admin's configured template for verified devices unchanged. */
function disown_release_mail_template_for_row(string $configuredTemplate, array $row, ?string $pendingTemplate = null): string
{
    return disown_jamf_state_from_row($row) === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED
        ? $configuredTemplate : ($pendingTemplate ?? disown_pending_release_mail_template());
}
