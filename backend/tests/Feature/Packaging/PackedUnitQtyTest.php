<?php

namespace Tests\Feature\Packaging;

use App\Models\Batch;
use App\Models\Material;
use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\SerialUnit;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Packaging\PackedUnitQty;
use App\Services\Traceability\SerialTraceService;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\WorkOrderProducedQty;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A good unit is a packed unit (owner's decision, 2026-11-22).
 *
 * `completed` alone says the unit's work is done, not that it is goods: a
 * sub-assembly registered by `SerialTraceService::registerSubassembly()` is
 * `completed` with no `packed_at` and no batch, yet it used to enter the order's
 * quantity as if it were a product (plan 1 read as produced 2). `packed_at` is
 * the physical act of packing, so it is the only proof of a good unit.
 */
class PackedUnitQtyTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Admin', 'web');
        Role::findOrCreate('Operator', 'web');
        $operator = User::factory()->create();
        $operator->assignRole('Operator');
        $this->operator = $operator;
    }

    /** An order whose only routing step is packing, with a running pack step. */
    private function packingStep(float $targetQty = 1): array
    {
        $product = ProductType::factory()->create();
        $template = ProcessTemplate::factory()->create(['product_type_id' => $product->id]);
        TemplateStep::create([
            'process_template_id' => $template->id, 'step_number' => 1, 'name' => 'Pack',
            'kind' => TemplateStep::KIND_PACKING, 'config' => ['unit' => 'carton', 'carton_capacity' => 5],
        ]);
        $wo = WorkOrder::factory()->create([
            'product_type_id' => $product->id,
            'process_snapshot' => $template->fresh()->toSnapshot(),
            'planned_qty' => $targetQty,
            'status' => WorkOrder::STATUS_IN_PROGRESS,
        ]);
        $batch = app(WorkOrderService::class)->createBatch($wo, $targetQty);
        $step = $batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($step, $this->operator);

        return [$wo->fresh(), $batch->fresh(), $step->fresh()];
    }

    /** A unit that went through packing: the only kind that is a good unit. */
    private function packedUnit(
        WorkOrder $wo,
        ?Batch $batch,
        string $no,
        string $status = SerialUnit::STATUS_COMPLETED,
    ): SerialUnit {
        return SerialUnit::create([
            'serial_no' => $no,
            'psn' => $no,
            'work_order_id' => $wo->id,
            'batch_id' => $batch?->id,
            'status' => $status,
            'packed_at' => now(),
        ]);
    }

    /** A unit the MES knows about but that never passed packing (a component). */
    private function unpackedUnit(
        WorkOrder $wo,
        ?Batch $batch,
        string $no,
        string $status = SerialUnit::STATUS_COMPLETED,
    ): SerialUnit {
        return SerialUnit::create([
            'serial_no' => $no,
            'psn' => $no,
            'work_order_id' => $wo->id,
            'batch_id' => $batch?->id,
            'status' => $status,
        ]);
    }

    /** A sub-assembly registered where it is made: completed, unpacked, no batch. */
    private function subassembly(WorkOrder $wo, string $no): SerialUnit
    {
        return app(SerialTraceService::class)->registerSubassembly(
            $no,
            Material::factory()->create(),
            $wo,
            $this->operator,
        );
    }

    private function counted(Batch $batch): float
    {
        return app(PackedUnitQty::class)->forBatch($batch->fresh());
    }

    // ── what counts as a good unit ──────────────────────────────────────────

    public function test_a_completed_unit_that_was_never_packed_is_not_a_good_unit(): void
    {
        [$wo, $batch] = $this->packingStep();

        $this->unpackedUnit($wo, $batch, 'SN-1');
        $this->assertSame(0.0, $this->counted($batch));

        $this->packedUnit($wo, $batch, 'SN-2');
        $this->assertSame(1.0, $this->counted($batch));
    }

    public function test_only_packed_units_in_a_finished_status_count(): void
    {
        [$wo, $batch] = $this->packingStep();

        $this->packedUnit($wo, $batch, 'SN-C', SerialUnit::STATUS_COMPLETED);
        $this->packedUnit($wo, $batch, 'SN-S', SerialUnit::STATUS_SHIPPED);
        // Packed, but off the line for good reasons or not finished at all.
        $this->packedUnit($wo, $batch, 'SN-B', SerialUnit::STATUS_BLOCKED);
        $this->packedUnit($wo, $batch, 'SN-P', SerialUnit::STATUS_IN_PRODUCTION);
        $this->packedUnit($wo, $batch, 'SN-X', SerialUnit::STATUS_SCRAPPED);

        $this->assertSame(2.0, $this->counted($batch));
    }

    // ── units without a batch (I15) ─────────────────────────────────────────

    public function test_a_loose_packed_unit_counts_for_an_order_with_one_batch(): void
    {
        [$wo, $batch] = $this->packingStep();
        $this->packedUnit($wo, $batch, 'SN-1');
        $this->packedUnit($wo, null, 'SN-2');

        $this->assertSame(2.0, $this->counted($batch));
    }

    public function test_a_loose_packed_unit_is_not_credited_when_the_order_has_two_batches(): void
    {
        [$wo, $batch] = $this->packingStep();
        $other = app(WorkOrderService::class)->createBatch($wo, 1);
        $this->packedUnit($wo, $batch, 'SN-1');
        $this->packedUnit($wo, null, 'SN-2');
        $this->assertSame(2, $wo->batches()->count());

        $this->assertSame(1.0, $this->counted($batch));
        $this->assertSame(0.0, $this->counted($other));
    }

    public function test_a_loose_unpacked_unit_is_not_credited_even_for_one_batch(): void
    {
        [$wo, $batch] = $this->packingStep();
        $this->unpackedUnit($wo, null, 'SN-1');

        $this->assertSame(0.0, $this->counted($batch));
    }

    // ── the repair: a component does not inflate the order ──────────────────

    public function test_packing_a_component_plus_one_good_unit_closes_on_the_plan_not_on_plan_plus_one(): void
    {
        [$wo, $batch, $step] = $this->packingStep(1);
        $component = $this->subassembly($wo, 'SUB-1');
        $this->packedUnit($wo, $batch, 'SN-1');

        // The component is `completed` in the ledger, exactly as registerSubassembly leaves it.
        $this->assertSame(SerialUnit::STATUS_COMPLETED, $component->fresh()->status);
        $this->assertNull($component->fresh()->packed_at);
        $this->assertNull($component->fresh()->batch_id);

        $done = app(BatchService::class)->completeStep($step, $this->operator);

        $this->assertSame(1.0, (float) $done->passed_qty);
        $this->assertSame(1.0, (float) $batch->fresh()->produced_qty);
        // Plan 1: the component is not a second good unit (it used to read 2).
        $this->assertSame(1.0, (float) $wo->fresh()->produced_qty);
    }

    public function test_a_component_alone_is_not_production(): void
    {
        [$wo, $batch] = $this->packingStep(1);
        $this->subassembly($wo, 'SUB-1');

        $batch->setProducedQtySource(Batch::PRODUCED_QTY_SOURCE_UNITS);
        $batch->update(['extra_data' => $batch->extra_data]);

        $this->assertSame(0.0, app(WorkOrderProducedQty::class)->recompute($wo->fresh()));
    }

    public function test_a_component_alone_does_not_close_packing_as_produced(): void
    {
        [$wo, $batch, $step] = $this->packingStep(1);
        $this->subassembly($wo, 'SUB-1');

        try {
            app(BatchService::class)->completeStep($step, $this->operator);
            $this->fail('A component is not a good unit, so packing cannot close on it.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('packing_shortage_requires_decision', $e->getMessage());
        }

        $this->assertSame(0.0, (float) $batch->fresh()->produced_qty);
    }

    // ── parity: everything packed behaves exactly as before ─────────────────

    public function test_three_packed_units_on_a_plan_of_three_are_the_quantity(): void
    {
        [$wo, $batch, $step] = $this->packingStep(3);
        foreach (range(1, 3) as $n) {
            $this->packedUnit($wo, $batch, 'SN-'.$n);
        }

        $done = app(BatchService::class)->completeStep($step, $this->operator);

        $this->assertSame(3.0, (float) $done->passed_qty);
        $this->assertSame(3.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(3.0, (float) $wo->fresh()->produced_qty);
    }
}
