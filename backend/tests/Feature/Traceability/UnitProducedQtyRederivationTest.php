<?php

namespace Tests\Feature\Traceability;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\ScrapReason;
use App\Models\SerialUnit;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\UnitProducedQtySync;
use App\Services\WorkOrder\WorkOrderService;
use App\Support\ProductionFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RQ-PA-263 / M3: a unit's status change re-derives the quantities it can, in
 * the same transaction, and states out loud what it cannot.
 *
 * Scrapping is a physical fact, so it always lands. What follows from it is the
 * work order's good units: recounted for a `units` batch, left alone for a
 * declared figure (manual) or the machine counter - and named in the answer
 * instead of being changed silently. A gap elsewhere (a finished batch with no
 * declared source) never blocks the scrap either: it is reported.
 */
class UnitProducedQtyRederivationTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $supervisor;

    private ScrapReason $reason;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Admin', 'Operator', 'Supervisor', 'PackingWorker'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('Supervisor');

        $this->reason = ScrapReason::factory()->create([
            'code' => 'NC-02',
            'name' => 'Broken beyond repair',
            'is_active' => true,
        ]);
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

    /**
     * A unit that went through packing: `UnitPackingService::packUnit()` sets
     * `packed_at` when it completes a unit, and only a packed unit is a good unit
     * (P2.12). A `blocked`/`scrapped` unit keeps the stamp but leaves the count.
     */
    private function unit(WorkOrder $workOrder, ?Batch $batch, string $no, string $status = SerialUnit::STATUS_COMPLETED): SerialUnit
    {
        return SerialUnit::create([
            'serial_no' => $no,
            'psn' => $no,
            'work_order_id' => $workOrder->id,
            'batch_id' => $batch?->id,
            'status' => $status,
            'packed_at' => in_array($status, [SerialUnit::STATUS_COMPLETED, SerialUnit::STATUS_SHIPPED], true) ? now() : null,
        ]);
    }

    /** Packing on a finished order reopens it, as `BatchController::recut()` does. */
    private function keepProducing(WorkOrder $workOrder): void
    {
        if ($workOrder->fresh()->status === WorkOrder::STATUS_DONE) {
            $workOrder->fresh()->update(['status' => WorkOrder::STATUS_IN_PROGRESS, 'completed_at' => null]);
        }
    }

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

    private function scrap(SerialUnit $unit): TestResponse
    {
        return $this->actingAs($this->operator)
            ->postJson("/api/v1/serial-units/{$unit->id}/scrap", ['reason' => 'Broken beyond repair']);
    }

    private function block(SerialUnit $unit): TestResponse
    {
        return $this->actingAs($this->operator)
            ->postJson("/api/v1/serial-units/{$unit->id}/block", ['scrap_reason_id' => $this->reason->id]);
    }

    private function unblock(SerialUnit $unit): TestResponse
    {
        return $this->actingAs($this->supervisor)
            ->postJson("/api/v1/serial-units/{$unit->id}/unblock", ['note' => 'Released']);
    }

    private function recut(SerialUnit $unit): TestResponse
    {
        return $this->actingAs($this->operator)
            ->postJson("/api/v1/serial-units/{$unit->id}/recut", ['reason' => 'Cut again']);
    }

    private function warningCodes(TestResponse $response): array
    {
        return array_column($response->json('warnings') ?? [], 'code');
    }

    // ── (a) RC-32: scrap substitutes the original, the replacement adds one ──

    public function test_scrap_re_derives_a_units_batch_and_the_replacement_brings_the_order_back(): void
    {
        $wo = $this->makeWorkOrder(1);
        $root = $this->makeBatch($wo, 1);
        $replacement = $this->makeBatch($wo, 1, $root);
        $original = $this->unit($wo, $root, 'SN-1');

        $this->pack($root);
        $this->assertSame(1.0, (float) $wo->fresh()->produced_qty);

        $this->scrap($original)->assertOk()
            ->assertJsonPath('data.status', SerialUnit::STATUS_SCRAPPED)
            ->assertJsonPath('warnings', []);

        // The counted batch follows its units; the order follows the batch.
        $this->assertSame(0.0, (float) $root->fresh()->produced_qty);
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_UNITS, $root->fresh()->producedQtySource());
        $this->assertSame(0.0, (float) $wo->fresh()->produced_qty);

        $this->unit($wo, $replacement, 'SN-R1');
        $this->pack($replacement);

        // 1 + 1 would be 2 (the live defect): the order stays at its one good unit.
        $this->assertSame(1.0, (float) $replacement->fresh()->produced_qty);
        $this->assertSame(1.0, (float) $wo->fresh()->produced_qty);
    }

    // ── (b) a declared figure is not rewritten, and the answer says why ──────

    public function test_scrap_leaves_a_manual_quantity_alone_and_says_so(): void
    {
        $wo = $this->makeWorkOrder(5);
        $batch = $this->makeBatch($wo, 5);
        $units = [];
        foreach (range(1, 5) as $n) {
            $units[] = $this->unit($wo, $batch, 'SN-'.$n);
        }
        $this->pack($batch, 5);

        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_MANUAL, $batch->fresh()->producedQtySource());
        $this->assertSame(5.0, (float) $wo->fresh()->produced_qty);

        $response = $this->scrap($units[0])->assertOk()
            ->assertJsonPath('data.status', SerialUnit::STATUS_SCRAPPED);

        $this->assertSame([UnitProducedQtySync::DECLARED_FIGURE], $this->warningCodes($response));

        // A human stated 5: the scrap does not silently lower it.
        $this->assertSame(5.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(5.0, (float) $wo->fresh()->produced_qty);
    }

    /**
     * The bench only renders `message`, so the notice has to be in it - the
     * `warnings` array alone would be a channel nobody reads at the station.
     */
    public function test_the_station_message_carries_the_notice(): void
    {
        $wo = $this->makeWorkOrder(1);
        $batch = $this->makeBatch($wo, 1);
        $unit = $this->unit($wo, $batch, 'SN-1');
        $this->pack($batch, 1);

        $response = $this->actingAs($this->operator)
            ->postJson(route('operator.unit-labels.scrap', $unit), ['reason' => 'Broken'])
            ->assertOk()
            ->assertJsonPath('unit.status', SerialUnit::STATUS_SCRAPPED);

        $this->assertSame([UnitProducedQtySync::DECLARED_FIGURE], $this->warningCodes($response));
        $this->assertStringContainsString('Batch '.$batch->id, (string) $response->json('message'));
    }

    // ── (c) counting_source=machine|both: the PLC counter owns the number ────

    public function test_a_machine_counted_order_keeps_the_counter_after_a_scrap(): void
    {
        $this->assertMachineCounterSurvivesScrap(WorkOrder::COUNTING_MACHINE);
    }

    public function test_a_both_counted_order_keeps_the_counter_after_a_scrap(): void
    {
        $this->assertMachineCounterSurvivesScrap(WorkOrder::COUNTING_BOTH);
    }

    private function assertMachineCounterSurvivesScrap(string $countingSource): void
    {
        $wo = $this->makeWorkOrder(10, [
            'counting_source' => $countingSource,
            'produced_qty' => 9,
        ]);
        $batch = $this->makeBatch($wo, 1);
        $unit = $this->unit($wo, $batch, 'SN-1');
        $this->pack($batch);

        $this->assertSame(1.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(9.0, (float) $wo->fresh()->produced_qty);

        $response = $this->scrap($unit)->assertOk();

        $this->assertSame([UnitProducedQtySync::MACHINE_COUNTER], $this->warningCodes($response));
        $this->assertSame(1.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(9.0, (float) $wo->fresh()->produced_qty);
    }

    // ── (d) a legacy batch without a source never blocks the scrap ───────────

    public function test_a_legacy_batch_without_a_source_is_reported_not_thrown(): void
    {
        $wo = $this->makeWorkOrder(1);
        $root = $this->makeBatch($wo, 1);
        $legacy = $this->makeBatch($wo, 1);
        $unit = $this->unit($wo, $root, 'SN-1');

        $this->pack($root);
        $this->assertSame(1.0, (float) $wo->fresh()->produced_qty);

        // A finished batch from before the marker: nobody recorded its source.
        $legacy->update(['produced_qty' => 2, 'status' => Batch::STATUS_DONE]);
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_UNKNOWN, $legacy->fresh()->producedQtySource());

        $response = $this->scrap($unit)->assertOk()
            ->assertJsonPath('data.status', SerialUnit::STATUS_SCRAPPED);

        // The physical fact lands, the counted batch follows its units, and the
        // order keeps the figure it had - no invented number.
        $this->assertSame([UnitProducedQtySync::SOURCE_UNKNOWN], $this->warningCodes($response));
        $this->assertSame(0.0, (float) $root->fresh()->produced_qty);
        $this->assertSame(1.0, (float) $wo->fresh()->produced_qty);

        $this->assertSame(1, AuditLog::where('action', 'produced_qty_rollup_blocked')
            ->where('entity_id', $wo->id)->count());
    }

    // ── (e) a hold behaves like its batch's source ───────────────────────────

    public function test_block_re_derives_a_units_batch_and_release_restores_it(): void
    {
        $wo = $this->makeWorkOrder(1);
        $batch = $this->makeBatch($wo, 1);
        $unit = SerialUnit::create([
            'serial_no' => 'SN-1',
            'psn' => 'SN-1',
            'work_order_id' => $wo->id,
            'batch_id' => $batch->id,
            'status' => SerialUnit::STATUS_COMPLETED,
            // A packed unit comes back as completed when released.
            'packed_at' => now(),
        ]);
        $this->pack($batch);

        $this->block($unit)->assertOk()
            ->assertJsonPath('data.status', SerialUnit::STATUS_BLOCKED)
            ->assertJsonPath('warnings', []);

        $this->assertSame(0.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(0.0, (float) $wo->fresh()->produced_qty);

        $this->unblock($unit)->assertOk()
            ->assertJsonPath('data.status', SerialUnit::STATUS_COMPLETED);

        $this->assertSame(1.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(1.0, (float) $wo->fresh()->produced_qty);
    }

    public function test_block_leaves_a_manual_quantity_alone_and_says_so(): void
    {
        $wo = $this->makeWorkOrder(1);
        $batch = $this->makeBatch($wo, 1);
        $unit = $this->unit($wo, $batch, 'SN-1');
        $this->pack($batch, 1);

        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_MANUAL, $batch->fresh()->producedQtySource());

        $response = $this->block($unit)->assertOk();

        $this->assertSame([UnitProducedQtySync::DECLARED_FIGURE], $this->warningCodes($response));
        $this->assertSame(1.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(1.0, (float) $wo->fresh()->produced_qty);
    }

    // ── (f) a re-cut is the same substitution under a new serial ─────────────

    public function test_recut_re_derives_the_batch_and_the_order(): void
    {
        $wo = $this->makeWorkOrder(1);
        $root = $this->makeBatch($wo, 1);
        $unit = $this->unit($wo, $root, 'SN-1');
        $this->pack($root);

        $this->recut($unit)->assertOk()
            ->assertJsonPath('data.status', SerialUnit::STATUS_SCRAPPED)
            ->assertJsonPath('warnings', []);

        $this->assertSame(0.0, (float) $root->fresh()->produced_qty);
        $this->assertSame(0.0, (float) $wo->fresh()->produced_qty);
    }

    // ── transfer flow: the step ledger owns the batch, not the unit count ────

    public function test_the_transfer_flow_keeps_its_step_ledger_after_a_scrap(): void
    {
        ProductionFlow::set(ProductionFlow::TRANSFER);

        $wo = $this->makeWorkOrder(1);
        $batch = $this->makeBatch($wo, 1);
        $unit = $this->unit($wo, $batch, 'SN-1');

        // What the transfer ledger wrote: the batch produced what left its step.
        $batch->setProducedQtySource(Batch::PRODUCED_QTY_SOURCE_UNITS);
        $batch->update(['produced_qty' => 1, 'extra_data' => $batch->extra_data, 'status' => Batch::STATUS_DONE]);
        $wo->update(['produced_qty' => 1]);

        $response = $this->scrap($unit)->assertOk();

        // Rewriting it here would disagree with `passed_qty` until the next
        // transition; a correction belongs in `correctGoodQuantity`.
        $this->assertSame([UnitProducedQtySync::TRANSFER_LEDGER], $this->warningCodes($response));
        $this->assertSame(1.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(1.0, (float) $wo->fresh()->produced_qty);
    }

    // ── the unit without a batch: the order still follows its units ──────────

    public function test_a_unit_without_a_batch_is_re_derived_through_the_order(): void
    {
        $wo = $this->makeWorkOrder(1);
        $batch = $this->makeBatch($wo, 1);
        $unit = $this->unit($wo, null, 'SN-1');
        $this->pack($batch);

        // The single batch carries the loose unit (I15), so the order counts it.
        $this->assertSame(1.0, (float) $wo->fresh()->produced_qty);

        $this->scrap($unit)->assertOk();

        // A loose unit has no batch of its own to re-derive: the order follows
        // it (I15), while the batch keeps the confirmation its step recorded (D1).
        $this->assertSame(1.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(0.0, (float) $wo->fresh()->produced_qty);
    }
}
