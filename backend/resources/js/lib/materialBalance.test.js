import { describe, expect, it } from 'vitest';
import { balanceStateOf } from './materialBalance';

describe('balanceStateOf', () => {
    it('is null for a declared balance', () => {
        expect(balanceStateOf({ balance_known: true, balance_state: 'ok', available_qty: 5 })).toBe(null);
    });

    it('is null when the flag is absent, as in older payloads', () => {
        expect(balanceStateOf({ balance_state: 'ok' })).toBe(null);
        expect(balanceStateOf(null)).toBe(null);
        expect(balanceStateOf(undefined)).toBe(null);
    });

    it('carries the provenance of an undeclared balance', () => {
        expect(
            balanceStateOf({
                balance_known: false,
                balance_state: 'stale',
                balance_source: 'manual:2026-05',
                balance_as_of: '2026-05-01T00:00:00+02:00',
                balance_owner: 'store-1',
            }),
        ).toEqual({
            state: 'stale',
            source: 'manual:2026-05',
            asOf: '2026-05-01T00:00:00+02:00',
            owner: 'store-1',
        });
    });

    it('defaults the state to unknown and the rest to null', () => {
        expect(balanceStateOf({ balance_known: false })).toEqual({
            state: 'unknown',
            source: null,
            asOf: null,
            owner: null,
        });
    });
});
