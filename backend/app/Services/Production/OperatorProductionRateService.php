<?php

namespace App\Services\Production;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\User;
use App\Models\Workstation;
use App\Services\Quality\QualityFacts;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Operator production rate per machine - how fast a given worker performs on a
 * given workstation, in units/hour.
 *
 * Derived live from existing production events (no dedicated table): every
 * completed batch step records who ran it (completed_by_id), on which machine
 * (workstation_id) and for how long (duration_minutes). A batch flows through
 * each of its steps, so a step "processes" the batch's produced quantity. The
 * rate for an (operator, workstation) pair is therefore:
 *
 *     units/hour = Σ(good pieces of the pair's batches)
 *                  ────────────────────────────────────────
 *                  Σ(step duration_minutes for the pair) / 60
 *
 * The produced figure is the batch's **good pieces** ({@see QualityFacts}), not
 * `batches.produced_qty`: that column is a step confirmation (R40/P2.12), so a
 * re-cut replacement batch would credit the same piece twice - the original
 * still carries its confirmation while the replacement carries its good unit.
 * Which evidence the number rests on rides along as `produced_basis`, and a
 * batch that never declared its source leaves the row empty
 * (`produced_units` null + `unknown_source_batches`), never a guessed zero.
 *
 * Because it reads the live step/batch data, the metric reflects new production
 * the moment a step completes - there is nothing to recompute.
 */
class OperatorProductionRateService
{
    public function __construct(private readonly QualityFacts $qualityFacts) {}

    /**
     * Production rates for every (operator, workstation) pair with history,
     * sorted fastest first. Optionally narrowed by line, time window or a
     * specific operator / workstation.
     *
     * @return \Illuminate\Support\Collection<int, array>
     */
    public function rates(
        ?int $lineId = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?int $operatorId = null,
        ?int $workstationId = null,
    ): Collection {
        // BatchStep's own soft-delete scope applies through Eloquent; the joined
        // batches are guarded explicitly (a raw join bypasses global scopes).
        $query = BatchStep::query()
            ->join('batches', 'batch_steps.batch_id', '=', 'batches.id')
            ->whereNull('batches.deleted_at')
            ->where('batch_steps.status', BatchStep::STATUS_DONE)
            ->whereNotNull('batch_steps.completed_by_id')
            ->whereNotNull('batch_steps.workstation_id')
            ->whereNotNull('batch_steps.duration_minutes')
            ->where('batch_steps.duration_minutes', '>', 0)
            ->where('batches.produced_qty', '>', 0)
            ->when($from, fn ($q) => $q->where('batch_steps.completed_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('batch_steps.completed_at', '<=', $to))
            ->when($operatorId, fn ($q) => $q->where('batch_steps.completed_by_id', $operatorId))
            ->when($workstationId, fn ($q) => $q->where('batch_steps.workstation_id', $workstationId));

        if ($lineId) {
            $query->whereExists(fn ($sub) => $sub->select(DB::raw(1))
                ->from('workstations')
                ->whereColumn('workstations.id', 'batch_steps.workstation_id')
                ->whereNull('workstations.deleted_at')
                ->where('workstations.line_id', $lineId));
        }

        // Group by batch first so a batch's quantity is counted once even when
        // the same (operator, workstation) pair ran several of its steps - the
        // same units pass through each step, they are not produced anew.
        $perBatch = $query
            ->groupBy('batch_steps.completed_by_id', 'batch_steps.workstation_id', 'batches.id')
            ->selectRaw(
                'batch_steps.completed_by_id as operator_id, '.
                'batch_steps.workstation_id as workstation_id, '.
                'batches.id as batch_id, '.
                'SUM(batch_steps.duration_minutes) as total_minutes, '.
                'COUNT(*) as steps_count, '.
                'MAX(batch_steps.completed_at) as last_produced_at'
            )
            ->get();

        if ($perBatch->isEmpty()) {
            return collect();
        }

        // Good pieces per batch come from the evidence (units vs declared), one
        // lookup for the whole set: a replacement original that produced nothing
        // good contributes 0, its replacement 1 - the sum stays 1, not 2.
        $batches = Batch::query()
            ->whereIn('id', $perBatch->pluck('batch_id')->unique()->values()->all())
            ->get(['id', 'work_order_id', 'produced_qty', 'scrap_qty', 'extra_data']);

        $facts = $this->qualityFacts->perBatch($batches);

        // Fold the per-batch rows up to one row per (operator, workstation).
        $rows = $perBatch
            ->groupBy(fn ($r) => $r->operator_id.':'.$r->workstation_id)
            ->map(function ($group) use ($facts) {
                $batchFacts = $group
                    ->map(fn ($row) => $facts[(int) $row->batch_id] ?? null)
                    ->filter()
                    ->values();

                // Null basis = at least one batch of the row never declared its
                // source: the produced figure is unstatable, not zero.
                $basis = QualityFacts::combinedBasis($batchFacts);

                return (object) [
                    'operator_id' => $group->first()->operator_id,
                    'workstation_id' => $group->first()->workstation_id,
                    'produced_units' => $basis === null
                        ? null
                        : round((float) $batchFacts->sum(fn (array $f) => (float) $f['good_qty']), 2),
                    'produced_basis' => $basis,
                    'unknown_source_batches' => $batchFacts->filter(fn (array $f) => $f['unknown'])->count(),
                    'total_minutes' => (int) $group->sum('total_minutes'),
                    'steps_count' => (int) $group->sum('steps_count'),
                    'last_produced_at' => $group->max('last_produced_at'),
                ];
            })
            ->values();

        // Resolve names once through Eloquent so soft-delete scopes still apply.
        $operatorNames = User::whereIn('id', $rows->pluck('operator_id')->unique())->pluck('name', 'id');
        $workstations = Workstation::with('line:id,name')
            ->whereIn('id', $rows->pluck('workstation_id')->unique())
            ->get()
            ->keyBy('id');

        return $rows
            ->map(function ($row) use ($operatorNames, $workstations) {
                $producedUnits = $row->produced_units === null ? null : (float) $row->produced_units;
                $totalMinutes = (int) $row->total_minutes;
                $ws = $workstations->get($row->workstation_id);

                return [
                    'operator_id' => (int) $row->operator_id,
                    'operator_name' => $operatorNames->get($row->operator_id) ?? __('Unknown'),
                    'workstation_id' => (int) $row->workstation_id,
                    'workstation_name' => $ws?->name,
                    'workstation_code' => $ws?->code,
                    'line_id' => $ws?->line_id,
                    'line_name' => $ws?->line?->name,
                    'produced_units' => $producedUnits === null ? null : round($producedUnits, 2),
                    'produced_basis' => $row->produced_basis,
                    'unknown_source_batches' => (int) $row->unknown_source_batches,
                    'total_minutes' => $totalMinutes,
                    'hours' => round($totalMinutes / 60, 2),
                    'steps_count' => (int) $row->steps_count,
                    'units_per_hour' => $producedUnits === null
                        ? null
                        : round($producedUnits / ($totalMinutes / 60), 2),
                    'last_produced_at' => $row->last_produced_at
                        ? Carbon::parse($row->last_produced_at)->format('Y-m-d H:i')
                        : null,
                ];
            })
            ->sortByDesc('units_per_hour')
            ->values();
    }

    /**
     * The rate for one operator on one workstation, or null when that pair has
     * no production history yet - the "machine a worker has never run" state.
     */
    public function rateFor(
        int $operatorId,
        int $workstationId,
        ?Carbon $from = null,
        ?Carbon $to = null,
    ): ?array {
        return $this->rates($lineId = null, $from, $to, $operatorId, $workstationId)->first();
    }
}
