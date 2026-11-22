import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Button, InlineAlert, TextField } from '@openmes/ui';
import OperatorLayout from '../../../layouts/OperatorLayout';
import usePrompt from '../../../components/usePrompt';
import { __, formatDateTime } from '../../../lib/i18n';
import { noticeFrom } from './notice';

function csrf() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
}

function normalize(value) {
    return String(value ?? '').replace(/\s+/g, '').toUpperCase();
}

export default function ReworkIndex() {
    const { units = [], canUnblock = false } = usePage().props;
    const [scan, setScan] = useState('');
    const [selectedId, setSelectedId] = useState(null);
    const [error, setError] = useState(null);
    const [notice, setNotice] = useState(null); // what the last decision answered, warnings included
    const [busyId, setBusyId] = useState(null);
    const { prompt, dialog: promptDialog } = usePrompt();

    const selectByScan = () => {
        const code = normalize(scan);
        if (code === '') return;
        setNotice(null);
        const unit = units.find((u) => normalize(u.serial_no) === code || normalize(u.psn) === code);
        if (!unit) {
            setSelectedId(null);
            setError(__('No blocked unit matches :code.', { code: scan }));
            return;
        }
        setError(null);
        setSelectedId(unit.id);
        document.getElementById(`rework-unit-${unit.id}`)?.scrollIntoView({ block: 'center' });
    };

    const submit = async (unit, action, body) => {
        setBusyId(unit.id);
        setError(null);
        setNotice(null);
        try {
            const res = await fetch(`/operator/unit-labels/${unit.id}/${action}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(body),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                setError(data.message ?? __('Could not save the rework decision.'));
                return;
            }
            // The decision lands even when the quantity cannot be re-derived; the
            // answer says so in `warnings`, and the operator must see it.
            setNotice(noticeFrom(data));
            router.reload({ only: ['units'] });
        } catch {
            setError(__('Could not save the rework decision.'));
        } finally {
            setBusyId(null);
        }
    };

    const pass = (unit) => prompt(
        { title: __('Pass rework'), label: __('Note'), required: true },
        (note) => submit(unit, 'unblock', { note }),
    );

    const fail = (unit) => prompt(
        { title: __('Fail rework'), label: __('Reason'), required: true },
        (reason) => submit(unit, 'scrap', { reason }),
    );

    const recut = (unit) => prompt(
        { title: __('Send to re-cut'), label: __('Re-cut reason'), required: true },
        (reason) => submit(unit, 'recut', { reason }),
    );

    return (
        <>
            <Head title={__('Rework')} />
            <div className="p-4 space-y-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold text-om-ink">{__('Rework')}</h1>
                        <p className="text-sm text-om-muted">{__('Blocked units awaiting a decision.')}</p>
                    </div>
                    <div className="w-full sm:w-80">
                        <TextField
                            label={__('Scan serial number or process serial')}
                            value={scan}
                            onChange={setScan}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    selectByScan();
                                }
                            }}
                            mono
                        />
                    </div>
                </div>

                {error && <InlineAlert severity="error" title={error} />}

                {notice && (
                    <div className="space-y-2">
                        {notice.lines.map((line, index) => (
                            <InlineAlert key={`${notice.severity}-${index}`} severity={notice.severity} title={line} />
                        ))}
                    </div>
                )}

                {units.length === 0 ? (
                    <InlineAlert severity="info" title={__('No blocked units.')} />
                ) : (
                    <div className="bg-om-card border border-om-line rounded-om overflow-hidden">
                        <div className="hidden md:grid grid-cols-[1.2fr_1.2fr_1fr_1fr_1.4fr_1fr_auto] gap-3 px-4 py-2 border-b border-om-line text-[9.5px] font-mono uppercase tracking-[0.08em] text-om-faint">
                            <span>{__('Serial number')}</span>
                            <span>{__('Process serial')}</span>
                            <span>{__('Product type')}</span>
                            <span>{__('Order')}</span>
                            <span>{__('Hold reason')}</span>
                            <span>{__('Updated')}</span>
                            <span />
                        </div>
                        <ul className="divide-y divide-om-line">
                            {units.map((unit) => (
                                <li
                                    key={unit.id}
                                    id={`rework-unit-${unit.id}`}
                                    className={`grid grid-cols-1 md:grid-cols-[1.2fr_1.2fr_1fr_1fr_1.4fr_1fr_auto] gap-3 px-4 py-3 items-center ${selectedId === unit.id ? 'bg-om-chip' : ''}`}
                                >
                                    <span className="font-mono text-sm text-om-ink">{unit.serial_no ?? '—'}</span>
                                    <span className="font-mono text-sm text-om-ink">{unit.psn ?? '—'}</span>
                                    <span className="text-sm text-om-ink">{unit.product_type_code ?? '—'}</span>
                                    <span className="text-sm text-om-ink">{unit.order_no ?? '—'}</span>
                                    <span className="text-sm text-om-muted">{unit.hold_reason ?? '—'}</span>
                                    <span className="text-sm text-om-muted">{unit.updated_at ? formatDateTime(unit.updated_at) : '—'}</span>
                                    <span className="flex flex-wrap gap-2 md:justify-end">
                                        {canUnblock && (
                                            <Button
                                                variant="primary"
                                                disabled={busyId === unit.id}
                                                onClick={() => pass(unit)}
                                            >
                                                {__('Rework passed')}
                                            </Button>
                                        )}
                                        <Button
                                            variant="secondary"
                                            disabled={busyId === unit.id}
                                            onClick={() => fail(unit)}
                                        >
                                            {__('Rework failed')}
                                        </Button>
                                        <Button
                                            variant="secondary"
                                            disabled={busyId === unit.id}
                                            onClick={() => recut(unit)}
                                        >
                                            {__('Recut')}
                                        </Button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
            {promptDialog}
        </>
    );
}

ReworkIndex.layout = (page) => <OperatorLayout>{page}</OperatorLayout>;
