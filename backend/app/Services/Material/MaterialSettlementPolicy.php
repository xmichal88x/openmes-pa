<?php

namespace App\Services\Material;

use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\WorkOrder;

/**
 * Which scope settles an allocation, and what to report while it waits.
 *
 * Follows the SAP rule that consumption cannot be posted for a product marked
 * for backflush — one item, one author of the withdrawal — and the SAP/Oracle
 * rule that a missing confirmation never stands in for the plan: the line is
 * parked for settlement (COGI, Pending Transactions) while the rest of the
 * batch books as usual.
 *
 * The scope is read from the work order's frozen snapshot only. Changing the
 * pilot later therefore never rewrites what an in-flight batch was allocated
 * under: the order carries the terms it was released with.
 */
class MaterialSettlementPolicy
{
    /** The operation that consumes the material is the author of the withdrawal. */
    public const MODE_OPERATION = 'operation';

    /** No opt-in: the pre-existing batch-level settlement, unchanged. */
    public const MODE_LEGACY = 'legacy';

    /** Where the scope lives on `work_orders.extra_data`. */
    public const EXTRA_DATA_KEY = 'material_settlement';

    public const STATE_PENDING = 'pending';

    public const STATE_SETTLED = 'settled';

    public const STATE_OPEN = 'open';

    /** No operation was attributed, so nobody can answer for the consumption. */
    public const REASON_OPERATION_NOT_ATTRIBUTED = 'operation_not_attributed';

    /** The operation ran but the retrieval was never declared. */
    public const REASON_CONSUMPTION_NOT_CONFIRMED = 'consumption_not_confirmed';

    /**
     * The settlement scope this work order carries for one material.
     *
     * Anything unreadable — no entry, an unexpected shape, a material that is
     * not listed — reads as `legacy`, so a malformed snapshot can never widen
     * the pilot by accident.
     */
    public function modeFor(WorkOrder $workOrder, int $materialId): string
    {
        try {
            $extraData = $workOrder->extra_data;
        } catch (\Throwable) {
            return self::MODE_LEGACY;
        }

        if (! is_array($extraData)) {
            return self::MODE_LEGACY;
        }

        $config = $extraData[self::EXTRA_DATA_KEY] ?? null;
        if (! is_array($config) || ($config['mode'] ?? null) !== self::MODE_OPERATION) {
            return self::MODE_LEGACY;
        }

        $materials = $config['materials'] ?? null;
        if (! is_array($materials)) {
            return self::MODE_LEGACY;
        }

        foreach ($materials as $id) {
            if (is_numeric($id) && (int) $id === $materialId) {
                return self::MODE_OPERATION;
            }
        }

        return self::MODE_LEGACY;
    }

    /**
     * Why this allocation is still waiting to be settled, or null when it is
     * not waiting at all. Only the pilot scope waits: the legacy scope keeps
     * settling with the plan at batch completion.
     */
    public function pendingReason(MaterialAllocation $allocation): ?string
    {
        if ($allocation->status !== MaterialAllocation::STATUS_ALLOCATED) {
            return null;
        }

        $workOrder = $allocation->workOrder;
        if (! $workOrder) {
            return null;
        }

        if ($this->modeFor($workOrder, (int) $allocation->material_id) !== self::MODE_OPERATION) {
            return null;
        }

        if ($allocation->batch_step_id === null) {
            return self::REASON_OPERATION_NOT_ATTRIBUTED;
        }

        if (! $allocation->consumption_recorded) {
            return self::REASON_CONSUMPTION_NOT_CONFIRMED;
        }

        return null;
    }

    /**
     * Is a settlement owed on this material by some operation? True while any
     * of its open allocations sits in the pilot scope and is still waiting, so
     * nobody else may write its balance in the meantime.
     */
    public function hasPendingSettlement(Material $material): bool
    {
        return MaterialAllocation::query()
            ->where('material_id', $material->id)
            ->where('status', MaterialAllocation::STATUS_ALLOCATED)
            ->with('workOrder')
            ->get()
            ->contains(fn (MaterialAllocation $allocation) => $this->pendingReason($allocation) !== null);
    }

    /**
     * `pending` while a settlement is owed, `settled` once the allocation is
     * closed (consumed or returned), `open` for everything still in flight.
     */
    public function state(MaterialAllocation $allocation): string
    {
        if ($this->pendingReason($allocation) !== null) {
            return self::STATE_PENDING;
        }

        return match ($allocation->status) {
            MaterialAllocation::STATUS_CONSUMED, MaterialAllocation::STATUS_RETURNED => self::STATE_SETTLED,
            default => self::STATE_OPEN,
        };
    }
}
