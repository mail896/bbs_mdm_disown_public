<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../db.php';
require __DIR__ . '/../workflow_store.php';

$assertions = 0;

function store_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Test fehlgeschlagen: ' . $message);
    }
}

function store_row(mysqli $mysqli, int $id = 1): array
{
    $result = $mysqli->query('SELECT * FROM requests WHERE id = ' . $id);
    if (!$result) {
        throw new RuntimeException('Temporärer Zustand konnte nicht gelesen werden.');
    }
    $row = $result->fetch_assoc();
    $result->free();
    return $row ?: [];
}

try {
    $sql = "CREATE TEMPORARY TABLE requests (
        id INT NOT NULL PRIMARY KEY,
        jamf_unenroll_state VARCHAR(32) NOT NULL DEFAULT 'not_started',
        jamf_unenrolled TINYINT(1) NOT NULL DEFAULT 0,
        jamf_unenrolled_at DATETIME NULL,
        jamf_unenroll_error TEXT NULL,
        jamf_command_accepted_at DATETIME NULL,
        jamf_verified_unmanaged_at DATETIME NULL,
        jamf_last_checked_at DATETIME NULL,
        jamf_next_check_at DATETIME NULL,
        jamf_check_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        jamf_last_http_status SMALLINT UNSIGNED NULL,
        jamf_response_class VARCHAR(64) NULL,
        jamf_command_id VARCHAR(128) NULL,
        jamf_command_status VARCHAR(32) NULL,
        jamf_command_status_checked_at DATETIME NULL,
        jamf_observed_location VARCHAR(16) NULL,
        jamf_observed_is_managed TINYINT(1) NULL,
        jamf_active_absent_at DATETIME NULL,
        jamf_trash_observed_at DATETIME NULL,
        mail_sent TINYINT(1) NOT NULL DEFAULT 0,
        mail_sent_at DATETIME NULL,
        mail_sent_to VARCHAR(255) NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'offen',
        completed_at DATETIME NULL,
        completed_by VARCHAR(100) NULL
    ) ENGINE=InnoDB";
    if (!$mysqli->query($sql) || !$mysqli->query('INSERT INTO requests (id) VALUES (1), (2)')) {
        throw new RuntimeException('Temporäre Speicherstruktur konnte nicht erstellt werden.');
    }

    $pendingState = persist_jamf_workflow_result($mysqli, 1, [
        'state' => DISOWN_JAMF_STATE_PENDING_DEVICE,
        'accepted' => true,
        'http_status' => 200,
        'response_class' => 'device_unenrolled_command_accepted',
        'command_id' => 'fixture-command',
        'command_status' => 'queued',
        'message' => 'Befehl angenommen.',
        'observed_location' => 'active',
        'observed_is_managed' => true,
        'active_absent' => false,
        'trash_observed' => false,
    ]);
    $pending = store_row($mysqli);
    store_assert($pendingState === DISOWN_JAMF_STATE_PENDING_DEVICE, 'Pending-Zustand wird zurückgegeben');
    store_assert((int) $pending['jamf_unenrolled'] === 0, 'Pending setzt den alten Erfolgs-Bool nicht');
    store_assert($pending['jamf_unenroll_error'] === null, 'Pending wird nicht als Fehler gespeichert');
    store_assert($pending['jamf_command_accepted_at'] !== null, 'Befehlsannahme erhält einen Zeitpunkt');
    store_assert($pending['jamf_next_check_at'] !== null, 'Pending erhält einen nächsten Prüfzeitpunkt');
    store_assert((int) $pending['jamf_check_attempts'] === 1, 'Prüfversuch wird gezählt');
    store_assert($pending['jamf_response_class'] === 'device_unenrolled_command_accepted', 'sichere Antwortklasse wird gespeichert');

    $verifiedState = persist_jamf_workflow_result($mysqli, 1, [
        'state' => DISOWN_JAMF_STATE_VERIFIED_UNMANAGED,
        'accepted' => true,
        'http_status' => 200,
        'response_class' => 'managed_false',
        'message' => 'Verifiziert.',
        'observed_location' => 'active',
        'observed_is_managed' => false,
        'active_absent' => false,
        'trash_observed' => false,
    ]);
    $verified = store_row($mysqli);
    store_assert($verifiedState === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED, 'Verifizierter Zustand wird zurückgegeben');
    store_assert((int) $verified['jamf_unenrolled'] === 1, 'nur Verifikation setzt den alten Erfolgs-Bool');
    store_assert($verified['jamf_verified_unmanaged_at'] !== null, 'Verifikation erhält einen Zeitpunkt');
    store_assert($verified['jamf_next_check_at'] === null, 'Verifikation entfernt den Retry-Zeitpunkt');
    store_assert((int) $verified['jamf_observed_is_managed'] === 0, 'explizites false bleibt in der DB false');

    $transitionRejected = false;
    try {
        persist_jamf_workflow_result($mysqli, 1, [
            'state' => DISOWN_JAMF_STATE_FAILED,
            'message' => 'Darf Verifikation nicht überschreiben.',
        ]);
    } catch (RuntimeException $e) {
        $transitionRejected = str_contains($e->getMessage(), 'Unzulässiger Jamf-Zustandswechsel');
    }
    store_assert($transitionRejected, 'verifizierter Zustand kann nicht auf failed zurückgesetzt werden');

    persist_completion_mail_result($mysqli, 1, 2, 'FEHLER: test@example.invalid', 'test-admin');
    $mailFailed = store_row($mysqli);
    store_assert((int) $mailFailed['mail_sent'] === 2, 'Mailfehler wird als fehlgeschlagen gespeichert');
    store_assert($mailFailed['status'] === 'erledigt', 'Mailfehler hält den Antrag nicht offen');
    store_assert($mailFailed['completed_at'] !== null, 'Mailfehler dokumentiert den fachlichen Abschlusszeitpunkt');
    store_assert($mailFailed['completed_by'] === 'test-admin', 'Mailfehler dokumentiert den Abschlussakteur');

    persist_completion_mail_result($mysqli, 1, 1, 'OK: retry@example.invalid', 'retry-admin');
    $mailRetried = store_row($mysqli);
    store_assert((int) $mailRetried['mail_sent'] === 1, 'erfolgreicher Mail-Retry aktualisiert den Zustellstatus');
    store_assert($mailRetried['completed_at'] === $mailFailed['completed_at'], 'Mail-Retry bewahrt den ursprünglichen fachlichen Abschlusszeitpunkt');
    store_assert($mailRetried['completed_by'] === 'test-admin', 'Mail-Retry überschreibt den ursprünglichen Abschlussakteur nicht');

    persist_completion_mail_result($mysqli, 2, 1, 'OK: test@example.invalid', 'test-admin');
    $mailSent = store_row($mysqli, 2);
    store_assert((int) $mailSent['mail_sent'] === 1, 'erfolgreiche Mail wird gespeichert');
    store_assert($mailSent['status'] === 'erledigt', 'erfolgreiche Mail schließt den Antrag ab');
    store_assert($mailSent['completed_at'] !== null, 'erfolgreiche Mail setzt Abschlusszeitpunkt');
    store_assert($mailSent['completed_by'] === 'test-admin', 'erfolgreiche Mail setzt Abschlussakteur');

    echo 'Workflow-Store-Tests erfolgreich: ' . $assertions . ' Assertions.' . PHP_EOL;
    echo 'Persistenztest lief ausschließlich gegen eine verbindungsgebundene temporäre Tabelle.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
