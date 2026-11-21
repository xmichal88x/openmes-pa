<?php

namespace App\Services\Scrap;

use App\Models\Batch;
use App\Models\Line;
use App\Models\ScrapEntry;
use App\Services\Quality\QualityFacts;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Aggregates scrap data for the admin reporting views and the public report
 * API so both surfaces share identical Pareto / scrap-rate maths.
 *
 * Grouping is done in PHP (over a date-bounded set of entries) so the same
 * code runs on PostgreSQL in production and SQLite in the test suite.
 */
class ScrapReportService
{
    public function __construct(private readonly QualityFacts $qualityFacts) {}

    /**
     * Scrap entries in the period (optionally constrained to one line), with
     * their reason eager-loaded.
     */
    private function entries(Carbon $from, Carbon $to, ?int $lineId): Collection
    {
        return ScrapEntry::query()
            ->with(['scrapReason', 'workOrder:id,line_id'])
            ->whereBetween('reported_at', [$from, $to])
            ->when($lineId, fn ($q) => $q->whereHas('workOrder', fn ($w) => $w->where('line_id', $lineId)))
            ->get();
    }

    /**
     * Pareto of scrap quantity by reason, sorted descending, with each reason's
     * share and the running cumulative share.
     */
    public function pareto(Carbon $from, Carbon $to, ?int $lineId = null): array
    {
        $entries = $this->entries($from, $to, $lineId);

        $reasons = $entries
            ->groupBy('scrap_reason_id')
            ->map(function (Collection $rows) {
                $reason = $rows->first()->scrapReason;

                return [
                    'scrap_reason_id' => $reason?->id,
                    'code' => $reason?->code,
                    'name' => $reason?->name ?? __('Unknown'),
                    'category' => $reason?->category,
                    'qty' => round((float) $rows->sum('quantity'), 2),
                    'entries' => $rows->count(),
                ];
            })
            ->sortByDesc('qty')
            ->values();

        $total = round((float) $reasons->sum('qty'), 2);
        $running = 0.0;

        $reasons = $reasons->map(function (array $row) use ($total, &$running) {
            $running += $row['qty'];
            $row['pct'] = $total > 0 ? round(($row['qty'] / $total) * 100, 2) : 0.0;
            $row['cumulative_pct'] = $total > 0 ? round(($running / $total) * 100, 2) : 0.0;

            return $row;
        })->values();

        return [
            'total_qty' => $total,
            'total_entries' => $entries->count(),
            'reasons' => $reasons->all(),
        ];
    }

    /**
     * Scrap quantity grouped by 5M category (material/machine/method/man/environment).
     */
    public function byCategory(Carbon $from, Carbon $to, ?int $lineId = null): array
    {
        return $this->entries($from, $to, $lineId)
            ->groupBy(fn (ScrapEntry $e) => $e->scrapReason?->category ?? 'unknown')
            ->map(fn (Collection $rows, $category) => [
                'category' => $category,
                'qty' => round((float) $rows->sum('quantity'), 2),
                'entries' => $rows->count(),
            ])
            ->sortByDesc('qty')
            ->values()
            ->all();
    }

    /**
     * Scrap rate per line over the period: the scrap of the line divided by the
     * line's good pieces plus that scrap.
     *
     * Numerator - two separate evidences, each counted once:
     *  - `scrap_entries` reported on the line in the window;
     *  - units scrapped on the line's completed batches ({@see QualityFacts::perBatch()}),
     *    which never create a ScrapEntry (`SerialTraceService::scrapUnit()` writes
     *    the status only).
     * A batch's own `scrap_qty` column is deliberately **not** added: the ledger
     * write path (`BatchService::recordQuantity()`) records the same loss as a
     * ScrapEntry too, so adding both would count it twice.
     *
     * Denominator - depends on the evidence the batches carry, because the two
     * flows already account for scrap differently ({@see QualityFacts}):
     *  - `units` batches: good pieces + the scrap above (a packed unit is good
     *    output; the reported scrap was never in it);
     *  - at least one `declared` batch: the declared totals (`total_qty`), which
     *    already include the pieces that were lost - adding the reported scrap
     *    again would count it twice.
     * What it is never: `SUM(batches.produced_qty)` as "produced". That column is
     * a step confirmation, and a re-cut replacement batch would put the same
     * piece in the denominator a second time (RQ-PA-214).
     *
     * What cannot be stated stays empty:
     *  - a line whose window holds a batch with no `produced_qty_source` gets
     *    `produced_qty`, `total_qty`, `scrap_rate_pct` and `produced_basis` null,
     *    plus `unknown_source_batches`;
     *  - a line with no completed batch in the window has no produced evidence at
     *    all: the same nulls, with `unknown_source_batches = 0`.
     */
    public function ratePerLine(Carbon $from, Carbon $to): array
    {
        $scrapEntriesByLine = ScrapEntry::query()
            ->join('work_orders', 'scrap_entries.work_order_id', '=', 'work_orders.id')
            ->whereBetween('scrap_entries.reported_at', [$from, $to])
            ->whereNotNull('work_orders.line_id')
            ->groupBy('work_orders.line_id')
            ->selectRaw('work_orders.line_id as line_id, COALESCE(SUM(scrap_entries.quantity), 0) as scrap_qty')
            ->pluck('scrap_qty', 'line_id');

        $batches = Batch::query()
            ->join('work_orders', 'batches.work_order_id', '=', 'work_orders.id')
            ->where('batches.status', Batch::STATUS_DONE)
            ->whereBetween('batches.completed_at', [$from, $to])
            ->whereNotNull('work_orders.line_id')
            ->select('batches.*', 'work_orders.line_id as line_id')
            ->get();

        $facts = $this->qualityFacts->perBatch($batches);

        /** @var array<int, array<int, array>> $factsByLine */
        $factsByLine = [];
        foreach ($batches as $batch) {
            $factsByLine[(int) $batch->line_id][] = $facts[(int) $batch->id];
        }

        $lineIds = $scrapEntriesByLine->keys()
            ->merge(array_keys($factsByLine))
            ->unique()
            ->values();

        if ($lineIds->isEmpty()) {
            return [];
        }

        $lineNames = Line::whereIn('id', $lineIds)->pluck('name', 'id');

        return $lineIds
            ->map(function ($lineId) use ($scrapEntriesByLine, $factsByLine, $lineNames) {
                $rows = $factsByLine[(int) $lineId] ?? [];
                $basis = QualityFacts::combinedBasis($rows);
                $unknownBatches = count(array_filter($rows, fn (array $row) => $row['unknown']));
                $scrappedUnits = (float) array_sum(array_column($rows, 'scrapped_units_qty'));
                $scrap = round((float) $scrapEntriesByLine->get($lineId, 0) + $scrappedUnits, 2);

                // No basis: either an unknown-source batch or no completed batch
                // at all. Neither a zero nor a partial sum may stand in for it.
                $good = $basis === null ? null : round((float) array_sum(array_column($rows, 'good_qty')), 2);

                $declared = array_filter($rows, fn (array $row) => $row['basis'] === QualityFacts::BASIS_DECLARED);

                $total = match (true) {
                    $good === null => null,
                    // A declared quantity is the attempt count: the reported scrap
                    // is already inside it.
                    $declared !== [] => round((float) array_sum(array_column($rows, 'total_qty')), 2),
                    // Packed units are good output only: the lost pieces enter here.
                    default => round($good + $scrap, 2),
                };

                return [
                    'line_id' => (int) $lineId,
                    'line_name' => $lineNames->get($lineId, '#'.$lineId),
                    'scrap_qty' => $scrap,
                    'produced_qty' => $good,
                    'total_qty' => $total,
                    'scrap_rate_pct' => $total !== null && $total > 0 ? round(($scrap / $total) * 100, 2) : null,
                    'produced_basis' => $basis,
                    'unknown_source_batches' => $unknownBatches,
                ];
            })
            ->sortByDesc('scrap_qty')
            ->values()
            ->all();
    }

    /**
     * Daily scrap-quantity trend across the period.
     */
    public function trend(Carbon $from, Carbon $to, ?int $lineId = null): array
    {
        return $this->entries($from, $to, $lineId)
            ->groupBy(fn (ScrapEntry $e) => $e->reported_at->toDateString())
            ->map(fn (Collection $rows, $date) => [
                'date' => $date,
                'qty' => round((float) $rows->sum('quantity'), 2),
                'entries' => $rows->count(),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }
}
