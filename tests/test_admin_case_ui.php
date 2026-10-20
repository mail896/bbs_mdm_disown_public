<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../workflow_state.php';
require __DIR__ . '/../jamf_cleanup.php';

// Render only the actual row template, without auth, database or external calls.
function normalize_device_case_status(string $status): string
{
    return trim($status) === 'geklaert' ? 'geklaert' : 'offen';
}

function display_device_case_status(string $status): string
{
    return $status === 'geklaert' ? 'geklärt' : 'offen';
}

$assertions = 0;
function ui_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function render_case_row(string $template, array $changes = [], bool $canWrite = true): DOMXPath
{
    $rows = [array_merge([
        'id' => 1, 'full_name' => 'Test Person', 'username' => 'test',
        'email' => 'test@example.invalid', 'private_email' => '', 'class_name' => '',
        'serial' => 'TEST123', 'device_name' => 'Test iPad', 'created_at' => '2026-10-20 12:00:00',
        'status' => 'offen', 'mail_sent' => 0, 'jamf_unenrolled' => 0,
        'jamf_unenroll_state' => DISOWN_JAMF_STATE_NOT_STARTED, 'asm_manual_done' => 0,
        'latest_case_id' => 0, 'latest_case_status' => 'offen', 'latest_case_title' => '',
        'open_case_count' => 0,
    ], $changes)];
    $bulkLastIds = [];
    $casesBySerial = [];
    $_SESSION['csrf_token'] = 'test-only';
    ob_start();
    try {
        eval('?>' . $template);
        $html = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><table><tbody>' . $html . '</table>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new DOMXPath($dom);
}

try {
    $source = (string) file_get_contents(__DIR__ . '/../admin.php');
    $start = strpos($source, '<?php foreach ($rows as $row): ?>');
    ui_assert($start !== false, 'Request template exists');
    $end = strpos($source, '        </table>', $start);
    ui_assert($end !== false, 'Request template has an end');
    $template = substr($source, $start, $end - $start);

    foreach ([[], ['latest_case_id' => 7, 'open_case_count' => 1, 'latest_case_note' => '<unsafe>"note'],
        ['latest_case_id' => 7, 'latest_case_status' => 'geklaert']] as $changes) {
        $view = render_case_row($template, $changes);
        $row = $view->query('//tr')->item(0);
        ui_assert($row instanceof DOMElement && $row->getAttribute('class') === 'request-row', 'No case styling or click target on the entire row');
        ui_assert(!$row->hasAttribute('title') && !$row->hasAttribute('data-case-id'), 'No row-wide case tooltip or editor data');
        $button = $view->query('//td[@data-label="Gerät"]//button[@class="serial-case-button"]')->item(0);
        ui_assert($button instanceof DOMElement && trim($button->textContent) === 'TEST123', 'Serial number is the case editor entry point');
        ui_assert($button->getAttribute('onclick') === 'showDeviceCase(this)', 'Serial opens the existing editor');
        ui_assert(!str_contains($button->getAttribute('title'), 'Klärfall'), 'Neutral serial tooltip');
        ui_assert($view->query('//button[contains(@class,"device-case-button")]')->length === 0, 'Device name is not a case action');
        ui_assert($view->query('//input[@class="bulk-select"]')->length === 1, 'Bulk selection remains available');
        if (!empty($changes['latest_case_id'])) {
            ui_assert($button->getAttribute('data-case-id') === '7', 'Existing case remains editable');
            ui_assert($view->query('//span[contains(@class,"case-status-badge")]')->length === 1, 'Actual case keeps its small status badge');
        } else {
            ui_assert(!str_contains($row->textContent, 'Klärfall'), 'Normal device is not labelled a case');
        }
        if (isset($changes['latest_case_note'])) {
            ui_assert($button->getAttribute('data-note') === $changes['latest_case_note'], 'Notes remain correctly escaped and preserved');
        }
    }

    $readonly = render_case_row($template, [], false);
    ui_assert($readonly->query('//button[@class="serial-case-button"]')->length === 0, 'Read-only users cannot open the editor');
    $mail = render_case_row($template, ['jamf_unenrolled' => 1,
        'jamf_unenroll_state' => DISOWN_JAMF_STATE_VERIFIED_UNMANAGED, 'asm_manual_done' => 1]);
    ui_assert($mail->query('//button[@onclick="showMailPreview(this)"]')->length === 1, 'Completion mail still requires its preview action');
    ui_assert(str_contains($source, 'onclick="sendMail()"'), 'Separate mail send confirmation remains');
    $pendingMail = render_case_row($template, ['jamf_unenroll_state' => DISOWN_JAMF_STATE_PENDING_DEVICE,
        'jamf_command_accepted_at' => '2026-10-19 12:00:00', 'asm_manual_done' => 1,
        'jamf_cleanup_state' => 'manual_trash_required']);
    $mailButton = $pendingMail->query('//button[@onclick="showMailPreview(this)"]')->item(0);
    ui_assert($mailButton instanceof DOMElement && !$mailButton->hasAttribute('data-jamf-verified'), 'Pending Apple-complete row offers the same standard mail action');
    ui_assert(substr_count($source, 'name="template_content"') === 1, 'Exactly one template editor');
    ui_assert($pendingMail->query('//input[@name="unenroll"]')->length === 0, 'Apple-complete row does not invite another unenroll');
    ui_assert($pendingMail->query('//input[@class="bulk-select"]')->item(0)->getAttribute('data-asm') === '1', 'Bulk can advance to manual mail despite waiting Jamf');
    $resume = render_case_row($template, ['jamf_unenroll_state' => DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED,
        'jamf_command_accepted_at' => '2026-10-19 12:00:00', 'jamf_observed_location' => 'active']);
    ui_assert(str_contains($resume->query('//td[@data-label="Aktion"]')->item(0)->textContent, 'Freigabe fortsetzen'), 'Previously waiting accepted requests can resume with one click');
    $css = (string) file_get_contents(__DIR__ . '/../assets/admin.css');
    ui_assert(!str_contains($css, 'content: " · Klärfall"'), 'Hover never invents a case label');
    $js = (string) file_get_contents(__DIR__ . '/../assets/admin.js');
    ui_assert(!str_contains($js, "querySelectorAll('.case-row-clickable')"), 'No row-wide case click handler');
    echo "OK: {$assertions} UI assertions; no database or external operations.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
