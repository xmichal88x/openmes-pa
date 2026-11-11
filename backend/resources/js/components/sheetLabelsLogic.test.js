import { describe, expect, it } from 'vitest';
import {
    hasSheetMap,
    normalizeSheetLabels,
    sameOriginPath,
    sheetLabelSummary,
    sheetLabelsEndpoint,
    sheetMapUrl,
    sheetName,
    shouldShowSheetLabels,
} from './sheetLabelsLogic';

describe('sheetLabelsEndpoint', () => {
    it('builds the read-only proxy URL', () => {
        expect(sheetLabelsEndpoint(42)).toBe('/packaging/labels/alphacam/42');
        expect(sheetLabelsEndpoint('abc')).toBe('/packaging/labels/alphacam/abc');
    });
});

describe('shouldShowSheetLabels', () => {
    it('shows only for production steps with an id', () => {
        expect(shouldShowSheetLabels({ kind: 'production', id: 7 })).toBe(true);
    });

    it('hides for packing, missing kind or missing id', () => {
        expect(shouldShowSheetLabels({ kind: 'packing', id: 7 })).toBe(false);
        expect(shouldShowSheetLabels({ id: 7 })).toBe(false);
        expect(shouldShowSheetLabels({ kind: 'production', id: null })).toBe(false);
        expect(shouldShowSheetLabels(null)).toBe(false);
        expect(shouldShowSheetLabels(undefined)).toBe(false);
    });
});

describe('normalizeSheetLabels', () => {
    it('returns the sheets array when present', () => {
        const sheets = [{ sheet: 'Arkusz A1', labels_count: 6 }];
        expect(normalizeSheetLabels({ sheets })).toEqual(sheets);
    });

    it('returns an empty array for missing or malformed payloads', () => {
        expect(normalizeSheetLabels(null)).toEqual([]);
        expect(normalizeSheetLabels(undefined)).toEqual([]);
        expect(normalizeSheetLabels({})).toEqual([]);
        expect(normalizeSheetLabels({ sheets: null })).toEqual([]);
        expect(normalizeSheetLabels({ sheets: 'nope' })).toEqual([]);
        expect(normalizeSheetLabels([])).toEqual([]);
    });
});

describe('sheetLabelSummary', () => {
    it('combines the sheet name with the label count (Polish plural)', () => {
        expect(sheetLabelSummary({ sheet: 'Arkusz A1', labels_count: 6 })).toBe('Arkusz A1 · 6 etykiet');
        expect(sheetLabelSummary({ sheet: 'Arkusz B1', labels_count: 1 })).toBe('Arkusz B1 · 1 etykieta');
        expect(sheetLabelSummary({ sheet: 'Arkusz B1', labels_count: 2 })).toBe('Arkusz B1 · 2 etykiety');
    });

    it('accepts string counts and falls back gracefully', () => {
        expect(sheetLabelSummary({ sheet: 'Arkusz A1', labels_count: '3' })).toBe('Arkusz A1 · 3 etykiety');
        expect(sheetLabelSummary({})).toBe('— · 0 etykiet');
        expect(sheetLabelSummary(null)).toBe('— · 0 etykiet');
    });

    it('never leaks an internal PDF URL', () => {
        const summary = sheetLabelSummary({
            sheet: 'Arkusz A1',
            labels_count: 6,
            labels_pdf: '/packaging/labels/alphacam/12/pdf?label_batch_id=abc',
        });
        expect(summary).not.toContain('/packaging');
        expect(summary).not.toContain('http');
        expect(summary).toBe('Arkusz A1 · 6 etykiet');
    });
});

describe('hasSheetMap', () => {
    it('is true only for a non-empty string map_pdf', () => {
        expect(hasSheetMap({ map_pdf: '/packaging/labels/alphacam/12/map?label_batch_id=abc' })).toBe(true);
        expect(hasSheetMap({ map_pdf: 'x' })).toBe(true);
    });

    it('is false for null, undefined, empty or non-string map_pdf', () => {
        expect(hasSheetMap({ map_pdf: null })).toBe(false);
        expect(hasSheetMap({ map_pdf: '' })).toBe(false);
        expect(hasSheetMap({})).toBe(false);
        expect(hasSheetMap(null)).toBe(false);
        expect(hasSheetMap(undefined)).toBe(false);
        expect(hasSheetMap({ map_pdf: 42 })).toBe(false);
    });
});

describe('sheetName', () => {
    it('returns the sheet name as a string', () => {
        expect(sheetName({ sheet: 'Arkusz A1' })).toBe('Arkusz A1');
    });

    it('falls back to an em dash when the name is missing', () => {
        expect(sheetName({})).toBe('—');
        expect(sheetName(null)).toBe('—');
        expect(sheetName(undefined)).toBe('—');
        expect(sheetName({ sheet: '' })).toBe('—');
    });
});

describe('sameOriginPath', () => {
    it('keeps relative paths untouched', () => {
        expect(sameOriginPath('/packaging/labels/alphacam/675/map?label_batch_id=abc')).toBe('/packaging/labels/alphacam/675/map?label_batch_id=abc');
    });

    it('strips the origin from an absolute URL', () => {
        expect(sameOriginPath('https://mes.edeseczka.pl/packaging/labels/alphacam/675/map?label_batch_id=abc'))
            .toBe('/packaging/labels/alphacam/675/map?label_batch_id=abc');
        expect(sameOriginPath('http://192.168.100.122/packaging/x?y=1')).toBe('/packaging/x?y=1');
    });

    it('returns empty for empty/non-string input', () => {
        expect(sameOriginPath(null)).toBe('');
        expect(sameOriginPath(undefined)).toBe('');
        expect(sameOriginPath('')).toBe('');
        expect(sameOriginPath(42)).toBe('');
    });
});

describe('sheetMapUrl', () => {
    it('extracts a same-origin path from the row map_pdf', () => {
        expect(sheetMapUrl({ map_pdf: 'https://mes.edeseczka.pl/packaging/labels/alphacam/1/map?label_batch_id=a' }))
            .toBe('/packaging/labels/alphacam/1/map?label_batch_id=a');
        expect(sheetMapUrl({ map_pdf: null })).toBe('');
        expect(sheetMapUrl(null)).toBe('');
    });
});
