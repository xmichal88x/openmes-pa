<?php

namespace Tests\Unit\Services;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\Line;
use App\Models\SerialUnit;
use App\Models\User;
use App\Models\Workstation;
use App\Services\Production\OperatorProductionRateService;
use App\Services\Quality\QualityFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperatorProductionRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private OperatorProductionRateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OperatorProductionRateService::class);
    }

    /**
     * Record a completed step run by $operator on $workstation, on a batch that
     * produced $producedQty over $durationMinutes. The batch declares where its
     * quantity came from, as every real write path does: without a source the
     * rate is unstatable and the row comes back empty (P1.2).
     */
    private function recordStep(
        User $operator,
        Workstation $workstation,
        float $producedQty,
        int $durationMinutes,
        ?string $completedAt = null,
        string $source = Batch::PRODUCED_QTY_SOURCE_MANUAL,
    ): BatchStep {
        $batch = Batch::factory()->create([
            'status' => Batch::STATUS_DONE,
            'produced_qty' => $producedQty,
        ]);
        $batch->setProducedQtySource($source);
        $batch->save();

        return BatchStep::factory()->create([
            'batch_id' => $batch->id,
            'status' => BatchStep::STATUS_DONE,
            'workstation_id' => $workstation->id,
            'completed_by_id' => $operator->id,
            'duration_minutes' => $durationMinutes,
            'completed_at' => $completedAt ?? now(),
        ]);
    }

    public function test_computes_units_per_hour_for_a_pair(): void
    {
        $operator = User::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => Line::factory()]);

        // 100 units over 120 minutes (2h) → 50 units/hour.
        $this->recordStep($operator, $workstation, 100, 120);

        $rate = $this->service->rateFor($operator->id, $workstation->id);

        $this->assertNotNull($rate);
        $this->assertSame(50.0, $rate['units_per_hour']);
        $this->assertSame(100.0, $rate['produced_units']);
        $this->assertSame(2.0, $rate['hours']);
        $this->assertSame(1, $rate['steps_count']);
        $this->assertSame($operator->id, $rate['operator_id']);
        $this->assertSame($workstation->id, $rate['workstation_id']);
    }

    public function test_aggregates_multiple_steps_for_the_same_pair(): void
    {
        $operator = User::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => Line::factory()]);

        // 100 units in 60 min + 100 units in 60 min → 200 units over 2h = 100/h.
        $this->recordStep($operator, $workstation, 100, 60);
        $this->recordStep($operator, $workstation, 100, 60);

        $rate = $this->service->rateFor($operator->id, $workstation->id);

        $this->assertSame(100.0, $rate['units_per_hour']);
        $this->assertSame(200.0, $rate['produced_units']);
        $this->assertSame(2, $rate['steps_count']);
    }

    public function test_separates_pairs_by_operator_and_machine(): void
    {
        $fast = User::factory()->create();
        $slow = User::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => Line::factory()]);

        $this->recordStep($fast, $workstation, 100, 60);  // 100/h
        $this->recordStep($slow, $workstation, 50, 60);   // 50/h

        $rates = $this->service->rates();

        $this->assertCount(2, $rates);
        // Sorted fastest first.
        $this->assertSame($fast->id, $rates->first()['operator_id']);
        $this->assertSame(100.0, $rates->first()['units_per_hour']);
    }

    public function test_does_not_double_count_produced_qty_across_steps_of_one_batch(): void
    {
        $operator = User::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => Line::factory()]);
        // One 100-unit batch, two completed steps by the same pair: the same 100
        // units pass through both steps - they are not produced twice.
        $batch = Batch::factory()->create(['status' => Batch::STATUS_DONE, 'produced_qty' => 100]);
        $batch->setProducedQtySource(Batch::PRODUCED_QTY_SOURCE_MANUAL);
        $batch->save();
        foreach ([60, 60] as $minutes) {
            BatchStep::factory()->create([
                'batch_id' => $batch->id,
                'status' => BatchStep::STATUS_DONE,
                'workstation_id' => $workstation->id,
                'completed_by_id' => $operator->id,
                'duration_minutes' => $minutes,
                'completed_at' => now(),
            ]);
        }

        $rate = $this->service->rateFor($operator->id, $workstation->id);

        // 100 units over 2h = 50/h, not 200/2h = 100/h.
        $this->assertSame(100.0, $rate['produced_units']);
        $this->assertSame(2.0, $rate['hours']);
        $this->assertSame(50.0, $rate['units_per_hour']);
        $this->assertSame(2, $rate['steps_count']);
    }

    public function test_filters_by_line(): void
    {
        $operator = User::factory()->create();
        $lineA = Line::factory()->create();
        $lineB = Line::factory()->create();
        $wsA = Workstation::factory()->create(['line_id' => $lineA->id]);
        $wsB = Workstation::factory()->create(['line_id' => $lineB->id]);

        $this->recordStep($operator, $wsA, 100, 60);
        $this->recordStep($operator, $wsB, 100, 60);

        $ratesA = $this->service->rates($lineA->id);

        $this->assertCount(1, $ratesA);
        $this->assertSame($wsA->id, $ratesA->first()['workstation_id']);
        $this->assertSame($lineA->id, $ratesA->first()['line_id']);
    }

    public function test_rate_for_returns_null_for_a_machine_the_worker_never_ran(): void
    {
        // Empty-history / no-data state: the pair has no completed steps.
        $operator = User::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => Line::factory()]);

        $this->assertNull($this->service->rateFor($operator->id, $workstation->id));
    }

    public function test_ignores_steps_without_duration_or_produced_quantity(): void
    {
        $operator = User::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => Line::factory()]);

        // No duration → cannot compute a rate.
        $noDuration = Batch::factory()->create(['status' => Batch::STATUS_DONE, 'produced_qty' => 100]);
        $noDuration->setProducedQtySource(Batch::PRODUCED_QTY_SOURCE_MANUAL);
        $noDuration->save();
        BatchStep::factory()->create([
            'batch_id' => $noDuration->id,
            'status' => BatchStep::STATUS_DONE,
            'workstation_id' => $workstation->id,
            'completed_by_id' => $operator->id,
            'duration_minutes' => null,
            'completed_at' => now(),
        ]);

        // Zero produced → no throughput to attribute.
        $this->recordStep($operator, $workstation, 0, 60);

        $this->assertNull($this->service->rateFor($operator->id, $workstation->id));
    }

    // ── P1.2: the produced figure is good pieces, from evidence ──────────

    /**
     * A re-cut replacement (RQ-PA-214) is the same physical piece: the original
     * still carries its step confirmation while the replacement carries its good
     * unit. Before P1.2 the pair read 2 produced pieces; the good pieces are one.
     */
    public function test_replacement_batch_counts_one_good_piece_not_two_confirmations(): void
    {
        $operator = User::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => Line::factory()]);
        $workOrder = \App\Models\WorkOrder::factory()->create();

        // Original: the piece was scrapped, so it produced nothing good.
        $original = Batch::factory()->create([
            'work_order_id' => $workOrder->id,
            'status' => Batch::STATUS_DONE,
            'produced_qty' => 1,
        ]);
        $original->setProducedQtySource(Batch::PRODUCED_QTY_SOURCE_UNITS);
        $original->save();
        SerialUnit::factory()->create([
            'work_order_id' => $workOrder->id,
            'batch_id' => $original->id,
            'status' => SerialUnit::STATUS_SCRAPPED,
            'packed_at' => now(),
        ]);

        // Replacement: the piece was cut again and packed - one good unit.
        $replacement = Batch::factory()->create([
            'work_order_id' => $workOrder->id,
            'status' => Batch::STATUS_DONE,
            'produced_qty' => 1,
        ]);
        $replacement->setProducedQtySource(Batch::PRODUCED_QTY_SOURCE_UNITS);
        $replacement->save();
        SerialUnit::factory()->create([
            'work_order_id' => $workOrder->id,
            'batch_id' => $replacement->id,
            'status' => SerialUnit::STATUS_COMPLETED,
            'packed_at' => now(),
        ]);

        foreach ([$original, $replacement] as $batch) {
            BatchStep::factory()->create([
                'batch_id' => $batch->id,
                'status' => BatchStep::STATUS_DONE,
                'workstation_id' => $workstation->id,
                'completed_by_id' => $operator->id,
                'duration_minutes' => 30,
                'completed_at' => now(),
            ]);
        }

        $rate = $this->service->rateFor($operator->id, $workstation->id);

        // Before P1.2: produced_units 2 (SUM of both confirmations). Now: 1.
        $this->assertSame(1.0, $rate['produced_units']);
        $this->assertSame(1.0, $rate['units_per_hour']);
        $this->assertSame(QualityFacts::BASIS_UNITS, $rate['produced_basis']);
        $this->assertSame(0, $rate['unknown_source_batches']);
    }

    /**
     * Parity with the operator (declared) flow: no packed unit, so the batch's
     * produced_qty is what was attempted and scrap is separate evidence. The
     * rate is the same number the pre-P1.2 code returned.
     */
    public function test_declared_flow_keeps_the_operator_result(): void
    {
        $operator = User::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => Line::factory()]);

        $this->recordStep($operator, $workstation, 100, 120);

        $rate = $this->service->rateFor($operator->id, $workstation->id);

        $this->assertSame(100.0, $rate['produced_units']);
        $this->assertSame(50.0, $rate['units_per_hour']);
        $this->assertSame(QualityFacts::BASIS_DECLARED, $rate['produced_basis']);
        $this->assertSame(0, $rate['unknown_source_batches']);
    }

    /** A batch that never declared its source leaves the row empty, not zero. */
    public function test_batch_without_a_declared_source_leaves_the_row_empty(): void
    {
        $operator = User::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => Line::factory()]);

        $batch = Batch::factory()->create(['status' => Batch::STATUS_DONE, 'produced_qty' => 100]);
        BatchStep::factory()->create([
            'batch_id' => $batch->id,
            'status' => BatchStep::STATUS_DONE,
            'workstation_id' => $workstation->id,
            'completed_by_id' => $operator->id,
            'duration_minutes' => 60,
            'completed_at' => now(),
        ]);

        $rate = $this->service->rateFor($operator->id, $workstation->id);

        $this->assertNotNull($rate);
        $this->assertNull($rate['produced_units'], 'no source: null, never a guessed zero');
        $this->assertNull($rate['units_per_hour']);
        $this->assertNull($rate['produced_basis']);
        $this->assertSame(1, $rate['unknown_source_batches']);
    }
}
