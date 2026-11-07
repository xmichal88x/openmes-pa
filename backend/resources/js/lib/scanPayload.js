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

/**
 * Build the QA item list sent to the server from a profile checklist and the
 * values collected in the modal. Missing required items are sent explicitly
 * (a `bool` as `false`, everything else as `''`) so an unticked checkbox fails
 * QA instead of being reported as a missing field.
 */
export function qaItemsFor(profile, values = {}) {
    const result = [];
    const collected = values ?? {};

    for (const item of profile?.checklist_json ?? []) {
        if (!item || item.id == null) continue;

        if (Object.prototype.hasOwnProperty.call(collected, item.id)) {
            result.push({ id: item.id, value: collected[item.id] });
        } else if (item.required) {
            result.push({ id: item.id, value: item.type === 'bool' ? false : '' });
        }
    }

    return result;
}

export default parseScanPayload;
