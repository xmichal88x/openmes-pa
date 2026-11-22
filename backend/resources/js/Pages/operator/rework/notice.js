/**
 * What the screen says after a rework decision, taken straight from the
 * server's answer (UnitLabelStationController::unitAnswer).
 *
 * The server appends its warnings to `message` and lists them in `warnings`,
 * so when warnings exist the screen shows those alone — reading `message` too
 * would print every note twice. Nothing is invented: an answer with neither
 * yields no notice at all rather than a made-up "saved".
 *
 * @returns {{severity: 'warning'|'info', lines: string[]} | null}
 */
export function noticeFrom(payload) {
    const warnings = Array.isArray(payload?.warnings) ? payload.warnings : [];
    const lines = warnings
        .map((warning) => (typeof warning === 'string' ? warning : warning?.message))
        .filter((line) => typeof line === 'string' && line.trim() !== '');

    if (lines.length > 0) {
        return { severity: 'warning', lines };
    }

    const message = typeof payload?.message === 'string' ? payload.message.trim() : '';

    return message === '' ? null : { severity: 'info', lines: [message] };
}
