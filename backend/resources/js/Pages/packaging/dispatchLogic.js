export const SCAN_KIND = {
    BOX: 'box',
    PALLET: 'pallet',
    UNKNOWN: 'unknown',
};

export function parseScanCode(raw) {
    const code = String(raw ?? '').trim();
    if (code === '') return { kind: SCAN_KIND.UNKNOWN, code: '' };
    if (/^BOX-/i.test(code)) return { kind: SCAN_KIND.BOX, code };
    if (/^PAL-/i.test(code)) return { kind: SCAN_KIND.PALLET, code };
    return { kind: SCAN_KIND.UNKNOWN, code };
}

export function emptySelection() {
    return { boxes: [], pallets: [] };
}

export function boxState(box) {
    const status = box?.status ?? 'open';
    const parts = Number(box?.part_count ?? 0);
    return {
        status,
        parts,
        open: status === 'open',
        closed: status === 'closed',
        voided: status === 'void',
        canClose: status === 'open' && parts > 0,
        canReopen: status === 'closed',
        canAssignPallet: status === 'closed' && !box?.pallet_id,
        canUnassignPallet: Boolean(box?.pallet_id),
        hasLabel: Boolean(box?.label_pdf || box?.label_zpl),
    };
}

export function findScannedEntity(raw, { boxes = [], pallets = [] } = {}) {
    const parsed = parseScanCode(raw);
    if (parsed.code === '') return { kind: SCAN_KIND.UNKNOWN, code: '', entity: null };

    const upper = parsed.code.toUpperCase();
    const box = boxes.find((b) => String(b.box_id ?? '').toUpperCase() === upper)
        ?? boxes.find((b) => String(b.id) === parsed.code)
        ?? boxes.find((b) => String(b.box_no) === parsed.code);
    if (box) return { kind: SCAN_KIND.BOX, code: parsed.code, entity: box };

    const pallet = pallets.find((p) => String(p.pallet_no ?? '').toUpperCase() === upper)
        ?? pallets.find((p) => String(p.id) === parsed.code);
    if (pallet) return { kind: SCAN_KIND.PALLET, code: parsed.code, entity: pallet };

    return { kind: parsed.kind, code: parsed.code, entity: null };
}

export function addSelection(selection, kind, ref) {
    const current = selection ?? emptySelection();
    if (!ref) return { selection: current, error: 'empty' };

    if (kind === SCAN_KIND.BOX && current.pallets.length > 0) {
        return { selection: current, error: 'mixed' };
    }
    if (kind === SCAN_KIND.PALLET && current.boxes.length > 0) {
        return { selection: current, error: 'mixed' };
    }

    const key = kind === SCAN_KIND.BOX ? 'boxes' : 'pallets';
    const own = current[key] ?? [];
    if (own.includes(ref)) return { selection: current, error: 'duplicate' };

    return { selection: { ...current, [key]: [...own, ref] }, error: null };
}

export function removeSelection(selection, kind, ref) {
    const current = selection ?? emptySelection();
    const key = kind === SCAN_KIND.BOX ? 'boxes' : 'pallets';
    return { ...current, [key]: (current[key] ?? []).filter((value) => value !== ref) };
}

export function validateDispatchSelection(selection, { boxes = [], pallets = [] } = {}) {
    const current = selection ?? emptySelection();
    const errors = [];

    if (current.boxes.length > 0 && current.pallets.length > 0) {
        errors.push({ code: 'mixed' });
    }

    if (current.boxes.length > 0) {
        const closed = boxes.filter((b) => b.status === 'closed');
        const missing = closed.filter((b) => !current.boxes.includes(b.box_id));
        if (missing.length > 0) errors.push({ code: 'missing_boxes', boxes: missing.map((b) => b.box_id) });

        const openWithParts = boxes.filter((b) => b.status === 'open' && Number(b.part_count ?? 0) > 0);
        if (openWithParts.length > 0) errors.push({ code: 'open_boxes', boxes: openWithParts.map((b) => b.box_id) });

        const onPallet = boxes.filter((b) => current.boxes.includes(b.box_id) && b.pallet_id);
        if (onPallet.length > 0) errors.push({ code: 'boxes_on_pallets', boxes: onPallet.map((b) => b.box_id) });
    }

    if (current.pallets.length > 0) {
        const selectedPallets = pallets.filter((p) => current.pallets.includes(p.pallet_no));
        const notClosed = selectedPallets.filter((p) => p.status !== 'closed');
        if (notClosed.length > 0) errors.push({ code: 'pallets_not_closed', pallets: notClosed.map((p) => p.pallet_no) });

        const notPassed = selectedPallets.filter((p) => !palletQualityPassed(p));
        if (notPassed.length > 0) errors.push({ code: 'pallets_not_passed', pallets: notPassed.map((p) => p.pallet_no) });

        const selectedIds = selectedPallets.map((p) => p.id);
        const offPallet = boxes.filter((b) => !b.pallet_id || !selectedIds.includes(b.pallet_id));
        if (offPallet.length > 0) errors.push({ code: 'boxes_off_pallets', boxes: offPallet.map((b) => b.box_id) });
    }

    return errors;
}

export function buildDispatchPayload({ workOrderId, selection, weight, carrier, reason, qcOverride, idempotencyKey }) {
    const current = selection ?? emptySelection();
    const payload = {
        work_order_id: Number(workOrderId),
        idempotency_key: idempotencyKey,
    };

    const boxesOnly = current.boxes.length > 0 && current.pallets.length === 0;

    if (current.boxes.length > 0) payload.scanned_boxes = [...current.boxes];
    if (current.pallets.length > 0) payload.scanned_pallets = [...current.pallets];
    if (boxesOnly && qcOverride) payload.qc_override = true;

    if (weight != null && String(weight).trim() !== '') {
        payload.weight_kg = Number(String(weight).replace(',', '.'));
    }
    if (carrier != null && String(carrier).trim() !== '') payload.carrier = String(carrier).trim();
    if (reason != null && String(reason).trim() !== '') payload.reason = String(reason).trim();

    return payload;
}

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

function uuidFromBytes(bytes) {
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/**
 * R07 dispatch idempotency key: the PA contract expects a UUID v4.
 *
 * crypto.randomUUID() is used when the browser exposes it; older mobile
 * webviews fall back to getRandomValues() and finally to Math.random().
 */
export function generateUuidV4(cryptoApi = globalThis.crypto) {
    if (typeof cryptoApi?.randomUUID === 'function') {
        const value = cryptoApi.randomUUID();
        if (UUID_V4.test(value)) return value.toLowerCase();
    }

    if (typeof cryptoApi?.getRandomValues === 'function') {
        const bytes = new Uint8Array(16);
        cryptoApi.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        return uuidFromBytes(bytes);
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
        const random = (Math.random() * 16) | 0;
        const value = char === 'x' ? random : (random & 0x3) | 0x8;
        return value.toString(16);
    });
}

export function makeIdempotencyKey() {
    return generateUuidV4();
}

export function palletQualityPassed(pallet) {
    return pallet?.quality_status === 'pass';
}

export function palletQualityPending(pallet) {
    return (pallet?.quality_status ?? 'pending') === 'pending';
}

export function buildPalletQualityPayload(status, reason) {
    const payload = { status };
    const text = String(reason ?? '').trim();
    if (text !== '') payload.reason = text;
    return payload;
}

export function palletDispatchable(pallet) {
    return pallet?.status === 'closed' && palletQualityPassed(pallet);
}

export function groupBoxesByWorkOrder(boxes = []) {
    const groups = new Map();
    for (const box of boxes) {
        const key = String(box.work_order_id ?? '');
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(box);
    }
    return groups;
}

export function groupPalletsByWorkOrder(pallets = []) {
    const groups = new Map();
    for (const pallet of pallets) {
        const key = String(pallet.work_order_id ?? '');
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(pallet);
    }
    return groups;
}

export function dispatchStatusTone(status) {
    switch (status) {
        case 'done': return 'done';
        case 'processing': return 'running';
        case 'failed': return 'blocked';
        case 'pending': return 'pending';
        case 'skipped': return 'downtime';
        case 'cancelled': return 'blocked';
        default: return 'pending';
    }
}
