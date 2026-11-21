<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Quality\FirstPassYieldService;
use App\Services\Scrap\ScrapReportService;
use App\Support\ModuleRegistry;
use App\Support\ProductionTargetSettings;
use App\Support\ShiftWindow;
use App\Support\TabRegistry;
use Carbon\Carbon;

/**
 * The four "shift at a glance" tiles of the plant overview: orders not
 * scheduled, lines producing, scrap rate and first-pass yield.
 *
 * Every number reuses the code its own screen uses — the planner backlog scope,
 * the line states already computed for the header, the Scrap Reports maths and
 * FirstPassYieldService — so a tile never disagrees with the page it links to.
 *
 * Tone (the caption colour) is decided here, so it is testable:
 *  - backlog — `bad` with anything overdue, `warn` with anything due this week,
 *    `mute` with only undated orders, `ok` when the backlog is empty;
 *  - lines   — `bad` with any line down, `warn` with any blocked, else `ok`
 *    (`mute` with no active lines);
 *  - scrap   — `ok` at or under the target, `warn` up to twice it, else `bad`;
 *  - FPY     — `ok` at or over the target, `warn` within 5 points under it,
 *    else `bad`. No measurement → `mute`.
 *
 * Links are resolved against the user's accessible tabs (which already account
 * for disabled modules); without access a tile carries no `href`.
 *
 * Query count is fixed regardless of the number of lines.
 */
class ShiftKpiService
{
    private const FPY_WARN_BAND = 5.0;

    public function __construct(
        private ScrapReportService $scrap,
        private FirstPassYieldService $fpy,
    ) {}

    /**
     * @param  array<int, array{id: int, state: string}>  $lineStates  from PlantOverviewService::lineStates()
     */
    public function tiles(array $lineStates, ?User $user): array
    {
        $window = ShiftWindow::current();
        $tabs = $user ? TabRegistry::accessibleFor($user) : [];
        $link = fn (string $tab, string $url) => in_array($tab, $tabs, true) ? $url : null;

        return [
            'backlog' => $this->backlog() + ['href' => $link('schedule', '/admin/schedule')],
            'lines' => $this->lines($lineStates) + ['href' => $link('shift_monitor', '/admin/shift-monitor')],
            'scrap' => $this->scrapRate($window, $lineStates) + ['href' => $link('advanced_reports', '/admin/scrap-reports')],
            'fpy' => $this->firstPassYield($window, $tabs),
        ];
    }

    /** @return array{count: int, due_this_week: int, overdue: int, tone: string} */
    private function backlog(): array
    {
        // Plant-local: TimezoneRegistry sets PHP's default zone, which is also
        // the zone due dates are written in.
        $today = Carbon::today();
        $weekEnd = Carbon::now()->endOfWeek();

        $count = WorkOrder::plannerBacklog()->count();
        $overdue = $count === 0 ? 0 : WorkOrder::plannerBacklog()->where('due_date', '<', $today)->count();
        $dueThisWeek = $count === 0 ? 0 : WorkOrder::plannerBacklog()
            ->where('due_date', '>=', $today)
            ->where('due_date', '<=', $weekEnd)
            ->count();

        $tone = match (true) {
            $overdue > 0 => 'bad',
            $dueThisWeek > 0 => 'warn',
            $count > 0 => 'mute',
            default => 'ok',
        };

        return ['count' => $count, 'due_this_week' => $dueThisWeek, 'overdue' => $overdue, 'tone' => $tone];
    }

    /** @return array{total: int, running: int, down: int, blocked: int, idle: int, tone: string} */
    private function lines(array $lineStates): array
    {
        $counts = ['running' => 0, 'down' => 0, 'blocked' => 0, 'idle' => 0];
        foreach ($lineStates as $line) {
            $counts[$line['state']] = ($counts[$line['state']] ?? 0) + 1;
        }

        $tone = match (true) {
            $lineStates === [] => 'mute',
            $counts['down'] > 0 => 'bad',
            $counts['blocked'] > 0 => 'warn',
            default => 'ok',
        };

        return ['total' => count($lineStates)] + $counts + ['tone' => $tone];
    }

    /**
     * Scrap ÷ the line's own denominator over the shift, with the Scrap Reports
     * page's maths ({@see \App\Services\Scrap\ScrapReportService::ratePerLine()}):
     * the report states both the good pieces and the denominator its rate
     * divides by, so this tile must not divide by `produced_qty` itself - that
     * would give the same shift two different scrap rates (P1.2).
     *
     * A line whose quantity evidence is unknown makes the tile unstatable: rate
     * null (mute), never a partial sum over the rows that happen to be known.
     * ScrapEntry and Batch carry no tenant scope and the query joins
     * work_orders without one, so rows are kept to the tenant-scoped active
     * lines the header already loaded.
     *
     * @return array{rate_pct: ?float, scrap_qty: float, produced_qty: ?float, total_qty: ?float, unknown_source_batches: int, target_max_pct: float, tone: string}
     */
    private function scrapRate(ShiftWindow $window, array $lineStates): array
    {
        $target = ProductionTargetSettings::maxScrapRate();
        $lineIds = array_column($lineStates, 'id');

        $rows = $lineIds === []
            ? collect()
            : collect($this->scrap->ratePerLine($window->start, $window->end))->whereIn('line_id', $lineIds);

        $unstatable = $rows->contains(fn (array $row) => $row['total_qty'] === null);
        $scrap = round((float) $rows->sum('scrap_qty'), 2);
        $produced = $unstatable ? null : round((float) $rows->sum('produced_qty'), 2);
        $total = $unstatable ? null : round((float) $rows->sum('total_qty'), 2);
        $rate = $total !== null && $total > 0 ? round($scrap / $total * 100, 2) : null;

        $tone = match (true) {
            $rate === null => 'mute',
            $rate <= $target => 'ok',
            $rate <= $target * 2 => 'warn',
            default => 'bad',
        };

        return [
            'rate_pct' => $rate,
            'scrap_qty' => $scrap,
            'produced_qty' => $produced,
            'total_qty' => $total,
            // Why the tile can be empty: a batch of the shift declared no source.
            'unknown_source_batches' => (int) $rows->sum('unknown_source_batches'),
            'target_max_pct' => $target,
            'tone' => $tone,
        ];
    }

    /**
     * @param  array<int, string>  $tabs
     * @return array{pct: ?float, inspected: int, first_pass: int, available: bool, target_min_pct: float, tone: string, href: ?string}
     */
    private function firstPassYield(ShiftWindow $window, array $tabs): array
    {
        $target = ProductionTargetSettings::minFirstPassYield();
        $breakdown = $this->fpy->breakdownForWindow($window->start, $window->end);
        $available = $breakdown !== null;
        $inspected = $breakdown['inspected'] ?? 0;
        $firstPass = $breakdown['first_pass'] ?? 0;
        $pct = $inspected > 0 ? round($firstPass / $inspected * 100, 1) : null;

        $tone = match (true) {
            $pct === null => 'mute',
            $pct >= $target => 'ok',
            $pct >= $target - self::FPY_WARN_BAND => 'warn',
            default => 'bad',
        };

        // Quality tasks sit in the Maintenance & QC area but are switched off
        // with the quality module, so both must be on for the link.
        $href = $available && in_array('maintenance', $tabs, true) && ModuleRegistry::isModuleEnabled('quality')
            ? '/admin/quality-tasks'
            : null;

        return [
            'pct' => $pct,
            'inspected' => $inspected,
            'first_pass' => $firstPass,
            'available' => $available,
            'target_min_pct' => $target,
            'tone' => $tone,
            'href' => $href,
        ];
    }
}
