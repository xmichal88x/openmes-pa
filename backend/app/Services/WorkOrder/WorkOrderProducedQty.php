<?php

namespace App\Services\WorkOrder;

use App\Models\Batch;
use App\Models\SerialUnit;
use App\Models\WorkOrder;
use App\Support\ProductionFlow;

/**
 * The one implementation of a work order's produced quantity (RQ-PA-263).
 *
 * `produced_qty` means good units, so it is derived per batch from the source
 * the batch declares — never from a guess:
 *
 * | batch source | contribution                                                          |
 * |--------------|-----------------------------------------------------------------------|
 * | units        | the units the MES counts now, so scrap and re-cuts change it           |
 * | manual       | the figure a human stated (packing correction)                        |
 * | machine      | the figure the machine counter wrote                                  |
 * | unknown      | nothing: a closed batch raises `produced_qty_source_unknown`;        |
 * |              | a batch still running has not declared a figure yet (0 is a fact)    |
 *
 * `units` with zero units is a correct zero (everything was scrapped): a counted
 * result, not a missing one.
 *
 * Two declared policies override the per-batch reading. Both are decisions, not
 * fallbacks, and both stay visible through `produced_qty_source`:
 *
 *  - counting_source=machine|both ⇒ the PLC counter (or the step ledger it
 *    feeds) is the order's source; unit counts never re-derive its batches.
 *  - transfer flow ⇒ the quantity is what left the batch's last step, and a
 *    batch without a unit record is a normal state there, not an error.
 */
class WorkOrderProducedQty
{
    /**
     * Recompute the order's good units. Callers persist the result; this never
     * writes on its own.
     *
     * @throws \DomainException `produced_qty_source_unknown` when a batch with
     *                          no declared source would enter a new rollup
     */
    public function recompute(WorkOrder $workOrder): float
    {
        // Polityka transferu: produkcja jest tym, co opuściło ostatni krok batcha
        // (`rollUpProducedQty` zapisuje to per batch z ledgera), więc suma po
        // batchach jest poprawna bez patrzenia na źródło i bez wykluczeń — to
        // ledger, nie deklaracja batcha, niesie liczbę.
        if (ProductionFlow::isTransfer()) {
            return (float) $workOrder->batches()->sum('produced_qty');
        }

        // Polityka: counting_source=machine|both ⇒ licznik PLC (decyzja 2026-11-21).
        // The counter owns the number; summing batch confirmations would replace
        // it with the plan every time a step closes.
        if ($workOrder->isMachineCounted()) {
            return (float) $workOrder->produced_qty;
        }

        $total = 0.0;
        $batches = $workOrder->batches()
            ->where('status', '!=', Batch::STATUS_CANCELLED)
            ->get();

        foreach ($batches as $batch) {
            // Hand the loop's order to each batch so the contribution applies the
            // counter policy without a query per batch.
            $batch->setRelation('workOrder', $workOrder);
            $total += $this->batchContribution($batch);
        }

        return $total;
    }

    /**
     * What one batch contributes to its order's good units.
     *
     * @throws \DomainException when the batch declares no source
     */
    public function batchContribution(Batch $batch): float
    {
        $source = $batch->producedQtySource();

        if ($source === Batch::PRODUCED_QTY_SOURCE_UNKNOWN) {
            // Polityka: batch, który się jeszcze nie zamknął, nie zadeklarował
            // ilości — jego zero jest faktem (produced_qty pisze domknięcie), a
            // nie domysłem. Blokuje dopiero zamknięcie bez źródła.
            if ($batch->status !== Batch::STATUS_DONE) {
                return 0.0;
            }

            throw new \DomainException(__(
                'produced_qty_source_unknown: work order :order, batch :batch - nothing records where its produced quantity came from, so it cannot be rolled up. State the source: correct the packing quantity, or let the machine counter record it. The MES will not guess.',
                ['order' => $batch->work_order_id, 'batch' => $batch->id],
            ));
        }

        if ($source === Batch::PRODUCED_QTY_SOURCE_UNITS) {
            // Polityka: counting_source=machine|both ⇒ licznik PLC (decyzja 2026-11-21).
            // On such an order a unit count may not override the counter, so the
            // batch keeps the figure the counter wrote.
            return $batch->workOrder?->isMachineCounted()
                ? (float) $batch->produced_qty
                : $this->unitsQty($batch);
        }

        // manual / machine: a declared figure, deterministic and auditable.
        return (float) $batch->produced_qty;
    }

    /**
     * The units that left the line on this batch. Mirrors the packing gate: units
     * carrying no batch count only when the order has a single batch, so they
     * cannot be credited to the wrong one (I15).
     */
    private function unitsQty(Batch $batch): float
    {
        $statuses = [SerialUnit::STATUS_COMPLETED, SerialUnit::STATUS_SHIPPED];

        $counted = (float) SerialUnit::query()
            ->where('batch_id', $batch->id)
            ->whereIn('status', $statuses)
            ->count();

        $workOrder = $batch->workOrder;

        if ($workOrder && $workOrder->batches()->count() === 1) {
            $counted += (float) SerialUnit::query()
                ->where('work_order_id', $workOrder->id)
                ->whereNull('batch_id')
                ->whereIn('status', $statuses)
                ->count();
        }

        return $counted;
    }
}
