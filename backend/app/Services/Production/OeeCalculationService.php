<?php

namespace App\Services\Production;

use App\Models\Batch;
use App\Models\Line;
use App\Models\OeeRecord;
use App\Models\ProcessTemplate;
use App\Models\Shift;
use App\Services\Quality\QualityFacts;
use App\Support\ShiftWindow;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class OeeCalculationService
{
    public function __construct(
        protected DowntimeService $downtimeService,
        protected QualityFacts $qualityFacts,
    ) {}

    /**
     * Calculate and store OEE for a line on a given date.
     * If shift is provided, calculates per-shift; otherwise whole day.
     */
    public function calculateForDate(Line $line, Carbon $date, ?Shift $shift = null): ?OeeRecord
    {
        $plannedMinutes = $this->getPlannedMinutes($line, $date, $shift);

        if ($plannedMinutes <= 0) {
            return null;
        }

        // Downtimes
        $lossDowntime = $this->downtimeService->getLossMinutes($line->id, $date, $shift?->id);
        $plannedDowntime = $this->downtimeService->getPlannedMinutes($line->id, $date, $shift?->id);

        // Subtract planned downtime from planned time (planned breaks don't count as losses)
        $netPlannedMinutes = $plannedMinutes - $plannedDowntime;
        $operatingMinutes = max(0, $netPlannedMinutes - $lossDowntime);

        // Production data. The quality ratio is not derived here: the basis
        // depends on the evidence the batches carry (packed units vs a declared
        // quantity), and only {@see QualityFacts} may decide that (P2.7).
        $productionData = $this->getProductionData($line, $date, $shift);
        $totalProduced = $productionData['total_produced'];
        $qualityFacts = $this->qualityFacts->forBatches($productionData['batches']);

        if ($qualityFacts['unknown_source_batches'] > 0) {
            // A cron must not die on a batch that never declared its source, and
            // it must not invent a ratio either: quality is left empty (null)
            // and the gap is named in the log.
            Log::warning('oee.quality_basis_unknown: window has batches with no produced_qty source, quality left empty', [
                'line_id' => $line->id,
                'record_date' => $date->toDateString(),
                'shift_id' => $shift?->id,
                'unknown_source_batches' => $qualityFacts['unknown_source_batches'],
            ]);
        }

        $quality = $qualityFacts['quality_pct'];
        $scrapQty = (float) $qualityFacts['scrap_qty'];
        $goodProduced = $qualityFacts['good_qty'];
        // Under the `units` basis the ratio's denominator (good + scrap) is not
        // the sum of `produced_qty`; store the ratio's own total so the record
        // reconciles (good + scrap = total). Performance below keeps the
        // produced figure it always used.
        $recordedProduced = $qualityFacts['total_qty'] ?? $totalProduced;

        // Ideal cycle time
        $idealCycle = $this->getIdealCycleTime($line, $date, $shift);

        // Calculate OEE components
        $availability = $netPlannedMinutes > 0
            ? ($operatingMinutes / $netPlannedMinutes) * 100
            : null;

        $performance = ($operatingMinutes > 0 && $idealCycle > 0)
            ? (($totalProduced * $idealCycle) / $operatingMinutes) * 100
            : null;

        // Cap performance at 100% to avoid nonsensical values
        if ($performance !== null && $performance > 100) {
            $performance = 100;
        }

        $oee = ($availability !== null && $performance !== null && $quality !== null)
            ? ($availability / 100) * ($performance / 100) * ($quality / 100) * 100
            : null;

        // Store/update record
        return OeeRecord::updateOrCreate(
            [
                'line_id' => $line->id,
                'workstation_id' => null,
                'shift_id' => $shift?->id,
                'record_date' => $date->toDateString(),
            ],
            [
                'planned_minutes' => $netPlannedMinutes,
                'operating_minutes' => $operatingMinutes,
                'downtime_minutes' => $lossDowntime,
                'ideal_cycle_minutes' => $idealCycle,
                'total_produced' => $recordedProduced,
                'good_produced' => $goodProduced,
                'scrap_qty' => $scrapQty,
                'availability_pct' => $availability !== null ? round($availability, 2) : null,
                'performance_pct' => $performance !== null ? round($performance, 2) : null,
                'quality_pct' => $quality !== null ? round($quality, 2) : null,
                'oee_pct' => $oee !== null ? round($oee, 2) : null,
            ]
        );
    }

    /**
     * Calculate OEE for all active lines for a given date.
     */
    public function calculateAll(Carbon $date): array
    {
        $lines = Line::where('is_active', true)->get();
        $records = [];

        foreach ($lines as $line) {
            $shifts = Shift::where('line_id', $line->id)->where('is_active', true)->get();

            if ($shifts->isEmpty()) {
                // No shifts defined → calculate whole day (assume 8h default)
                $record = $this->calculateForDate($line, $date);
                if ($record) {
                    $records[] = $record;
                }
            } else {
                foreach ($shifts as $shift) {
                    $record = $this->calculateForDate($line, $date, $shift);
                    if ($record) {
                        $records[] = $record;
                    }
                }
            }
        }

        return $records;
    }

    /**
     * Get planned production minutes for a line/shift/date.
     */
    private function getPlannedMinutes(Line $line, Carbon $date, ?Shift $shift): int
    {
        if ($shift) {
            return $this->shiftDurationMinutes($shift);
        }

        // If no specific shift, check if line has shifts defined
        $shifts = Shift::where('line_id', $line->id)->where('is_active', true)->get();

        if ($shifts->isNotEmpty()) {
            return $shifts->sum(fn ($s) => $this->shiftDurationMinutes($s));
        }

        // Default: 8 hours
        return 480;
    }

    private function shiftDurationMinutes(Shift $shift): int
    {
        $start = Carbon::parse($shift->start_time);
        $end = Carbon::parse($shift->end_time);

        // Handle overnight shifts
        if ($end->lte($start)) {
            $end->addDay();
        }

        return (int) $start->diffInMinutes($end);
    }

    /**
     * Get the batches that closed inside the window, plus their recorded totals.
     *
     * The rows (not an SQL aggregate) are returned because the quality ratio is
     * derived from the evidence they carry - packed units, scrapped units and
     * `produced_qty_source` - which a sum cannot express. One query either way.
     *
     * @return array{total_produced: float, scrap_qty: float, batches: \Illuminate\Support\Collection<int, Batch>}
     */
    private function getProductionData(Line $line, Carbon $date, ?Shift $shift): array
    {
        $query = Batch::whereHas('workOrder', fn ($q) => $q->where('line_id', $line->id))
            ->where('status', Batch::STATUS_DONE);

        if ($shift) {
            // The occurrence, not the calendar day. A night shift's whereDate
            // and its 22:00–06:00 window intersect to two hours, so everything
            // produced after midnight was dropped — while the downtime side of
            // the same record now covers the full eight. Half-open at the end so
            // a batch landing exactly on the boundary belongs to one shift only.
            $window = ShiftWindow::startingOn($shift, $date);

            $query->where('completed_at', '>=', $window->start)
                ->where('completed_at', '<', $window->end);
        } else {
            $query->whereDate('completed_at', $date);
        }

        $batches = $query->get();

        return [
            'total_produced' => (float) $batches->sum(fn (Batch $batch) => (float) $batch->produced_qty),
            'scrap_qty' => (float) $batches->sum(fn (Batch $batch) => (float) $batch->scrap_qty),
            'batches' => $batches,
        ];
    }

    /**
     * Get ideal cycle time for a line.
     * Priority: 1) Manual on template, 2) Best historical, 3) Estimated from steps
     */
    private function getIdealCycleTime(Line $line, Carbon $date, ?Shift $shift): float
    {
        // Find most common process template used on this line recently
        $templateId = Batch::where('batches.status', Batch::STATUS_DONE)
            ->whereDate('batches.completed_at', '>=', $date->copy()->subDays(30))
            ->join('work_orders', 'batches.work_order_id', '=', 'work_orders.id')
            ->where('work_orders.line_id', $line->id)
            ->whereNotNull('work_orders.process_snapshot')
            ->selectRaw("work_orders.process_snapshot->>'template_id' as tpl_id")
            ->groupBy('tpl_id')
            ->orderByRaw('COUNT(*) DESC')
            ->value('tpl_id');

        if ($templateId) {
            $template = ProcessTemplate::find($templateId);

            // 1. Manual ideal cycle time
            if ($template?->ideal_cycle_minutes) {
                return (float) $template->ideal_cycle_minutes;
            }
        }

        // 2. Best historical cycle time (fastest batch in last 30 days).
        // Computed in PHP so this query runs on both Postgres and SQLite (tests).
        $batches = Batch::whereHas('workOrder', fn ($q) => $q->where('line_id', $line->id))
            ->where('status', Batch::STATUS_DONE)
            ->where('produced_qty', '>', 0)
            ->whereDate('completed_at', '>=', $date->copy()->subDays(30))
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->get(['started_at', 'completed_at', 'produced_qty']);

        $bestCycle = $batches
            ->map(fn ($b) => $b->started_at->diffInMinutes($b->completed_at) / max(0.0001, (float) $b->produced_qty))
            ->filter(fn ($v) => $v > 0)
            ->min();

        if ($bestCycle !== null && $bestCycle > 0) {
            return round((float) $bestCycle, 4);
        }

        // 3. Fallback: sum of estimated step durations
        if (isset($template)) {
            $totalEstimated = $template->steps()->sum('estimated_duration_minutes');
            if ($totalEstimated > 0) {
                return (float) $totalEstimated;
            }
        }

        // Ultimate fallback — 1 minute (to avoid division by zero)
        return 1.0;
    }
}
