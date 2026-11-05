export const ACTIVE_STATUSES = ['pending', 'processing'];

const STATUS_TONES = {
    pending: 'pending',
    processing: 'running',
    done: 'done',
    failed: 'blocked',
    cancelled: 'blocked',
};

const REASON_KEYS = {
    feature_disabled: 'Feature disabled',
    not_cnc: 'Not a CNC step',
    step_not_in_progress: 'Step is not in progress',
    line_not_allowed: 'Line not allowed',
    line_mismatch: 'Workstation is on another line',
    forbidden: 'No permission',
};

const TYPE_KEYS = {
    load_nc: 'Load NC',
    manual_m30: 'Finish cut (M30)',
    generate_labels: 'Generate labels',
};

const STEP_STATUS_KEYS = {
    PENDING: 'Pending',
    READY: 'Ready',
    IN_PROGRESS: 'In progress',
    DONE: 'Done',
    SKIPPED: 'Skipped',
};

const OUTCOME_KEYS = {
    ok: 'OK',
    already_loaded: 'Already loaded',
    already_exists: 'Already exists',
    skipped: 'Skipped',
    duplicate: 'Duplicate',
};

export const ERROR_KEYS = {
    cycle_active_unconfirmed:
        'The program was not confirmed as loaded. Check that the correct program is on the machine, then try again.',
    cycle_active_other_sheet:
        'A program for a different sheet is loaded on the machine. This sheet was not loaded.',
    state_read_failed: 'Could not read the machine state. Try again in a moment.',
};

const MANUAL_M30 = 'manual_m30';

export function isActiveCommand(command) {
    return ACTIVE_STATUSES.includes(command?.status);
}

export function hasActive(commands) {
    return Array.isArray(commands) && commands.some(isActiveCommand);
}

export function latestActive(commands) {
    if (!Array.isArray(commands)) return null;
    return commands.find(isActiveCommand) ?? null;
}

export function statusTone(status) {
    return STATUS_TONES[status] ?? 'pending';
}

export function capabilityReasonKey(reason) {
    return REASON_KEYS[reason] ?? null;
}

export function formatCommandType(type) {
    return TYPE_KEYS[type] ?? type ?? '';
}

export function stepStatusKey(status) {
    const raw = status == null ? '' : String(status);
    return STEP_STATUS_KEYS[raw.toUpperCase()] ?? raw;
}

export function outcomeKey(outcome) {
    if (outcome == null) return '';
    return OUTCOME_KEYS[outcome] ?? String(outcome);
}

/**
 * Operator-facing message for a gateway/MES `last_error`. Known codes become
 * translation keys; unknown strings are returned trimmed so no error is hidden.
 * Missing, whitespace-only and non-string values yield '' (never `[object Object]`).
 */
export function errorMessageKey(error) {
    if (typeof error !== 'string') return '';
    const raw = error.trim();
    if (raw === '') return '';
    return ERROR_KEYS[raw] ?? raw;
}

/**
 * What the action panel may render at all. A CNC step shows the panel while it
 * offers an action or has history (so past commands stay reachable).
 */
export function shouldShowPanel(caps, recent = [], activeCommand = null) {
    if (!caps?.is_cnc) return false;

    return !!caps.can_load
        || !!caps.can_m30
        || !!caps.can_labels
        || (Array.isArray(recent) && recent.length > 0)
        || !!activeCommand;
}

/**
 * Body for POST /machine-commands (the idempotency key is added by the caller).
 * `force` belongs to manual_m30 only; the service rejects it elsewhere.
 */
export function buildCommandPayload(type, { reason = '', force = false, canM30 = false } = {}) {
    const payload = { type };

    if (type === MANUAL_M30) {
        payload.reason = String(reason ?? '').trim();

        if (canM30) {
            payload.force = !!force;
        }
    }

    return payload;
}

export function newIdempotencyKey(cryptoApi = globalThis.crypto) {
    if (typeof cryptoApi?.randomUUID === 'function') {
        return cryptoApi.randomUUID();
    }

    const bytes = new Uint8Array(16);
    if (typeof cryptoApi?.getRandomValues === 'function') {
        cryptoApi.getRandomValues(bytes);
    } else {
        for (let i = 0; i < bytes.length; i += 1) bytes[i] = Math.floor(Math.random() * 256);
    }

    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
