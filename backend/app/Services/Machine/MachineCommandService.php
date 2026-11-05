<?php

namespace App\Services\Machine;

use App\Exceptions\MachineCommandException;
use App\Models\AuditLog;
use App\Models\BatchStep;
use App\Models\Line;
use App\Models\MachineCommand;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Workstation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Ramsey\Uuid\Uuid;

/**
 * Machine command outbox service (P1a).
 *
 * MES only records what should happen on the machine and what the gateway
 * reported back. Nothing here opens a socket, an SSH session or an MQTT
 * connection — execution belongs to the gateway.
 */
class MachineCommandService
{
    /**
     * Line ids the actor may touch, memoised per user for the lifetime of the
     * service (one request). The work-order view passes its own list so a
     * page render never runs one `lines()` query per step.
     *
     * @var array<int, array<int, int>>
     */
    private array $lineAccessCache = [];

    /**
     * Line code memoised per line id, so capabilities computed for many steps
     * on the same line do not reload it.
     *
     * @var array<int, string|null>
     */
    private array $lineCodeCache = [];

    /**
     * Create a command intent.
     *
     * Guards run in the documented order; a retried request (same idempotency
     * key and fingerprint) returns the existing row instead of a duplicate.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function create(
        WorkOrder $workOrder,
        BatchStep $step,
        string $type,
        User $actor,
        string $idempotencyKey,
        ?array $payload = null,
        ?string $reason = null,
    ): MachineCommand {
        return DB::transaction(function () use ($workOrder, $step, $type, $actor, $idempotencyKey, $payload, $reason) {
            // 1. Feature and type must be switched on for this instance.
            if (! config('machine_commands.enabled')) {
                throw MachineCommandException::featureDisabled();
            }

            if (! in_array($type, MachineCommand::TYPES, true)) {
                throw MachineCommandException::invalidType($type);
            }

            if (! config('machine_commands.types.'.$type, false)) {
                throw MachineCommandException::featureDisabled($type);
            }

            // manual_m30 is destructive: the role check is repeated here so a
            // hand-granted permission in Settings → Access cannot bypass it.
            if ($type === MachineCommand::TYPE_MANUAL_M30
                && ! $actor->hasAnyRole(['Admin', 'Supervisor'])) {
                throw MachineCommandException::forbidden();
            }

            // `force` is part of the manual_m30 contract only. Rejecting it in
            // the service (not just in the form request) means a console call,
            // a gateway replay or a nested payload cannot smuggle it past the
            // HTTP validation.
            if ($payload !== null && array_key_exists('force', $payload)) {
                if ($type !== MachineCommand::TYPE_MANUAL_M30) {
                    throw MachineCommandException::forceNotAllowed($type);
                }

                if (! is_bool($payload['force'])) {
                    throw MachineCommandException::invalidPayload(
                        'payload.force',
                        __('The force flag must be a boolean.'),
                    );
                }
            }

            // 2. manual_m30 is destructive and needs an auditable reason.
            $reason = $reason !== null ? trim($reason) : null;
            if ($reason === '') {
                $reason = null;
            }

            if ($type === MachineCommand::TYPE_MANUAL_M30 && ($reason === null || mb_strlen($reason) < 5)) {
                throw MachineCommandException::reasonRequired($type);
            }

            $workOrder = WorkOrder::whereKey($workOrder->getKey())->lockForUpdate()->firstOrFail();
            $step = BatchStep::with(['batch', 'workstation.workstationType'])
                ->whereKey($step->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // 3. The step must belong to this work order, on the same line.
            if ((int) $step->batch?->work_order_id !== (int) $workOrder->id) {
                throw MachineCommandException::invalidStep(__('The batch step does not belong to this work order.'));
            }

            if (! $step->workstation_id) {
                throw MachineCommandException::invalidStep(__('Assign a workstation to the step before issuing a machine command.'));
            }

            $workstation = $step->workstation;
            if (! $workstation) {
                throw MachineCommandException::invalidStep(__('The step workstation no longer exists.'));
            }

            $line = Line::whereKey($workOrder->line_id)->first();
            if (! $line) {
                throw MachineCommandException::invalidStep(__('The work order has no production line.'));
            }

            if ((int) $workstation->line_id !== (int) $workOrder->line_id) {
                throw MachineCommandException::invalidStep(__('The step workstation belongs to another production line.'));
            }

            // 4. Only configured lines may issue machine commands.
            if (! $this->codeInList($line->code, config('machine_commands.line_codes', []))) {
                throw MachineCommandException::lineNotAllowed((string) $line->code);
            }

            // 5. The step must look like a CNC step.
            if (! $this->isCncStep($step, $workstation)) {
                throw MachineCommandException::notCncStep();
            }

            // 6. All P1a command types act on a running step only.
            if ($step->status !== BatchStep::STATUS_IN_PROGRESS) {
                throw MachineCommandException::invalidStepStatus((string) $step->status, $type);
            }

            // 7. Shop-floor accounts are scoped to their own lines.
            $this->assertLineAccess($actor, (int) $line->id);

            // 8. Idempotency: same key + same request returns the same row.
            //    The key is globally unique, so a key owned by another tenant is
            //    reported as a conflict instead of leaking that tenant's row.
            $existing = MachineCommand::withoutGlobalScopes()
                ->where('idempotency_key', $idempotencyKey)
                ->when($actor->tenant_id !== null, fn ($query) => $query->where('tenant_id', $actor->tenant_id))
                ->first();

            if ($existing) {
                if ($actor->tenant_id === null && $existing->tenant_id !== null) {
                    throw MachineCommandException::idempotencyConflict();
                }

                if (! $this->fingerprintMatches($existing, $workOrder, $step, $type, $reason, $payload)) {
                    throw MachineCommandException::idempotencyConflict();
                }

                return $existing;
            }

            // The scoped lookup missed; make sure the key is not already taken
            // by a different tenant before the unique index turns it into a 500.
            $takenElsewhere = MachineCommand::withoutGlobalScopes()
                ->where('idempotency_key', $idempotencyKey)
                ->when(
                    $actor->tenant_id !== null,
                    fn ($query) => $query->where(
                        fn ($inner) => $inner
                            ->where('tenant_id', '!=', $actor->tenant_id)
                            ->orWhereNull('tenant_id'),
                    ),
                    fn ($query) => $query->whereNotNull('tenant_id'),
                )
                ->exists();

            if ($takenElsewhere) {
                throw MachineCommandException::idempotencyConflict();
            }

            // 9. One active command per step and type.
            $active = MachineCommand::withoutGlobalScopes()
                ->where('batch_step_id', $step->id)
                ->where('type', $type)
                ->when($actor->tenant_id !== null, fn ($query) => $query->where('tenant_id', $actor->tenant_id))
                ->whereIn('status', MachineCommand::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->exists();

            if ($active) {
                throw MachineCommandException::commandInProgress($type, (int) $step->id);
            }

            // 10. Persist the intent with audit snapshots. State columns are not
            //     fillable; the model defaults keep a new row pending.
            $command = MachineCommand::create([
                'tenant_id' => $actor->tenant_id,
                'work_order_id' => $workOrder->id,
                'batch_step_id' => $step->id,
                'line_id' => $line->id,
                'workstation_id' => $workstation->id,
                'workstation_code' => $workstation->code,
                'step_name' => $step->name,
                'step_kind' => $step->kind,
                'type' => $type,
                'idempotency_key' => $idempotencyKey,
                'requested_by_id' => $actor->id,
                'reason' => $reason,
                'payload' => $payload,
            ]);

            // manual_m30 has no idempotent machine-side identity of its own, so
            // give it a deterministic delivery id derived from the row id.
            if ($type === MachineCommand::TYPE_MANUAL_M30) {
                $command->forceFill([
                    'delivery_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'machine-command:'.$command->id),
                ])->save();
            }

            $after = [
                'type' => $type,
                'work_order_id' => $workOrder->id,
                'batch_step_id' => $step->id,
                'workstation_code' => $workstation->code,
                'reason' => $reason,
            ];

            if ($type === MachineCommand::TYPE_MANUAL_M30 && is_array($payload) && array_key_exists('force', $payload)) {
                $after['force'] = $payload['force'];
            }

            $this->audit('machine_command.requested', $actor->id, $command, $after);

            return $command->fresh();
        });
    }

    /**
     * Claim the oldest runnable command for one of the gateway's workstations.
     *
     * Expired leases are reclaimed first: a row that still has attempts left
     * returns to pending, one that exhausted max_attempts is failed/transient.
     * manual_m30 is never retried after a lease loss — the machine may already
     * have acted, so it is parked as failed/post_effect.
     *
     * @param  array<int, string>  $workstationCodes
     * @param  array<int, string>|null  $types  optional type filter; [] claims nothing
     */
    public function claim(
        array $workstationCodes,
        string $lockToken,
        ?int $leaseSeconds = null,
        ?int $tenantId = null,
        ?int $actorId = null,
        ?array $types = null,
    ): ?MachineCommand {
        $leaseSeconds = max(30, $leaseSeconds ?? (int) config('machine_commands.lease_seconds', 300));
        $workstationCodes = $this->normalizeWorkstationCodes($workstationCodes, $tenantId);

        if ($workstationCodes === []) {
            return null;
        }

        // Kill switch: with the feature off nothing is leased. A globally
        // enabled instance still only runs types that are individually on; a
        // command of a disabled type stays pending for a later enable.
        if (! config('machine_commands.enabled')) {
            throw MachineCommandException::featureDisabled();
        }

        $enabledTypes = array_keys(array_filter(
            (array) config('machine_commands.types', []),
            fn ($enabled) => (bool) $enabled,
        ));

        return DB::transaction(function () use ($workstationCodes, $lockToken, $leaseSeconds, $tenantId, $actorId, $enabledTypes, $types) {
            $now = now();

            // Reclaim before consulting the enabled-type switch: a lease that
            // expired while a type was turned off must not stay `processing`
            // forever just because no type is currently claimable.
            $expired = MachineCommand::withoutGlobalScopes()
                ->where('status', MachineCommand::STATUS_PROCESSING)
                ->whereIn('workstation_code', $workstationCodes)
                ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                ->whereNotNull('locked_until')
                ->where('locked_until', '<', $now)
                ->lockForUpdate()
                ->get();

            foreach ($expired as $command) {
                $this->reclaimExpired($command);
            }

            if ($enabledTypes === []) {
                return null;
            }

            $command = MachineCommand::withoutGlobalScopes()
                ->where('status', MachineCommand::STATUS_PENDING)
                ->whereIn('type', $enabledTypes)
                ->when($types !== null, fn ($query) => $query->whereIn('type', $types))
                ->whereIn('workstation_code', $workstationCodes)
                ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                ->where(function ($query) use ($now) {
                    $query->whereNull('next_attempt_at')
                        ->orWhere('next_attempt_at', '<=', $now);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $command) {
                return null;
            }

            $command->forceFill([
                'status' => MachineCommand::STATUS_PROCESSING,
                'lock_token' => $lockToken,
                'locked_until' => $now->copy()->addSeconds($leaseSeconds),
                'next_attempt_at' => null,
                'started_at' => $command->started_at ?? $now,
                'attempt_count' => (int) $command->attempt_count + 1,
            ])->save();

            $this->audit('machine_command.claimed', $actorId, $command, [
                'type' => $command->type,
                'workstation_code' => $command->workstation_code,
                'attempt_count' => $command->attempt_count,
                'lease_seconds' => $leaseSeconds,
            ]);

            return $command->fresh();
        });
    }

    /**
     * Report the gateway result for a claimed command.
     *
     * Completion is deliberately not guarded by the feature/type flags: turning
     * the feature off stops new work from being leased, but a command that has
     * already run must still be able to report its outcome (otherwise the row
     * would stay `processing` until the lease expires).
     *
     * @param  array<string, mixed>|null  $result
     */
    public function complete(
        MachineCommand $command,
        string $lockToken,
        string $status,
        ?string $outcome = null,
        ?array $result = null,
        ?string $error = null,
        ?string $errorClass = null,
        ?string $deliveryId = null,
    ): MachineCommand {
        return DB::transaction(function () use ($command, $lockToken, $status, $outcome, $result, $error, $errorClass, $deliveryId) {
            // Tenant-scoped refetch: never resolve the row by id alone.
            $command = MachineCommand::withoutGlobalScopes()
                ->where('tenant_id', $command->tenant_id)
                ->whereKey($command->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $isManual = $command->type === MachineCommand::TYPE_MANUAL_M30;

            // Delivery id is the machine-side identity of a manual_m30. It is
            // fixed at creation; the gateway must echo the stored value.
            if (! $isManual && $deliveryId !== null) {
                throw MachineCommandException::deliveryNotAllowed($command->type);
            }

            if ($isManual) {
                if ($command->delivery_id === null || $command->delivery_id === '') {
                    throw MachineCommandException::deliveryMismatch();
                }

                if ($deliveryId !== null && ! hash_equals((string) $command->delivery_id, $deliveryId)) {
                    throw MachineCommandException::deliveryMismatch();
                }
            }

            // Safe retry after a lost response: the same terminal report is a
            // no-op that returns the stored row instead of a 409. A replay that
            // disagrees with what was stored is a conflict, not a retry.
            if ($command->isTerminal()) {
                if ($status !== $command->status) {
                    throw MachineCommandException::invalidTransition($command->status, $status);
                }

                if ($command->status === MachineCommand::STATUS_DONE
                    && $outcome !== null
                    && $command->outcome !== null
                    && $outcome !== $command->outcome) {
                    throw MachineCommandException::invalidTransition($command->status, $status);
                }

                if ($deliveryId === null || $deliveryId === $command->delivery_id) {
                    return $command;
                }

                throw MachineCommandException::invalidTransition($command->status, $status);
            }

            if (! in_array($status, [
                MachineCommand::STATUS_DONE,
                MachineCommand::STATUS_FAILED,
                MachineCommand::STATUS_PENDING,
            ], true)) {
                throw MachineCommandException::invalidTransition($command->status, $status);
            }

            if ($command->status !== MachineCommand::STATUS_PROCESSING) {
                throw MachineCommandException::invalidTransition($command->status, $status);
            }

            if ($command->locked_until !== null && $command->locked_until->lt(now())) {
                throw MachineCommandException::leaseLost();
            }

            if (! $command->lock_token || ! hash_equals($command->lock_token, $lockToken)) {
                throw MachineCommandException::leaseLost();
            }

            // manual_m30 may already have fired the machine cycle, so it must
            // never return to the automatic retry queue. An explicit transient
            // retry is the only exception (the gateway decided the machine has
            // not acted yet, e.g. the delivery is still in progress elsewhere).
            // The attempt budget is still enforced by the pending branch below.
            if ($isManual && $status === MachineCommand::STATUS_PENDING
                && $errorClass !== MachineCommand::ERROR_TRANSIENT) {
                throw MachineCommandException::requeueNotAllowed($errorClass ?? 'unspecified');
            }

            $now = now();
            $fill = [
                'lock_token' => null,
                'locked_until' => null,
            ];

            if ($status === MachineCommand::STATUS_DONE) {
                if ($outcome === null || ! in_array($outcome, MachineCommand::OUTCOMES, true)) {
                    throw MachineCommandException::invalidOutcome($outcome);
                }

                $fill['status'] = MachineCommand::STATUS_DONE;
                $fill['outcome'] = $outcome;
                $fill['finished_at'] = $now;
                $fill['next_attempt_at'] = null;
                $fill['last_error'] = null;
                $fill['error_class'] = null;

                if ($result !== null) {
                    $fill['result'] = $result;
                }
            } elseif ($status === MachineCommand::STATUS_FAILED) {
                if ($error === null || trim($error) === '') {
                    throw MachineCommandException::errorRequired();
                }

                if ($errorClass !== null && ! in_array($errorClass, MachineCommand::ERROR_CLASSES, true)) {
                    throw MachineCommandException::invalidTransition($command->status, $status);
                }

                $fill['status'] = MachineCommand::STATUS_FAILED;
                $fill['last_error'] = $error;
                $fill['error_class'] = $errorClass;
                $fill['finished_at'] = $now;
                $fill['next_attempt_at'] = null;

                if ($result !== null) {
                    $fill['result'] = $result;
                }
            } else {
                // Requeue is for transient faults only; a permanent/conflict
                // report must be reported as failed instead of spinning.
                $effectiveClass = $errorClass ?? MachineCommand::ERROR_TRANSIENT;

                if ($effectiveClass !== MachineCommand::ERROR_TRANSIENT) {
                    throw MachineCommandException::requeueNotAllowed($effectiveClass);
                }

                // The result of a failed attempt is not final; ignore it here.
                $maxAttempts = max(1, (int) config('machine_commands.max_attempts', 5));

                if ((int) $command->attempt_count >= $maxAttempts) {
                    $fill['status'] = MachineCommand::STATUS_FAILED;
                    $fill['error_class'] = MachineCommand::ERROR_TRANSIENT;
                    $fill['last_error'] = $error
                        ?? $command->last_error
                        ?? __('The machine command exhausted its retry budget.');
                    $fill['finished_at'] = $now;
                    $fill['next_attempt_at'] = null;
                } else {
                    $fill['status'] = MachineCommand::STATUS_PENDING;
                    $fill['next_attempt_at'] = $now->copy()->addSeconds($this->retryDelay((int) $command->attempt_count));
                    $fill['error_class'] = MachineCommand::ERROR_TRANSIENT;

                    if ($error !== null) {
                        $fill['last_error'] = $error;
                    }
                }
            }

            $command->forceFill($fill)->save();

            $action = match ($command->status) {
                MachineCommand::STATUS_DONE => 'machine_command.completed',
                MachineCommand::STATUS_FAILED => 'machine_command.failed',
                default => 'machine_command.requeued',
            };

            $this->audit($action, null, $command, [
                'status' => $command->status,
                'outcome' => $command->outcome,
                'error' => $command->last_error,
                'error_class' => $command->error_class,
                'delivery_id' => $command->delivery_id,
            ]);

            return $command->fresh();
        });
    }

    /**
     * Cancel a command that has not been claimed yet. Processing and terminal
     * rows are immutable.
     */
    public function cancel(MachineCommand $command, User $actor, string $reason): MachineCommand
    {
        return DB::transaction(function () use ($command, $actor, $reason) {
            $command = MachineCommand::withoutGlobalScopes()
                ->where('tenant_id', $command->tenant_id)
                ->whereKey($command->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($command->status !== MachineCommand::STATUS_PENDING) {
                throw MachineCommandException::invalidTransition($command->status, MachineCommand::STATUS_CANCELLED);
            }

            $reason = trim($reason);
            if (mb_strlen($reason) < 5) {
                throw MachineCommandException::reasonRequired(MachineCommand::STATUS_CANCELLED);
            }

            $command->forceFill([
                'status' => MachineCommand::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by_id' => $actor->id,
                'cancel_reason' => $reason,
                'lock_token' => null,
                'locked_until' => null,
                'next_attempt_at' => null,
            ])->save();

            $this->audit('machine_command.cancelled', $actor->id, $command, [
                'cancel_reason' => $reason,
            ]);

            return $command->fresh();
        });
    }

    /**
     * Reclaim every expired processing row. Intended for a future scheduler;
     * not registered in P1a.
     */
    public function expireLeases(?int $tenantId = null): int
    {
        return DB::transaction(function () use ($tenantId) {
            $now = now();

            $commands = MachineCommand::withoutGlobalScopes()
                ->where('status', MachineCommand::STATUS_PROCESSING)
                ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                ->whereNotNull('locked_until')
                ->where('locked_until', '<', $now)
                ->lockForUpdate()
                ->get();

            foreach ($commands as $command) {
                $this->reclaimExpired($command);
            }

            return $commands->count();
        });
    }

    /**
     * Commands recorded for a step, newest first (P2 UI).
     *
     * `$allowedTypes` is the visibility whitelist from the policy; `null` means
     * every type is visible. An empty list returns no rows.
     *
     * @param  array<int, string>|null  $allowedTypes
     * @return Collection<int, MachineCommand>
     */
    public function findForStep(BatchStep $step, ?string $type = null, ?array $allowedTypes = null): Collection
    {
        return MachineCommand::query()
            ->where('batch_step_id', $step->id)
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->when($allowedTypes !== null, fn ($query) => $query->whereIn('type', $allowedTypes))
            ->orderByDesc('id')
            ->get();
    }

    /**
     * What the action panel may offer for one step (P2a).
     *
     * The UI must not infer permissions or CNC-ness from step names, so this
     * mirrors the create/claim guards and reports the same reasons. Line context
     * is the work order's line: the workstation must sit on it, its code must be
     * in `machine_commands.line_codes`, and the actor must have access to it.
     *
     * `$workOrder` lets the caller hand in the already-loaded order (with its
     * line relation). `$allowedLineIds` lets the view resolve the actor's lines
     * once; `null` falls back to the memoised lookup (and means "all lines" for
     * Admin/Supervisor, which is handled by the role check).
     *
     * `$activeLookupDone` says the caller already preloaded the active commands
     * for this step; with it a null `$activeCommand` means "none", not "look it
     * up", so a multi-step page does not run one SELECT per step.
     *
     * The active command is filtered by the policy's visible types so a
     * manual_m30 row never leaks into an operator's capabilities.
     *
     * @param  array<int, int>|null  $allowedLineIds
     * @return array{
     *     is_cnc: bool,
     *     step_status: string,
     *     can_load: bool,
     *     can_m30: bool,
     *     can_labels: bool,
     *     line_id: int|null,
     *     visible_types: array<int, string>|null,
     *     active_command: array{id: int, type: string, status: string}|null,
     *     feature_enabled: bool,
     *     reason: string|null
     * }
     */
    public function capabilitiesFor(
        User $user,
        BatchStep $step,
        ?MachineCommand $activeCommand = null,
        ?WorkOrder $workOrder = null,
        ?array $allowedLineIds = null,
        bool $activeLookupDone = false,
    ): array {
        $workOrder ??= $step->batch?->workOrder;

        $policy = Gate::getPolicyFor(MachineCommand::class);
        $visibleTypes = $policy === null ? [] : $policy->visibleTypes($user);

        $workstation = $step->workstation;
        $isCnc = $workstation !== null && $this->isCncStep($step, $workstation);

        $globalEnabled = (bool) config('machine_commands.enabled');
        $typeEnabled = [
            MachineCommand::TYPE_LOAD_NC => $globalEnabled && (bool) config('machine_commands.types.'.MachineCommand::TYPE_LOAD_NC, false),
            MachineCommand::TYPE_MANUAL_M30 => $globalEnabled && (bool) config('machine_commands.types.'.MachineCommand::TYPE_MANUAL_M30, false),
            MachineCommand::TYPE_GENERATE_LABELS => $globalEnabled && (bool) config('machine_commands.types.'.MachineCommand::TYPE_GENERATE_LABELS, false),
        ];
        $featureEnabled = in_array(true, $typeEnabled, true);

        $inProgress = $step->status === BatchStep::STATUS_IN_PROGRESS;

        $workOrderLineId = $workOrder?->line_id !== null ? (int) $workOrder->line_id : null;
        $lineCode = $this->lineCode($workOrderLineId, $workOrder);
        $lineAllowed = $lineCode !== null
            && $this->codeInList($lineCode, config('machine_commands.line_codes', []));
        $lineMatches = $workOrderLineId !== null
            && $workstation?->line_id !== null
            && (int) $workstation->line_id === $workOrderLineId;
        $hasLineAccess = $workOrderLineId !== null
            && $this->hasLineAccessFor($user, $workOrderLineId, $allowedLineIds);

        $canRequestType = fn (string $type): bool => $typeEnabled[$type]
            && Gate::forUser($user)->allows('create', [MachineCommand::class, $type]);

        $baseAllowed = $featureEnabled && $isCnc && $inProgress && $lineAllowed && $lineMatches && $hasLineAccess;

        $canLoad = $baseAllowed && $canRequestType(MachineCommand::TYPE_LOAD_NC);
        $canM30 = $baseAllowed && $canRequestType(MachineCommand::TYPE_MANUAL_M30);
        $canLabels = $baseAllowed && $canRequestType(MachineCommand::TYPE_GENERATE_LABELS);

        $canRequestAny = $canRequestType(MachineCommand::TYPE_LOAD_NC)
            || $canRequestType(MachineCommand::TYPE_MANUAL_M30)
            || $canRequestType(MachineCommand::TYPE_GENERATE_LABELS);

        $reason = match (true) {
            ! $featureEnabled => 'feature_disabled',
            ! $isCnc => 'not_cnc',
            ! $inProgress => 'step_not_in_progress',
            ! $lineAllowed => 'line_not_allowed',
            ! $lineMatches => 'line_mismatch',
            ! $hasLineAccess || ! $canRequestAny => 'forbidden',
            default => null,
        };

        // A caller-supplied row is authoritative: if its type is not visible the
        // panel gets null instead of a hidden command leaking through.
        $activeCommandProvided = $activeCommand !== null;

        if ($activeCommand !== null
            && $visibleTypes !== null
            && ! in_array($activeCommand->type, $visibleTypes, true)) {
            $activeCommand = null;
        }

        if (! $activeCommandProvided && ! $activeLookupDone && $visibleTypes !== []) {
            $activeCommand = MachineCommand::query()
                ->where('batch_step_id', $step->id)
                ->whereIn('status', MachineCommand::ACTIVE_STATUSES)
                ->when($visibleTypes !== null, fn ($query) => $query->whereIn('type', $visibleTypes))
                ->orderByDesc('id')
                ->first();
        }

        return [
            'is_cnc' => $isCnc,
            'step_status' => (string) $step->status,
            'can_load' => $canLoad,
            'can_m30' => $canM30,
            'can_labels' => $canLabels,
            'line_id' => $workOrderLineId,
            'visible_types' => $visibleTypes,
            'active_command' => $activeCommand === null ? null : [
                'id' => (int) $activeCommand->id,
                'type' => (string) $activeCommand->type,
                'status' => (string) $activeCommand->status,
            ],
            'feature_enabled' => $featureEnabled,
            'reason' => $reason,
        ];
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    private function assertLineAccess(User $actor, int $lineId): void
    {
        if (! $this->hasLineAccess($actor, $lineId)) {
            throw MachineCommandException::lineForbidden($lineId);
        }
    }

    /**
     * @param  array<int, int>|null  $allowedLineIds
     */
    private function hasLineAccessFor(User $actor, int $lineId, ?array $allowedLineIds): bool
    {
        if ($actor->hasAnyRole(['Admin', 'Supervisor'])) {
            return true;
        }

        $allowed = array_map('intval', $allowedLineIds ?? $this->allowedLineIds($actor));

        return in_array($lineId, $allowed, true);
    }

    private function hasLineAccess(User $actor, int $lineId): bool
    {
        return $this->hasLineAccessFor($actor, $lineId, null);
    }

    /**
     * @return array<int, int>
     */
    private function allowedLineIds(User $actor): array
    {
        $key = (int) $actor->getKey();

        if (! array_key_exists($key, $this->lineAccessCache)) {
            $this->lineAccessCache[$key] = $actor->lines()
                ->pluck('lines.id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return $this->lineAccessCache[$key];
    }

    private function lineCode(?int $lineId, ?WorkOrder $workOrder): ?string
    {
        if ($lineId === null) {
            return null;
        }

        if ($workOrder !== null
            && $workOrder->relationLoaded('line')
            && (int) $workOrder->line?->id === $lineId) {
            return $workOrder->line?->code;
        }

        if (! array_key_exists($lineId, $this->lineCodeCache)) {
            $this->lineCodeCache[$lineId] = Line::whereKey($lineId)->value('code');
        }

        return $this->lineCodeCache[$lineId];
    }

    private function isCncStep(BatchStep $step, Workstation $workstation): bool
    {
        // Packing work is never a CNC step, regardless of what the line, the
        // workstation or the step name says.
        if ($step->kind === TemplateStep::KIND_PACKING) {
            return false;
        }

        $allowedTypes = config('machine_commands.workstation_type_codes', []);

        if ($allowedTypes !== []) {
            $typeCode = $workstation->workstationType?->code;

            if (! $this->codeInList($typeCode, $allowedTypes)) {
                return false;
            }
        }

        // The line code is deliberately not a needle: a CNC line can also run
        // packing or other non-CNC steps.
        $needles = array_values(array_filter(array_map(
            fn ($value) => mb_strtolower(trim((string) $value)),
            [
                $step->name,
                $workstation->code,
                $workstation->workstationType?->code,
            ],
        ), fn (string $value) => $value !== ''));

        foreach (config('machine_commands.step_match', []) as $token) {
            $token = mb_strtolower(trim((string) $token));

            if ($token === '') {
                continue;
            }

            foreach ($needles as $haystack) {
                if (str_contains($haystack, $token)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>|null  $code
     */
    private function codeInList(?string $code, array $codes): bool
    {
        if ($code === null) {
            return false;
        }

        $code = mb_strtolower(trim($code));

        foreach ($codes as $candidate) {
            if (mb_strtolower(trim((string) $candidate)) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Trim, dedupe and drop empty codes. With a tenant in play, a code that
     * exists in MES only on another tenant's line is not claimable; a code with
     * no MES workstation record stays allowed (the gateway may know machines
     * that MES does not).
     *
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    private function normalizeWorkstationCodes(array $codes, ?int $tenantId): array
    {
        $normalized = [];

        foreach ($codes as $code) {
            $code = trim((string) $code);

            if ($code !== '') {
                $normalized[$code] = true;
            }
        }

        $normalized = array_keys($normalized);

        if ($tenantId === null || $normalized === []) {
            return $normalized;
        }

        $records = Workstation::query()
            ->whereIn('code', $normalized)
            ->get(['id', 'code', 'line_id']);

        $lineTenants = Line::withoutGlobalScopes()
            ->whereIn('id', $records->pluck('line_id')->filter()->unique()->all())
            ->pluck('tenant_id', 'id');

        return array_values(array_filter($normalized, function (string $code) use ($records, $lineTenants, $tenantId): bool {
            $matches = $records->where('code', $code);

            if ($matches->isEmpty()) {
                return true;
            }

            return $matches->contains(function (Workstation $workstation) use ($lineTenants, $tenantId): bool {
                $lineTenant = $lineTenants->get($workstation->line_id);

                // A null tenant is a shared/single-tenant line; it is legal for
                // any tenant to address its workstations.
                return $lineTenant === null || (int) $lineTenant === $tenantId;
            });
        }));
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function reclaimExpired(MachineCommand $command): void
    {
        $attempts = (int) $command->attempt_count;
        $maxAttempts = max(1, (int) config('machine_commands.max_attempts', 5));

        // manual_m30 may already have fired the machine cycle. A lost lease is
        // a post-effect failure: never retry it automatically.
        if ($command->type === MachineCommand::TYPE_MANUAL_M30 && $attempts >= 1) {
            $command->forceFill([
                'status' => MachineCommand::STATUS_FAILED,
                'lock_token' => null,
                'locked_until' => null,
                'next_attempt_at' => null,
                'finished_at' => now(),
                'error_class' => MachineCommand::ERROR_POST_EFFECT,
                'last_error' => $command->last_error
                    ?? __('The manual M30 command lease expired; the machine may already have run.'),
            ])->save();

            $this->audit('machine_command.failed', null, $command, [
                'attempt_count' => $attempts,
                'reason' => 'lease_expired_manual',
                'error_class' => MachineCommand::ERROR_POST_EFFECT,
            ]);

            return;
        }

        if ($attempts < $maxAttempts) {
            $command->forceFill([
                'status' => MachineCommand::STATUS_PENDING,
                'lock_token' => null,
                'locked_until' => null,
                'next_attempt_at' => now()->addSeconds($this->retryDelay($attempts)),
                'error_class' => MachineCommand::ERROR_TRANSIENT,
                'last_error' => $command->last_error ?? __('The machine command lease expired.'),
            ])->save();

            $this->audit('machine_command.requeued', null, $command, [
                'attempt_count' => $attempts,
                'reason' => 'lease_expired',
            ]);

            return;
        }

        $command->forceFill([
            'status' => MachineCommand::STATUS_FAILED,
            'lock_token' => null,
            'locked_until' => null,
            'next_attempt_at' => null,
            'finished_at' => now(),
            'error_class' => MachineCommand::ERROR_TRANSIENT,
            'last_error' => $command->last_error ?? __('The machine command lease expired after :count attempts.', [
                'count' => $attempts,
            ]),
        ])->save();

        $this->audit('machine_command.failed', null, $command, [
            'attempt_count' => $attempts,
            'reason' => 'lease_expired',
        ]);
    }

    private function retryDelay(int $attempts): int
    {
        return max(30, 30 * max(1, $attempts));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function fingerprintMatches(
        MachineCommand $command,
        WorkOrder $workOrder,
        BatchStep $step,
        string $type,
        ?string $reason,
        ?array $payload,
    ): bool {
        return (int) $command->work_order_id === (int) $workOrder->id
            && (int) $command->batch_step_id === (int) $step->id
            && $command->type === $type
            && $command->reason === $reason
            && $this->normalize($command->payload) === $this->normalize($payload);
    }

    /**
     * Key-order-insensitive comparison of the recorded payload.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function normalize(?array $payload): array
    {
        $payload ??= [];
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normalize($value);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(string $action, ?int $userId, MachineCommand $command, array $after): void
    {
        AuditLog::create([
            'user_id' => $userId,
            'entity_type' => MachineCommand::class,
            'entity_id' => $command->id,
            'action' => $action,
            'before_state' => null,
            'after_state' => $after,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
