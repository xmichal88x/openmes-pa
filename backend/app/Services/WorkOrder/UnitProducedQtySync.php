<?php

namespace App\Services\WorkOrder;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\SerialUnit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\ProductionFlow;
use Illuminate\Support\Facades\Log;

/**
 * Re-derives an order's good units after one of its units changed status
 * (RQ-PA-263 / M3): scrap, re-cut, hold, release.
 *
 * A unit event is a physical fact, so it always lands - this class never
 * refuses it. Only the numbers that follow from a declared source are
 * re-derived, in the caller's transaction:
 *
 * | batch source      | what happens                                              |
 * |-------------------|-----------------------------------------------------------|
 * | units             | the contribution is recounted now; the order follows      |
 * | manual / machine  | nothing - a human/the PLC stated the figure, and the answer |
 * |                   | says so (`produced_qty_declared_unchanged`)               |
 * | unknown, running  | nothing - the batch has not declared a figure yet         |
 * | unknown, finished | nothing, and the answer says `produced_qty_source_unknown` |
 *
 * The order-level policies outrank the batch source. `counting_source=machine|both`
 * means the PLC counter owns the number, and the transfer flow means the step
 * ledger does (`rollUpProducedQty` writes each batch from `passed_qty`, and a
 * correction goes through `correctGoodQuantity`), so in both the batch is left
 * alone and the answer says why (`produced_qty_machine_counter`,
 * `produced_qty_transfer_ledger`).
 *
 * Why the last rows do not throw: a scrap is a physical fact, not a rollup.
 * Blocking the scrapping of a good piece because some batch lacks provenance
 * would be paralysis. It is not a silent fallback either - the stored figure
 * stays exactly as it was, and the gap is named in the response warnings, the
 * audit log and the application log.
 */
class UnitProducedQtySync
{
    /** A finished batch declares no source, so the order cannot be rolled up. */
    public const SOURCE_UNKNOWN = 'produced_qty_source_unknown';

    /** The batch's figure was stated (manual) or counted by the machine. */
    public const DECLARED_FIGURE = 'produced_qty_declared_unchanged';

    /** The order is machine counted, so the PLC counter is the source. */
    public const MACHINE_COUNTER = 'produced_qty_machine_counter';

    /** The transfer flow derives the batch from its step ledger. */
    public const TRANSFER_LEDGER = 'produced_qty_transfer_ledger';

    public function __construct(private readonly WorkOrderProducedQty $rollup) {}

    /**
     * Bring the batch and the order in line with the unit's new status.
     *
     * @param  string  $event  what happened to the unit (`scrapped`, `recut`, `blocked`, `unblocked`)
     * @return array<int, array{code: string, message: string}> what could not be re-derived
     */
    public function afterStatusChange(SerialUnit $unit, string $event, ?User $actor = null): array
    {
        $workOrder = $unit->workOrder;
        $batch = $unit->batch;
        $warnings = [];

        // A cancelled batch contributes nothing to the order (its demand was
        // withdrawn), so there is no number to recompute. A soft-deleted batch
        // does not load at all - deletion puts it outside the rollup.
        if ($batch !== null && $batch->status !== Batch::STATUS_CANCELLED) {
            $warnings = $this->recomputeBatch($batch, $workOrder, $event);
        }

        if ($workOrder === null) {
            // A unit that belongs to no order carries no order quantity.
            return $warnings;
        }

        try {
            $total = $this->rollup->recompute($workOrder->fresh());
        } catch (\DomainException $e) {
            // The order keeps the figure it had: a gap elsewhere is not a reason
            // to invent one here.
            $warnings[] = ['code' => self::SOURCE_UNKNOWN, 'message' => $e->getMessage()];
            $this->reportBlockedRollup($workOrder, $unit, $event, $actor, $e->getMessage());

            return $warnings;
        }

        if ((float) $workOrder->produced_qty !== $total) {
            $workOrder->update(['produced_qty' => $total]);
        }

        return $warnings;
    }

    /**
     * @return array<int, array{code: string, message: string}>
     */
    private function recomputeBatch(Batch $batch, ?WorkOrder $workOrder, string $event): array
    {
        // Polityka transferu: liczba batcha to `passed_qty` jego ostatniego kroku
        // (ledger), a korekta idzie przez `correctGoodQuantity` - przepisanie jej
        // z jednostek rozjechałoby się z ledgerem do najbliższego przejścia.
        if (ProductionFlow::isTransfer()) {
            return [[
                'code' => self::TRANSFER_LEDGER,
                'message' => __(
                    'Batch :batch counts what left its last step (transfer flow), so :event leaves its quantity unchanged.',
                    ['batch' => $batch->id, 'event' => $event],
                ),
            ]];
        }

        // Polityka: counting_source=machine|both ⇒ licznik PLC (decyzja 2026-11-21).
        // The counter owns the order's number, so a unit count may not rewrite
        // the batch either - and saying so keeps it from looking like a guess.
        if ($workOrder?->isMachineCounted()) {
            return [[
                'code' => self::MACHINE_COUNTER,
                'message' => __(
                    'Batch :batch counts by the machine counter (counting_source=:source), so :event leaves its quantity unchanged.',
                    ['batch' => $batch->id, 'source' => $workOrder->counting_source, 'event' => $event],
                ),
            ]];
        }

        $source = $batch->producedQtySource();

        if ($source === Batch::PRODUCED_QTY_SOURCE_MANUAL || $source === Batch::PRODUCED_QTY_SOURCE_MACHINE) {
            return [[
                'code' => self::DECLARED_FIGURE,
                'message' => __(
                    'Batch :batch keeps the quantity it declared (:qty, source :source) - :event does not change a stated figure. Correct the packing quantity if it is wrong.',
                    ['batch' => $batch->id, 'qty' => (float) $batch->produced_qty, 'source' => $source, 'event' => $event],
                ),
            ]];
        }

        if ($source !== Batch::PRODUCED_QTY_SOURCE_UNITS) {
            // unknown: an unfinished batch has not declared a figure yet (its
            // zero is a fact), and a finished one is reported by the order
            // rollup above as `produced_qty_source_unknown`.
            return [];
        }

        // Hand the order over so the contribution applies the counter policy
        // without a query per batch.
        if ($workOrder !== null) {
            $batch->setRelation('workOrder', $workOrder);
        }

        $recounted = $this->rollup->batchContribution($batch);

        if ((float) $batch->produced_qty !== $recounted) {
            $batch->update(['produced_qty' => $recounted]);
        }

        return [];
    }

    /** Name the gap where an operator and a supervisor will see it later. */
    private function reportBlockedRollup(WorkOrder $workOrder, SerialUnit $unit, string $event, ?User $actor, string $message): void
    {
        Log::warning('produced_qty_source_unknown: order rollup skipped after a unit changed status', [
            'work_order_id' => $workOrder->id,
            'unit_id' => $unit->id,
            'unit_event' => $event,
        ]);

        AuditLog::create([
            'user_id' => $actor?->id,
            'entity_type' => WorkOrder::class,
            'entity_id' => $workOrder->id,
            'action' => 'produced_qty_rollup_blocked',
            'before_state' => [
                'produced_qty' => (float) $workOrder->produced_qty,
                'unit_id' => $unit->id,
                'unit_event' => $event,
            ],
            'after_state' => [
                // The stored figure, unchanged - never a recomputed guess.
                'produced_qty' => (float) $workOrder->produced_qty,
                'reason' => self::SOURCE_UNKNOWN,
                'error' => $message,
            ],
        ]);
    }
}
