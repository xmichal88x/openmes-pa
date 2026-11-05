import { __ } from '../lib/i18n';

/**
 * Non-blocking warning shown when a step's start preflight could not verify the
 * shared sheet. Presentational only — the caller owns dismissal state.
 */
export default function PreflightWarningBanner({ message, onDismiss }) {
    if (typeof message !== 'string' || message.trim() === '') {
        return null;
    }

    return (
        <div role="status" aria-live="polite" className="mb-3 flex items-start justify-between gap-3 rounded-om border border-om-line2 bg-om-panel px-4 py-3 text-sm text-om-muted">
            <span className="min-w-0 break-words">{message}</span>
            <button type="button" onClick={onDismiss} className="shrink-0 cursor-pointer text-[16px] leading-none text-om-faint hover:text-om-muted" aria-label={__('Dismiss')}>
                ×
            </button>
        </div>
    );
}
