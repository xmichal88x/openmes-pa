<?php

namespace Tests\Feature\Packaging;

use App\Models\AuditLog;
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
 * A packing step closes on the units it packed, not on the plan (I14: a quantity
 * below the plan needs a short-close decision; I15: units without a batch count
 * only for an order with a single batch).
 */
class PackingQtyDerivedTest extends TestCase
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
    private function packingStep(int $targetQty = 2, ?callable $tune = null): array
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
        if ($tune) {
            $tune($wo->fresh(), $batch->fresh());
        }
        $step = $batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($step, $this->operator);

        return [$wo->fresh(), $batch->fresh(), $step->fresh()];
    }

    /** A unit that left production (or the dock) on a batch. */
    private function unit(WorkOrder $wo, ?Batch $batch, string $no, string $status = SerialUnit::STATUS_COMPLETED): SerialUnit
    {
        return SerialUnit::create([
            'serial_no' => $no,
            'psn' => $no,
            'work_order_id' => $wo->id,
            'batch_id' => $batch?->id,
            'status' => $status,
        ]);
    }

    public function test_the_packed_units_are_the_quantity_and_the_plan_is_not_written_off(): void
    {
        [$wo, $batch, $step] = $this->packingStep(2);
        $this->unit($wo, $batch, 'SN-1');
        $this->unit($wo, $batch, 'SN-2');

        $done = app(BatchService::class)->completeStep($step, $this->operator);

        $this->assertSame(BatchStep::STATUS_DONE, $done->status);
        $this->assertSame(2.0, (float) $done->passed_qty);
        $this->assertSame(Batch::STATUS_DONE, $batch->fresh()->status);
        $this->assertSame(2.0, (float) $batch->fresh()->produced_qty);
    }

    public function test_units_of_a_single_batch_order_count_even_when_they_carry_no_batch(): void
    {
        [$wo, $batch, $step] = $this->packingStep(2);
        $this->unit($wo, $batch, 'SN-1');
        $this->unit($wo, null, 'SN-2');

        $done = app(BatchService::class)->completeStep($step, $this->operator);

        $this->assertSame(BatchStep::STATUS_DONE, $done->status);
        $this->assertSame(2.0, (float) $done->passed_qty);
        $this->assertSame(2.0, (float) $batch->fresh()->produced_qty);
    }

    public function test_units_without_a_batch_are_refused_when_the_order_has_several_batches(): void
    {
        [$wo, $batch, $step] = $this->packingStep(2);
        app(WorkOrderService::class)->createBatch($wo, 2);
        $this->unit($wo, null, 'SN-1');
        $this->assertSame(2, $wo->batches()->count());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('unit_attribution_ambiguous');
        app(BatchService::class)->completeStep($step, $this->operator);
    }

    public function test_a_short_pack_without_a_decision_keeps_the_step_open(): void
    {
        [$wo, $batch, $step] = $this->packingStep(2);
        $this->unit($wo, $batch, 'SN-1');

        try {
            app(BatchService::class)->completeStep($step, $this->operator);
            $this->fail('A short pack must not close without a short-close decision.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('packing_shortage_requires_decision', $e->getMessage());
        }

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $step->fresh()->status);
        $this->assertSame(0.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(0.0, (float) $step->fresh()->passed_qty);
    }

    public function test_a_short_pack_closes_on_the_real_quantity_once_a_supervisor_decided(): void
    {
        [$wo, $batch, $step] = $this->packingStep(2, function (WorkOrder $order) {
            $order->update(['short_closed_at' => now(), 'planned_qty' => 2, 'produced_qty' => 1]);
        });
        $this->unit($wo, $batch, 'SN-1');

        $done = app(BatchService::class)->completeStep($step, $this->operator);

        $this->assertSame(BatchStep::STATUS_DONE, $done->status);
        $this->assertSame(1.0, (float) $done->passed_qty);
        $this->assertSame(1.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(1.0, (float) $wo->fresh()->produced_qty);
    }

    public function test_a_stated_quantity_must_match_the_packed_units(): void
    {
        [$wo, $batch, $step] = $this->packingStep(2);
        $this->unit($wo, $batch, 'SN-1');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('packing_qty_mismatch_units');
        app(BatchService::class)->completeStep($step, $this->operator, ['produced_qty' => 2]);
    }

    public function test_with_no_unit_at_all_the_operator_must_state_the_quantity_and_it_is_audited(): void
    {
        [$wo, $batch, $step] = $this->packingStep(2);

        try {
            app(BatchService::class)->completeStep($step, $this->operator);
            $this->fail('Without units the quantity must be entered.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('packing_qty_required', $e->getMessage());
        }
        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $step->fresh()->status);

        $done = app(BatchService::class)->completeStep($step, $this->operator, ['produced_qty' => 2]);

        $this->assertSame(BatchStep::STATUS_DONE, $done->status);
        $this->assertSame(2.0, (float) $batch->fresh()->produced_qty);
        $audit = AuditLog::where('action', 'packing_qty_manual_no_units')->first();
        $this->assertNotNull($audit);
        $this->assertSame($step->id, (int) $audit->entity_id);
        $this->assertSame(2.0, (float) $audit->after_state['produced_qty']);
        $this->assertSame('no_units', $audit->after_state['reason']);
    }

    public function test_a_production_step_still_closes_on_the_plan(): void
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

        $done = app(BatchService::class)->completeStep($step, $this->operator);

        $this->assertSame(3.0, (float) $done->passed_qty);
        $this->assertSame(3.0, (float) $batch->fresh()->produced_qty);
    }
}
