<?php

namespace App\Services\Material;

use App\Models\AuditLog;
use App\Models\BomItem;
use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;

/**
 * The administrative boundary of the pilot settlement scope (RQ-PA-265).
 *
 * The scope — "these components are settled by the operation that consumes
 * them" — used to be hand-edited into `work_orders.extra_data`. Nobody
 * validated it, nobody recorded who changed it, and a stale id quietly
 * changed who books the material. This service is the only writer: it states
 * why a component of the frozen routing cannot be settled by an operation,
 * refuses the opt-in once the order is in flight, and leaves the decision (who,
 * when) on the order itself.
 *
 * Rules are read from the snapshot the order was released with, so a later
 * routing change never rewrites the terms of an order already on the floor.
 */
class MaterialSettlementScope
{
    /** Nothing was selected, so there is no pilot to switch on. */
    public const ERROR_EMPTY = 'material_settlement_scope_empty';

    /** At least one selected component cannot be settled by its operation. */
    public const ERROR_INELIGIBLE = 'material_settlement_scope_ineligible';

    /** Material is already withdrawn for this order; the scope is fixed. */
    public const ERROR_WORK_ORDER_IN_FLIGHT = 'material_settlement_work_order_in_flight';

    /** Bought per carton/pallet, so one withdrawal per piece is meaningless. */
    public const REASON_NOT_PER_UNIT = 'not_per_unit';

    /** The component no longer exists in the catalogue (or was deleted). */
    public const REASON_MATERIAL_MISSING = 'material_missing';

    /** No step attributed, or the step is not part of the frozen routing. */
    public const REASON_OPERATION_MISSING = 'operation_missing';

    /** Named on more than one BOM line: nobody can say which line consumes it. */
    public const REASON_MULTIPLE_BOM_LINES = 'multiple_bom_lines';

    /** Consumed when the batch closes, which is after the operation is over. */
    public const REASON_SETTLED_AT_END = 'settled_at_end';

    /** Serial-tracked: the quantity is a genealogy fact, not a declaration. */
    public const REASON_SERIAL_TRACKED = 'serial_tracked';

    /** Measured, not counted in finished pieces — the pilot counts pieces. */
    public const REASON_NOT_PER_PIECE = 'not_per_piece';

    /** Something is already allocated to this order; the terms are locked. */
    public const REASON_WORK_ORDER_IN_FLIGHT = 'work_order_in_flight';

    /**
     * Units that are not a count of finished pieces. Declared policy: the pilot
     * settles what can be counted one-for-one at the operation, so a measured
     * quantity stays on the legacy (plan-driven) settlement.
     */
    private const MEASURED_UNITS = ['m2', 'm3', 'm', 'kg', 'g', 'l', 'ml'];

    /**
     * The scope currently recorded on the order.
     *
     * Anything unreadable — no entry, an unexpected shape, `mode` other than
     * `operation`, an empty participant list — reads as disabled, so a
     * malformed snapshot can never widen the pilot by accident.
     *
     * @return array{enabled: bool, materials: array<int, int>, enabled_by: int|null, enabled_at: string|null, disabled_by: int|null, disabled_at: string|null}
     */
    public function current(WorkOrder $workOrder): array
    {
        $current = [
            'enabled' => false,
            'materials' => [],
            'enabled_by' => null,
            'enabled_at' => null,
            'disabled_by' => null,
            'disabled_at' => null,
        ];

        $config = $this->config($workOrder);
        if ($config === null) {
            return $current;
        }

        $current['enabled_by'] = $this->intOrNull($config['enabled_by'] ?? null);
        $current['enabled_at'] = $this->stringOrNull($config['enabled_at'] ?? null);
        $current['disabled_by'] = $this->intOrNull($config['disabled_by'] ?? null);
        $current['disabled_at'] = $this->stringOrNull($config['disabled_at'] ?? null);

        if (($config['mode'] ?? null) !== MaterialSettlementPolicy::MODE_OPERATION) {
            return $current;
        }

        $materials = $this->materialIds($config['materials'] ?? null);
        if ($materials === []) {
            return $current;
        }

        $current['enabled'] = true;
        $current['materials'] = $materials;

        return $current;
    }

    /**
     * Every BOM line of the frozen routing that names a material, together with
     * whether the operation may settle it and, when it may not, the reason code.
     *
     * @return array<int, array{material_id: int, material_code: string|null, material_name: string|null, unit_of_measure: string|null, step_number: int|null, step_name: string|null, consumed_at: string|null, per: string, eligible: bool, reason: string|null}>
     */
    public function candidates(WorkOrder $workOrder): array
    {
        $snapshot = $this->snapshot($workOrder);

        $lines = array_values(array_filter(
            $snapshot['bom'] ?? [],
            fn ($line) => is_array($line) && ! empty($line['material_id']),
        ));

        $stepNumbers = [];
        $stepNames = [];
        foreach ($snapshot['steps'] ?? [] as $step) {
            if (! is_array($step) || ! isset($step['step_number'])) {
                continue;
            }

            $stepNumbers[] = (int) $step['step_number'];
            $stepNames[(int) $step['step_number']] = $step['name'] ?? null;
        }

        $ids = array_map(fn (array $line) => (int) $line['material_id'], $lines);
        $materials = $this->materialsById($ids);
        $lineCounts = array_count_values($ids);
        $inFlight = $this->isInFlight($workOrder);

        $candidates = [];
        foreach ($lines as $line) {
            $materialId = (int) $line['material_id'];
            $material = $materials->get($materialId);
            $stepNumber = isset($line['step_number']) ? (int) $line['step_number'] : null;

            $reason = $inFlight
                ? self::REASON_WORK_ORDER_IN_FLIGHT
                : $this->eligibilityReason($line, $material, $stepNumber, $stepNumbers, $lineCounts);

            $candidates[] = [
                'material_id' => $materialId,
                'material_code' => $line['material_code'] ?? $material?->code,
                'material_name' => $line['material_name'] ?? $material?->name,
                'unit_of_measure' => $line['unit_of_measure'] ?? $material?->unit_of_measure,
                'step_number' => $stepNumber,
                'step_name' => $stepNumber === null ? null : ($stepNames[$stepNumber] ?? null),
                'consumed_at' => isset($line['consumed_at']) ? (string) $line['consumed_at'] : null,
                'per' => (string) ($line['per'] ?? BomItem::PER_UNIT),
                'eligible' => $reason === null,
                'reason' => $reason,
            ];
        }

        return $candidates;
    }

    /**
     * Switch the pilot on for these components, recording who did it and when.
     *
     * Refused when nothing was selected, when any selected component is not
     * eligible according to {@see candidates()}, or when material has already
     * been withdrawn for the order — changing who settles it mid-run would
     * rewrite the terms of an operation already on the floor.
     *
     * @param  array<int, int|string>  $materialIds
     *
     * @throws \DomainException with an `ERROR_*` code as the message prefix
     */
    public function enable(WorkOrder $workOrder, array $materialIds, User $actor): WorkOrder
    {
        $ids = $this->materialIds($materialIds);

        if ($ids === []) {
            throw new \DomainException(sprintf(
                '%s: %s',
                self::ERROR_EMPTY,
                __('Select at least one component of this order.'),
            ));
        }

        if ($this->isInFlight($workOrder)) {
            throw new \DomainException(sprintf(
                '%s: %s',
                self::ERROR_WORK_ORDER_IN_FLIGHT,
                __('Material was already withdrawn for :order_no; the settlement scope is fixed for the rest of the order.', [
                    'order_no' => $workOrder->order_no,
                ]),
            ));
        }

        $refusals = $this->refusals($workOrder, $ids);
        if ($refusals !== []) {
            throw new \DomainException(sprintf(
                '%s: %s',
                self::ERROR_INELIGIBLE,
                __(':components cannot be settled by the operation that consumes them.', [
                    'components' => implode(', ', $refusals),
                ]),
            ));
        }

        $before = $this->extraData($workOrder);
        $extraData = $before;
        $extraData[MaterialSettlementPolicy::EXTRA_DATA_KEY] = [
            'mode' => MaterialSettlementPolicy::MODE_OPERATION,
            'materials' => $ids,
            'enabled_by' => (int) $actor->id,
            'enabled_at' => now()->toIso8601String(),
        ];

        // One update, so the model's audit trait records the whole decision.
        $workOrder->update(['extra_data' => $extraData]);

        $this->recordConsoleAuditIfSkipped($workOrder, $actor, $before);

        return $workOrder;
    }

    /**
     * Switch the pilot off. The decision is recorded on the order rather than
     * erased — an order that was once in the pilot says so, and says who ended
     * it and when.
     *
     * Refused while a pilot withdrawal is still outstanding: leaving the pilot
     * would hand that allocation back to the plan-driven settlement, and the batch
     * would book a quantity nobody declared — exactly the silent substitution
     * this pilot exists to remove. The retrieval must be declared and settled
     * first (a declared zero counts as a declaration).
     *
     * Once the withdrawal is settled the switch is free again: the terms of that
     * settlement are frozen on the allocation itself (RQ-PA-265 A7), so nothing
     * is rewritten by ending the pilot — the legacy rows the order still holds
     * never depended on it.
     *
     * @throws \DomainException with an `ERROR_*` code as the message prefix
     */
    public function disable(WorkOrder $workOrder, User $actor): WorkOrder
    {
        $this->guardDisable($workOrder);

        $before = $this->extraData($workOrder);
        $extraData = $before;
        $extraData[MaterialSettlementPolicy::EXTRA_DATA_KEY] = [
            'mode' => MaterialSettlementPolicy::MODE_LEGACY,
            'disabled_by' => (int) $actor->id,
            'disabled_at' => now()->toIso8601String(),
        ];

        $workOrder->update(['extra_data' => $extraData]);

        $this->recordConsoleAuditIfSkipped($workOrder, $actor, $before);

        return $workOrder;
    }

    /**
     * Is material already allocated to this order? Any allocation at all means
     * the operation has begun to act, so the terms can no longer change.
     */
    public function isInFlight(WorkOrder $workOrder): bool
    {
        return MaterialAllocation::query()
            ->where('work_order_id', $workOrder->id)
            ->exists();
    }

    /**
     * Is a withdrawal on this order still owed a settlement — an allocation
     * that was neither consumed nor returned?
     *
     * Narrower than {@see isInFlight()}: a settled allocation no longer owes
     * anything, so switching the pilot off is safe again. An open one does, and
     * turning the pilot off under it would make the batch fall back to the plan.
     */
    public function hasOpenAllocation(WorkOrder $workOrder): bool
    {
        return MaterialAllocation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('status', MaterialAllocation::STATUS_ALLOCATED)
            ->exists();
    }

    /**
     * Is a withdrawal still owed to the pilot on this order?
     *
     * Narrower than {@see hasOpenAllocation()}: a row whose confirmation froze
     * the `operation` mode is a pilot row wherever the order stands today, and a
     * row that is still unconfirmed but belongs to a component in the current
     * scope is a pilot row as well — its mode is only frozen by the confirmation
     * that is still missing (RQ-PA-265 A7).
     *
     * A settled row (consumed/returned) owes nothing: it carries its own frozen
     * terms, so switching the scope off no longer rewrites how it is corrected.
     */
    public function hasOpenPilotAllocation(WorkOrder $workOrder): bool
    {
        $materials = $this->current($workOrder)['materials'];

        return MaterialAllocation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('status', MaterialAllocation::STATUS_ALLOCATED)
            ->where(function ($query) use ($materials) {
                $query->where('settlement_mode', MaterialSettlementPolicy::MODE_OPERATION);

                if ($materials === []) {
                    return;
                }

                $query->orWhere(function ($unconfirmed) use ($materials) {
                    $unconfirmed->whereNull('settlement_mode')
                        ->whereIn('material_id', $materials);
                });
            })
            ->exists();
    }

    /**
     * Refuse switching the pilot off while a pilot withdrawal is still outstanding.
     *
     * Public so a caller that must not write — a console `--dry-run`, a preview
     * screen — can ask the same question the write path asks, instead of
     * keeping its own copy of the rule.
     *
     * @throws \DomainException with `ERROR_WORK_ORDER_IN_FLIGHT` as the message prefix
     */
    public function guardDisable(WorkOrder $workOrder): void
    {
        if (! $this->hasOpenPilotAllocation($workOrder)) {
            return;
        }

        throw new \DomainException(sprintf(
            '%s: %s',
            self::ERROR_WORK_ORDER_IN_FLIGHT,
            __('A pilot withdrawal is still open for :order_no; declare and settle the retrieval first (a declared zero counts too), then switch the settlement scope off.', [
                'order_no' => $workOrder->order_no,
            ]),
        ));
    }

    /**
     * Write the audit entry the model trait omits in a console run.
     *
     * `Auditable::createAuditLog()` returns early whenever the app runs in the
     * console and is not running unit tests, so a scope changed by
     * `material:pilot` would leave no row in `audit_logs` — only the
     * `enabled_by`/`enabled_at` pair on the order, which says nothing about who
     * replaced an earlier author. The HTTP path is untouched: there the trait
     * already writes the entry, and writing it twice would fake two changes.
     *
     * @param  array<string, mixed>  $before  `extra_data` as it was before the update
     */
    private function recordConsoleAuditIfSkipped(WorkOrder $workOrder, User $actor, array $before): void
    {
        if (! (app()->runningInConsole() && ! app()->runningUnitTests())) {
            return;
        }

        $this->recordConsoleAudit($workOrder, $actor, $before);
    }

    /**
     * The console audit entry: same entity and action the trait would record,
     * attributed to the actor the caller resolved, with the console as the client.
     *
     * @param  array<string, mixed>  $before
     */
    protected function recordConsoleAudit(WorkOrder $workOrder, User $actor, array $before): void
    {
        AuditLog::create([
            'user_id' => (int) $actor->id,
            'entity_type' => WorkOrder::class,
            'entity_id' => $workOrder->id,
            'action' => 'updated',
            'before_state' => ['extra_data' => $before],
            'after_state' => ['extra_data' => $workOrder->fresh()->extra_data],
            'ip_address' => null,
            'user_agent' => 'console',
        ]);
    }

    /**
     * Why one component cannot be settled by its operation, or null when it can.
     *
     * The order of the checks is the order of the answers an administrator
     * needs: what the line is (packaging, then a component at all), where it is
     * consumed, how it is tracked, and what it is measured in.
     *
     * @param  array<string, mixed>  $line
     * @param  array<int, int>  $stepNumbers
     * @param  array<int, int>  $lineCounts
     */
    private function eligibilityReason(array $line, ?Material $material, ?int $stepNumber, array $stepNumbers, array $lineCounts): ?string
    {
        if ((string) ($line['per'] ?? BomItem::PER_UNIT) !== BomItem::PER_UNIT) {
            return self::REASON_NOT_PER_UNIT;
        }

        if ($material === null) {
            return self::REASON_MATERIAL_MISSING;
        }

        if ($stepNumber === null || ! in_array($stepNumber, $stepNumbers, true)) {
            return self::REASON_OPERATION_MISSING;
        }

        if (($lineCounts[(int) $line['material_id']] ?? 0) > 1) {
            return self::REASON_MULTIPLE_BOM_LINES;
        }

        if (($line['consumed_at'] ?? null) === 'end') {
            return self::REASON_SETTLED_AT_END;
        }

        if (($line['tracking_type'] ?? $material->tracking_type) === 'serial') {
            return self::REASON_SERIAL_TRACKED;
        }

        $unit = strtolower(trim((string) ($line['unit_of_measure'] ?? $material->unit_of_measure)));
        if (in_array($unit, self::MEASURED_UNITS, true)) {
            return self::REASON_NOT_PER_PIECE;
        }

        return null;
    }

    /**
     * One readable refusal per selected component that cannot be settled, so
     * the caller learns all of them at once instead of one per attempt.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function refusals(WorkOrder $workOrder, array $ids): array
    {
        $byMaterial = [];
        foreach ($this->candidates($workOrder) as $candidate) {
            $byMaterial[$candidate['material_id']] ??= $candidate;
        }

        $refusals = [];
        foreach ($ids as $id) {
            $candidate = $byMaterial[$id] ?? null;
            if ($candidate === null) {
                $refusals[] = sprintf('%d (%s)', $id, self::REASON_MATERIAL_MISSING);

                continue;
            }

            if (! $candidate['eligible']) {
                $refusals[] = sprintf('%s (%s)', $candidate['material_code'] ?? $id, $candidate['reason']);
            }
        }

        return $refusals;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    private function materialIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $out = [];
        foreach ($ids as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $out[] = (int) $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Material>
     */
    private function materialsById(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        // Soft-deleted components are, for this question, gone: nothing may
        // withdraw them, so the legacy settlement keeps them.
        return Material::query()
            ->whereIn('id', array_values(array_unique($ids)))
            ->get()
            ->keyBy(fn (Material $material) => (int) $material->id);
    }

    /**
     * The recorded scope array, or null when the order carries nothing readable.
     *
     * @return array<string, mixed>|null
     */
    private function config(WorkOrder $workOrder): ?array
    {
        $extraData = $this->extraData($workOrder);
        $config = $extraData[MaterialSettlementPolicy::EXTRA_DATA_KEY] ?? null;

        return is_array($config) ? $config : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function extraData(WorkOrder $workOrder): array
    {
        try {
            $extraData = $workOrder->extra_data;
        } catch (\Throwable) {
            return [];
        }

        return is_array($extraData) ? $extraData : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(WorkOrder $workOrder): array
    {
        try {
            $snapshot = $workOrder->process_snapshot;
        } catch (\Throwable) {
            return [];
        }

        return is_array($snapshot) ? $snapshot : [];
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
