/**
 * Pure helpers for the AlphaCAM sheet-labels menu (F3).
 *
 * The MES only lists and prints the PDFs that PA already generated for a batch;
 * nothing here regenerates labels or exposes internal storage URLs.
 */

export function sheetLabelsEndpoint(stepId) {
    return `/packaging/labels/alphacam/${stepId}`;
}

export function shouldShowSheetLabels(step) {
    return step?.kind === 'production' && step?.id != null;
}

export function normalizeSheetLabels(payload) {
    return Array.isArray(payload?.sheets) ? payload.sheets : [];
}

function labelWord(count) {
    const abs = Math.abs(count);
    if (abs === 1) return 'etykieta';
    const mod10 = abs % 10;
    const mod100 = abs % 100;
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'etykiety';
    return 'etykiet';
}

export function sheetLabelSummary(row) {
    const sheet = row?.sheet ? String(row.sheet) : '—';
    const parsed = Number(row?.labels_count);
    const count = Number.isFinite(parsed) ? Math.max(0, parsed) : 0;
    return `${sheet} · ${count} ${labelWord(count)}`;
}

export function sheetName(row) {
    return row?.sheet ? String(row.sheet) : '—';
}

export function hasSheetMap(row) {
    return typeof row?.map_pdf === 'string' && row.map_pdf !== '';
}
