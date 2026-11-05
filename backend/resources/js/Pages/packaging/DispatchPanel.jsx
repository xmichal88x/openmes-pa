import { useCallback, useEffect, useMemo, useState } from 'react';
import { Badge, Button, Checkbox, Icon, InlineAlert, StatusPill, TextField } from '@openmes/ui';
import { apiCall, apiGet } from '../../lib/http';
import { __, formatDateTime } from '../../lib/i18n';
import {
    addSelection,
    boxState,
    buildDispatchPayload,
    buildPalletQualityPayload,
    dispatchStatusTone,
    emptySelection,
    findScannedEntity,
    groupBoxesByWorkOrder,
    groupPalletsByWorkOrder,
    makeIdempotencyKey,
    palletDispatchable,
    palletQualityPassed,
    palletQualityPending,
    removeSelection,
    validateDispatchSelection,
} from './dispatchLogic';

const FIELD_LABEL = 'mb-[7px] block font-mono text-[9.5px] uppercase tracking-[0.08em] text-om-faint';

const STATUS_LABELS = {
    pending: 'Pending',
    processing: 'Processing',
    done: 'Done',
    failed: 'Failed',
    skipped: 'Skipped',
    cancelled: 'Cancelled',
};

async function readJson(res) {
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || __('Request failed'));
    return data;
}

function selectionErrorMessage(error) {
    switch (error.code) {
        case 'mixed': return __('Scan boxes or pallets - not both.');
        case 'missing_boxes': return __('Every closed box must be scanned: :boxes', { boxes: error.boxes.join(', ') });
        case 'open_boxes': return __('Close these boxes before dispatch: :boxes', { boxes: error.boxes.join(', ') });
        case 'boxes_on_pallets': return __('These boxes are on a pallet - scan the pallet instead: :boxes', { boxes: error.boxes.join(', ') });
        case 'pallets_not_closed': return __('Close these pallets first: :pallets', { pallets: error.pallets.join(', ') });
        case 'pallets_not_passed': return __('These pallets did not pass quality control: :pallets', { pallets: error.pallets.join(', ') });
        case 'boxes_off_pallets': return __('Every box must be on a scanned pallet: :boxes', { boxes: error.boxes.join(', ') });
        default: return __('The dispatch selection is incomplete.');
    }
}

export default function DispatchPanel({
    items = [],
    scanHandlerRef = null,
    toast,
}) {
    const [allBoxes, setAllBoxes] = useState([]);
    const [allPallets, setAllPallets] = useState([]);
    const [dispatches, setDispatches] = useState([]);
    const [selectedWoId, setSelectedWoId] = useState('');
    const [selection, setSelection] = useState(emptySelection());
    const [weight, setWeight] = useState('');
    const [carrier, setCarrier] = useState('');
    const [reason, setReason] = useState('');
    const [qcOverride, setQcOverride] = useState(false);
    const [manualCode, setManualCode] = useState('');
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [scanError, setScanError] = useState(null);
    const [lastResult, setLastResult] = useState(null);
    const [qualityTarget, setQualityTarget] = useState(null);
    const [qualityReason, setQualityReason] = useState('');
    const [qualityBusy, setQualityBusy] = useState(false);

    const notify = useCallback((severity, title) => {
        toast?.(severity === 'error' ? { severity: 'error', title } : { severity: 'success', title });
    }, [toast]);

    const load = useCallback(async (silent = false) => {
        if (!silent) setLoading(true);
        try {
            const [boxesRes, palletsRes, dispatchesRes] = await Promise.all([
                apiGet('/packaging/boxes?status=closed'),
                apiGet('/packaging/pallets/boxes?status=closed'),
                apiGet('/packaging/dispatches?per_page=50'),
            ]);

            const boxesData = await readJson(boxesRes);
            const palletsData = await readJson(palletsRes);
            const dispatchData = await readJson(dispatchesRes);

            setAllBoxes(boxesData.data ?? []);
            setAllPallets(palletsData.data ?? []);
            setDispatches(dispatchData.data ?? []);
            setError(null);
        } catch (e) {
            if (!silent) setError(e.message);
        } finally {
            if (!silent) setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);
    useEffect(() => {
        const timer = setInterval(() => load(true), 5000);
        return () => clearInterval(timer);
    }, [load]);

    const boxesByOrder = useMemo(() => groupBoxesByWorkOrder(allBoxes), [allBoxes]);
    const palletsByOrder = useMemo(() => groupPalletsByWorkOrder(allPallets), [allPallets]);

    const dispatchableOrders = useMemo(() => {
        const map = new Map();
        for (const item of items) {
            if (item.status === 'DONE') map.set(String(item.id), item);
        }
        for (const box of allBoxes) {
            const key = String(box.work_order_id);
            if (!map.has(key)) map.set(key, { id: box.work_order_id, order_no: box.order_no, product: box.order_no, status: 'DONE' });
        }
        for (const pallet of allPallets) {
            const key = String(pallet.work_order_id);
            if (!map.has(key)) map.set(key, { id: pallet.work_order_id, order_no: pallet.order_no, product: pallet.order_no, status: 'DONE' });
        }
        return [...map.values()].sort((a, b) => String(a.order_no ?? '').localeCompare(String(b.order_no ?? '')));
    }, [items, allBoxes, allPallets]);

    useEffect(() => {
        if (dispatchableOrders.length === 0) {
            setSelectedWoId('');
            return;
        }
        const exists = dispatchableOrders.some((order) => String(order.id) === String(selectedWoId));
        if (!exists) setSelectedWoId(String(dispatchableOrders[0].id));
    }, [dispatchableOrders, selectedWoId]);

    useEffect(() => {
        setSelection(emptySelection());
        setScanError(null);
        setLastResult(null);
        setQualityTarget(null);
        setQualityReason('');
    }, [selectedWoId]);

    const selectedBoxes = useMemo(
        () => allBoxes.filter((box) => String(box.work_order_id) === String(selectedWoId)),
        [allBoxes, selectedWoId],
    );
    const selectedPallets = useMemo(
        () => allPallets.filter((pallet) => String(pallet.work_order_id) === String(selectedWoId)),
        [allPallets, selectedWoId],
    );
    const selectedDispatch = dispatches.filter((dispatch) => String(dispatch.work_order_id) === String(selectedWoId));

    const selectionErrors = useMemo(
        () => validateDispatchSelection(selection, { boxes: selectedBoxes, pallets: selectedPallets }),
        [selection, selectedBoxes, selectedPallets],
    );

    const handleScan = useCallback((raw) => {
        if (!raw) return;
        const found = findScannedEntity(raw, { boxes: selectedBoxes, pallets: selectedPallets });

        if (!found.entity) {
            setScanError(__('Unknown box or pallet in this work order: :code', { code: found.code }));
            return;
        }

        if (found.kind === 'box') {
            const state = boxState(found.entity);
            if (!state.closed) {
                setScanError(__('Box :id is not closed.', { id: found.entity.box_id }));
                return;
            }
            if (found.entity.pallet_id) {
                setScanError(__('Box :id is on a pallet - scan the pallet instead.', { id: found.entity.box_id }));
                return;
            }
        }

        if (found.kind === 'pallet') {
            if (found.entity.status !== 'closed') {
                setScanError(__('Pallet :no must be closed before dispatch.', { no: found.entity.pallet_no }));
                return;
            }
            if (!palletQualityPassed(found.entity)) {
                setScanError(__('Pallet :no must pass quality control (quality=pass) before dispatch.', { no: found.entity.pallet_no }));
                return;
            }
        }

        const ref = found.kind === 'box' ? found.entity.box_id : found.entity.pallet_no;
        const result = addSelection(selection, found.kind, ref);
        if (result.error === 'mixed') {
            setScanError(__('Scan boxes or pallets - not both.'));
            return;
        }
        if (result.error === 'duplicate') {
            setScanError(__(':ref is already scanned.', { ref }));
            return;
        }

        setSelection(result.selection);
        setScanError(null);
    }, [selection, selectedBoxes, selectedPallets]);

    useEffect(() => {
        if (!scanHandlerRef) return undefined;
        scanHandlerRef.current = handleScan;
        return () => { scanHandlerRef.current = null; };
    }, [scanHandlerRef, handleScan]);

    const submitManualScan = useCallback((event) => {
        event.preventDefault();
        const code = manualCode.trim();
        if (!code) return;
        setManualCode('');
        handleScan(code);
    }, [manualCode, handleScan]);

    const toggleEntity = useCallback((kind, ref) => {
        const current = kind === 'box' ? selection.boxes : selection.pallets;
        if (current.includes(ref)) {
            setSelection(removeSelection(selection, kind, ref));
            setScanError(null);
            return;
        }

        const result = addSelection(selection, kind, ref);
        if (result.error === 'mixed') setScanError(__('Scan boxes or pallets - not both.'));
        else if (result.error === 'duplicate') setScanError(__(':ref is already scanned.', { ref }));
        else { setSelection(result.selection); setScanError(null); }
    }, [selection]);

    const openQualityEditor = useCallback((pallet) => {
        setQualityTarget(pallet);
        setQualityReason('');
        setError(null);
    }, []);

    const submitQuality = useCallback(async (status) => {
        if (!qualityTarget || qualityBusy) return;
        if (status === 'fail' && !qualityReason.trim()) {
            setError(__('A reason is required when a pallet fails quality control.'));
            return;
        }

        setQualityBusy(true);
        setError(null);
        try {
            await readJson(await apiCall(
                `/packaging/pallets/${qualityTarget.id}/quality`,
                'POST',
                buildPalletQualityPayload(status, qualityReason),
            ));
            notify('success', __('Quality status updated for :no', { no: qualityTarget.pallet_no }));
            setQualityTarget(null);
            setQualityReason('');
            await load(true);
        } catch (e) {
            setError(e.message);
            notify('error', e.message);
        } finally {
            setQualityBusy(false);
        }
    }, [qualityTarget, qualityReason, qualityBusy, notify, load]);

    const dispatch = useCallback(async () => {
        if (busy || !selectedWoId) return;
        if (selection.boxes.length === 0 && selection.pallets.length === 0) {
            setError(__('Scan at least one box or pallet.'));
            return;
        }
        if (selectionErrors.length > 0) return;

        const boxesOnly = selection.boxes.length > 0 && selection.pallets.length === 0;
        if (boxesOnly && !qcOverride) {
            setError(__('Direct box dispatch requires an explicit QC override.'));
            return;
        }
        if (boxesOnly && String(reason).trim() === '') {
            setError(__('QC override requires a reason.'));
            return;
        }

        setBusy(true);
        setError(null);
        setLastResult(null);
        try {
            const idempotencyKey = makeIdempotencyKey();
            const payload = buildDispatchPayload({
                workOrderId: selectedWoId,
                selection,
                weight,
                carrier,
                reason,
                qcOverride,
                idempotencyKey,
            });
            const data = await readJson(await apiCall('/packaging/dispatch', 'POST', payload));
            setLastResult(data.data ?? null);
            setSelection(emptySelection());
            setWeight('');
            setCarrier('');
            setReason('');
            setQcOverride(false);
            notify('success', __('Dispatch created'));
            await load(true);
        } catch (e) {
            setError(e.message);
            notify('error', e.message);
        } finally {
            setBusy(false);
        }
    }, [busy, selectedWoId, selection, selectionErrors, weight, carrier, reason, qcOverride, notify, load]);

    const cancelDispatch = useCallback(async (dispatch) => {
        if (!window.confirm(__('Cancel dispatch #:id?', { id: dispatch.id }))) return;
        try {
            await readJson(await apiCall(`/packaging/dispatches/${dispatch.id}/cancel`, 'POST', { reason: __('Cancelled at the packing station') }));
            notify('success', __('Dispatch cancelled'));
            await load(true);
        } catch (e) {
            notify('error', e.message);
        }
    }, [notify, load]);

    const selectedOrder = dispatchableOrders.find((order) => String(order.id) === String(selectedWoId));
    const canDispatch = Boolean(selectedWoId)
        && (selection.boxes.length > 0 || selection.pallets.length > 0)
        && selectionErrors.length === 0
        && !busy;
    const noClosedUnits = Boolean(selectedWoId) && selectedBoxes.length === 0 && selectedPallets.length === 0;

    return (
        <div className="grid grid-cols-1 gap-5 xl:grid-cols-[320px_minmax(0,1fr)]">
            <section className="overflow-hidden rounded-om border border-om-line bg-om-card">
                <div className="flex flex-wrap items-center justify-between gap-3 px-[22px] pt-4 pb-3">
                    <h2 className="text-[15px] font-semibold text-om-ink">{__('Work orders to dispatch')}</h2>
                    <div className="flex items-center gap-2">
                        <Badge variant="neutral">{dispatchableOrders.length}</Badge>
                        <Button variant="ghost" size="sm" leftIcon={<Icon name="refresh-cw" size={13} />} loading={loading} onClick={() => load()}>
                            {__('Refresh')}
                        </Button>
                    </div>
                </div>
                <div className="max-h-[520px] overflow-y-auto border-t border-om-line2">
                    {dispatchableOrders.length === 0 ? (
                        <p className="px-[22px] py-5 text-[12.5px] text-om-faint">{__('No DONE work orders with closed boxes or pallets')}</p>
                    ) : (
                        <ul className="divide-y divide-om-line2">
                            {dispatchableOrders.map((order) => {
                                const boxes = boxesByOrder.get(String(order.id)) ?? [];
                                const pallets = palletsByOrder.get(String(order.id)) ?? [];
                                const active = String(order.id) === String(selectedWoId);
                                return (
                                    <li key={order.id}>
                                        <button
                                            type="button"
                                            onClick={() => setSelectedWoId(String(order.id))}
                                            className={`flex w-full items-center justify-between gap-3 px-[22px] py-3 text-left transition-colors ${active ? 'bg-om-selected' : 'hover:bg-om-bg'}`}
                                        >
                                            <span className="min-w-0">
                                                <span className="block font-mono text-[13px] font-semibold text-om-ink">{order.order_no}</span>
                                                <span className="block truncate text-[11.5px] text-om-muted">{order.product}</span>
                                            </span>
                                            <span className="flex shrink-0 items-center gap-1">
                                                <Badge variant="neutral">{boxes.length} {__('boxes')}</Badge>
                                                <Badge variant="outline">{pallets.length} {__('pallets')}</Badge>
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            </section>

            <div className="space-y-5">
                <section className="overflow-hidden rounded-om border border-om-line bg-om-card">
                    <div className="flex flex-wrap items-center justify-between gap-3 px-[22px] pt-4 pb-3">
                        <h2 className="text-[15px] font-semibold text-om-ink">
                            {selectedOrder ? `${__('Dispatch')} · ${selectedOrder.order_no}` : __('Dispatch')}
                        </h2>
                        <StatusPill status="pending" label={__('PACK WO DONE')} pulse={false} />
                    </div>

                    <div className="px-[22px] pb-5">
                        {error && <InlineAlert severity="error" title={error} className="mb-3" />}

                        {!selectedOrder ? (
                            <p className="text-[12.5px] text-om-faint">{__('Select a work order to dispatch.')}</p>
                        ) : (
                            <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                                <div>
                                    <div className={FIELD_LABEL}>{__('Closed boxes')} ({selectedBoxes.filter((box) => box.status === 'closed').length})</div>
                                    {selectedBoxes.length === 0 ? (
                                        <p className="text-[12px] text-om-faint">{__('No closed boxes for this work order.')}</p>
                                    ) : (
                                        <ul className="divide-y divide-om-line2 rounded-om border border-om-line2">
                                            {selectedBoxes.map((box) => {
                                                const state = boxState(box);
                                                const checked = selection.boxes.includes(box.box_id);
                                                const disabled = !state.closed || Boolean(box.pallet_id);
                                                return (
                                                    <li key={box.id} className="flex items-center justify-between gap-2 px-3 py-2">
                                                        <span className="min-w-0 text-[12.5px]">
                                                            <span className="font-mono font-semibold text-om-ink">{box.box_id}</span>
                                                            <span className="text-om-muted"> · {__(':count parts', { count: state.parts })}{box.pallet_no ? ` · ${__('Pallet')} ${box.pallet_no}` : ''}</span>
                                                        </span>
                                                        <Button
                                                            variant={checked ? 'primary' : 'outline'}
                                                            size="sm"
                                                            disabled={disabled}
                                                            onClick={() => toggleEntity('box', box.box_id)}
                                                        >
                                                            {checked ? __('Scanned') : __('Scan')}
                                                        </Button>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    )}

                                    <div className={`${FIELD_LABEL} mt-4`}>{__('Closed pallets')} ({selectedPallets.length})</div>
                                    <p className="mb-2 text-[11.5px] text-om-faint">{__('Only pallets with quality=pass can be dispatched.')}</p>
                                    {selectedPallets.length === 0 ? (
                                        <p className="text-[12px] text-om-faint">{__('No closed boxes-mode pallets for this work order.')}</p>
                                    ) : (
                                        <ul className="divide-y divide-om-line2 rounded-om border border-om-line2">
                                            {selectedPallets.map((pallet) => {
                                                const checked = selection.pallets.includes(pallet.pallet_no);
                                                const disabled = !palletDispatchable(pallet);
                                                const editing = qualityTarget?.id === pallet.id;
                                                return (
                                                    <li key={pallet.id} className="px-3 py-2">
                                                        <div className="flex items-center justify-between gap-2">
                                                            <span className="min-w-0 text-[12.5px]">
                                                                <span className="font-mono font-semibold text-om-ink">{pallet.pallet_no}</span>
                                                                <span className="text-om-muted"> · {pallet.boxes_count ?? 0} {__('boxes')} · {pallet.status} · {__('QC')}: {pallet.quality_status ?? 'pending'}</span>
                                                            </span>
                                                            <span className="flex shrink-0 items-center gap-1">
                                                                {(palletQualityPending(pallet) || pallet.quality_status === 'fail') && (
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        onClick={() => (editing ? setQualityTarget(null) : openQualityEditor(pallet))}
                                                                    >
                                                                        {__('Quality pass/fail')}
                                                                    </Button>
                                                                )}
                                                                <Button
                                                                    variant={checked ? 'primary' : 'outline'}
                                                                    size="sm"
                                                                    disabled={disabled}
                                                                    onClick={() => toggleEntity('pallet', pallet.pallet_no)}
                                                                >
                                                                    {checked ? __('Scanned') : __('Scan')}
                                                                </Button>
                                                            </span>
                                                        </div>
                                                        {editing && (
                                                            <div className="mt-2 rounded-om border border-om-line2 bg-om-bg p-2">
                                                                <TextField
                                                                    label={__('Quality reason (required for fail)')}
                                                                    value={qualityReason}
                                                                    onChange={setQualityReason}
                                                                    placeholder={__('e.g. cracked edge, wrong dimensions')}
                                                                />
                                                                <div className="mt-2 flex gap-2">
                                                                    <Button
                                                                        variant="primary"
                                                                        size="sm"
                                                                        loading={qualityBusy}
                                                                        onClick={() => submitQuality('pass')}
                                                                    >
                                                                        {__('Quality pass')}
                                                                    </Button>
                                                                    <Button
                                                                        variant="outline"
                                                                        size="sm"
                                                                        disabled={!qualityReason.trim() || qualityBusy}
                                                                        onClick={() => submitQuality('fail')}
                                                                    >
                                                                        {__('Quality fail')}
                                                                    </Button>
                                                                </div>
                                                            </div>
                                                        )}
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    )}
                                </div>

                                <div>
                                    <form onSubmit={submitManualScan} className="flex items-end gap-2">
                                        <TextField
                                            mono
                                            label={__('Scan boxes or pallets')}
                                            value={manualCode}
                                            onChange={setManualCode}
                                            placeholder="BOX-… / PAL-…"
                                            className="flex-1"
                                        />
                                        <Button type="submit" variant="outline" disabled={!manualCode.trim()}>{__('Add')}</Button>
                                    </form>
                                    {scanError && <InlineAlert severity="warning" title={scanError} className="mt-2" />}

                                    {(selection.boxes.length > 0 || selection.pallets.length > 0) && (
                                        <div className="mt-3">
                                            <div className={FIELD_LABEL}>{__('Scanned')}</div>
                                            <div className="flex flex-wrap gap-1.5">
                                                {selection.boxes.map((ref) => (
                                                    <button key={ref} type="button" onClick={() => toggleEntity('box', ref)} title={__('Remove')}>
                                                        <Badge variant="outline">{ref} ×</Badge>
                                                    </button>
                                                ))}
                                                {selection.pallets.map((ref) => (
                                                    <button key={ref} type="button" onClick={() => toggleEntity('pallet', ref)} title={__('Remove')}>
                                                        <Badge variant="outline">{ref} ×</Badge>
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    )}

                                    {selectionErrors.map((selectionError) => {
                                        const firstBlocked = selectionError.code === 'pallets_not_passed'
                                            ? selectedPallets.find((pallet) => pallet.pallet_no === selectionError.pallets[0])
                                            : null;
                                        return (
                                            <InlineAlert
                                                key={selectionError.code}
                                                severity="warning"
                                                title={selectionErrorMessage(selectionError)}
                                                className="mt-2"
                                            >
                                                {firstBlocked && (
                                                    <button
                                                        type="button"
                                                        className="font-semibold underline"
                                                        onClick={() => openQualityEditor(firstBlocked)}
                                                    >
                                                        {__('Set quality status')}
                                                    </button>
                                                )}
                                            </InlineAlert>
                                        );
                                    })}

                                    {noClosedUnits && (
                                        <InlineAlert
                                            severity="warning"
                                            title={__('No closed cartons for this work order - create cartons in Packing mode first.')}
                                            className="mt-2"
                                        />
                                    )}

                                    <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <TextField mono inputMode="decimal" label={__('Weight (kg)')} value={weight} onChange={setWeight} placeholder="0.000" />
                                        <TextField label={__('Carrier')} value={carrier} onChange={setCarrier} placeholder={__('DHL, DPD, …')} />
                                    </div>
                                    {selectedBoxes.length > 0 && selectedPallets.length === 0 && (
                                        <div className="mt-3 rounded-om-sm border border-om-line2 p-3">
                                            <Checkbox
                                                checked={qcOverride}
                                                onChange={setQcOverride}
                                                label={__('QC override - ship cartons without a pallet')}
                                            />
                                            <p className="mt-1 text-[11.5px] text-om-faint">
                                                {__('Pallets are the quality-control unit. Direct carton dispatch needs this override and a reason.')}
                                            </p>
                                        </div>
                                    )}
                                    <div className="mt-3">
                                        <TextField label={__('Reason')} value={reason} onChange={setReason} placeholder={__('Optional note for the dispatch')} />
                                    </div>

                                    <Button
                                        className="mt-4 w-full"
                                        variant="primary"
                                        onClick={dispatch}
                                        loading={busy}
                                        disabled={!canDispatch}
                                        leftIcon={<Icon name="truck" size={14} />}
                                    >
                                        {__('Dispatch work order')}
                                    </Button>

                                    {lastResult && (
                                        <InlineAlert severity="success" title={__('Dispatch #:id created (:status)', { id: lastResult.id, status: lastResult.status })} className="mt-3">
                                            {lastResult.expected_weight_kg != null && (
                                                <span>
                                                    {__('Expected :expected kg, deviation :deviation%', {
                                                        expected: lastResult.expected_weight_kg,
                                                        deviation: lastResult.weight_deviation_pct ?? 0,
                                                    })}
                                                </span>
                                            )}
                                        </InlineAlert>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </section>

                <section className="overflow-hidden rounded-om border border-om-line bg-om-card">
                    <div className="flex flex-wrap items-center justify-between gap-3 px-[22px] pt-4 pb-3">
                        <h2 className="text-[15px] font-semibold text-om-ink">{__('Dispatch status')}</h2>
                        <Badge variant="neutral">{selectedDispatch.length}</Badge>
                    </div>
                    <div className="border-t border-om-line2">
                        {selectedDispatch.length === 0 ? (
                            <p className="px-[22px] py-4 text-[12.5px] text-om-faint">{__('No dispatches for this work order.')}</p>
                        ) : (
                            <ul className="divide-y divide-om-line2">
                                {selectedDispatch.map((dispatch) => (
                                    <li key={dispatch.id} className="flex flex-wrap items-center justify-between gap-3 px-[22px] py-3">
                                        <div className="min-w-0 text-[12.5px]">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-mono font-semibold text-om-ink">#{dispatch.id}</span>
                                                <StatusPill status={dispatchStatusTone(dispatch.status)} label={__(STATUS_LABELS[dispatch.status] ?? dispatch.status)} pulse={false} />
                                                {dispatch.carrier && <Badge variant="outline">{dispatch.carrier}</Badge>}
                                            </div>
                                            <div className="mt-1 text-om-muted">
                                                {formatDateTime(dispatch.created_at)}
                                                {' · '}{(dispatch.scanned_boxes ?? []).length} {__('boxes')}
                                                {' · '}{(dispatch.scanned_pallets ?? []).length} {__('pallets')}
                                                {dispatch.weight_kg ? ` · ${dispatch.weight_kg} kg` : ''}
                                                {dispatch.last_error ? ` · ${dispatch.last_error}` : ''}
                                            </div>
                                        </div>
                                        {dispatch.status === 'pending' && (
                                            <Button variant="outline" size="sm" onClick={() => cancelDispatch(dispatch)}>
                                                {__('Cancel pending')}
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </section>
            </div>
        </div>
    );
}
