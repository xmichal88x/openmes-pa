import { Button } from '@openmes/ui';
import ModalShell from './ModalShell';
import { __ } from '../lib/i18n';

/**
 * Minimal confirmation for starting a step whose sheet is shared with other
 * work orders. The backend requires an explicit `confirm_shared_sheet` flag;
 * this modal is the only place the operator gives it.
 */
export default function SharedSheetConfirmModal({ preflight, onConfirm, onCancel }) {
    const orders = preflight?.orders ?? [];
    const count = preflight?.nOrders ?? orders.length;
    const targetJob = preflight?.targetJob;

    return (
        <ModalShell
            title={__('Start shared work')}
            subtitle={targetJob || undefined}
            onClose={onCancel}
        >
            <div className="px-[18px] py-4">
                <p className="text-sm text-om-ink">
                    {__('This will start :count orders on the shared sheet:', { count })}
                </p>
                <ul className="mt-2 space-y-1 font-mono text-[12px] text-om-muted">
                    {orders.map((order) => (
                        <li key={order.order_no}>{order.order_no}</li>
                    ))}
                </ul>
            </div>
            <div className="flex justify-end gap-[9px] border-t border-om-line2 bg-om-panel px-[18px] py-[14px]">
                <Button variant="secondary" type="button" onClick={onCancel}>
                    {__('Cancel')}
                </Button>
                <Button variant="accent" type="button" onClick={onConfirm}>
                    {__('Confirm')}
                </Button>
            </div>
        </ModalShell>
    );
}
