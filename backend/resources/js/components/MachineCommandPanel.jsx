import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Button, Checkbox, Modal, StatusPill } from '@openmes/ui';
import { apiCall, apiGet } from '../lib/http';
import { __, formatDateTime } from '../lib/i18n';
import {
    buildCommandPayload,
    capabilityReasonKey,
    errorMessageKey,
    formatCommandType,
    hasActive,
    isActiveCommand,
    latestActive,
    newIdempotencyKey,
    outcomeKey,
    shouldShowPanel,
    statusTone,
    stepStatusKey,
} from './machine/machineCommandsLogic';

const STATUS_LABELS = {
    pending: 'Pending',
    processing: 'Processing',
    done: 'Done',
    failed: 'Failed',
    cancelled: 'Cancelled',
};

const POLL_MS = 3000;

function errorMessage(json, status, fallbackKey) {
    return json?.error
        || json?.reason
        || json?.message
        || json?.errors?.reason?.[0]
        || `${__(fallbackKey)} (${status})`;
}

export default function MachineCommandPanel({ stepId, caps, className = '' }) {
    const { auth } = usePage().props;
    const userId = auth?.user?.id;
    const canCancelAny = (auth?.user?.roles ?? []).some((role) => role === 'Admin' || role === 'Supervisor');

    const [commands, setCommands] = useState([]);
    const [liveCaps, setLiveCaps] = useState(caps ?? null);
    const [loading, setLoading] = useState(false);
    const [busyType, setBusyType] = useState(null);
    const [error, setError] = useState(null);
    const [notice, setNotice] = useState(null);
    const [m30Open, setM30Open] = useState(false);
    const [reason, setReason] = useState('');
    const [force, setForce] = useState(false);

    // One key per command type, kept until the server accepts the command so a
    // double click or a timeout retry dedupes instead of queueing twice.
    const idempotencyKeys = useRef({});
    const idempotencyKeyFor = useCallback((type) => {
        if (!idempotencyKeys.current[type]) {
            idempotencyKeys.current[type] = newIdempotencyKey();
        }
        return idempotencyKeys.current[type];
    }, []);
    const clearIdempotencyKey = useCallback((type) => {
        delete idempotencyKeys.current[type];
    }, []);

    useEffect(() => {
        setLiveCaps(caps ?? null);
    }, [caps]);

    const isCnc = !!liveCaps?.is_cnc;

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await apiGet(`/operator/batch-step/${stepId}/machine-commands`);
            if (!res.ok) {
                setError(`${__('Command request failed')} (${res.status})`);
                return;
            }

            const json = await res.json();
            setCommands(Array.isArray(json.data) ? json.data : []);
            if (json.capabilities) setLiveCaps(json.capabilities);
            setError(null);
        } catch {
            setError(__('Command request failed'));
        } finally {
            setLoading(false);
        }
    }, [stepId]);

    useEffect(() => {
        if (isCnc) load();
    }, [isCnc, load]);

    const pollActive = hasActive(commands);

    useEffect(() => {
        if (!isCnc || !pollActive) return undefined;
        const timer = setInterval(load, POLL_MS);
        return () => clearInterval(timer);
    }, [isCnc, pollActive, load]);

    const activeCommand = liveCaps?.active_command ?? latestActive(commands);
    const activeTypes = useMemo(() => {
        const types = new Set(commands.filter(isActiveCommand).map((command) => command.type));
        if (activeCommand && isActiveCommand(activeCommand)) types.add(activeCommand.type);
        return types;
    }, [commands, activeCommand]);

    const reasonKey = capabilityReasonKey(liveCaps?.reason);
    const recent = commands.slice(0, 5);
    const canSubmitM30 = reason.trim().length >= 5;
    const visible = shouldShowPanel(liveCaps, recent, activeCommand);

    const closeM30 = useCallback(() => {
        setM30Open(false);
        setReason('');
        setForce(false);
        setError(null);
    }, []);

    const submit = useCallback(async (type) => {
        const payload = buildCommandPayload(type, {
            reason,
            force,
            canM30: !!liveCaps?.can_m30,
        });

        if (type === 'manual_m30' && payload.reason.length < 5) {
            setError(__('Reason must be at least 5 characters'));
            return;
        }

        payload.idempotency_key = idempotencyKeyFor(type);

        setBusyType(type);
        setError(null);
        setNotice(null);

        try {
            const res = await apiCall(`/operator/batch-step/${stepId}/machine-commands`, 'POST', payload);
            const json = await res.json().catch(() => ({}));

            if (!res.ok) {
                setError(errorMessage(json, res.status, 'Command request failed'));
                return;
            }

            clearIdempotencyKey(type);
            setNotice(__('Command queued'));

            if (type === 'manual_m30') {
                closeM30();
            }

            await load();
        } catch {
            setError(__('Command request failed'));
        } finally {
            setBusyType(null);
        }
    }, [clearIdempotencyKey, closeM30, force, idempotencyKeyFor, liveCaps?.can_m30, load, reason, stepId]);

    const cancel = useCallback(async (command) => {
        const entered = window.prompt(__('Cancel command reason (required)'));
        if (entered == null) return;

        const text = entered.trim();
        if (text.length < 5) {
            setError(__('Reason must be at least 5 characters'));
            return;
        }

        setBusyType(`cancel:${command.id}`);
        setError(null);
        setNotice(null);

        try {
            const res = await apiCall(`/operator/machine-commands/${command.id}/cancel`, 'POST', { reason: text });
            const json = await res.json().catch(() => ({}));

            if (!res.ok) {
                setError(errorMessage(json, res.status, 'Cancel request failed'));
                return;
            }

            setNotice(__('Command cancelled'));
            await load();
        } catch {
            setError(__('Command request failed'));
        } finally {
            setBusyType(null);
        }
    }, [load]);

    if (!visible) return null;

    const canCancel = (command) => command.status === 'pending'
        && (canCancelAny || command.requested_by_id === userId)
        && liveCaps?.line_id != null
        && Number(command.line_id) === Number(liveCaps.line_id);

    const disabledReason = (allowed, type) => {
        if (!allowed) return reasonKey ? __(reasonKey) : undefined;
        if (activeTypes.has(type)) return __('A command of this type is already active');
        return undefined;
    };

    return (
        <div className={`w-full rounded-om-sm border border-om-line2 bg-om-panel/40 p-3 ${className}`}>
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-[12px] font-semibold uppercase tracking-[0.08em] text-om-muted">{__('Machine actions')}</span>
                <span className="font-mono text-[10px] uppercase tracking-[0.06em] text-om-faint">{__(stepStatusKey(liveCaps.step_status))}</span>
                <Button variant="ghost" size="sm" className="ml-auto" onClick={load} disabled={loading}>
                    {__('Refresh')}
                </Button>
            </div>

            <div className="mt-2 flex flex-wrap gap-2">
                <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => submit('load_nc')}
                    disabled={!liveCaps.can_load || !!busyType || activeTypes.has('load_nc')}
                    loading={busyType === 'load_nc'}
                    title={disabledReason(liveCaps.can_load, 'load_nc')}
                >
                    {__('Load NC')}
                </Button>
                <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => setM30Open(true)}
                    disabled={!liveCaps.can_m30 || !!busyType || activeTypes.has('manual_m30')}
                    title={disabledReason(liveCaps.can_m30, 'manual_m30')}
                >
                    {__('Finish cut (M30)')}
                </Button>
                <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => submit('generate_labels')}
                    disabled={!liveCaps.can_labels || !!busyType || activeTypes.has('generate_labels')}
                    loading={busyType === 'generate_labels'}
                    title={disabledReason(liveCaps.can_labels, 'generate_labels')}
                >
                    {__('Generate labels')}
                </Button>
            </div>

            {reasonKey && <p className="mt-2 mb-0 text-[12px] text-om-muted">{__(reasonKey)}</p>}

            {activeCommand && (
                <div className="mt-2 rounded-om-sm border border-om-line2 bg-om-card px-2.5 py-1.5 font-mono text-[11px] text-om-running">
                    {__('In progress: :type', { type: __(formatCommandType(activeCommand.type)) })}
                </div>
            )}

            {error && <p role="alert" className="mt-2 mb-0 text-[12px] text-om-blocked">{error}</p>}
            {notice && <p role="status" className="mt-2 mb-0 text-[12px] text-om-running">{notice}</p>}

            <div className="mt-3">
                {recent.length === 0 ? (
                    <p className="m-0 text-[12px] text-om-faint">{__('No machine commands yet')}</p>
                ) : (
                    <ul className="m-0 list-none space-y-1.5 p-0">
                        {recent.map((command) => {
                            const lastError = errorMessageKey(command.last_error);

                            return (
                                <li key={command.id} className="flex flex-wrap items-center gap-2 rounded-om-sm border border-om-line2 bg-om-card px-2.5 py-1.5">
                                    <span className="text-[12px] font-medium text-om-ink">{__(formatCommandType(command.type))}</span>
                                    <StatusPill status={statusTone(command.status)} label={__(STATUS_LABELS[command.status] ?? command.status)} />
                                    {command.outcome && <span className="font-mono text-[10px] text-om-muted">{__(outcomeKey(command.outcome))}</span>}
                                    {lastError && (
                                        <span className="min-w-0 flex-1 break-words font-mono text-[10px] text-om-blocked">{__(lastError)}</span>
                                    )}
                                    <span className="font-mono text-[10px] text-om-faint">{formatDateTime(command.created_at)}</span>
                                    {canCancel(command) && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => cancel(command)}
                                            disabled={!!busyType}
                                            loading={busyType === `cancel:${command.id}`}
                                        >
                                            {__('Cancel command')}
                                        </Button>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            <Modal
                open={m30Open}
                onClose={() => { if (!busyType) closeM30(); }}
                title={__('Finish cut (M30)')}
                subtitle={__('Manual machine stop')}
                closeLabel={__('Close')}
                footer={
                    <>
                        <Button variant="secondary" onClick={closeM30} disabled={!!busyType}>
                            {__('Cancel')}
                        </Button>
                        <Button
                            variant="primary"
                            onClick={() => submit('manual_m30')}
                            loading={busyType === 'manual_m30'}
                            disabled={!canSubmitM30 || !!busyType}
                        >
                            {__('Queue command')}
                        </Button>
                    </>
                }
            >
                <div className="flex flex-col gap-3">
                    {error && <p role="alert" className="m-0 text-[12px] text-om-blocked">{error}</p>}
                    <label className="flex flex-col gap-1">
                        <span className="text-[12px] font-medium text-om-ink">{__('Reason (required)')}</span>
                        <textarea
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            rows={3}
                            minLength={5}
                            maxLength={1000}
                            required
                            placeholder={__('Why is the cut being finished manually?')}
                            className="w-full rounded-om-sm border border-om-line bg-om-bg px-3 py-2 text-[13px] text-om-ink outline-none focus:border-om-accent"
                        />
                    </label>
                    {liveCaps.can_m30 && (
                        <div className="flex flex-col gap-1">
                            <Checkbox
                                checked={force}
                                onChange={setForce}
                                label={__('Force while the machine is running')}
                            />
                            <p className="m-0 text-[11px] text-om-blocked">
                                {__('Force mode skips the machine-idle check. Use only when the machine is still cutting.')}
                            </p>
                        </div>
                    )}
                </div>
            </Modal>
        </div>
    );
}
