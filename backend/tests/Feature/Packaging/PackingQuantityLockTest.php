<?php

namespace Tests\Feature\Packaging;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * P2.6: a packing correction rewrites the step, the batch and the order, so it
 * must serialise on the order before it reads the rollup.
 *
 * Two supervisors correcting two different batches of the same order at the same
 * time used to read the same rollup and write it back twice: the second write
 * dropped the first correction (lost update). `correctPackingQuantity()` now
 * takes the order lock first - the order every other quantity path in
 * `BatchService` uses - and only then the step.
 *
 * The test database is SQLite (`phpunit.xml`: `DB_CONNECTION=sqlite`), where
 * `lockForUpdate()` compiles to nothing, so a real concurrency test is not
 * possible here. Covered instead: the rollup reads every batch (not only the
 * corrected one), a model read before an earlier correction still produces the
 * correct figures, and the order row is read before the step row - the lock order
 * the correction depends on.
 */
class PackingQuantityLockTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Admin', 'web');
        Role::findOrCreate('Operator', 'web');
        Role::findOrCreate('Supervisor', 'web');

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('Supervisor');
    }

    /**
     * An order of :planned units split into two batches, each packed and closed
     * on its own target, so both contribute to one rollup.
     *
     * @return array{0: WorkOrder, 1: BatchStep, 2: BatchStep}
     */
    private function orderWithTwoClosedBatches(float $plannedQty = 4, float $perBatch = 2): array
    {
        $product = ProductType::factory()->create();
        $template = ProcessTemplate::factory()->create(['product_type_id' => $product->id]);
        TemplateStep::create([
            'process_template_id' => $template->id, 'step_number' => 1, 'name' => 'Pack',
            'kind' => TemplateStep::KIND_PACKING, 'config' => ['unit' => 'carton', 'carton_capacity' => 5],
        ]);

        $workOrder = WorkOrder::factory()->create([
            'product_type_id' => $product->id,
            'process_snapshot' => $template->fresh()->toSnapshot(),
            'planned_qty' => $plannedQty,
            'produced_qty' => 0,
            'status' => WorkOrder::STATUS_IN_PROGRESS,
        ]);

        $service = app(BatchService::class);
        $steps = [];

        for ($i = 0; $i < 2; $i++) {
            $batch = app(WorkOrderService::class)->createBatch($workOrder->fresh(), $perBatch);
            $step = $batch->steps()->where('step_number', 1)->firstOrFail();
            $service->startStep($step, $this->operator);
            $service->completeStep($step->fresh(), $this->operator, ['produced_qty' => $perBatch]);
            $steps[] = $step->fresh();
        }

        return [$workOrder->fresh(), $steps[0], $steps[1]];
    }

    private function correct(BatchStep $step, float $produced, string $reason = 'Supervisor counted the cartons.'): BatchStep
    {
        return app(BatchService::class)->correctPackingQuantity($step, $this->supervisor, $produced, $reason);
    }

    public function test_two_batches_corrected_in_turn_roll_up_both_corrections(): void
    {
        [$workOrder, $stepA, $stepB] = $this->orderWithTwoClosedBatches();
        $this->assertSame(4.0, (float) $workOrder->produced_qty);

        $this->correct($stepA, 1);

        $afterFirst = $workOrder->fresh();
        $this->assertSame(3.0, (float) $afterFirst->produced_qty);
        $this->assertSame(WorkOrder::STATUS_IN_PROGRESS, $afterFirst->status);

        $this->correct($stepB->fresh(), 1);

        // The sum of both corrections, never the figure of the last one.
        $this->assertSame(2.0, (float) $workOrder->fresh()->produced_qty);
        $this->assertSame(1.0, (float) $stepA->fresh()->passed_qty);
        $this->assertSame(1.0, (float) $stepB->fresh()->passed_qty);
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_MANUAL, $stepA->fresh()->batch->producedQtySource());
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_MANUAL, $stepB->fresh()->batch->producedQtySource());
    }

    public function test_a_step_read_before_an_earlier_correction_still_rolls_up_the_current_order(): void
    {
        [$workOrder, $stepA, $stepB] = $this->orderWithTwoClosedBatches();

        // The second "process" holds models read before anything changed.
        $stale = BatchStep::with('batch.workOrder')->findOrFail($stepB->id);
        $this->assertSame(4.0, (float) $stale->batch->workOrder->produced_qty);

        $this->correct($stepA, 1);
        $this->assertSame(4.0, (float) $stale->batch->workOrder->produced_qty, 'the stale model must not follow the database');

        $this->correct($stale, 1);

        $this->assertSame(2.0, (float) $workOrder->fresh()->produced_qty);
        $this->assertSame(1.0, (float) $stale->fresh()->passed_qty);

        // The audit carries what the locked re-read saw, not the caller's copy.
        $audit = AuditLog::where('action', 'packing_qty_corrected')
            ->where('entity_id', $stepB->id)
            ->firstOrFail();
        $this->assertSame(3.0, (float) $audit->before_state['wo_produced_qty']);
        $this->assertSame(2.0, (float) $audit->after_state['wo_produced_qty']);
        $this->assertSame(2.0, (float) $audit->before_state['passed_qty']);
        $this->assertSame(1.0, (float) $audit->after_state['passed_qty']);
    }

    public function test_a_repeated_correction_from_the_same_stale_model_audits_the_real_previous_quantity(): void
    {
        [$workOrder, $stepA] = $this->orderWithTwoClosedBatches();

        $stale = BatchStep::with('batch.workOrder')->findOrFail($stepA->id);
        $this->assertSame(2.0, (float) $stale->passed_qty);

        $this->correct($stale, 1);
        $this->correct($stale, 0);

        $second = AuditLog::where('action', 'packing_qty_corrected')
            ->where('entity_id', $stepA->id)
            ->orderByDesc('id')
            ->firstOrFail();

        // The stale object still says 2; the transaction re-read 1 and audited that.
        $this->assertSame(2.0, (float) $stale->passed_qty);
        $this->assertSame(1.0, (float) $second->before_state['passed_qty']);
        $this->assertSame(0.0, (float) $second->after_state['passed_qty']);
        $this->assertSame(2.0, (float) $workOrder->fresh()->produced_qty);
    }

    /**
     * The lock itself cannot be observed on SQLite, but its precondition can: the
     * order row is read (and therefore locked on PostgreSQL/MySQL) before the step
     * row, so every correction of any batch of this order queues on it. This is
     * the ordering P2.6 introduced - it fails on the previous code, where the step
     * was locked first and the order only read afterwards.
     */
    public function test_the_order_row_is_read_before_the_step_row(): void
    {
        [, , $stepB] = $this->orderWithTwoClosedBatches();

        $statements = [];
        $collecting = true;
        DB::listen(function ($query) use (&$statements, &$collecting): void {
            if ($collecting) {
                $statements[] = strtolower($query->sql);
            }
        });

        $this->correct($stepB, 1);
        $collecting = false;

        $firstOrderRead = null;
        $firstStepRead = null;

        foreach ($statements as $index => $sql) {
            if (! str_starts_with($sql, 'select')) {
                continue;
            }
            if ($firstOrderRead === null && str_contains($sql, 'work_orders')) {
                $firstOrderRead = $index;
            }
            if ($firstStepRead === null && str_contains($sql, 'batch_steps')) {
                $firstStepRead = $index;
            }
        }

        $this->assertNotNull($firstOrderRead, 'the correction must read the order row it serialises on');
        $this->assertNotNull($firstStepRead);
        $this->assertLessThan($firstStepRead, $firstOrderRead);
    }
}
