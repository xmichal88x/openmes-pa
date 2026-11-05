/**
 * Pure mapping of the start-preflight response to the operator UI action.
 *
 * The backend is read-only and degrades to `unknown`/`unavailable` whenever it
 * cannot reach the queue runtime; option B is to warn and let the operator
 * start anyway. Keeping the mapping here makes every state testable without
 * React or fetch.
 */

export const PREFLIGHT_ACTIONS = {
    START: 'start',
    BLOCK: 'block',
    CONFIRM: 'confirm',
    WARN_START: 'warn_start',
};

function normalizeOrders(orders) {
    if (!Array.isArray(orders)) return [];
    return orders
        .filter((order) => order && typeof order === 'object')
        .map((order) => ({
            order_no: String(order.order_no ?? ''),
            wo_ids: Array.isArray(order.wo_ids) ? order.wo_ids.map(Number) : [],
        }))
        .filter((order) => order.order_no !== '');
}

/**
 * @param {null|undefined|{state?: string, n_orders?: number, orders?: Array, target_job?: string|null, is_source?: boolean, message?: string}} preflight
 * @returns {{kind: string, reason?: string, message?: string, nOrders?: number, orders?: Array, targetJob?: string|null}}
 */
export function resolvePreflightAction(preflight) {
    if (!preflight || typeof preflight !== 'object') {
        return { kind: PREFLIGHT_ACTIONS.WARN_START, reason: 'unavailable' };
    }

    const orders = normalizeOrders(preflight.orders);
    const message = typeof preflight.message === 'string' ? preflight.message : '';

    switch (preflight.state) {
        case 'ok':
            return { kind: PREFLIGHT_ACTIONS.START };

        case 'rebuilding':
            return {
                kind: PREFLIGHT_ACTIONS.BLOCK,
                reason: 'rebuilding',
                message,
            };

        case 'merged':
            if (preflight.is_source) {
                return {
                    kind: PREFLIGHT_ACTIONS.BLOCK,
                    reason: 'merged_source',
                    message,
                    nOrders: Number(preflight.n_orders) || orders.length,
                    orders,
                    targetJob: preflight.target_job ?? null,
                };
            }

            return {
                kind: PREFLIGHT_ACTIONS.CONFIRM,
                nOrders: Number(preflight.n_orders) || orders.length,
                orders,
                targetJob: preflight.target_job ?? null,
                message,
            };

        case 'unknown':
        case 'unavailable':
        default:
            return {
                kind: PREFLIGHT_ACTIONS.WARN_START,
                reason: preflight.state || 'unknown',
                message,
            };
    }
}

/** Shape a start payload that only carries the shared-sheet flag when needed. */
export function startPayload(confirmSharedSheet) {
    return confirmSharedSheet ? { confirm_shared_sheet: true } : {};
}

/** Map a raw start error to a translation key (or pass it through unchanged). */
export function startErrorKey(message) {
    const text = String(message ?? '');
    if (/already IN_PROGRESS/i.test(text)) return 'This step is already in progress.';
    return text;
}
