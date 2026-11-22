import { describe, expect, it } from 'vitest';
import { noticeFrom } from './notice';

describe('noticeFrom', () => {
    it('shows every quantity warning, and not the message they were appended to', () => {
        const notice = noticeFrom({
            message: 'Unit SN-1 released. Quantity stays the declared figure. The machine counter records it.',
            warnings: [
                { code: 'produced_qty_declared_unchanged', message: 'Quantity stays the declared figure.' },
                { code: 'produced_qty_machine_counter', message: 'The machine counter records it.' },
            ],
        });

        expect(notice).toEqual({
            severity: 'warning',
            lines: ['Quantity stays the declared figure.', 'The machine counter records it.'],
        });
    });

    it('shows a plain answer message as a confirmation', () => {
        expect(noticeFrom({ message: 'Unit SN-1 released.', warnings: [] })).toEqual({
            severity: 'info',
            lines: ['Unit SN-1 released.'],
        });
    });

    it('says nothing when the answer carries neither warnings nor a message', () => {
        expect(noticeFrom({ warnings: [] })).toBeNull();
        expect(noticeFrom({ message: '   ' })).toBeNull();
        expect(noticeFrom({})).toBeNull();
        expect(noticeFrom(null)).toBeNull();
        expect(noticeFrom(undefined)).toBeNull();
    });

    it('skips warnings without a usable message', () => {
        expect(noticeFrom({ warnings: [{ code: 'x' }, { code: 'y', message: '  ' }] })).toBeNull();
        expect(noticeFrom({ warnings: [null, 'Plain note'] })).toEqual({ severity: 'warning', lines: ['Plain note'] });
    });
});
