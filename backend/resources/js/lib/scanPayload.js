/**
 * Accept both part-label formats: QR JSON (use `id`, fall back to
 * `barcode_payload`) and plain Code128 (`label_id`). Anything unparseable is
 * sent as-is so the server can answer with a clear 404/409.
 */
export function parseScanPayload(raw) {
    const value = String(raw ?? '').trim();
    if (!value.startsWith('{')) return value;

    try {
        const parsed = JSON.parse(value);
        if (parsed && typeof parsed === 'object') {
            if (parsed.id != null) return String(parsed.id);
            if (parsed.barcode_payload != null) return String(parsed.barcode_payload);
        }
    } catch {
        return value;
    }

    return value;
}

export default parseScanPayload;
