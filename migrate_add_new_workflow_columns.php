<?php
/**
 * Database Migration: Add new workflow columns for hybrid manual/automated device release
 *
 * This migration adds 5 new columns to the `requests` table to track the new workflow:
 * 1. jamf_unenrolled - flag indicating device has been unenrolled from Jamf
 * 2. jamf_unenrolled_at - timestamp of unenroll action
 * 3. jamf_unenroll_error - error message if unenroll failed
 * 4. asm_manual_done - flag indicating manual ASM release has been confirmed
 * 5. asm_manual_done_at - timestamp of manual ASM confirmation
 *
 * Usage:
 *   php migrate_add_new_workflow_columns.php
 *
 * This script will:
 * - Connect to the database using mysqli
 * - Execute ALTER TABLE statements to add new columns
 * - Display success or error messages
 */

// Include database connection
require_once __DIR__ . '/db.php';

echo "Connected to database successfully.\n";

// Define the new columns
$columns = [
    'jamf_unenrolled' => 'TINYINT(1) NOT NULL DEFAULT 0 COMMENT "Indicates device has been unenrolled from Jamf School"',
    'jamf_unenrolled_at' => 'DATETIME NULL COMMENT "Timestamp when device was unenrolled from Jamf"',
    'jamf_unenroll_error' => 'TEXT NULL COMMENT "Error message if Jamf unenroll failed"',
    'asm_manual_done' => 'TINYINT(1) NOT NULL DEFAULT 0 COMMENT "Indicates manual ASM/ADE release has been confirmed"',
    'asm_manual_done_at' => 'DATETIME NULL COMMENT "Timestamp when manual ASM release was confirmed"',
];

// Execute ALTER TABLE statements
$added_columns = [];
$errors = [];

foreach ($columns as $column_name => $column_def) {
    $sql = "ALTER TABLE requests ADD COLUMN IF NOT EXISTS {$column_name} {$column_def}";
    
    echo "Executing: {$sql}\n";
    
    if ($mysqli->query($sql)) {
        $added_columns[] = $column_name;
        echo "  ✓ Column '{$column_name}' added successfully.\n";
    } else {
        $errors[] = [
            'column' => $column_name,
            'error' => $mysqli->error,
        ];
        echo "  ✗ Error adding column '{$column_name}': " . $mysqli->error . "\n";
    }
}

echo "\n--- Migration Summary ---\n";
echo "Columns added: " . count($added_columns) . "\n";

if (!empty($added_columns)) {
    echo "Successfully added:\n";
    foreach ($added_columns as $col) {
        echo "  - {$col}\n";
    }
}

if (!empty($errors)) {
    echo "\nErrors encountered:\n";
    foreach ($errors as $err) {
        echo "  - {$err['column']}: {$err['error']}\n";
    }
} else {
    echo "\nNo errors. Migration completed successfully!\n";
}

// Display current table structure
echo "\n--- Updated Table Structure ---\n";
$result = $mysqli->query("SHOW CREATE TABLE requests");
if ($result) {
    $row = $result->fetch_row();
    echo $row[1] . "\n";
}

$mysqli->close();
?>

