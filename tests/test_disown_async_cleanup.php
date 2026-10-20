<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// PUBLIC has no credential-bearing db.php; allow an explicit local test bootstrap.
require (getenv('DISOWN_TEST_DB_BOOTSTRAP') ?: __DIR__ . '/../db.php');
require __DIR__ . '/../jamf_cleanup.php';
require __DIR__ . '/../workflow_mail.php';
require __DIR__ . '/../migrations/lib/jamf_cleanup_migration.php';

$assertions = 0;
function async_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function async_row(mysqli $mysqli, int $id = 900001): array
{
    return $mysqli->query('SELECT * FROM requests WHERE id = ' . $id)->fetch_assoc() ?: [];
}
function log_request_action(mysqli $mysqli, int $id, string $action, string $details): void
{
    $stmt = $mysqli->prepare("INSERT INTO request_audit_log (request_id, action, admin_user, details) VALUES (?, ?, 'test-admin', ?)");
    $stmt->bind_param('iss', $id, $action, $details);
    $stmt->execute();
    $stmt->close();
}
function async_observation(bool $active, bool $trash, bool $managed = true): array
{
    return ['serial' => 'TESTASYNC01', 'active_lookup_ok' => true, 'trash_lookup_ok' => true,
        'active_http_status' => 200, 'trash_http_status' => 200,
        'active' => ['found' => $active, 'is_managed' => $managed],
        'trash' => ['found' => $trash, 'is_managed' => $managed,
            'modified' => '2026-10-17 16:48:02', 'last_checkin' => '2026-10-17 14:33:59']];
}

try {
    // Shadow real tables on this connection. No real request or audit can change.
    $mysqli->query("CREATE TEMPORARY TABLE requests (
        id INT PRIMARY KEY, serial VARCHAR(190) NOT NULL,
        jamf_unenroll_state VARCHAR(32) NOT NULL DEFAULT 'not_started',
        jamf_unenrolled TINYINT NOT NULL DEFAULT 0, jamf_unenrolled_at DATETIME NULL,
        jamf_unenroll_error TEXT NULL, jamf_command_accepted_at DATETIME NULL,
        jamf_verified_unmanaged_at DATETIME NULL, jamf_last_checked_at DATETIME NULL,
        jamf_next_check_at DATETIME NULL, jamf_check_attempts INT NOT NULL DEFAULT 0,
        jamf_last_http_status INT NULL, jamf_response_class VARCHAR(64) NULL,
        jamf_command_id VARCHAR(128) NULL, jamf_command_status VARCHAR(32) NULL,
        jamf_command_status_checked_at DATETIME NULL, jamf_observed_location VARCHAR(16) NULL,
        jamf_observed_is_managed TINYINT NULL, jamf_active_absent_at DATETIME NULL,
        jamf_trash_observed_at DATETIME NULL, asm_manual_done TINYINT NOT NULL DEFAULT 0,
        asm_manual_done_at DATETIME NULL, mail_sent TINYINT NOT NULL DEFAULT 0,
        mail_sent_at DATETIME NULL, mail_sent_to VARCHAR(255) NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'offen', completed_at DATETIME NULL,
        completed_by VARCHAR(100) NULL
    ) ENGINE=InnoDB");
    disown_migrate_jamf_cleanup($mysqli);
    $mysqli->query('CREATE TEMPORARY TABLE request_audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY, request_id INT, action VARCHAR(100),
        admin_user VARCHAR(100), details TEXT
    ) ENGINE=InnoDB');
    $mysqli->query("INSERT INTO requests (id, serial) VALUES (900001, 'TESTASYNC01'), (900002, 'TESTASYNC02'), (900003, 'TESTASYNC03'), (900004, 'TESTASYNC04')");
    $mysqli->query('CREATE TEMPORARY TABLE cleanup_migration_fixture (id INT PRIMARY KEY, asm_manual_done TINYINT NOT NULL DEFAULT 0)');
    $first = disown_migrate_jamf_cleanup($mysqli, 'cleanup_migration_fixture');
    $second = disown_migrate_jamf_cleanup($mysqli, 'cleanup_migration_fixture');
    async_assert(count($first['added_columns']) === 6 && count($first['added_indexes']) === 1, 'Additive cleanup migration creates expected fields');
    async_assert(!$second['added_columns'] && !$second['added_indexes'], 'Migration is repeatable');

    // Exercise the exact admin follow-up function with an injected Apple stub.
    $admin = (string) file_get_contents(__DIR__ . '/../admin.php');
    $start = strpos($admin, 'function execute_asm_release_and_persist(');
    $end = strpos($admin, 'function format_mail_delivery_status(', $start);
    async_assert($start !== false && $end !== false, 'Actual admin follow-up located');
    eval(substr($admin, $start, $end - $start));
    $appleCalls = [];
    $apple = static function (string $serial) use (&$appleCalls): array {
        $appleCalls[] = $serial;
        return ['success' => true, 'dry_run' => false, 'message' => 'Test-only confirmed release', 'details' => []];
    };
    $denied = execute_asm_release_and_persist($mysqli, async_row($mysqli), '', false, $apple);
    async_assert(empty($denied['local_completed']) && !$appleCalls, 'No Apple call without Jamf authority');

    $acceptedAt = date('Y-m-d H:i:s', time() - 3600);
    $pending = ['state' => DISOWN_JAMF_STATE_PENDING_DEVICE, 'accepted' => true,
        'command_accepted_at' => $acceptedAt, 'http_status' => 200,
        'response_class' => 'device_unenrolled_command_accepted', 'observed_location' => 'active',
        'observed_is_managed' => true, 'message' => 'Queued'];
    persist_jamf_workflow_result($mysqli, 900001, $pending);
    $row = async_row($mysqli);
    async_assert($row['jamf_command_accepted_at'] === $acceptedAt, 'Real acceptance time survives short polling delay');
    async_assert((int) $row['jamf_unenrolled'] === 0, 'Accepted never fabricates successful unenroll');
    async_assert(disown_request_allows_asm($row), 'Accepted pending command permits Apple release');
    async_assert(!disown_request_allows_completion_mail($row), 'Mail remains blocked until Apple confirmation');
    $released = execute_asm_release_and_persist($mysqli, $row, 'test single', false, $apple);
    async_assert(!empty($released['local_completed']) && $appleCalls === ['TESTASYNC01'], 'Single action releases exactly the stored serial');
    $row = async_row($mysqli);
    async_assert((int) $row['asm_manual_done'] === 1 && (int) $row['mail_sent'] === 0, 'Apple done, mail still untouched');
    async_assert($row['status'] !== 'erledigt' && $row['completed_at'] === null, 'No automatic completion without manual mail action');
    async_assert(disown_request_allows_completion_mail($row), 'Pending Jamf no longer blocks manually requested mail');
    $custom = 'Custom verified template preserved exactly';
    async_assert(disown_release_mail_template_for_row($custom, $row) !== $custom, 'Pending gets truthful separate template');
    async_assert(str_contains(disown_release_mail_template_for_row($custom, $row), 'wurde veranlasst'), 'Pending template describes initiation, not completed profile removal');
    async_assert(disown_pending_release_mail_default_template() === disown_pending_release_mail_template(), 'Built-in fallback exactly matches the bilingual versioned template');
    foreach (['{{name}}', '{{device_name}}', '{{serial}}'] as $placeholder) {
        async_assert(substr_count(disown_pending_release_mail_template(), $placeholder) === 2, 'Both languages retain placeholder ' . $placeholder);
    }
    async_assert(!str_contains(disown_pending_release_mail_template(), 'Bestandsbereinigung'), 'Student mail contains no internal cleanup wording');
    $verifiedTemplateRow = array_replace($row, ['jamf_unenroll_state' => DISOWN_JAMF_STATE_VERIFIED_UNMANAGED]);
    async_assert(disown_release_mail_template_for_row($custom, $verifiedTemplateRow) === $custom, 'Configured verified template is not overwritten');

    $active = async_observation(true, false);
    async_assert(disown_jamf_cleanup_classify($row, $active) === 'waiting', 'Brief grace period before manual cleanup notice');
    $mysqli->query('UPDATE requests SET asm_manual_done_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE id = 900001');
    $row = async_row($mysqli);
    async_assert(disown_jamf_cleanup_classify($row, $active) === 'manual_trash_required', 'Active released inventory after grace period needs manual trash');
    persist_jamf_cleanup_observation($mysqli, $row, $active);
    $auditCount = (int) $mysqli->query('SELECT COUNT(*) AS n FROM request_audit_log')->fetch_assoc()['n'];
    persist_jamf_cleanup_observation($mysqli, $row, $active);
    async_assert((int) $mysqli->query('SELECT COUNT(*) AS n FROM request_audit_log')->fetch_assoc()['n'] === $auditCount, 'Unchanged state does not spam audit');
    async_assert((int) async_row($mysqli)['mail_sent'] === 0, 'Background observation sends no mail');

    $staleTrash = async_observation(false, true);
    async_assert(persist_jamf_cleanup_observation($mysqli, $row, $staleTrash) === 'trashed', 'Trash with stale managed=true resolves inventory cleanup');
    $row = async_row($mysqli);
    async_assert((int) $row['jamf_unenrolled'] === 0 && $row['jamf_verified_unmanaged_at'] === null, 'Trash does not invent profile removal');
    async_assert($row['jamf_cleanup_resolved_at'] !== null && $row['jamf_cleanup_error'] === null, 'Cleanup gets its own timestamp');
    $scope = disown_jamf_cleanup_scope_sql();
    async_assert((int) $mysqli->query("SELECT COUNT(*) AS n FROM requests WHERE {$scope} AND jamf_cleanup_state <> 'trashed'")->fetch_assoc()['n'] === 0, 'Trashed device disappears from cleanup view');

    persist_completion_mail_result($mysqli, 900001, 2, 'FEHLER: test@example.invalid', 'test-admin');
    $row = async_row($mysqli);
    async_assert($row['status'] === 'erledigt' && (int) $row['jamf_unenrolled'] === 0, 'Manual mail failure completes main workflow without fake Jamf success');
    async_assert((int) $mysqli->query("SELECT COUNT(*) AS n FROM requests WHERE {$scope}")->fetch_assoc()['n'] === 1, 'Completed/mail-failed requests remain monitored');

    $failedLookup = $active;
    $failedLookup['active_lookup_ok'] = false;
    async_assert(persist_jamf_cleanup_observation($mysqli, $row, $failedLookup) === 'lookup_error', 'Lookup failure is not a trash recommendation or success');
    async_assert(disown_request_allows_completion_mail(async_row($mysqli)), 'Cleanup API outage does not block mail retry after confirmed Apple release');
    async_assert(disown_jamf_cleanup_classify($row, async_observation(false, false)) === 'not_found', 'Neither inventory nor Trash is a separate verification case');
    async_assert(disown_jamf_cleanup_classify($row, async_observation(true, true)) === 'conflict', 'Conflicting location never suggests trashing');
    $wrong = $staleTrash;
    $wrong['serial'] = 'WRONGSERIAL';
    $rejected = false;
    try {
        persist_jamf_cleanup_observation($mysqli, $row, $wrong);
    } catch (RuntimeException $e) {
        $rejected = true;
    }
    async_assert($rejected && async_row($mysqli)['jamf_cleanup_state'] === 'lookup_error', 'Wrong serial rolls back without modifying request');

    $freshTrash = $staleTrash;
    $freshTrash['trash']['is_managed'] = false;
    $freshTrash['trash']['modified'] = date('Y-m-d H:i:s');
    $freshTrash['trash']['last_checkin'] = date('Y-m-d H:i:s');
    persist_jamf_cleanup_observation($mysqli, $row, $freshTrash);
    async_assert((int) async_row($mysqli)['jamf_unenrolled'] === 1, 'Later correlated evidence can genuinely verify unenroll');

    foreach (['unknown', 'conflict'] as $location) {
        async_assert(!disown_request_allows_asm(array_replace($row, ['asm_manual_done' => 0, 'mail_sent' => 0,
            'jamf_observed_location' => $location])), 'Unsafe observation blocks Apple: ' . $location);
    }
    foreach (['failed', 'error', 'cancelled'] as $commandStatus) {
        async_assert(!disown_request_has_release_basis(array_replace($row, ['jamf_command_status' => $commandStatus])), 'Failed command cannot authorize release');
    }
    async_assert(!disown_request_has_release_basis(array_replace($row, ['jamf_command_accepted_at' => null])), 'Legacy manual/pending without acceptance stays blocked');
    async_assert(!disown_request_has_release_basis(array_replace($row, ['completed_by' => 'history-import'])), 'History imports cannot authorize release');
    $terminalFailure = jamf_classify_unenroll_response(['http_status' => 200,
        'body' => '{"message":"DeviceUnenrolled","commandStatus":"failed"}']);
    async_assert(empty($terminalFailure['accepted']), 'Explicit command failure is not overridden by HTTP 200');
    async_assert(jamf_nullable_bool(2) === null && jamf_nullable_bool(0.5) === null, 'Invalid numeric management flags never count as unmanaged');

    // Same actual follow-up for bulk; each row is isolated and mail remains manual.
    foreach ([900002, 900003, 900004] as $id) {
        persist_jamf_workflow_result($mysqli, $id, $pending);
    }
    $mysqli->query("UPDATE requests SET jamf_command_accepted_at = NULL, jamf_unenroll_state = 'failed' WHERE id = 900003");
    foreach ([900002, 900003, 900004] as $id) {
        $executor = $id === 900004 ? static fn(string $serial): array => ['success' => false, 'message' => 'Test Apple failure'] : $apple;
        execute_asm_release_and_persist($mysqli, async_row($mysqli, $id), 'test bulk', true, $executor);
    }
    async_assert((int) async_row($mysqli, 900002)['asm_manual_done'] === 1, 'Bulk accepted row advances');
    async_assert((int) async_row($mysqli, 900003)['asm_manual_done'] === 0, 'Bulk failed Jamf row stays blocked');
    async_assert((int) async_row($mysqli, 900004)['asm_manual_done'] === 0, 'Bulk Apple failure never opens mail');
    async_assert(!disown_request_allows_completion_mail(async_row($mysqli, 900004)), 'No mail after Apple failure');
    async_assert((int) async_row($mysqli, 900002)['mail_sent'] === 0, 'Bulk does not automatically send mail');
    async_assert($appleCalls === ['TESTASYNC01', 'TESTASYNC02'], 'Only eligible exact serials reached Apple stub');
    $dryRun = execute_asm_release_and_persist($mysqli, async_row($mysqli, 900004), '', false,
        static fn(string $serial): array => ['success' => true, 'dry_run' => true, 'message' => 'Test-only ordinary dry-run']);
    async_assert(empty($dryRun['local_completed']) && (int) async_row($mysqli, 900004)['asm_manual_done'] === 0, 'Ordinary dry-run never marks Apple release completed');

    $testConfig = ['JAMF_URL' => 'https://jamf.invalid', 'JAMF_NETWORK_ID' => 'test', 'JAMF_API_KEY' => 'test'];
    $duplicateCalls = 0;
    $duplicateTransport = static function (array $request) use (&$duplicateCalls): array {
        $duplicateCalls++;
        async_assert($request['method'] === 'GET', 'Ambiguous lookup never sends a command');
        return ['http_status' => 200, 'body' => json_encode(['devices' => $duplicateCalls === 1
            ? [['serialNumber' => 'TESTASYNC01'], ['serialNumber' => 'TESTASYNC01']] : []])];
    };
    $duplicate = jamf_unenroll_by_serial('TESTASYNC01', false, $duplicateTransport, $testConfig);
    async_assert(empty($duplicate['accepted']) && $duplicate['state'] === DISOWN_JAMF_STATE_FAILED, 'Duplicate serials fail closed');
    $conflictCalls = 0;
    $conflictTransport = static function (array $request) use (&$conflictCalls): array {
        $conflictCalls++;
        async_assert($request['method'] === 'GET', 'Active/Trash conflict never sends a command');
        return ['http_status' => 200, 'body' => '{"devices":[{"serialNumber":"TESTASYNC01","isManaged":false}]}'];
    };
    $conflict = jamf_unenroll_by_serial('TESTASYNC01', false, $conflictTransport, $testConfig);
    async_assert($conflict['response_class'] === 'active_trash_conflict' && $conflictCalls === 2, 'Conflict is not silently considered unmanaged');
    echo "Async/Cleanup tests: {$assertions} assertions. Connection-local temporary tables, injected Apple stub, no external writes.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
