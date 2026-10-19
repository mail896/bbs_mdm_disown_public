<?php

declare(strict_types=1);

require_once __DIR__ . '/jamf_state_migration.php';

/** Additive, repeatable migration; never invents enrollment/profile evidence. */
function disown_migrate_jamf_cleanup(mysqli $mysqli, string $table = 'requests'): array
{
    $tableSql = disown_migration_identifier($table);
    $columns = [
        'jamf_cleanup_state' => "VARCHAR(32) NOT NULL DEFAULT 'not_checked'",
        'jamf_cleanup_checked_at' => 'DATETIME NULL',
        'jamf_cleanup_next_check_at' => 'DATETIME NULL',
        'jamf_cleanup_needed_at' => 'DATETIME NULL',
        'jamf_cleanup_resolved_at' => 'DATETIME NULL',
        'jamf_cleanup_error' => 'VARCHAR(255) NULL',
    ];
    $added = [];
    foreach ($columns as $column => $definition) {
        if (!disown_migration_column_exists($mysqli, $table, $column)) {
            $columnSql = disown_migration_identifier($column);
            if (!$mysqli->query("ALTER TABLE {$tableSql} ADD COLUMN {$columnSql} {$definition}")) {
                throw new RuntimeException('Jamf-Nacharbeit: Spalte konnte nicht angelegt werden.');
            }
            $added[] = $column;
        }
    }
    $index = 'idx_requests_jamf_cleanup_due';
    $addedIndexes = [];
    if (!disown_migration_index_exists($mysqli, $table, $index)) {
        if (!$mysqli->query("ALTER TABLE {$tableSql} ADD INDEX {$index} (asm_manual_done, jamf_cleanup_next_check_at)")) {
            throw new RuntimeException('Jamf-Nacharbeit: Index konnte nicht angelegt werden.');
        }
        $addedIndexes[] = $index;
    }
    return ['added_columns' => $added, 'added_indexes' => $addedIndexes];
}
