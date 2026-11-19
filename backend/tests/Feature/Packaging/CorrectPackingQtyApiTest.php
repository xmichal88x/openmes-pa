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
use Tests\TestCase;

/**
 * P1/M-6: the MES sometimes claims a packing quantity that PA never packed. A
 * supervisor corrects the closed packing step with a reason, and the step, the
 * batch and the order are re-derived together — including the honest status: an
 * order that no longer covers its plan stops pretending to be DONE.
 */
class CorrectPackingQtyApiTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private User $operator;

    private string $supervisorToken;

    private string $operatorToken;

    private WorkOrder $workOrder;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('Supervisor');
        $this->supervisorToken = $this->supervisor->createToken('test')->plainTextToken;

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');
        $this->operatorToken = $this->operator->createToken('test')->plainTextToken;

        $product = ProductType::factory()->create();
        $template = ProcessTemplate::factory()->create(['product_type_id' => $product->id]);
        TemplateStep::create([
            'process_template_id' => $template->id, 'step_number' => 1, 'name' => 'Assemble',
        ]);
        TemplateStep::create([
            'process_template_id' => $template->id, 'step_number' => 2, 'name' => 'Pack',
            'kind' => TemplateStep::KIND_PACKING, 'config' => ['unit' => 'carton', 'carton_capacity' => 5],
        ]);

        $this->workOrder = WorkOrder::factory()->create([
            'product_type_id' => $product->id,
            'process_snapshot' => $template->fresh()->toSnapshot(),
            'planned_qty' => 2,
            'produced_qty' => 0,
            'status' => WorkOrder::STATUS_IN_PROGRESS,
        ]);

        $this->batch = app(WorkOrderService::class)->createBatch($this->workOrder, 2);

        $production = $this->batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($production, $this->operator);
        app(BatchService::class)->completeStep($production->fresh(), $this->operator);

        // One unit is all that PA packed, but the MES closed the packing step on
        // the plan and the order as complete: the inconsistency this endpoint repairs.
        SerialUnit::create([
            'serial_no' => 'SN-1',
            'psn' => 'SN-1',
            'work_order_id' => $this->workOrder->id,
            'batch_id' => $this->batch->id,
            'status' => SerialUnit::STATUS_COMPLETED,
        ]);

        $this->packingStep()->update([
            'status' => BatchStep::STATUS_DONE,
            'passed_qty' => 2,
            'completed_at' => now(),
        ]);
        $this->batch->update(['status' => Batch::STATUS_DONE, 'produced_qty' => 2, 'completed_at' => now()]);
        $this->workOrder->update([
            'produced_qty' => 2,
            'status' => WorkOrder::STATUS_DONE,
            'completed_at' => now(),
        ]);
    }

    private function url(BatchStep $step): string
    {
        return "/api/v1/batch-steps/{$step->id}/correct-packing-qty";
    }

    private function asSupervisor(BatchStep $step, array $payload = ['produced_qty' => 1, 'reason' => 'Only one carton was actually packed.'])
    {
        return $this->withHeader('Authorization', "Bearer {$this->supervisorToken}")
            ->postJson($this->url($step), $payload);
    }

    private function packingStep(): BatchStep
    {
        return BatchStep::where('batch_id', $this->batch->id)
            ->where('step_number', 2)
            ->firstOrFail();
    }

    private function productionStep(): BatchStep
    {
        return BatchStep::where('batch_id', $this->batch->id)
            ->where('step_number', 1)
            ->firstOrFail();
    }

    public function test_supervisor_correction_rewrites_the_step_batch_and_order_and_restores_an_honest_status(): void
    {
        $response = $this->asSupervisor($this->packingStep())->assertStatus(200)
            ->assertJsonPath('message', 'Packing quantity corrected')
            ->assertJsonPath('wo_status', WorkOrder::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.status', BatchStep::STATUS_DONE);
        $this->assertSame(1.0, (float) $response->json('batch_produced_qty'));
        $this->assertSame(1.0, (float) $response->json('wo_produced_qty'));
        $this->assertSame(1.0, (float) $response->json('data.passed_qty'));

        $step = $this->packingStep();
        $this->assertSame(BatchStep::STATUS_DONE, $step->status);
        $this->assertSame(1.0, (float) $step->passed_qty);

        $batch = $this->batch->fresh();
        $this->assertSame(1.0, (float) $batch->produced_qty);

        $order = $this->workOrder->fresh();
        $this->assertSame(1.0, (float) $order->produced_qty);
        $this->assertSame(WorkOrder::STATUS_IN_PROGRESS, $order->status);
        $this->assertNull($order->completed_at);

        $audit = AuditLog::where('action', 'packing_qty_corrected')->first();
        $this->assertNotNull($audit);
        $this->assertSame(BatchStep::class, $audit->entity_type);
        $this->assertSame($step->id, (int) $audit->entity_id);
        $this->assertSame($this->supervisor->id, $audit->user_id);
        $this->assertSame(2.0, (float) $audit->before_state['passed_qty']);
        $this->assertSame(WorkOrder::STATUS_DONE, $audit->before_state['wo_status']);
        $this->assertSame(1.0, (float) $audit->after_state['passed_qty']);
        $this->assertSame(WorkOrder::STATUS_IN_PROGRESS, $audit->after_state['wo_status']);
        $this->assertSame('Only one carton was actually packed.', $audit->after_state['reason']);
    }

    public function test_a_short_closed_order_keeps_its_done_after_the_correction(): void
    {
        $this->workOrder->update([
            'short_closed_at' => now(),
            'short_closed_by_id' => $this->supervisor->id,
            'short_close_reason' => 'Second piece scrapped before packing.',
        ]);

        $this->asSupervisor($this->packingStep())
            ->assertStatus(200)
            ->assertJsonPath('wo_status', WorkOrder::STATUS_DONE);

        $order = $this->workOrder->fresh();
        $this->assertSame(WorkOrder::STATUS_DONE, $order->status);
        $this->assertSame(1.0, (float) $order->produced_qty);
        $this->assertTrue($order->isShortClosed());
    }

    public function test_an_open_packing_step_is_refused_and_nothing_changes(): void
    {
        $step = $this->packingStep();
        $step->update(['status' => BatchStep::STATUS_IN_PROGRESS, 'passed_qty' => 0]);

        $response = $this->asSupervisor($step)->assertStatus(422);
        $this->assertStringContainsString('packing_correction_step_open', $response->json('message'));

        $this->assertSame(0.0, (float) $step->fresh()->passed_qty);
        $this->assertSame(2.0, (float) $this->batch->fresh()->produced_qty);
        $this->assertSame(2.0, (float) $this->workOrder->fresh()->produced_qty);
        $this->assertSame(WorkOrder::STATUS_DONE, $this->workOrder->fresh()->status);
        $this->assertFalse(AuditLog::where('action', 'packing_qty_corrected')->exists());
    }

    public function test_a_non_packing_step_is_refused(): void
    {
        $response = $this->asSupervisor($this->productionStep())->assertStatus(422);
        $this->assertStringContainsString('packing_correction_not_packing', $response->json('message'));

        $this->assertSame(2.0, (float) $this->productionStep()->passed_qty);
        $this->assertSame(2.0, (float) $this->packingStep()->passed_qty);
        $this->assertSame(WorkOrder::STATUS_DONE, $this->workOrder->fresh()->status);
    }

    /**
     * X-5: a business rule that surfaces as a plain exception must reach the
     * supervisor as 422 with a reason, never as an empty 500. The correction
     * writes the step first and only then records the audit entry, so a refusal
     * raised at that point proves the whole correction rolls back.
     *
     * `correctPackingQuantity()` does not complete a step, so the read
     * confirmation / document / checklist gates cannot be used here: every
     * business check in the service is a `\DomainException`. The plain exception
     * is therefore injected at the last write of the same transaction.
     */
    public function test_a_business_failure_mid_correction_answers_422_and_rolls_every_write_back(): void
    {
        $step = $this->packingStep();

        AuditLog::creating(function (AuditLog $log): void {
            if ($log->action === 'packing_qty_corrected') {
                throw new \Exception('The packing correction cannot be recorded in a closed accounting period.');
            }
        });

        $response = $this->asSupervisor($step)->assertStatus(422);
        $this->assertStringContainsString('closed accounting period', $response->json('message'));

        $this->assertSame(2.0, (float) $step->fresh()->passed_qty);
        $this->assertSame(2.0, (float) $this->batch->fresh()->produced_qty);
        $this->assertSame(2.0, (float) $this->workOrder->fresh()->produced_qty);
        $this->assertSame(WorkOrder::STATUS_DONE, $this->workOrder->fresh()->status);
        $this->assertNotNull($this->workOrder->fresh()->completed_at);
        $this->assertFalse(AuditLog::where('action', 'packing_qty_corrected')->exists());
    }

    public function test_an_operator_cannot_correct_the_packing_quantity(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->operatorToken}")
            ->postJson($this->url($this->packingStep()), [
                'produced_qty' => 1,
                'reason' => 'Operator counted one carton.',
            ])->assertStatus(403);

        $this->assertSame(2.0, (float) $this->packingStep()->passed_qty);
        $this->assertSame(2.0, (float) $this->batch->fresh()->produced_qty);
        $this->assertSame(2.0, (float) $this->workOrder->fresh()->produced_qty);
        $this->assertSame(WorkOrder::STATUS_DONE, $this->workOrder->fresh()->status);
        $this->assertFalse(AuditLog::where('action', 'packing_qty_corrected')->exists());
    }

    public function test_correcting_to_the_plan_keeps_the_order_done(): void
    {
        $completedAt = $this->workOrder->fresh()->completed_at;

        $this->asSupervisor($this->packingStep(), [
            'produced_qty' => 2,
            'reason' => 'PA confirmed both cartons.',
        ])->assertStatus(200)
            ->assertJsonPath('wo_status', WorkOrder::STATUS_DONE);

        $order = $this->workOrder->fresh();
        $this->assertSame(WorkOrder::STATUS_DONE, $order->status);
        $this->assertSame(2.0, (float) $order->produced_qty);
        $this->assertNotNull($order->completed_at);
        $this->assertTrue($completedAt->equalTo($order->completed_at));
    }
}
