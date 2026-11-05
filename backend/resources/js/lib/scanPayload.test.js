import { describe, it, expect } from 'vitest';
import parseScanPayload, { parseScanPayload as namedParseScanPayload } from './scanPayload';

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
