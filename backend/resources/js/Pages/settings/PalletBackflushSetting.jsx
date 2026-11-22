// Backflush on pallet creation is withdrawn, not merely switched off: the
// policy is enforced in code (PalletBackflushService), so the admin sees the
// reason instead of an editable switch that would silently do nothing. There
// is deliberately no way to turn it on from the UI — the server rejects it.
import { Switch } from '@openmes/ui';
import { __ } from '../../lib/i18n';

export default function PalletBackflushSetting({ stored = null }) {
    return (
        <div className="flex items-start gap-3" data-testid="pallet-backflush-policy">
            <Switch
                checked={false}
                disabled
                aria-checked="false"
                data-testid="backflush-switch"
                aria-label={__('Backflush on pallet creation')}
            />
            <div>
                <p className="text-[13px] font-medium text-om-ink">{__('Backflush on pallet creation')}</p>
                <p className="text-om-muted text-[12.5px]">
                    {__('Material booking from pallets is disabled; packing works normally. Raw material is settled once by the operation that consumes it.')}
                </p>
                {/* Diagnostics only: a row left at `true` explains why the setting
                    appears to be on somewhere else — it is not effective. */}
                {stored === true && (
                    <p className="text-om-muted text-[12.5px]" data-testid="backflush-stored-note">
                        {__('The stored value "true" is not effective: this policy is enforced in code.')}
                    </p>
                )}
            </div>
        </div>
    );
}
