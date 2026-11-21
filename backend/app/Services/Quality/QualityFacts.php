<?php

namespace App\Services\Quality;

use App\Models\Batch;
use App\Models\SerialUnit;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The one implementation of "how much of what we made was good" (P2.7).
 *
 * `produced_qty` does not mean the same thing everywhere, so a percentage
 * cannot be one expression. It is derived from the *evidence* the record has,
 * never from `counting_source` and never from a default:
 *
 * | basis      | chosen when                                              | good           | total        |
 * |------------|----------------------------------------------------------|----------------|--------------|
 * | `units`    | packed units exist (`packed_at` set on a unit of the     | packed units   | good + scrap |
 * |            | order/batch - `App\Services\Packaging\PackedUnitQty`)    |                |              |
 * | `declared` | no packed unit at all (operator / ledger / counter flow) | max(0,         | produced_qty |
 * |            |                                                          | produced-scrap)|              |
 *
 * Why the evidence decides: under the unit flow `produced_qty` already *is* the
 * packed (good) units, so subtracting scrap again would count the same loss
 * twice; under the operator flow `produced_qty` is what was attempted, and the
 * scrap is reported separately. Both flows coexist in one install, so the basis
 * is a property of the record, not of a setting.
 *
 * `scrap` = Σ `scrap_entries.quantity` + the number of `scrapped` units. These
 * are two separate pieces of evidence, not two copies: `SerialTraceService::
 * scrapUnit()` writes the unit's status and its step history, never a
 * ScrapEntry, so a scrapped unit is counted exactly once. A unit that was
 * packed and scrapped afterwards leaves the `good` set (status filter) and
 * enters the `scrap` set, again exactly once.
 *
 * What the answer says when there is no basis:
 *  - `total_qty <= 0` ⇒ `quality_pct` null (no basis: not 0%, not 100%);
 *  - `good_qty = 0` with `total_qty > 0` ⇒ 0.0 (a fact: everything was scrap);
 *  - a batch that never declared where its quantity came from
 *    (`produced_qty_source=unknown`) ⇒ `quality_pct` null, `basis` null and
 *    `unknown_source_batches` = count. Mixing known and unknown batches into
 *    one ratio would quietly shrink the denominator, so the set is not added up
 *    at all. This never throws: the OEE cron must survive it.
 *
 * Query cost: a loaded `scrapEntries` relation costs nothing; the units are
 * always counted in one conditional aggregate, never from a relation, so the
 * cost is at most two queries whatever the batch or unit count (a relation may
 * be loaded with a `limit`, and a partial set would silently undercount).
 */
class QualityFacts
{
    /** Good units come from the packed units the MES counts. */
    public const BASIS_UNITS = 'units';

    /** The record only has a declared quantity; scrap is separate evidence. */
    public const BASIS_DECLARED = 'declared';

    /** Unit statuses that left the line as goods (`PackedUnitQty` reads the same pair). */
    private const GOOD_STATUSES = [SerialUnit::STATUS_COMPLETED, SerialUnit::STATUS_SHIPPED];

    /**
     * Quality of one work order, with the numbers behind it.
     *
     * @return array{basis: string|null, good_qty: float, scrap_qty: float, scrap_entries_qty: float, scrapped_units_qty: float, total_qty: float, quality_pct: float|null}
     */
    public function forWorkOrder(WorkOrder $workOrder): array
    {
        $scrapEntriesQty = $workOrder->relationLoaded('scrapEntries')
            ? (float) $workOrder->scrapEntries->sum('quantity')
            : (float) $workOrder->scrapEntries()->sum('quantity');

        $units = $this->unitsAggregate(SerialUnit::query()->where('work_order_id', $workOrder->id));

        return $this->facts($units, $scrapEntriesQty, (float) $workOrder->produced_qty);
    }

    /**
     * Quality of a set of batches (the OEE window), with the numbers behind it.
     *
     * @param  Collection<int, Batch>|iterable<int, Batch>  $batches
     * @return array{basis: string|null, good_qty: float|null, scrap_qty: float, scrap_entries_qty: float, scrapped_units_qty: float, total_qty: float|null, quality_pct: float|null, unknown_source_batches: int}
     */
    public function forBatches(Collection|iterable $batches): array
    {
        $batches = collect($batches);

        $declaredTotal = (float) $batches->sum(fn (Batch $batch) => (float) $batch->produced_qty);
        $scrapEntriesQty = (float) $batches->sum(fn (Batch $batch) => (float) $batch->scrap_qty);

        $units = $batches->isEmpty()
            ? ['packed' => 0.0, 'scrapped' => 0.0, 'ever_packed' => 0.0]
            : $this->unitsOfBatches($batches);

        $facts = $this->facts($units, $scrapEntriesQty, $declaredTotal);

        // A batch with no declared source makes the window's quality unstatable:
        // its figure is neither attempts nor good units, so it cannot enter
        // either side of the ratio. The count names the gap instead of guessing.
        $unknown = $batches
            ->filter(fn (Batch $batch) => $batch->producedQtySource() === Batch::PRODUCED_QTY_SOURCE_UNKNOWN)
            ->count();

        if ($unknown > 0) {
            $facts['basis'] = null;
            $facts['good_qty'] = null;
            $facts['total_qty'] = null;
            $facts['quality_pct'] = null;
        }

        $facts['unknown_source_batches'] = $unknown;

        return $facts;
    }

    /**
     * Turn the two evidences into a ratio under the basis they support.
     *
     * @param  array{packed: float, scrapped: float, ever_packed: float}  $units
     * @return array{basis: string, good_qty: float, scrap_qty: float, scrap_entries_qty: float, scrapped_units_qty: float, total_qty: float, quality_pct: float|null}
     */
    private function facts(array $units, float $scrapEntriesQty, float $declaredTotal): array
    {
        $scrap = $scrapEntriesQty + $units['scrapped'];
        $basis = $units['ever_packed'] > 0 ? self::BASIS_UNITS : self::BASIS_DECLARED;

        if ($basis === self::BASIS_UNITS) {
            $good = $units['packed'];
            $total = $good + $scrap;
        } else {
            $total = $declaredTotal;
            $good = max(0.0, $total - $scrap);
        }

        return [
            'basis' => $basis,
            'good_qty' => $good,
            'scrap_qty' => $scrap,
            'scrap_entries_qty' => $scrapEntriesQty,
            'scrapped_units_qty' => $units['scrapped'],
            'total_qty' => $total,
            'quality_pct' => $total > 0 ? round($good / $total * 100, 2) : null,
        ];
    }

    /**
     * Unit evidence for a set of batches, with the same I15 rule
     * {@see \App\Services\Packaging\PackedUnitQty} applies: a unit carrying no
     * batch is credited to its order only when that order has a single batch -
     * with more than one, crediting it anywhere would be a guess.
     *
     * @param  Collection<int, Batch>  $batches
     * @return array{packed: float, scrapped: float, ever_packed: float}
     */
    private function unitsOfBatches(Collection $batches): array
    {
        $batchIds = $batches->pluck('id')->filter()->values()->all();
        $orderIds = $batches->pluck('work_order_id')->filter()->unique()->values()->all();

        $singleBatchOrderIds = $orderIds === [] ? [] : WorkOrder::query()
            ->whereIn('id', $orderIds)
            ->withCount('batches')
            ->get(['id'])
            ->filter(fn (WorkOrder $workOrder) => (int) $workOrder->batches_count === 1)
            ->pluck('id')
            ->all();

        $query = SerialUnit::query()->where(function (Builder $query) use ($batchIds, $singleBatchOrderIds) {
            $query->whereIn('batch_id', $batchIds);

            if ($singleBatchOrderIds !== []) {
                $query->orWhere(fn (Builder $inner) => $inner
                    ->whereNull('batch_id')
                    ->whereIn('work_order_id', $singleBatchOrderIds));
            }
        });

        return $this->unitsAggregate($query);
    }

    /**
     * The three unit counts in one query, so the cost never follows the batch
     * count. Kept on the Eloquent builder (not `toBase()`) so global scopes,
     * such as the tenant boundary, still apply.
     *
     * @return array{packed: float, scrapped: float, ever_packed: float}
     */
    private function unitsAggregate(Builder $query): array
    {
        $row = $query->selectRaw(
            'COALESCE(SUM(CASE WHEN status IN (?, ?) AND packed_at IS NOT NULL THEN 1 ELSE 0 END), 0) as packed_units, '
            .'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as scrapped_units, '
            .'COALESCE(SUM(CASE WHEN packed_at IS NOT NULL THEN 1 ELSE 0 END), 0) as ever_packed_units',
            [self::GOOD_STATUSES[0], self::GOOD_STATUSES[1], SerialUnit::STATUS_SCRAPPED],
        )->first();

        return [
            'packed' => (float) $row->packed_units,
            'scrapped' => (float) $row->scrapped_units,
            'ever_packed' => (float) $row->ever_packed_units,
        ];
    }
}
