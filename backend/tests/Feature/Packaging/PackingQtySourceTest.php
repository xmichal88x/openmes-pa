<?php

namespace Tests\Feature\Packaging;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\SerialUnit;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RQ-PA-263: the packing step records where its produced quantity came from.
 * Units the MES counted -> `units` (re-derived from the unit ledger later), a
 * quantity the operator stated -> `manual`, a supervisor's correction ->
 * `manual`. Nothing else is marked: paths without that evidence stay `unknown`.
 */
class PackingQtySourceTest extends TestCase
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

    /** An order whose only routing step is packing, with a running pack step. */
    private function packingStep(int $targetQty = 2): array
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

    /** A unit that went through packing - the only kind that is a good unit (P2.12). */
    private function unit(WorkOrder $wo, Batch $batch, string $no): SerialUnit
    {
        return SerialUnit::create([
            'serial_no' => $no,
            'psn' => $no,
            'work_order_id' => $wo->id,
            'batch_id' => $batch->id,
            'status' => SerialUnit::STATUS_COMPLETED,
            'packed_at' => now(),
        ]);
    }

    public function test_a_quantity_the_mes_derived_from_units_is_marked_units(): void
    {
        [$wo, $batch, $step] = $this->packingStep(2);
        $this->unit($wo, $batch, 'SN-1');
        $this->unit($wo, $batch, 'SN-2');

        app(BatchService::class)->completeStep($step, $this->operator);

        $saved = $batch->fresh();
        $this->assertSame(2.0, (float) $saved->produced_qty);
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_UNITS, $saved->producedQtySource());
    }

    public function test_a_quantity_the_operator_stated_is_marked_manual(): void
    {
        [$wo, $batch, $step] = $this->packingStep(2);

        app(BatchService::class)->completeStep($step, $this->operator, ['produced_qty' => 2]);

        $saved = $batch->fresh();
        $this->assertSame(2.0, (float) $saved->produced_qty);
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_MANUAL, $saved->producedQtySource());
    }

    public function test_a_stated_quantity_matching_the_units_is_still_marked_manual(): void
    {
        [$wo, $batch, $step] = $this->packingStep(2);
        $this->unit($wo, $batch, 'SN-1');
        $this->unit($wo, $batch, 'SN-2');

        app(BatchService::class)->completeStep($step, $this->operator, ['produced_qty' => 2]);

        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_MANUAL, $batch->fresh()->producedQtySource());
    }

    public function test_a_supervisor_correction_makes_the_quantity_manual(): void
    {
        [$wo, $batch, $step] = $this->packingStep(2);
        $this->unit($wo, $batch, 'SN-1');
        $this->unit($wo, $batch, 'SN-2');
        app(BatchService::class)->completeStep($step, $this->operator);
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_UNITS, $batch->fresh()->producedQtySource());

        app(BatchService::class)->correctPackingQuantity(
            BatchStep::findOrFail($step->id),
            $this->supervisor,
            1,
            'Only one carton was actually packed.',
        );

        $saved = $batch->fresh();
        $this->assertSame(1.0, (float) $saved->produced_qty);
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_MANUAL, $saved->producedQtySource());
    }

    /**
     * A non-packing step leaves the quantity without evidence: no unit count, no
     * stated figure. Reading it is allowed (and says `unknown`); closing it is
     * refused, because the rollup must not write the plan off as produced.
     */
    public function test_a_non_packing_step_reads_as_unknown_and_refuses_to_close(): void
    {
        $product = ProductType::factory()->create();
        $template = ProcessTemplate::factory()->create(['product_type_id' => $product->id]);
        TemplateStep::create(['process_template_id' => $template->id, 'step_number' => 1, 'name' => 'Assemble']);
        $wo = WorkOrder::factory()->create([
            'product_type_id' => $product->id,
            'process_snapshot' => $template->fresh()->toSnapshot(),
            'planned_qty' => 3,
            'status' => WorkOrder::STATUS_IN_PROGRESS,
        ]);
        $batch = app(WorkOrderService::class)->createBatch($wo, 3);
        $step = $batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($step, $this->operator);

        // Reading never throws and never guesses a source.
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_UNKNOWN, $batch->producedQtySource());

        try {
            app(BatchService::class)->completeStep($step, $this->operator);
            $this->fail('A batch with no declared source must not be rolled up.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('produced_qty_source_unknown', $e->getMessage());
        }

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $step->fresh()->status);
        $this->assertSame(0.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_UNKNOWN, $batch->fresh()->producedQtySource());
    }
}
