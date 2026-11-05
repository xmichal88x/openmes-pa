import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Badge, Button, Dropdown, Icon, InlineAlert, StatusPill, TextField } from '@openmes/ui';
import { apiCall, apiGet } from '../../lib/http';
import { __ } from '../../lib/i18n';
import { boxState } from './dispatchLogic';

const FIELD_LABEL = 'mb-[7px] block font-mono text-[9.5px] uppercase tracking-[0.08em] text-om-faint';

async function readJson(res) {
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || __('Request failed'));
    return data;
}

export default function BoxPanel({
    orders = [],
    defaultWorkOrderId = '',
    refreshToken = 0,
    onActiveBoxChange,
    toast,
}) {
    const [woId, setWoId] = useState(defaultWorkOrderId ? String(defaultWorkOrderId) : '');
    const [boxes, setBoxes] = useState([]);
    const [pallets, setPallets] = useState([]);
    const [active, setActive] = useState(null);
    const [weight, setWeight] = useState('');
    const [palletId, setPalletId] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const activeRef = useRef(null);

    useEffect(() => { activeRef.current = active; }, [active]);
    useEffect(() => { onActiveBoxChange?.(active); }, [active, onActiveBoxChange]);
    useEffect(() => () => onActiveBoxChange?.(null), [onActiveBoxChange]);

    const notify = useCallback((severity, title) => {
        toast?.(severity === 'error' ? { severity: 'error', title } : { severity: 'success', title });
    }, [toast]);

    const activate = useCallback(async (box) => {
        if (!box?.id) { setActive(null); return; }
        try {
            const res = await apiGet(`/packaging/boxes/${box.id}`);
            const data = await readJson(res);
            setActive(data.data ?? box);
            setWeight(data.data?.weight_kg ? String(data.data.weight_kg) : '');
        } catch {
            setActive(box);
        }
    }, []);

    const load = useCallback(async () => {
        try {
            const params = new URLSearchParams();
            if (woId) params.set('work_order_id', woId);
            if (!woId) params.set('status', 'open');

            const [boxesRes, palletsRes] = await Promise.all([
                apiGet(`/packaging/boxes?${params.toString()}`),
                apiGet(`/packaging/pallets/boxes${woId ? `?work_order_id=${woId}` : ''}`),
            ]);

            const boxesData = await readJson(boxesRes);
            const palletsData = await readJson(palletsRes);
            const nextBoxes = boxesData.data ?? [];
            const nextPallets = palletsData.data ?? [];

            setBoxes(nextBoxes);
            setPallets(nextPallets);
            setError(null);

            const current = activeRef.current;
            if (current?.id) {
                const fresh = nextBoxes.find((box) => box.id === current.id);
                if (fresh) await activate(fresh);
                else setActive(null);
            }
        } catch (e) {
            setError(e.message);
        }
    }, [woId, activate]);

    useEffect(() => { load(); }, [load]);

    useEffect(() => {
        if (refreshToken > 0 && activeRef.current?.id) {
            activate(activeRef.current);
        }
    }, [refreshToken, activate]);

    const run = useCallback(async (action, successMessage) => {
        if (busy) return null;
        setBusy(true);
        setError(null);
        try {
            const data = await action();
            if (successMessage) notify('success', successMessage);
            return data;
        } catch (e) {
            setError(e.message);
            notify('error', e.message);
            return null;
        } finally {
            setBusy(false);
        }
    }, [busy, notify]);

    const createBox = useCallback(async () => {
        if (!woId) { setError(__('Pick a work order first.')); return; }
        const data = await run(async () => readJson(await apiCall('/packaging/boxes', 'POST', { work_order_id: Number(woId) })), __('Box created'));
        if (data?.data) {
            activeRef.current = data.data;
            setActive(data.data);
            setWeight('');
            load();
        }
    }, [woId, run, load]);

    const closeBox = useCallback(async () => {
        if (!active) return;
        const body = weight.trim() === '' ? {} : { weight_kg: Number(weight.replace(',', '.')) };
        const data = await run(async () => readJson(await apiCall(`/packaging/boxes/${active.id}/close`, 'POST', body)), __('Box closed'));
        if (data?.data) { setActive(data.data); load(); }
    }, [active, weight, run, load]);

    const reopenBox = useCallback(async () => {
        if (!active) return;
        const data = await run(async () => readJson(await apiCall(`/packaging/boxes/${active.id}/reopen`, 'POST')), __('Box reopened'));
        if (data?.data) { setActive(data.data); load(); }
    }, [active, run, load]);

    const assignExistingPallet = useCallback(async () => {
        if (!active || !palletId) return;
        const data = await run(
            async () => readJson(await apiCall(`/packaging/boxes/${active.id}/pallet`, 'POST', { pallet_id: Number(palletId) })),
            __('Box put on the pallet'),
        );
        if (data?.data) { setActive(data.data); setPalletId(''); load(); }
    }, [active, palletId, run, load]);

    const createPallet = useCallback(async () => {
        if (!active) return;
        const palletData = await run(
            async () => readJson(await apiCall('/packaging/pallets/boxes', 'POST', { work_order_id: active.work_order_id })),
            __('Pallet created'),
        );
        const newPallet = palletData?.data;
        if (!newPallet) return;

        const boxData = await run(
            async () => readJson(await apiCall(`/packaging/boxes/${active.id}/pallet`, 'POST', { pallet_id: newPallet.id })),
            __('Box put on the pallet'),
        );
        if (boxData?.data) { setActive(boxData.data); load(); }
    }, [active, run, load]);

    const unassignPallet = useCallback(async () => {
        if (!active) return;
        const data = await run(
            async () => readJson(await apiCall(`/packaging/boxes/${active.id}/pallet`, 'DELETE')),
            __('Box taken off the pallet'),
        );
        if (data?.data) { setActive(data.data); load(); }
    }, [active, run, load]);

    const state = boxState(active);
    const openBoxes = boxes.filter((box) => box.status === 'open');
    const recentBoxes = boxes.slice(0, 20);
    const openPallets = pallets.filter((pallet) => pallet.status === 'open');
    const labelUrl = active?.label_pdf || active?.label_zpl
        || (active?.pallet_id ? `/packaging/labels/pallet/${active.pallet_id}/pdf` : null);
    const labelName = active?.label_pdf || active?.label_zpl ? __('Box label') : active?.pallet_id ? __('Pallet label') : __('Label');
    const palletOptions = useMemo(
        () => openPallets.map((pallet) => ({ value: String(pallet.id), label: `${pallet.pallet_no} · ${pallet.boxes_count ?? 0} ${__('boxes')}` })),
        [openPallets],
    );

    return (
        <section className="overflow-hidden rounded-om border border-om-line bg-om-card">
            <div className="flex flex-wrap items-center justify-between gap-3 px-[22px] pt-4 pb-3">
                <h2 className="text-[15px] font-semibold text-om-ink">{__('Boxes')}</h2>
                <Badge variant="neutral">{__(':count open', { count: openBoxes.length })}</Badge>
            </div>

            <div className="px-[22px] pb-5">
                <div className="grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                    <div>
                        <div className={FIELD_LABEL}>{__('Work order')}</div>
                        <Dropdown
                            aria-label={__('Work order')}
                            value={woId}
                            onChange={(value) => { setWoId(value); setActive(null); }}
                            placeholder={__('— Select order —')}
                            options={orders.map((order) => ({ value: String(order.id), label: `${order.order_no} — ${order.product}` }))}
                            className="w-full"
                        />
                    </div>
                    <Button
                        variant="primary"
                        onClick={createBox}
                        loading={busy}
                        disabled={busy || !woId}
                        leftIcon={<Icon name="package-plus" size={14} />}
                    >
                        {__('New box')}
                    </Button>
                </div>

                {error && <InlineAlert severity="error" title={error} className="mt-3" />}

                {active ? (
                    <div className="mt-4 rounded-om border border-om-line2 bg-om-bg p-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-mono text-[22px] font-semibold tracking-[-0.02em] text-om-ink">{active.box_id}</span>
                                    <StatusPill
                                        status={state.open ? 'running' : state.closed ? 'done' : 'pending'}
                                        label={state.open ? __('Open') : state.closed ? __('Closed') : active.status}
                                    />
                                    {active.pallet_no && <Badge variant="outline">{__('Pallet')} {active.pallet_no}</Badge>}
                                </div>
                                <p className="mt-1 text-[12.5px] text-om-muted">
                                    {__(':count parts', { count: state.parts })}
                                    {active.weight_kg ? ` · ${active.weight_kg} kg` : ''}
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    leftIcon={<Icon name="printer" size={13} />}
                                    disabled={!labelUrl}
                                    title={labelUrl ? undefined : __('No box label endpoint is available in this API.')}
                                    onClick={() => labelUrl && window.open(labelUrl, '_blank', 'noopener')}
                                >
                                    {labelName}
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    leftIcon={<Icon name="file-text" size={13} />}
                                    disabled={!active.work_order_id}
                                    onClick={() => window.open(`/packaging/work-orders/${active.work_order_id}/packing-list.pdf`, '_blank', 'noopener')}
                                >
                                    {__('Packing list')}
                                </Button>
                            </div>
                        </div>

                        <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-[160px_auto_auto] md:items-end">
                            <TextField
                                mono
                                inputMode="decimal"
                                label={__('Weight (kg)')}
                                value={weight}
                                onChange={setWeight}
                                placeholder="0.000"
                            />
                            {state.open ? (
                                <Button variant="primary" onClick={closeBox} loading={busy} disabled={busy || !state.canClose} leftIcon={<Icon name="package-check" size={14} />}>
                                    {__('Close box')}
                                </Button>
                            ) : (
                                <Button variant="outline" onClick={reopenBox} loading={busy} disabled={busy || !state.canReopen}>
                                    {__('Reopen box')}
                                </Button>
                            )}
                            {active.pallet_id ? (
                                <Button variant="outline" onClick={unassignPallet} loading={busy} disabled={busy}>
                                    {__('Remove from pallet')}
                                </Button>
                            ) : (
                                <div className="flex flex-wrap items-end gap-2">
                                    <Dropdown
                                        aria-label={__('Pallet')}
                                        value={palletId}
                                        onChange={setPalletId}
                                        placeholder={__('— Select pallet —')}
                                        options={palletOptions}
                                        className="min-w-44"
                                    />
                                    <Button variant="outline" onClick={assignExistingPallet} loading={busy} disabled={busy || !palletId || !state.canAssignPallet}>
                                        {__('Add to pallet')}
                                    </Button>
                                    <Button variant="ghost" onClick={createPallet} loading={busy} disabled={busy || !state.canAssignPallet}>
                                        {__('New boxes pallet')}
                                    </Button>
                                </div>
                            )}
                        </div>

                        <p className="mt-3 text-[11.5px] text-om-faint">
                            {state.open
                                ? __('Scan a part label - it goes into this open box.')
                                : __('Close the box before putting it on a pallet.')}
                        </p>
                    </div>
                ) : (
                    <p className="mt-4 text-[12.5px] text-om-faint">{__('No active box — create one above')}</p>
                )}

                {recentBoxes.length > 0 && (
                    <div className="mt-4 border-t border-om-line2 pt-3">
                        <div className={FIELD_LABEL}>{__('Recent boxes')}</div>
                        <ul className="divide-y divide-om-line2">
                            {recentBoxes.map((box) => (
                                <li key={box.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <span className="min-w-0 text-[13px]">
                                        <span className="font-mono font-semibold text-om-ink">{box.box_id}</span>
                                        <span className="text-om-muted"> · {__(':count parts', { count: box.part_count })} · {box.status === 'open' ? __('Open') : __('Closed')}</span>
                                        {box.pallet_no ? <span className="text-om-muted"> · {__('Pallet')} {box.pallet_no}</span> : null}
                                    </span>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={active?.id === box.id}
                                        onClick={() => activate(box)}
                                    >
                                        {active?.id === box.id ? __('Active') : __('Activate')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </section>
    );
}
