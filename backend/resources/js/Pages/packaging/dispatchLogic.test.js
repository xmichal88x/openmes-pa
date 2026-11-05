import { describe, expect, it } from 'vitest';
import {
    addSelection,
    boxState,
    buildDispatchPayload,
    buildPalletQualityPayload,
    dispatchStatusTone,
    emptySelection,
    findScannedEntity,
    generateUuidV4,
    groupBoxesByWorkOrder,
    makeIdempotencyKey,
    palletDispatchable,
    palletQualityPassed,
    palletQualityPending,
    parseScanCode,
    removeSelection,
    validateDispatchSelection,
} from './dispatchLogic';

const boxes = [
    { id: 1, box_id: 'BOX-WO-1-1', box_no: 1, work_order_id: 7, status: 'closed', part_count: 4, pallet_id: null },
    { id: 2, box_id: 'BOX-WO-1-2', box_no: 2, work_order_id: 7, status: 'open', part_count: 2, pallet_id: null },
    { id: 3, box_id: 'BOX-WO-1-3', box_no: 3, work_order_id: 7, status: 'closed', part_count: 3, pallet_id: 9 },
];

const pallets = [
    { id: 9, pallet_no: 'PAL-000009', work_order_id: 7, status: 'closed', quality_status: 'pass' },
];

describe('parseScanCode', () => {
    it('classifies box and pallet labels', () => {
        expect(parseScanCode('BOX-WO-1-1')).toEqual({ kind: 'box', code: 'BOX-WO-1-1' });
        expect(parseScanCode(' pal-000009 ')).toEqual({ kind: 'pallet', code: 'pal-000009' });
    });

    it('leaves numeric and empty codes unresolved', () => {
        expect(parseScanCode('12')).toEqual({ kind: 'unknown', code: '12' });
        expect(parseScanCode('')).toEqual({ kind: 'unknown', code: '' });
    });
});

describe('findScannedEntity', () => {
    it('resolves a box by label and a pallet by number', () => {
        expect(findScannedEntity('BOX-WO-1-2', { boxes, pallets }).entity.id).toBe(2);
        expect(findScannedEntity('PAL-000009', { boxes, pallets }).entity.id).toBe(9);
    });

    it('resolves numeric ids against both lists', () => {
        expect(findScannedEntity('3', { boxes, pallets })).toMatchObject({ kind: 'box' });
        expect(findScannedEntity('9', { boxes, pallets })).toMatchObject({ kind: 'pallet' });
    });

    it('reports an unknown code instead of guessing', () => {
        expect(findScannedEntity('NOPE', { boxes, pallets })).toMatchObject({ kind: 'unknown', entity: null });
    });
});

describe('boxState', () => {
    it('allows closing only a non-empty open box', () => {
        expect(boxState(boxes[0]).canClose).toBe(false);
        expect(boxState(boxes[1]).canClose).toBe(true);
    });

    it('allows a pallet assignment only for a closed unassigned box', () => {
        expect(boxState(boxes[0]).canAssignPallet).toBe(true);
        expect(boxState(boxes[2]).canAssignPallet).toBe(false);
        expect(boxState(boxes[2]).canUnassignPallet).toBe(true);
    });

    it('marks a closed box reopenable and exposes a label only when the API sends one', () => {
        expect(boxState(boxes[0]).canReopen).toBe(true);
        expect(boxState(boxes[0]).hasLabel).toBe(false);
        expect(boxState({ ...boxes[0], label_pdf: '/packaging/labels/box/1.pdf' }).hasLabel).toBe(true);
    });
});

describe('dispatch selection', () => {
    it('adds boxes and de-duplicates them', () => {
        const first = addSelection(emptySelection(), 'box', 'BOX-WO-1-1');
        expect(first.error).toBeNull();
        expect(first.selection.boxes).toEqual(['BOX-WO-1-1']);
        expect(addSelection(first.selection, 'box', 'BOX-WO-1-1').error).toBe('duplicate');
    });

    it('never mixes boxes and pallets', () => {
        const withBox = addSelection(emptySelection(), 'box', 'BOX-WO-1-1').selection;
        expect(addSelection(withBox, 'pallet', 'PAL-000009').error).toBe('mixed');
    });

    it('removes a scanned ref', () => {
        const selected = { boxes: ['BOX-WO-1-1', 'BOX-WO-1-3'], pallets: [] };
        expect(removeSelection(selected, 'box', 'BOX-WO-1-1')).toEqual({ boxes: ['BOX-WO-1-3'], pallets: [] });
    });

    it('flags boxes that are on a pallet, missing boxes and open boxes', () => {
        const errors = validateDispatchSelection({ boxes: ['BOX-WO-1-1'], pallets: [] }, { boxes, pallets });
        expect(errors.map((e) => e.code)).toEqual(['missing_boxes', 'open_boxes']);
        expect(errors[0].boxes).toEqual(['BOX-WO-1-3']);
    });

    it('accepts every closed loose box', () => {
        expect(validateDispatchSelection({ boxes: ['BOX-WO-1-1'], pallets: [] }, { boxes: [boxes[0]], pallets })).toEqual([]);
    });

    it('requires every box to sit on a scanned closed pallet', () => {
        expect(validateDispatchSelection({ boxes: [], pallets: ['PAL-000009'] }, { boxes: [boxes[2]], pallets })).toEqual([]);
        const errors = validateDispatchSelection({ boxes: [], pallets: ['PAL-000009'] }, { boxes: [boxes[0]], pallets });
        expect(errors.map((e) => e.code)).toEqual(['boxes_off_pallets']);
    });

    it('blocks a pallet that has not passed quality control', () => {
        const pending = { ...pallets[0], quality_status: 'pending' };
        const failed = { ...pallets[0], quality_status: 'fail' };

        expect(palletQualityPassed(pallets[0])).toBe(true);
        expect(palletDispatchable(pallets[0])).toBe(true);
        expect(palletDispatchable(pending)).toBe(false);
        expect(palletDispatchable(failed)).toBe(false);

        expect(validateDispatchSelection({ boxes: [], pallets: [pending.pallet_no] }, { boxes: [], pallets: [pending] }))
            .toEqual([{ code: 'pallets_not_passed', pallets: [pending.pallet_no] }]);
    });

    it('offers a quality decision only while the pallet is pending', () => {
        expect(palletQualityPending({ quality_status: 'pending' })).toBe(true);
        expect(palletQualityPending({})).toBe(true);
        expect(palletQualityPending({ quality_status: 'pass' })).toBe(false);
        expect(palletQualityPending({ quality_status: 'fail' })).toBe(false);
    });

    it('builds the quality payload with a trimmed reason only when given', () => {
        expect(buildPalletQualityPayload('pass')).toEqual({ status: 'pass' });
        expect(buildPalletQualityPayload('fail', '  cracked edge  ')).toEqual({
            status: 'fail',
            reason: 'cracked edge',
        });
        expect(buildPalletQualityPayload('fail', '   ')).toEqual({ status: 'fail' });
    });
});

describe('dispatch payload', () => {
    it('sends exactly one side of the XOR and normalises the weight', () => {
        const payload = buildDispatchPayload({
            workOrderId: 7,
            selection: { boxes: ['BOX-WO-1-1'], pallets: [] },
            weight: '12,5',
            carrier: ' DHL ',
            reason: ' ',
            idempotencyKey: 'k1',
        });

        expect(payload).toEqual({
            work_order_id: 7,
            idempotency_key: 'k1',
            scanned_boxes: ['BOX-WO-1-1'],
            weight_kg: 12.5,
            carrier: 'DHL',
        });
    });

    it('marks direct box dispatch with an explicit qc_override', () => {
        const boxesOnly = buildDispatchPayload({
            workOrderId: 7,
            selection: { boxes: ['BOX-WO-1-1'], pallets: [] },
            reason: 'customer pickup',
            qcOverride: true,
            idempotencyKey: 'k1',
        });

        expect(boxesOnly.qc_override).toBe(true);
        expect(boxesOnly.reason).toBe('customer pickup');

        const pallets = buildDispatchPayload({
            workOrderId: 7,
            selection: { boxes: [], pallets: ['PAL-000001'] },
            reason: 'pallet dispatch',
            qcOverride: true,
            idempotencyKey: 'k2',
        });

        expect(pallets.qc_override).toBeUndefined();
    });

    it('builds a unique UUID v4 idempotency key', () => {
        const uuidV4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
        const first = makeIdempotencyKey();
        const second = makeIdempotencyKey();

        expect(first).toMatch(uuidV4);
        expect(second).toMatch(uuidV4);
        expect(first).not.toBe(second);
    });

    it('falls back to getRandomValues and then Math.random when randomUUID is missing', () => {
        const uuidV4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
        const bytesApi = {
            getRandomValues(bytes) {
                bytes.forEach((_, index) => { bytes[index] = index; });
                return bytes;
            },
        };
        const randomApi = {
            getRandomValues(bytes) {
                bytes.forEach((_, index) => { bytes[index] = 255 - index; });
                return bytes;
            },
        };

        expect(generateUuidV4(bytesApi)).toMatch(uuidV4);
        expect(generateUuidV4(randomApi)).toMatch(uuidV4);
        expect(generateUuidV4({ randomUUID: () => 'not-a-uuid' })).toMatch(uuidV4);
    });
});

describe('grouping and status tone', () => {
    it('groups boxes by work order', () => {
        expect(groupBoxesByWorkOrder(boxes).get('7')).toHaveLength(3);
    });

    it('maps every dispatch status to a pill tone', () => {
        expect(dispatchStatusTone('pending')).toBe('pending');
        expect(dispatchStatusTone('processing')).toBe('running');
        expect(dispatchStatusTone('done')).toBe('done');
        expect(dispatchStatusTone('failed')).toBe('blocked');
        expect(dispatchStatusTone('cancelled')).toBe('blocked');
    });
});
