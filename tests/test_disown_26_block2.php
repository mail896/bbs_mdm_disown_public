<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../workflow_state.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/../migrations/lib/jamf_state_migration.php';

$assertions = 0;

function block2_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Test fehlgeschlagen: ' . $message);
    }
}

function block2_row(mysqli $mysqli, string $table, int $id): array
{
    $tableSql = disown_migration_identifier($table);
    $result = $mysqli->query("SELECT * FROM {$tableSql} WHERE id = {$id}");
    if (!$result) {
        throw new RuntimeException('Testzeile konnte nicht gelesen werden: ' . $mysqli->error);
    }
    $row = $result->fetch_assoc();
    $result->free();
    if (!$row) {
        throw new RuntimeException('Testzeile fehlt: ' . $id);
    }
    return $row;
}

try {
    block2_assert(count(disown_jamf_states()) === 6, 'genau sechs Jamf-Zustände');
    foreach (disown_jamf_states() as $state) {
        block2_assert(disown_jamf_state_is_valid($state), 'gültiger Zustand ' . $state);
    }
    block2_assert(!disown_jamf_state_is_valid('success'), 'unspezifischer Erfolgszustand ist ungültig');
    block2_assert(
        disown_jamf_can_transition(DISOWN_JAMF_STATE_NOT_STARTED, DISOWN_JAMF_STATE_COMMAND_ACCEPTED),
        'Befehlsannahme ist ein zulässiger Startübergang'
    );
    block2_assert(
        !disown_jamf_can_transition(DISOWN_JAMF_STATE_COMMAND_ACCEPTED, DISOWN_JAMF_STATE_NOT_STARTED),
        'Befehlsannahme kann nicht still zurückgesetzt werden'
    );
    block2_assert(
        !disown_jamf_can_transition(DISOWN_JAMF_STATE_VERIFIED_UNMANAGED, DISOWN_JAMF_STATE_PENDING_DEVICE),
        'spätere Wiederanmeldung überschreibt den historischen Nachweis nicht'
    );
    block2_assert(
        disown_jamf_can_transition(DISOWN_JAMF_STATE_NOT_STARTED, DISOWN_JAMF_STATE_PENDING_DEVICE),
        'direkt angenommener asynchroner Befehl darf als pending gespeichert werden'
    );
    block2_assert(
        disown_jamf_can_transition(DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED, DISOWN_JAMF_STATE_VERIFIED_UNMANAGED),
        'manuell zu prüfender Zustand kann durch späteren read-only Nachweis verifiziert werden'
    );
    foreach (disown_jamf_states() as $state) {
        block2_assert(
            disown_jamf_state_allows_release($state) === ($state === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED),
            'nur verified_unmanaged erlaubt die Freigabe'
        );
    }
    block2_assert(
        disown_jamf_state_from_row(['jamf_unenrolled' => 1]) === DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED,
        'Legacy-Erfolg wird nicht als verifiziert erfunden'
    );
    block2_assert(
        disown_jamf_state_from_row(['jamf_unenrolled' => 0, 'jamf_unenroll_error' => 'Fehler']) === DISOWN_JAMF_STATE_FAILED,
        'Legacy-Fehler bleibt Fehler'
    );
    block2_assert(!disown_request_allows_asm([
        'jamf_unenroll_state' => DISOWN_JAMF_STATE_PENDING_DEVICE,
        'asm_manual_done' => 0,
        'mail_sent' => 0,
    ]), 'ASM ist bei pending gesperrt');
    block2_assert(disown_request_allows_asm([
        'jamf_unenroll_state' => DISOWN_JAMF_STATE_VERIFIED_UNMANAGED,
        'asm_manual_done' => 0,
        'mail_sent' => 0,
    ]), 'ASM ist nur nach Verifikation freigegeben');
    block2_assert(!disown_request_allows_completion_mail([
        'jamf_unenroll_state' => DISOWN_JAMF_STATE_VERIFIED_UNMANAGED,
        'asm_manual_done' => 0,
        'mail_sent' => 0,
    ]), 'Abschlussmail ist vor ASM gesperrt');
    block2_assert(disown_request_allows_completion_mail([
        'jamf_unenroll_state' => DISOWN_JAMF_STATE_VERIFIED_UNMANAGED,
        'asm_manual_done' => 1,
        'mail_sent' => 0,
    ]), 'Abschlussmail ist nach Verifikation und ASM freigegeben');
    $asynchronousTrashEvidence = [
        'command_accepted' => true,
        'command_terminal_success' => false,
        'active_found' => false,
        'trash_found' => true,
        'trash_changed_after_command' => true,
        'trash_is_managed' => true,
        'trash_last_checkin_before_command' => true,
    ];
    block2_assert(
        disown_jamf_state_from_evidence($asynchronousTrashEvidence) === DISOWN_JAMF_STATE_PENDING_DEVICE,
        'asynchroner Trash-Fall mit stale isManaged=true bleibt ohne Command-Nachweis pending'
    );
    block2_assert(
        disown_jamf_state_from_evidence($asynchronousTrashEvidence, true) === DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED,
        'unklarer asynchroner Trash-Fall verlangt nach Retry-Limit manuelle Verifizierung'
    );
    $asynchronousTrashEvidence['command_terminal_success'] = true;
    block2_assert(
        disown_jamf_state_from_evidence($asynchronousTrashEvidence) === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED,
        'terminaler Command-Nachweis plus passender Trash-Übergang bestätigt asynchronen Abschluss'
    );
    block2_assert(
        disown_jamf_state_from_evidence([
            'command_accepted' => false,
            'direct_unmanaged' => true,
        ]) === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED,
        'bereits unmanaged kann durch aktuellen Direktnachweis bestätigt werden'
    );
    block2_assert(disown_reenrollment_detected([
        'active_lookup_ok' => true,
        'active' => ['found' => true, 'is_managed' => true],
    ]), 'aktive erneute Verwaltung wird als Abweichung erkannt');
    block2_assert(!disown_reenrollment_detected([
        'active_lookup_ok' => true,
        'active' => ['found' => true, 'is_managed' => false],
    ]), 'aktiver unmanaged-Nachweis ist keine Wiederanmeldung');
    block2_assert(!disown_reenrollment_detected([
        'active_lookup_ok' => false,
        'active' => ['found' => true, 'is_managed' => true],
    ]), 'fehlgeschlagener Lookup erzeugt keine Wiederanmeldungsbehauptung');

    $temporaryTable = 'tmp_disown_26_block2_' . getmypid();
    $temporaryTableSql = disown_migration_identifier($temporaryTable);
    $createSql = "CREATE TEMPORARY TABLE {$temporaryTableSql} (
        id INT NOT NULL PRIMARY KEY,
        jamf_unenrolled TINYINT(1) NOT NULL DEFAULT 0,
        jamf_unenroll_error TEXT NULL
    ) ENGINE=InnoDB";
    if (!$mysqli->query($createSql)) {
        throw new RuntimeException('Temporäre Teststruktur konnte nicht erstellt werden: ' . $mysqli->error);
    }
    if (!$mysqli->query(
        "INSERT INTO {$temporaryTableSql} (id, jamf_unenrolled, jamf_unenroll_error) VALUES
        (1, 0, NULL),
        (2, 0, 'legacy failure'),
        (3, 1, NULL),
        (4, 1, 'old warning')"
    )) {
        throw new RuntimeException('Testdaten konnten nicht erstellt werden: ' . $mysqli->error);
    }

    $firstRun = disown_migrate_jamf_state($mysqli, $temporaryTable);
    block2_assert(count($firstRun['added_columns']) === 16, 'erster Lauf ergänzt alle sechzehn Spalten');
    block2_assert(count($firstRun['added_indexes']) === 2, 'erster Lauf ergänzt beide Indizes');
    block2_assert($firstRun['backfilled_rows'] === 3, 'erster Lauf klassifiziert drei Legacy-Zeilen');

    $row1 = block2_row($mysqli, $temporaryTable, 1);
    $row2 = block2_row($mysqli, $temporaryTable, 2);
    $row3 = block2_row($mysqli, $temporaryTable, 3);
    $row4 = block2_row($mysqli, $temporaryTable, 4);
    block2_assert($row1['jamf_unenroll_state'] === DISOWN_JAMF_STATE_NOT_STARTED, 'unbearbeitete Zeile bleibt not_started');
    block2_assert($row1['jamf_response_class'] === null, 'unbearbeitete Zeile erhält keine erfundene Antwortklasse');
    block2_assert($row2['jamf_unenroll_state'] === DISOWN_JAMF_STATE_FAILED, 'Legacy-Fehler wird failed');
    block2_assert($row2['jamf_response_class'] === 'legacy_error', 'Legacy-Fehler erhält sichere Klasse');
    block2_assert($row3['jamf_unenroll_state'] === DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED, 'Legacy-Erfolg verlangt Prüfung');
    block2_assert($row3['jamf_response_class'] === 'legacy_unverified', 'Legacy-Erfolg bleibt unbestätigt');
    block2_assert($row4['jamf_unenroll_state'] === DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED, 'Legacy-Bool gewinnt nicht gegen alten Freitext');
    block2_assert(array_key_exists('jamf_command_id', $row4), 'Command-ID kann getrennt gespeichert werden');
    block2_assert(array_key_exists('jamf_command_status', $row4), 'Command-Status kann getrennt gespeichert werden');
    block2_assert(array_key_exists('jamf_command_status_checked_at', $row4), 'Command-Prüfzeitpunkt kann getrennt gespeichert werden');
    block2_assert(array_key_exists('jamf_active_absent_at', $row4), 'Zeitpunkt der Active-Abwesenheit kann gespeichert werden');
    block2_assert(array_key_exists('jamf_trash_observed_at', $row4), 'Zeitpunkt des Trash-Übergangs kann gespeichert werden');

    if (!$mysqli->query(
        "UPDATE {$temporaryTableSql}
         SET jamf_unenroll_state = 'verified_unmanaged',
             jamf_response_class = 'managed_false',
             jamf_verified_unmanaged_at = NOW()
         WHERE id = 1"
    )) {
        throw new RuntimeException('Verifizierter Testzustand konnte nicht gesetzt werden: ' . $mysqli->error);
    }

    $secondRun = disown_migrate_jamf_state($mysqli, $temporaryTable);
    block2_assert($secondRun['added_columns'] === [], 'zweiter Lauf ergänzt keine Spalten');
    block2_assert($secondRun['added_indexes'] === [], 'zweiter Lauf ergänzt keine Indizes');
    block2_assert($secondRun['backfilled_rows'] === 0, 'zweiter Lauf verändert keine Zeile');
    $verifiedRow = block2_row($mysqli, $temporaryTable, 1);
    block2_assert($verifiedRow['jamf_unenroll_state'] === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED, 'zweiter Lauf bewahrt Verifikation');
    block2_assert($verifiedRow['jamf_response_class'] === 'managed_false', 'zweiter Lauf bewahrt Antwortklasse');

    echo 'Block-2-Tests erfolgreich: ' . $assertions . ' Assertions.' . PHP_EOL;
    echo 'Migration wurde zweimal gegen eine verbindungsgebundene temporäre Tabelle ausgeführt.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
