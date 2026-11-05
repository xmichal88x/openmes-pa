/**
 * Shared modal frame — §09 idiom (radius 12, deep shadow, header hairline +
 * mono subtitle, panel footer supplied by callers inside their own footer).
 */
export default function ModalShell({ title, subtitle, onClose, children }) {
    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            <div className="fixed inset-0 bg-[rgba(10,9,8,0.4)]" onClick={onClose} />
            <div className="flex min-h-full items-center justify-center p-4">
                <div
                    className="relative w-full max-w-md overflow-hidden rounded-om border border-om-line bg-om-card shadow-[0_20px_50px_-20px_rgba(0,0,0,.35)]"
                    onClick={(e) => e.stopPropagation()}
                >
                    <div className="flex items-center justify-between border-b border-om-line2 px-[18px] py-4">
                        <div>
                            <div className="text-[15px] font-semibold text-om-ink">{title}</div>
                            {subtitle != null && (
                                <div className="mt-[3px] font-mono text-[9.5px] uppercase tracking-[0.08em] text-om-faint">{subtitle}</div>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            className="cursor-pointer text-[18px] leading-none text-om-faint hover:text-om-muted"
                        >
                            ×
                        </button>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
