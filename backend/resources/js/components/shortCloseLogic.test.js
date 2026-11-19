import { describe, expect, it } from 'vitest';
import {
    canCloseShort,
    hasSupervisorRole,
    isShortClosed,
    shortCloseErrorMessage,
    shortClosePayload,
    shortageQty,
} from './shortCloseLogic';

const SUPERVISOR = ['Supervisor'];

const openShortage = {
    id: 7,
    status: 'IN_PROGRESS',
    planned_qty: '100.00',
    produced_qty: '94.00',
    short_closed_at: null,
};

describe('shortageQty', () => {
    it('prefers the value reported by the API', () => {
        expect(shortageQty({ planned_qty: 100, produced_qty: 94, shortage_qty: '6.00' })).toBe(6);
    });

    it('falls back to planned minus produced, never below zero', () => {
        expect(shortageQty({ planned_qty: '100.00', produced_qty: '94.50' })).toBe(5.5);
        expect(shortageQty({ planned_qty: 10, produced_qty: 12 })).toBe(0);
        expect(shortageQty(null)).toBe(0);
    });
});

describe('isShortClosed', () => {
    it('is true only once the decision is recorded', () => {
        expect(isShortClosed({ short_closed_at: '2026-11-19T09:00:00+00:00' })).toBe(true);
        expect(isShortClosed(openShortage)).toBe(false);
        expect(isShortClosed(null)).toBe(false);
    });
});

describe('hasSupervisorRole', () => {
    it('accepts Supervisor and Admin only', () => {
        expect(hasSupervisorRole(['Supervisor'])).toBe(true);
        expect(hasSupervisorRole(['Admin', 'Supervisor'])).toBe(true);
        expect(hasSupervisorRole(['Operator'])).toBe(false);
        expect(hasSupervisorRole([])).toBe(false);
        expect(hasSupervisorRole(undefined)).toBe(false);
    });
});

describe('canCloseShort', () => {
    it('is true for a supervisor with units missing', () => {
        expect(canCloseShort(openShortage, SUPERVISOR)).toBe(true);
        expect(canCloseShort(openShortage, ['Admin'])).toBe(true);
    });

    it('is false when nothing is missing', () => {
        expect(canCloseShort({ ...openShortage, shortage_qty: 0, produced_qty: '100.00' }, SUPERVISOR)).toBe(false);
    });

    it('is false when the order is already closed short', () => {
        expect(canCloseShort({ ...openShortage, short_closed_at: '2026-11-19T09:00:00+00:00' }, SUPERVISOR)).toBe(false);
    });

    it('is false for an operator, a cancelled or a rejected order', () => {
        expect(canCloseShort(openShortage, ['Operator'])).toBe(false);
        expect(canCloseShort(openShortage)).toBe(false);
        expect(canCloseShort({ ...openShortage, status: 'CANCELLED' }, SUPERVISOR)).toBe(false);
        expect(canCloseShort({ ...openShortage, status: 'REJECTED' }, SUPERVISOR)).toBe(false);
    });
});

describe('shortClosePayload', () => {
    it('rejects a reason shorter than three characters', () => {
        expect(shortClosePayload('ab')).toBeNull();
        expect(shortClosePayload('  ab  ')).toBeNull();
        expect(shortClosePayload('')).toBeNull();
        expect(shortClosePayload(null)).toBeNull();
    });

    it('trims and returns the reason when it is long enough', () => {
        expect(shortClosePayload('  trzy sztuki uszkodzone  ')).toEqual({ reason: 'trzy sztuki uszkodzone' });
        expect(shortClosePayload('abc')).toEqual({ reason: 'abc' });
    });
});

describe('shortCloseErrorMessage', () => {
    it('returns the API message', () => {
        expect(shortCloseErrorMessage(422, { message: 'Issue blokujący produkcję (short_close_blocked_issue).' }))
            .toBe('Issue blokujący produkcję (short_close_blocked_issue).');
        expect(shortCloseErrorMessage(422, { message: '  Nothing is missing (short_close_not_needed).  ' }))
            .toBe('Nothing is missing (short_close_not_needed).');
    });

    it('falls back to the reason field error, then to a status-based message', () => {
        expect(shortCloseErrorMessage(422, { errors: { reason: ['The reason field is required.'] } }))
            .toBe('The reason field is required.');
        expect(shortCloseErrorMessage(500, {})).toBe('Short close failed (500)');
        expect(shortCloseErrorMessage(null, null)).toBe('Short close failed');
    });
});
