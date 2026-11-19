/**
 * Pure helpers for the supervisor's short-close decision (P1/M-5).
 *
 * The packing gate refuses to close a step below plan until a supervisor
 * decides what to do with the missing units; these helpers only shape that
 * decision — who may take it, what the payload looks like and what a failed
 * request says. The backend (POST /api/v1/work-orders/{id}/close-short) stays
 * the single authority on whether the order can actually be closed short.
 */

/** Roles allowed to take the decision — mirrors the route's `role:` gate. */
const SUPERVISOR_ROLES = ['Supervisor', 'Admin'];

/** Same floor as CloseShortWorkOrderRequest (`reason` min:3). */
export const SHORT_CLOSE_MIN_REASON = 3;

/** States where a short close makes no sense (a cancelled order is not a shortage). */
const SHORT_CLOSE_BLOCKED_STATUSES = ['CANCELLED', 'REJECTED'];

function toQty(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
}

/** Units still missing to meet the plan, rounded like the backend's two decimals. */
export function shortageQty(wo) {
    const reported = Number(wo?.shortage_qty);
    if (Number.isFinite(reported)) return Math.max(0, reported);

    return Math.max(0, Math.round((toQty(wo?.planned_qty) - toQty(wo?.produced_qty)) * 100) / 100);
}

/** Whether a supervisor already closed this order with a documented shortage. */
export function isShortClosed(wo) {
    return Boolean(wo?.short_closed_at);
}

export function hasSupervisorRole(roles) {
    return Array.isArray(roles) && roles.some((role) => SUPERVISOR_ROLES.includes(role));
}

/**
 * Whether the short-close action is offered: a supervisor/admin, an order that
 * is not closed that way yet nor cancelled/rejected, and something missing.
 */
export function canCloseShort(wo, roles = []) {
    if (!hasSupervisorRole(roles)) return false;
    if (isShortClosed(wo)) return false;
    if (SHORT_CLOSE_BLOCKED_STATUSES.includes(wo?.status)) return false;

    return shortageQty(wo) > 0;
}

/** `{ reason }` for the API, or null when the reason is too short to record. */
export function shortClosePayload(reason) {
    const trimmed = typeof reason === 'string' ? reason.trim() : '';
    if (trimmed.length < SHORT_CLOSE_MIN_REASON) return null;

    return { reason: trimmed };
}

/**
 * Message for a failed request: the API's own `message` (or the reason field
 * error from a 422 validation), never an empty string.
 */
export function shortCloseErrorMessage(status, body) {
    const message = typeof body?.message === 'string' ? body.message.trim() : '';
    if (message !== '') return message;

    const fieldError = body?.errors?.reason;
    if (Array.isArray(fieldError) && typeof fieldError[0] === 'string' && fieldError[0].trim() !== '') {
        return fieldError[0].trim();
    }

    return status ? `Short close failed (${status})` : 'Short close failed';
}
