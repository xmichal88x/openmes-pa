<?php

namespace Tests\Feature\Dashboard;

use App\Models\Batch;
use App\Models\Line;
use App\Models\SerialUnit;
use App\Models\WorkOrder;
use App\Services\Dashboard\LineProgressService;
use App\Services\Quality\QualityFacts;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1.2 - the line card's rate for a line without a collector.
 *
 * The card shows `work_orders.produced_qty`, which is good pieces (R40/P2.12).
 * The rate ($/h) comes from the order's batches completed in the last hour and
 * must rest on the same evidence: good pieces from {@see QualityFacts}, not
 * `SUM(batches.produced_qty)` (a step confirmation). A re-cut replacement used
 * to put the same piece in the rate twice.
 */
class LineProgressFallbackTest extends TestCase
{
    use RefreshDatabase;

    private LineProgressService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-26 10:00:00'));
        $this->service = app(LineProgressService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse('2026-05-26 '.$time);
    }

    private function order(Line $line): WorkOrder
    {
        return WorkOrder::factory()->create([
            'line_id' => $line->id,
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 100,
            'produced_qty' => 1,
            'planned_end_at' => null,
        ]);
    }

    private function batch(WorkOrder $order, float $producedQty, string $source): Batch
    {
        $batch = Batch::factory()->create([
            'work_order_id' => $order->id,
            'status' => Batch::STATUS_DONE,
            'produced_qty' => $producedQty,
            'started_at' => $this->at('09:00'),
            'completed_at' => $this->at('09:30'),
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

    /** @return array<string, mixed> */
    private function row(Line $line): array
    {
        return $this->service->forLines([['id' => $line->id, 'has_collector' => false]], Carbon::now())[0];
    }

    /**
     * Replacement: the original batch confirms 1 and its piece was scrapped, the
     * replacement confirms 1 and its piece is good. Before P1.2 the rate was
     * 2 pcs/h; the good pieces are one.
     */
    public function test_replacement_counts_one_good_piece_not_two_confirmations(): void
    {
        $line = Line::factory()->create();
        $order = $this->order($line);

        $original = $this->batch($order, 1, Batch::PRODUCED_QTY_SOURCE_UNITS);
        $this->unit($original, SerialUnit::STATUS_SCRAPPED);

        $replacement = $this->batch($order, 1, Batch::PRODUCED_QTY_SOURCE_UNITS);
        $this->unit($replacement, SerialUnit::STATUS_COMPLETED);

        $row = $this->row($line);

        $this->assertEquals(1.0, $row['rate_per_hour'], 'one good piece, not two step confirmations');
        $this->assertSame(QualityFacts::BASIS_UNITS, $row['produced_basis']);
        $this->assertSame(0, $row['unknown_source_batches']);
    }

    /** Operator/declared flow: parity with the pre-P1.2 rate. */
    public function test_declared_batches_keep_the_rate_they_produced(): void
    {
        $line = Line::factory()->create();
        $order = $this->order($line);
        $this->batch($order, 40, Batch::PRODUCED_QTY_SOURCE_MANUAL);

        $row = $this->row($line);

        $this->assertEquals(40.0, $row['rate_per_hour']);
        $this->assertSame(QualityFacts::BASIS_DECLARED, $row['produced_basis']);
    }

    /** A batch with no declared source leaves the rate empty, never zero. */
    public function test_batch_without_a_source_leaves_the_rate_empty(): void
    {
        $line = Line::factory()->create();
        $order = $this->order($line);
        Batch::factory()->create([
            'work_order_id' => $order->id,
            'status' => Batch::STATUS_DONE,
            'produced_qty' => 40,
            'completed_at' => $this->at('09:30'),
        ]);

        $row = $this->row($line);

        $this->assertNull($row['rate_per_hour'], 'no source: null, never a guessed 0');
        $this->assertNull($row['produced_basis']);
        $this->assertSame(1, $row['unknown_source_batches']);
    }

    /** A collector line states where its rate came from, on the same card. */
    public function test_collector_line_names_its_basis(): void
    {
        $line = Line::factory()->create();
        $this->order($line);

        $row = $this->service->forLines([['id' => $line->id, 'has_collector' => true]], Carbon::now())[0];

        $this->assertSame('machine_counter', $row['produced_basis']);
    }
}
