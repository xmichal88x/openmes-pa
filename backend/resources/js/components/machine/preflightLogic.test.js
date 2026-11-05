import { describe, expect, it } from 'vitest';
import { PREFLIGHT_ACTIONS, resolvePreflightAction, startErrorKey, startPayload } from './preflightLogic';

const mergedTarget = {
    state: 'merged',
    n_orders: 3,
    orders: [
        { order_no: 'WO-1', wo_ids: [1] },
        { order_no: 'WO-2', wo_ids: [2] },
        { order_no: 'WO-3', wo_ids: [3] },
    ],
    target_job: 'JOB-42',
    is_source: false,
    message: '',
};

describe('resolvePreflightAction', () => {
    it('starts directly when the queue is ok', () => {
        expect(resolvePreflightAction({ state: 'ok' })).toEqual({ kind: PREFLIGHT_ACTIONS.START });
    });

    it('blocks when the queue is rebuilding', () => {
        const result = resolvePreflightAction({ state: 'rebuilding', message: 'Rebuilding nesting.' });

        expect(result.kind).toBe(PREFLIGHT_ACTIONS.BLOCK);
        expect(result.reason).toBe('rebuilding');
        expect(result.message).toBe('Rebuilding nesting.');
    });

    it('blocks a merged source order and points at the shared job', () => {
        const result = resolvePreflightAction({ ...mergedTarget, is_source: true });

        expect(result.kind).toBe(PREFLIGHT_ACTIONS.BLOCK);
        expect(result.reason).toBe('merged_source');
        expect(result.targetJob).toBe('JOB-42');
        expect(result.nOrders).toBe(3);
        expect(result.orders.map((o) => o.order_no)).toEqual(['WO-1', 'WO-2', 'WO-3']);
    });

    it('asks for confirmation on a merged target order', () => {
        const result = resolvePreflightAction(mergedTarget);

        expect(result.kind).toBe(PREFLIGHT_ACTIONS.CONFIRM);
        expect(result.nOrders).toBe(3);
        expect(result.targetJob).toBe('JOB-42');
        expect(result.orders).toHaveLength(3);
    });

    it('warns and starts when the state is unknown', () => {
        expect(resolvePreflightAction({ state: 'unknown' }).kind).toBe(PREFLIGHT_ACTIONS.WARN_START);
    });

    it('warns and starts when the state is unavailable', () => {
        expect(resolvePreflightAction({ state: 'unavailable' }).kind).toBe(PREFLIGHT_ACTIONS.WARN_START);
    });

    it('warns and starts when the preflight request itself failed', () => {
        expect(resolvePreflightAction(null).kind).toBe(PREFLIGHT_ACTIONS.WARN_START);
    });
});

describe('startPayload', () => {
    it('omits the flag unless the operator confirmed the shared sheet', () => {
        expect(startPayload(false)).toEqual({});
        expect(startPayload(true)).toEqual({ confirm_shared_sheet: true });
    });
});

describe('startErrorKey', () => {
    it('maps an already-in-progress error to the friendly translation key', () => {
        expect(startErrorKey('Step is already IN_PROGRESS')).toBe('This step is already in progress.');
    });

    it('passes other errors through unchanged', () => {
        expect(startErrorKey('some other error')).toBe('some other error');
    });

    it('treats missing messages as empty', () => {
        expect(startErrorKey(null)).toBe('');
        expect(startErrorKey(undefined)).toBe('');
    });
});
