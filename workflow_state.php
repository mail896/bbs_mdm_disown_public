<?php

declare(strict_types=1);

const DISOWN_JAMF_STATE_NOT_STARTED = 'not_started';
const DISOWN_JAMF_STATE_COMMAND_ACCEPTED = 'command_accepted';
const DISOWN_JAMF_STATE_PENDING_DEVICE = 'pending_device';
const DISOWN_JAMF_STATE_VERIFIED_UNMANAGED = 'verified_unmanaged';
const DISOWN_JAMF_STATE_FAILED = 'failed';
const DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED = 'manual_intervention_required';

/**
 * @return list<string>
 */
function disown_jamf_states(): array
{
    return [
        DISOWN_JAMF_STATE_NOT_STARTED,
        DISOWN_JAMF_STATE_COMMAND_ACCEPTED,
        DISOWN_JAMF_STATE_PENDING_DEVICE,
        DISOWN_JAMF_STATE_VERIFIED_UNMANAGED,
        DISOWN_JAMF_STATE_FAILED,
        DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED,
    ];
}

function disown_jamf_state_is_valid(string $state): bool
{
    return in_array($state, disown_jamf_states(), true);
}

/**
 * The map describes technical state changes only. Authorization and override
 * requirements are enforced by the action handling code that requests a change.
 *
 * Self-transitions are allowed so a read-only verification attempt can update
 * timestamps and safe response metadata without inventing another state.
 *
 * @return array<string, list<string>>
 */
function disown_jamf_allowed_transitions(): array
{
    return [
        DISOWN_JAMF_STATE_NOT_STARTED => [
            DISOWN_JAMF_STATE_NOT_STARTED,
            DISOWN_JAMF_STATE_COMMAND_ACCEPTED,
            DISOWN_JAMF_STATE_PENDING_DEVICE,
            DISOWN_JAMF_STATE_VERIFIED_UNMANAGED,
            DISOWN_JAMF_STATE_FAILED,
            DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED,
        ],
        DISOWN_JAMF_STATE_COMMAND_ACCEPTED => [
            DISOWN_JAMF_STATE_COMMAND_ACCEPTED,
            DISOWN_JAMF_STATE_PENDING_DEVICE,
            DISOWN_JAMF_STATE_VERIFIED_UNMANAGED,
            DISOWN_JAMF_STATE_FAILED,
            DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED,
        ],
        DISOWN_JAMF_STATE_PENDING_DEVICE => [
            DISOWN_JAMF_STATE_PENDING_DEVICE,
            DISOWN_JAMF_STATE_COMMAND_ACCEPTED,
            DISOWN_JAMF_STATE_VERIFIED_UNMANAGED,
            DISOWN_JAMF_STATE_FAILED,
            DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED,
        ],
        DISOWN_JAMF_STATE_VERIFIED_UNMANAGED => [
            DISOWN_JAMF_STATE_VERIFIED_UNMANAGED,
        ],
        DISOWN_JAMF_STATE_FAILED => [
            DISOWN_JAMF_STATE_FAILED,
            DISOWN_JAMF_STATE_COMMAND_ACCEPTED,
            DISOWN_JAMF_STATE_VERIFIED_UNMANAGED,
            DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED,
        ],
        DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED => [
            DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED,
            DISOWN_JAMF_STATE_COMMAND_ACCEPTED,
            DISOWN_JAMF_STATE_VERIFIED_UNMANAGED,
            DISOWN_JAMF_STATE_FAILED,
        ],
    ];
}

function disown_jamf_can_transition(string $from, string $to): bool
{
    if (!disown_jamf_state_is_valid($from) || !disown_jamf_state_is_valid($to)) {
        return false;
    }

    return in_array($to, disown_jamf_allowed_transitions()[$from], true);
}

function disown_jamf_state_allows_release(string $state): bool
{
    return $state === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED;
}

/** @param array<string, mixed> $row */
function disown_request_allows_asm(array $row): bool
{
    return disown_jamf_state_from_row($row) === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED
        && empty($row['asm_manual_done'])
        && (int) ($row['mail_sent'] ?? 0) === 0
        && (($row['completed_by'] ?? '') !== 'history-import');
}

/** @param array<string, mixed> $observation */
function disown_reenrollment_detected(array $observation): bool
{
    $active = is_array($observation['active'] ?? null) ? $observation['active'] : [];
    return !empty($observation['active_lookup_ok'])
        && !empty($active['found'])
        && array_key_exists('is_managed', $active)
        && $active['is_managed'] === true;
}

/** @param array<string, mixed> $row */
function disown_request_allows_completion_mail(array $row): bool
{
    return disown_jamf_state_from_row($row) === DISOWN_JAMF_STATE_VERIFIED_UNMANAGED
        && !empty($row['asm_manual_done'])
        && (int) ($row['mail_sent'] ?? 0) !== 1
        && (($row['completed_by'] ?? '') !== 'history-import');
}

/**
 * Classify normalized read-only evidence without depending on a particular
 * Jamf response shape. The future API adapter must only set direct_unmanaged
 * for current, exact-device evidence; a Trash snapshot never qualifies.
 *
 * @param array<string, mixed> $evidence
 */
function disown_jamf_state_from_evidence(array $evidence, bool $retryExhausted = false): string
{
    if (!empty($evidence['direct_unmanaged'])) {
        return DISOWN_JAMF_STATE_VERIFIED_UNMANAGED;
    }

    $terminalCommandEvidence = !empty($evidence['command_terminal_success'])
        && array_key_exists('active_found', $evidence)
        && $evidence['active_found'] === false
        && !empty($evidence['trash_found'])
        && !empty($evidence['trash_changed_after_command']);
    if ($terminalCommandEvidence) {
        return DISOWN_JAMF_STATE_VERIFIED_UNMANAGED;
    }

    if (!empty($evidence['command_accepted'])) {
        return $retryExhausted
            ? DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED
            : DISOWN_JAMF_STATE_PENDING_DEVICE;
    }

    return DISOWN_JAMF_STATE_FAILED;
}

/**
 * Resolve old rows conservatively while the application is being migrated.
 * A historical boolean never proves the required Jamf post-condition.
 *
 * @param array<string, mixed> $row
 */
function disown_jamf_state_from_row(array $row): string
{
    $storedState = trim((string) ($row['jamf_unenroll_state'] ?? ''));
    if (disown_jamf_state_is_valid($storedState)) {
        return $storedState;
    }

    if (!empty($row['jamf_unenrolled'])) {
        return DISOWN_JAMF_STATE_MANUAL_INTERVENTION_REQUIRED;
    }

    if (trim((string) ($row['jamf_unenroll_error'] ?? '')) !== '') {
        return DISOWN_JAMF_STATE_FAILED;
    }

    return DISOWN_JAMF_STATE_NOT_STARTED;
}
