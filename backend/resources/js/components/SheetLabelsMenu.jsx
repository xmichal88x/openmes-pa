import { useState } from 'react';
import { Button, Icon } from '@openmes/ui';
import LabelPreviewModal from './LabelPreviewModal';
import ModalShell from './ModalShell';
import { __ } from '../lib/i18n';
import {
    normalizeSheetLabels,
    sheetLabelSummary,
    sheetLabelsEndpoint,
    shouldShowSheetLabels,
} from './sheetLabelsLogic';

function errorMessage(status) {
    if (status === 503) return __('AlphaCAM labels are not configured.');
    if (status === 502) return __('AlphaCAM labels service returned an error.');
    return __('Could not load sheet labels.');
}

/**
 * Lists the sheet labels PA has already generated for this step's batch and
 * prints them through the shared label preview. Read-only: it never asks MES
 * to generate a label or a map.
 */
export default function SheetLabelsMenu({ step, size = 'sm', variant = 'outline' }) {
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [sheets, setSheets] = useState([]);
    const [preview, setPreview] = useState(null);

    if (!shouldShowSheetLabels(step)) return null;

    const openMenu = async () => {
        setOpen(true);
        setLoading(true);
        setError(null);
        setSheets([]);
        setPreview(null);

        try {
            const res = await fetch(sheetLabelsEndpoint(step.id), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const payload = await res.json().catch(() => null);

            if (!res.ok) {
                setError(typeof payload?.message === 'string' && payload.message !== '' ? payload.message : errorMessage(res.status));
                return;
            }

            setSheets(normalizeSheetLabels(payload));
        } catch {
            setError(__('Could not load sheet labels.'));
        } finally {
            setLoading(false);
        }
    };

    const closeMenu = () => {
        setOpen(false);
        setPreview(null);
    };

    return (
        <>
            <Button variant={variant} size={size} leftIcon={<Icon name="printer" size={14} />} onClick={openMenu}>
                {__('Sheet labels')}
            </Button>

            {open && (
                <ModalShell title={__('Sheet labels')} subtitle={step.name} onClose={closeMenu}>
                    <div className="px-[18px] py-4">
                        {loading && <p className="m-0 text-sm text-om-muted">{__('Loading…')}</p>}
                        {!loading && error && <p role="alert" className="m-0 text-sm text-om-blocked">{error}</p>}
                        {!loading && !error && sheets.length === 0 && (
                            <p className="m-0 text-sm text-om-muted">{__('No labels for this sheet.')}</p>
                        )}
                        {!loading && !error && sheets.length > 0 && (
                            <ul className="m-0 list-none space-y-2 p-0">
                                {sheets.map((row, index) => (
                                    <li
                                        key={row?.label_batch_id ?? `${row?.sheet ?? 'sheet'}-${index}`}
                                        className="flex items-center justify-between gap-3 rounded-om-sm border border-om-line px-3 py-2"
                                    >
                                        <div className="min-w-0">
                                            <div className="truncate text-[13px] font-medium text-om-ink">{sheetLabelSummary(row)}</div>
                                            {row?.order_no != null && (
                                                <div className="font-mono text-[11px] text-om-faint">
                                                    {__('Order :no', { no: row.order_no })}
                                                </div>
                                            )}
                                        </div>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            leftIcon={<Icon name="printer" size={14} />}
                                            disabled={!row?.labels_pdf}
                                            onClick={() => setPreview({
                                                title: __('Sheet labels: :sheet', { sheet: row?.sheet ?? '—' }),
                                                pdfUrl: row.labels_pdf,
                                            })}
                                        >
                                            {__('Print')}
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                    <div className="flex justify-end gap-[9px] border-t border-om-line2 bg-om-panel px-[18px] py-[14px]">
                        <Button variant="secondary" onClick={closeMenu}>{__('Close')}</Button>
                    </div>
                </ModalShell>
            )}

            <LabelPreviewModal
                open={preview != null}
                onClose={() => setPreview(null)}
                title={preview?.title}
                pdfUrl={preview?.pdfUrl}
                zplUrl={null}
            />
        </>
    );
}
