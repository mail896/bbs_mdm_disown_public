<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// Reuse the actual row renderer and its existing isolated, database-free stubs.
$root = $argv[1] ?? dirname(__DIR__, 2);
$cssPath = $argv[2] ?? $root . '/assets/admin.css';
ob_start();
require $root . '/tests/test_admin_case_ui.php';
ob_end_clean();
$accepted = ['jamf_unenroll_state' => DISOWN_JAMF_STATE_PENDING_DEVICE,
    'jamf_command_accepted_at' => '2026-10-20 12:00:00', 'jamf_observed_location' => 'active'];
$verified = ['jamf_unenroll_state' => DISOWN_JAMF_STATE_VERIFIED_UNMANAGED, 'jamf_unenrolled' => 1];
$cases = [
    'release' => [[], true, 'Freigeben'],
    'check' => [['jamf_unenroll_state' => DISOWN_JAMF_STATE_PENDING_DEVICE], true, 'Status prüfen'],
    'continue' => [$accepted, true, 'Freigabe fortsetzen'],
    'apple' => [$verified, true, 'ASM prüfen'],
    'mail' => [$verified + ['asm_manual_done' => 1], true, 'Mail senden'],
    'pending-mail' => [$accepted + ['asm_manual_done' => 1], true, 'Mail senden'],
    'retry-mail' => [$verified + ['asm_manual_done' => 1, 'mail_sent' => 2], true, 'Mail senden'],
    'read-only' => [[], false, ''],
];
echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>',
    file_get_contents($cssPath), '</style></head><body><div class="page"><div class="card table-wrap table-card"><table id="requestsTable"><thead><tr>',
    '<th></th><th>ID</th><th>Datum</th><th>Person</th><th>Gerät</th><th>Status</th><th>Aktion</th></tr></thead><tbody>';
foreach ($cases as $name => [$changes, $canWrite, $label]) {
    $view = render_case_row($template, $changes, $canWrite);
    $row = $view->query('//tr')->item(0);
    $row->setAttribute('data-layout-case', $name);
    $row->setAttribute('data-expected-action', $label);
    echo $row->ownerDocument->saveHTML($row);
}
echo '</tbody></table></div></div></body></html>';
