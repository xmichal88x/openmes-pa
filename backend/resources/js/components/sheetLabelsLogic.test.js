import { describe, expect, it } from 'vitest';
import {
    hasSheetMap,
    normalizeSheetLabels,
    sheetLabelSummary,
    sheetLabelsEndpoint,
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
