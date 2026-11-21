<?php

namespace Tests\Feature\Reports;

use App\Models\Batch;
use App\Models\Line;
use App\Models\ScrapEntry;
use App\Models\SerialUnit;
use App\Models\WorkOrder;
use App\Services\Quality\QualityFacts;
use App\Services\Scrap\ScrapReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1.2 - the scrap rate's denominator is good output, not step confirmations.
 *
 * `batches.produced_qty` closes a batch (R40/P2.12); summing it as "produced"
 * counted a re-cut replacement twice and mixed two different meanings into one
 * ratio. The report now derives its quantities from {@see QualityFacts} and
 * states the basis it used. The scrap itself stays two separate evidences, each
 * counted once: reported `scrap_entries` plus scrapped units.
 */
class ScrapReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ScrapReportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-26 10:00:00'));
        $this->service = app(ScrapReportService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function windowStart(): Carbon
    {
        return Carbon::parse('2026-05-25 00:00:00');
    }

    private function windowEnd(): Carbon
    {
        return Carbon::parse('2026-05-26 23:59:59');
    }

    private function order(Line $line, float $producedQty = 0): WorkOrder
    {
        return WorkOrder::factory()->create([
            'line_id' => $line->id,
            'produced_qty' => $producedQty,
        ]);
    }

    /** A batch that closed inside the report window, with its declared source. */
    private function batch(WorkOrder $order, float $producedQty, string $source, float $scrapQty = 0): Batch
    {
        $batch = Batch::factory()->create([
            'work_order_id' => $order->id,
            'status' => Batch::STATUS_DONE,
            'produced_qty' => $producedQty,
            'scrap_qty' => $scrapQty,
            'started_at' => Carbon::parse('2026-05-26 08:00:00'),
            'completed_at' => Carbon::parse('2026-05-26 09:00:00'),
        ]);
        $batch->setProducedQtySource($source);
        $batch->save();

        return $batch;
    }

    private function unit(Batch $batch, string $status): SerialUnit
    {
        return SerialUnit::factory()->create([
            'work_order_id' => $batch->work_order_id,
            'batch_id' => $batch->id,
            'status' => $status,
            'packed_at' => now(),
        ]);
    }

    private function scrapEntry(WorkOrder $order, float $quantity): ScrapEntry
    {
        return ScrapEntry::factory()->create([
            'work_order_id' => $order->id,
            'quantity' => $quantity,
            'reported_at' => Carbon::parse('2026-05-26 09:30:00'),
        ]);
    }

    /** @return array<string, mixed> */
    private function rowFor(Line $line): array
    {
        $rows = $this->service->ratePerLine($this->windowStart(), $this->windowEnd());

        foreach ($rows as $row) {
            if ($row['line_id'] === $line->id) {
                return $row;
            }
        }

        $this->fail("no scrap-rate row for line {$line->id}");
    }

    /**
     * Units basis: 3 packed pieces + 1 scrapped unit + a reported entry of 2.
     * The denominator is good (3) + scrap (1 unit + 2 reported) = 6 -> 50 %.
     */
    public function test_units_basis_counts_good_pieces_and_every_scrap_evidence_once(): void
    {
        $line = Line::factory()->create();
        $order = $this->order($line, 3);
        $batch = $this->batch($order, 3, Batch::PRODUCED_QTY_SOURCE_UNITS);

        $this->unit($batch, SerialUnit::STATUS_COMPLETED);
        $this->unit($batch, SerialUnit::STATUS_COMPLETED);
        $this->unit($batch, SerialUnit::STATUS_COMPLETED);
        $this->unit($batch, SerialUnit::STATUS_SCRAPPED);
        $this->scrapEntry($order, 2);

        $row = $this->rowFor($line);

        $this->assertSame(3.0, $row['scrap_qty'], '2 reported + 1 scrapped unit, each counted once');
        $this->assertSame(3.0, $row['produced_qty'], 'good pieces, not the batch confirmation');
        $this->assertSame(6.0, $row['total_qty']);
        $this->assertSame(50.0, $row['scrap_rate_pct']);
        $this->assertSame(QualityFacts::BASIS_UNITS, $row['produced_basis']);
        $this->assertSame(0, $row['unknown_source_batches']);
    }

    /**
     * Replacement (RQ-PA-214): the original confirmation still stands while the
     * replacement carries the good piece. Before P1.2 the denominator was
     * 1 + 1 = 2 confirmations and no scrap of the scrapped unit was counted at
     * all (0 %); now good = 1, scrap = 1, total = 2 -> 50 %.
     */
    public function test_replacement_batch_is_counted_once_in_the_denominator(): void
    {
        $line = Line::factory()->create();
        $order = $this->order($line);

        $original = $this->batch($order, 1, Batch::PRODUCED_QTY_SOURCE_UNITS);
        $this->unit($original, SerialUnit::STATUS_SCRAPPED);

        $replacement = $this->batch($order, 1, Batch::PRODUCED_QTY_SOURCE_UNITS);
        $this->unit($replacement, SerialUnit::STATUS_COMPLETED);

        $row = $this->rowFor($line);

        $this->assertSame(1.0, $row['produced_qty'], 'one good piece, not 1 + 1 confirmations');
        $this->assertSame(1.0, $row['scrap_qty']);
        $this->assertSame(2.0, $row['total_qty']);
        $this->assertSame(50.0, $row['scrap_rate_pct']);
    }

    /** A batch with no declared source leaves the row empty and names the gap. */
    public function test_unknown_source_batch_reports_null_not_a_guessed_rate(): void
    {
        $line = Line::factory()->create();
        $order = $this->order($line);
        Batch::factory()->create([
            'work_order_id' => $order->id,
            'status' => Batch::STATUS_DONE,
            'produced_qty' => 10,
            'started_at' => Carbon::parse('2026-05-26 08:00:00'),
            'completed_at' => Carbon::parse('2026-05-26 09:00:00'),
        ]);
        $this->scrapEntry($order, 1);

        $row = $this->rowFor($line);

        $this->assertNull($row['produced_qty'], 'no basis: null, never a partial sum');
        $this->assertNull($row['total_qty']);
        $this->assertNull($row['scrap_rate_pct']);
        $this->assertNull($row['produced_basis']);
        $this->assertSame(1, $row['unknown_source_batches']);
        $this->assertSame(1.0, $row['scrap_qty'], 'the scrap itself is still a fact');
    }

    /**
     * Operator (declared) flow parity: produced 100, scrap 25. The scrap rate and
     * the scrap quantity are exactly what they were before P1.2 (25 / 100 = 25 %);
     * `produced_qty` is now the good figure (75), not the declared total.
     */
    public function test_declared_flow_keeps_the_same_scrap_rate(): void
    {
        $line = Line::factory()->create();
        $order = $this->order($line, 100);
        $this->batch($order, 100, Batch::PRODUCED_QTY_SOURCE_MANUAL, 25);
        $this->scrapEntry($order, 25);

        $row = $this->rowFor($line);

        $this->assertSame(25.0, $row['scrap_qty'], 'unchanged');
        $this->assertSame(25.0, $row['scrap_rate_pct'], 'unchanged parity with the pre-P1.2 formula');
        $this->assertSame(75.0, $row['produced_qty'], 'good pieces');
        $this->assertSame(100.0, $row['total_qty']);
        $this->assertSame(QualityFacts::BASIS_DECLARED, $row['produced_basis']);
    }

    /** No completed batch in the window: no produced evidence, so no rate. */
    public function test_line_without_a_completed_batch_has_no_produced_evidence(): void
    {
        $line = Line::factory()->create();
        $order = $this->order($line);
        $this->scrapEntry($order, 4);

        $row = $this->rowFor($line);

        $this->assertSame(4.0, $row['scrap_qty']);
        $this->assertNull($row['produced_qty']);
        $this->assertNull($row['scrap_rate_pct']);
        $this->assertNull($row['produced_basis']);
        $this->assertSame(0, $row['unknown_source_batches']);
    }

    /** A line with neither scrap nor completed batches is not a row at all. */
    public function test_line_with_nothing_in_the_window_is_absent(): void
    {
        Line::factory()->create();

        $this->assertSame([], $this->service->ratePerLine($this->windowStart(), $this->windowEnd()));
    }
}
