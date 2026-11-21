<?php

namespace App\Services\Packaging;

use App\Models\Batch;
use App\Models\SerialUnit;

/**
 * The one implementation of "how many good units did this batch produce".
 *
 * A good unit is a *packed* unit: status `completed` or `shipped` **and**
 * `packed_at` set. `packed_at` is the physical act of packing recorded by
 * `UnitPackingService::packUnit()` - the moment a unit stops being work in
 * progress and becomes goods that may leave the line.
 *
 * Why the timestamp decides (owner's decision, 2026-11-22): `completed` alone
 * is not proof of a good unit. `SerialTraceService::registerSubassembly()`
 * creates a unit `completed` with no `packed_at` and no batch - a component
 * (board, module) that goes *into* a product, not a product. Counting it as
 * produced inflated the order (plan 1, `produced_qty` 2). The same holds for
 * the kits SolidWorks / Cabinet Vision will send: a component is not a good
 * unit, and the distinction must not depend on who wrote the row.
 *
 * A zero is a fact here, never a default: no packed unit means nothing left
 * the line. Where the figure comes from is stated separately on the batch
 * (`produced_qty_source`), so a zero never silently stands in for missing data.
 *
 * Units carrying no batch are added only when the order has exactly one batch
 * (I15): with more than one they cannot be credited to a specific batch, so
 * crediting them anywhere would be a guess.
 */
class PackedUnitQty
{
    /**
     * Statuses of a unit that left the line. Packing never puts a unit back
     * into production, so these are the only two a packed unit holds.
     */
    private const STATUSES = [SerialUnit::STATUS_COMPLETED, SerialUnit::STATUS_SHIPPED];

    /**
     * The batch's packed units: its own, plus the order's unattributed ones
     * when the order has a single batch.
     */
    public function forBatch(Batch $batch): float
    {
        $counted = (float) SerialUnit::query()
            ->where('batch_id', $batch->id)
            ->whereIn('status', self::STATUSES)
            ->whereNotNull('packed_at')
            ->count();

        $workOrder = $batch->workOrder;

        if ($workOrder && $workOrder->batches()->count() === 1) {
            $counted += (float) SerialUnit::query()
                ->where('work_order_id', $workOrder->id)
                ->whereNull('batch_id')
                ->whereIn('status', self::STATUSES)
                ->whereNotNull('packed_at')
                ->count();
        }

        return $counted;
    }
}
