import { usePage } from '@inertiajs/react';

/**
 * AGPL-3.0 §13 source offer.
 *
 * `sourceUrl` is shared by HandleInertiaRequests from APP_SOURCE_URL
 * (config/app.php), so a deployment can point at the exact commit it runs.
 * Rendered before login (AuthLayout) and inside the app chrome.
 */
export default function SourceLink({ className = '' }) {
    const sourceUrl = usePage().props.sourceUrl;

    if (!sourceUrl) return null;

    return (
        <a
            href={sourceUrl}
            target="_blank"
            rel="noopener noreferrer"
            className={`font-mono text-[11px] text-om-faint hover:text-om-ink underline underline-offset-2 ${className}`}
        >
            Source (AGPL-3.0)
        </a>
    );
}
