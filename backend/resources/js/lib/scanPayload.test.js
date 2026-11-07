import { describe, it, expect } from 'vitest';
import parseScanPayload, { parseScanPayload as namedParseScanPayload, qaItemsFor } from './scanPayload';

describe('parseScanPayload', () => {
    it('returns a plain Code128 payload unchanged', () => {
        expect(parseScanPayload('LBL-1')).toBe('LBL-1');
    });

    it('reads the id from a QR JSON payload', () => {
        expect(parseScanPayload('{"id":"LBL-1"}')).toBe('LBL-1');
    });

    it('falls back to barcode_payload when id is missing', () => {
        expect(parseScanPayload('{"barcode_payload":"LBL-2"}')).toBe('LBL-2');
    });

    it('prefers id over barcode_payload', () => {
        expect(parseScanPayload('{"id":"LBL-1","barcode_payload":"LBL-2"}')).toBe('LBL-1');
    });

    it('keeps the original text for invalid JSON', () => {
        expect(parseScanPayload('{"id":')).toBe('{"id":');
    });

    it('trims surrounding whitespace', () => {
        expect(parseScanPayload('  LBL-1\n')).toBe('LBL-1');
        expect(parseScanPayload('  {"id":"LBL-1"}  ')).toBe('LBL-1');
    });

    it('stringifies numeric ids', () => {
        expect(parseScanPayload('{"id":42}')).toBe('42');
    });

    it('returns an empty string for nullish input', () => {
        expect(parseScanPayload(null)).toBe('');
        expect(parseScanPayload(undefined)).toBe('');
    });

    it('exports the same function as named and default', () => {
        expect(namedParseScanPayload).toBe(parseScanPayload);
    });
});

describe('qaItemsFor', () => {
    const profile = {
        checklist_json: [
            { id: 'dim', label: 'Wymiar', type: 'bool', required: true },
            { id: 'qty', label: 'Ilość', type: 'number', required: true },
            { id: 'note', label: 'Notatka', type: 'text', required: true },
            { id: 'extra', label: 'Dodatkowe', type: 'bool', required: false },
        ],
    };

    it('sends missing required bools as false', () => {
        expect(qaItemsFor(profile, {})).toContainEqual({ id: 'dim', value: false });
    });

    it('keeps a provided true bool', () => {
        expect(qaItemsFor(profile, { dim: true })).toContainEqual({ id: 'dim', value: true });
    });

    it('omits optional items without a value', () => {
        expect(qaItemsFor(profile, {}).some((item) => item.id === 'extra')).toBe(false);
    });

    it('sends missing required number and text items as an empty string', () => {
        const items = qaItemsFor(profile, {});
        expect(items).toContainEqual({ id: 'qty', value: '' });
        expect(items).toContainEqual({ id: 'note', value: '' });
    });
});
