<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/workflow_state.php';

function disown_migration_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Ungültiger SQL-Bezeichner.');
    }

    return '`' . $identifier . '`';
}

function disown_migration_column_exists(mysqli $mysqli, string $table, string $column): bool
{
    $tableSql = disown_migration_identifier($table);
    $columnSql = $mysqli->real_escape_string($column);
    $result = $mysqli->query("SHOW COLUMNS FROM {$tableSql} LIKE '{$columnSql}'");
    if (!$result) {
        throw new RuntimeException('Spaltenprüfung fehlgeschlagen: ' . $mysqli->error);
    }

    $exists = (bool) $result->fetch_assoc();
    $result->free();
    return $exists;
}

function disown_migration_index_exists(mysqli $mysqli, string $table, string $index): bool
{
    $tableSql = disown_migration_identifier($table);
    $indexSql = $mysqli->real_escape_string($index);
    $result = $mysqli->query("SHOW INDEX FROM {$tableSql} WHERE Key_name = '{$indexSql}'");
    if (!$result) {
        throw new RuntimeException('Indexprüfung fehlgeschlagen: ' . $mysqli->error);
    }

    $exists = (bool) $result->fetch_assoc();
    $result->free();
    return $exists;
}

/**
 * Add the DISOWN 2.6 Jamf state columns and conservatively classify legacy rows.
 * The function is intentionally table-parameterized so the exact migration can
 * be exercised against a connection-scoped temporary table before deployment.
 *
 * @return array{added_columns: list<string>, added_indexes: list<string>, backfilled_rows: int}
 */
function disown_migrate_jamf_state(mysqli $mysqli, string $table = 'requests'): array
{
    $tableSql = disown_migration_identifier($table);
    $columns = [
        'jamf_unenroll_state' => "VARCHAR(32) NOT NULL DEFAULT 'not_started'",
        'jamf_command_id' => 'VARCHAR(128) NULL',
        'jamf_command_status' => 'VARCHAR(32) NULL',
        'jamf_command_status_checked_at' => 'DATETIME NULL',
        'jamf_command_accepted_at' => 'DATETIME NULL',
        'jamf_verified_unmanaged_at' => 'DATETIME NULL',
        'jamf_last_checked_at' => 'DATETIME NULL',
        'jamf_next_check_at' => 'DATETIME NULL',
        'jamf_check_attempts' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'jamf_last_http_status' => 'SMALLINT UNSIGNED NULL',
        'jamf_response_class' => 'VARCHAR(64) NULL',
        'jamf_observed_location' => 'VARCHAR(16) NULL',
        'jamf_observed_is_managed' => 'TINYINT(1) NULL',
        'jamf_active_absent_at' => 'DATETIME NULL',
        'jamf_trash_observed_at' => 'DATETIME NULL',
        'jamf_reenrollment_detected_at' => 'DATETIME NULL',
    ];
    $indexes = [
        'idx_requests_jamf_state_next_check' => ['jamf_unenroll_state', 'jamf_next_check_at'],
        'idx_requests_jamf_reenrollment' => ['jamf_reenrollment_detected_at'],
    ];

    $addedColumns = [];
    foreach ($columns as $column => $definition) {
        if (disown_migration_column_exists($mysqli, $table, $column)) {
            continue;
        }
        $columnSql = disown_migration_identifier($column);
        if (!$mysqli->query("ALTER TABLE {$tableSql} ADD COLUMN {$columnSql} {$definition}")) {
            throw new RuntimeException("Migration der Spalte {$column} fehlgeschlagen: " . $mysqli->error);
        }
        $addedColumns[] = $column;
    }

    $addedIndexes = [];
    foreach ($indexes as $index => $indexColumns) {
        if (disown_migration_index_exists($mysqli, $table, $index)) {
            continue;
        }
        $indexSql = disown_migration_identifier($index);
        $indexColumnSql = implode(', ', array_map('disown_migration_identifier', $indexColumns));
        if (!$mysqli->query("ALTER TABLE {$tableSql} ADD INDEX {$indexSql} ({$indexColumnSql})")) {
            throw new RuntimeException("Migration des Index {$index} fehlgeschlagen: " . $mysqli->error);
        }
        $addedIndexes[] = $index;
    }

    $manualState = $mysqli->real_escape_string(DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED);
    $failedState = $mysqli->real_escape_string(DISOWN_JAMF_STATE_FAILED);
    $notStartedState = $mysqli->real_escape_string(DISOWN_JAMF_STATE_NOT_STARTED);
    $backfillSql = "UPDATE {$tableSql}
        SET jamf_unenroll_state = CASE
                WHEN jamf_unenrolled <> 0 THEN '{$manualState}'
                ELSE '{$failedState}'
            END,
            jamf_response_class = CASE
                WHEN jamf_unenrolled <> 0 THEN 'legacy_unverified'
                ELSE 'legacy_error'
            END
        WHERE jamf_unenroll_state = '{$notStartedState}'
          AND jamf_response_class IS NULL
          AND (
              jamf_unenrolled <> 0
              OR TRIM(COALESCE(jamf_unenroll_error, '')) <> ''
          )";
    if (!$mysqli->query($backfillSql)) {
        throw new RuntimeException('Legacy-Backfill fehlgeschlagen: ' . $mysqli->error);
    }

    return [
        'added_columns' => $addedColumns,
        'added_indexes' => $addedIndexes,
        'backfilled_rows' => $mysqli->affected_rows,
    ];
}
