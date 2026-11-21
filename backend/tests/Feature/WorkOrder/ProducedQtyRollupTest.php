<?php

namespace Tests\Feature\WorkOrder;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\SerialUnit;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\WorkOrderProducedQty;
use App\Services\WorkOrder\WorkOrderService;
use App\Support\ProductionFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RQ-PA-263: one rollup for `produced_qty` and an explicit source per batch.
 *
 * The order's good units are the sum of what each batch contributes, read from
 * the source the batch declares: counted units (re-derived, so scrap and re-cuts
 * substitute instead of adding), a stated quantity, or the machine counter. A
 * batch with no declared source blocks a new rollup (422) instead of guessing;
 * reading the stored figure never throws.
 */
class ProducedQtyRollupTest extends TestCase
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

    /** An order whose only routing step is packing (the batch closes on it). */
    private function makeWorkOrder(float $plannedQty, array $overrides = []): WorkOrder
    {
        $product = ProductType::factory()->create();
        $template = ProcessTemplate::factory()->create(['product_type_id' => $product->id]);
        TemplateStep::create([
            'process_template_id' => $template->id, 'step_number' => 1, 'name' => 'Pack',
            'kind' => TemplateStep::KIND_PACKING, 'config' => ['unit' => 'carton', 'carton_capacity' => 5],
        ]);

        return WorkOrder::factory()->create($overrides + [
            'product_type_id' => $product->id,
            'process_snapshot' => $template->fresh()->toSnapshot(),
            'planned_qty' => $plannedQty,
            'status' => WorkOrder::STATUS_IN_PROGRESS,
        ]);
    }

    private function makeBatch(WorkOrder $workOrder, float $targetQty, ?Batch $replaces = null): Batch
    {
        $batch = app(WorkOrderService::class)->createBatch($workOrder, $targetQty);

        if ($replaces) {
            $batch->forceFill([
                'is_replacement' => true,
                'replaces_batch_id' => $replaces->id,
            ])->save();
        }

        return $batch->fresh();
    }

    private function unit(WorkOrder $workOrder, Batch $batch, string $no, string $status = SerialUnit::STATUS_COMPLETED): SerialUnit
    {
        return SerialUnit::create([
            'serial_no' => $no,
            'psn' => $no,
            'work_order_id' => $workOrder->id,
            'batch_id' => $batch->id,
            'status' => $status,
        ]);
    }

    /** Scrap as the unit ledger shows it; SerialTraceService's re-derivation is M3. */
    private function scrap(SerialUnit $unit): void
    {
        $unit->update(['status' => SerialUnit::STATUS_SCRAPPED]);
    }

    /**
     * Re-cut on a finished order reopens it, as `BatchController::recut()` does -
     * the shortage/status ladder is not what these tests measure.
     */
    private function keepProducing(WorkOrder $workOrder): void
    {
        if ($workOrder->fresh()->status === WorkOrder::STATUS_DONE) {
            $workOrder->fresh()->update(['status' => WorkOrder::STATUS_IN_PROGRESS, 'completed_at' => null]);
        }
    }

    /** Close a batch's packing step, deriving the quantity from units unless stated. */
    private function pack(Batch $batch, ?float $statedQty = null): BatchStep
    {
        $this->keepProducing($batch->workOrder);

        $step = $batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($step, $this->operator);

        return app(BatchService::class)->completeStep(
            $step->fresh(),
            $this->operator,
            $statedQty === null ? [] : ['produced_qty' => $statedQty],
        );
    }

    private function recompute(WorkOrder $workOrder): float
    {
        return app(WorkOrderProducedQty::class)->recompute($workOrder->fresh());
    }

    // ── (a) RC-32: the scrapped original is substituted, not added ───────────

    public function test_a_scrapped_unit_is_replaced_not_added(): void
    {
        $wo = $this->makeWorkOrder(1);
        $root = $this->makeBatch($wo, 1);
        $replacement = $this->makeBatch($wo, 1, $root);
        $original = $this->unit($wo, $root, 'SN-1');

        $this->pack($root);
        $this->assertSame(1.0, (float) $wo->fresh()->produced_qty);

        $this->scrap($original);
        $this->unit($wo, $replacement, 'SN-R1');
        $this->pack($replacement);

        // 1 + 1 would be 2: the order re-derives the root from its units (0 now).
        // The batch itself keeps the step confirmation it recorded - the order
        // carries good units, the batch carries what its step confirmed (D1).
        $this->assertSame(1.0, (float) $root->fresh()->produced_qty);
        $this->assertSame(1.0, (float) $wo->fresh()->produced_qty);
    }

    // ── (b) scrap before packing: a counted zero, not a missing figure ───────

    public function test_a_unit_scrapped_before_packing_leaves_that_batch_at_zero(): void
    {
        $wo = $this->makeWorkOrder(1);
        $root = $this->makeBatch($wo, 1);
        $replacement = $this->makeBatch($wo, 1, $root);
        $this->scrap($this->unit($wo, $root, 'SN-1'));

        $this->pack($root);

        $this->assertSame(0.0, (float) $root->fresh()->produced_qty);
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_UNITS, $root->fresh()->producedQtySource());
        $this->assertSame(0.0, (float) $wo->fresh()->produced_qty);

        $this->unit($wo, $replacement, 'SN-R1');
        $this->pack($replacement);

        $this->assertSame(1.0, (float) $wo->fresh()->produced_qty);
    }

    // ── (c) five units, one re-cut item ─────────────────────────────────────

    public function test_five_units_with_one_recut_item_still_count_five(): void
    {
        $wo = $this->makeWorkOrder(5);
        $root = $this->makeBatch($wo, 5);
        $replacement = $this->makeBatch($wo, 1, $root);

        $units = [];
        foreach (range(1, 5) as $n) {
            $units[] = $this->unit($wo, $root, 'SN-'.$n);
        }
        $this->pack($root);
        $this->assertSame(5.0, (float) $wo->fresh()->produced_qty);

        $this->scrap($units[0]);
        $this->unit($wo, $replacement, 'SN-R1');
        $this->pack($replacement);

        $this->assertSame(5.0, (float) $wo->fresh()->produced_qty);
    }

    // ── (d) a re-cut of a re-cut ────────────────────────────────────────────

    public function test_a_unit_recut_twice_still_counts_once(): void
    {
        $wo = $this->makeWorkOrder(1);
        $root = $this->makeBatch($wo, 1);
        $first = $this->makeBatch($wo, 1, $root);
        $second = $this->makeBatch($wo, 1, $first);

        $this->unit($wo, $root, 'SN-1');
        $this->pack($root);
        $this->scrap(SerialUnit::where('batch_id', $root->id)->firstOrFail());

        $this->unit($wo, $first, 'SN-R1');
        $this->pack($first);
        $this->assertSame(1.0, (float) $wo->fresh()->produced_qty);

        $this->scrap(SerialUnit::where('batch_id', $first->id)->firstOrFail());
        $this->unit($wo, $second, 'SN-R2');
        $this->pack($second);

        $this->assertSame(1.0, (float) $wo->fresh()->produced_qty);
    }

    // ── declared sources ────────────────────────────────────────────────────

    public function test_a_declared_quantity_wins_over_the_unit_count(): void
    {
        $wo = $this->makeWorkOrder(7);
        $batch = $this->makeBatch($wo, 7);
        $this->unit($wo, $batch, 'SN-1');
        $this->unit($wo, $batch, 'SN-2');
        $this->unit($wo, $batch, 'SN-3');

        // A supervisor's correction (correctPackingQuantity) states the figure.
        $batch->update(['produced_qty' => 7]);
        $batch->setProducedQtySource(Batch::PRODUCED_QTY_SOURCE_MANUAL);
        $batch->update(['extra_data' => $batch->extra_data]);

        $this->assertSame(7.0, $this->recompute($wo));
    }

    public function test_an_unknown_source_blocks_a_new_rollup(): void
    {
        $wo = $this->makeWorkOrder(3);
        $batch = $this->makeBatch($wo, 3);
        $batch->update(['produced_qty' => 3, 'status' => Batch::STATUS_DONE]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('produced_qty_source_unknown');

        $this->recompute($wo);
    }

    public function test_reading_an_unknown_source_keeps_the_stored_figures(): void
    {
        $wo = $this->makeWorkOrder(3);
        $batch = $this->makeBatch($wo, 3);
        $batch->update(['produced_qty' => 3, 'status' => Batch::STATUS_DONE]);
        $wo->update(['produced_qty' => 3]);

        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_UNKNOWN, $batch->fresh()->producedQtySource());
        $this->assertSame('unknown', $batch->fresh()->toArray()['produced_qty_source']);
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_UNKNOWN, $wo->fresh()->producedQtySource());
        $this->assertSame('unknown', $wo->fresh()->toArray()['produced_qty_source']);
        // Reading never rewrites the stored quantity.
        $this->assertSame(3.0, (float) $wo->fresh()->produced_qty);
    }

    public function test_closing_a_batch_without_a_source_is_refused(): void
    {
        // A production step (no packing): closing it would write the plan as the
        // produced quantity, which is not a source.
        $wo = WorkOrder::factory()->create([
            'planned_qty' => 3,
            'status' => WorkOrder::STATUS_IN_PROGRESS,
        ]);
        $batch = $this->makeBatch($wo, 3);

        $steps = $batch->steps()->orderBy('step_number')->get();
        foreach ($steps->slice(0, -1) as $earlier) {
            $earlier->update([
                'status' => BatchStep::STATUS_DONE,
                'started_at' => now()->subHour(),
                'completed_at' => now()->subMinutes(30),
            ]);
        }

        $step = $steps->last();
        $step->update(['status' => BatchStep::STATUS_IN_PROGRESS, 'started_at' => now()->subMinutes(5)]);

        try {
            app(BatchService::class)->completeStep($step->fresh(), $this->operator);
            $this->fail('A batch with no declared source must not be rolled up.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('produced_qty_source_unknown', $e->getMessage());
            $this->assertStringContainsString((string) $batch->id, $e->getMessage());
        }

        // The transaction rolled back: the step stays open and nothing was written.
        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $step->fresh()->status);
        $this->assertSame(0.0, (float) $wo->fresh()->produced_qty);
    }

    public function test_the_api_answers_422_when_a_batch_has_no_source(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Operator');

        $wo = WorkOrder::factory()->create(['planned_qty' => 3, 'status' => WorkOrder::STATUS_IN_PROGRESS]);
        $user->lines()->attach($wo->line_id);
        $batch = $this->makeBatch($wo, 3);

        $steps = $batch->steps()->orderBy('step_number')->get();
        foreach ($steps->slice(0, -1) as $earlier) {
            $earlier->update([
                'status' => BatchStep::STATUS_DONE,
                'started_at' => now()->subHour(),
                'completed_at' => now()->subMinutes(30),
            ]);
        }
        $step = $steps->last();
        $step->update(['status' => BatchStep::STATUS_IN_PROGRESS, 'started_at' => now()->subMinutes(5)]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/batch-steps/{$step->id}/complete", []);

        $response->assertStatus(422);
        $this->assertStringContainsString('produced_qty_source_unknown', $response->json('message'));

        // Fail closed: nothing was written, the step stays open.
        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $step->fresh()->status);
        $this->assertSame(0.0, (float) $wo->fresh()->produced_qty);
    }

    // ── counting_source policy (machine|both ⇒ PLC counter) ─────────────────

    public function test_the_machine_counter_is_the_source_of_a_machine_counted_order(): void
    {
        $wo = $this->makeWorkOrder(10, [
            'counting_source' => WorkOrder::COUNTING_MACHINE,
            'produced_qty' => 9,
        ]);
        $batch = $this->makeBatch($wo, 3);
        $this->unit($wo, $batch, 'SN-1');
        $batch->setProducedQtySource(Batch::PRODUCED_QTY_SOURCE_UNITS);
        $batch->update(['extra_data' => $batch->extra_data]);

        $this->assertSame(9.0, $this->recompute($wo));
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_MACHINE, $wo->fresh()->producedQtySource());
    }

    public function test_the_counter_wins_over_packed_units_when_counting_is_both(): void
    {
        $wo = $this->makeWorkOrder(10, [
            'counting_source' => WorkOrder::COUNTING_BOTH,
            'produced_qty' => 9,
        ]);
        $batch = $this->makeBatch($wo, 3);
        $this->unit($wo, $batch, 'SN-1');
        $this->unit($wo, $batch, 'SN-2');
        $this->unit($wo, $batch, 'SN-3');
        $batch->setProducedQtySource(Batch::PRODUCED_QTY_SOURCE_UNITS);
        $batch->update(['extra_data' => $batch->extra_data]);

        // counting_source=both ⇒ licznik PLC (decyzja 2026-11-21): not 3 units.
        $this->assertSame(9.0, $this->recompute($wo));
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_MACHINE, $wo->fresh()->producedQtySource());
    }

    // ── transfer flow policy ────────────────────────────────────────────────

    public function test_transfer_flow_rolls_up_the_step_ledger(): void
    {
        ProductionFlow::set(ProductionFlow::TRANSFER);

        $wo = $this->makeWorkOrder(10);
        $first = $this->makeBatch($wo, 4);
        $second = $this->makeBatch($wo, 3);
        $first->update(['produced_qty' => 4]);
        $second->update(['produced_qty' => 3]);

        // Transfer: the ledger carries the figure, so no batch needs a source.
        $this->assertSame(7.0, $this->recompute($wo));
        $this->assertSame(WorkOrder::PRODUCED_QTY_SOURCE_STEPS, $wo->fresh()->producedQtySource());
    }
}
