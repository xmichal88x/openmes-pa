<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\Issue;
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
 * P1/M-2: an order that cannot reach its plan is closed by an explicit
 * supervisor decision, never by a silent write-off of the packing quantity.
 *
 * The scenario is always the same: 2 planned, production finished, only 1 unit
 * packed — so there is a shortage to decide about, and a packing step that the
 * M-3 gate refuses to close without that decision.
 */
class CloseShortWorkOrderApiTest extends TestCase
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

        // Production ran: the step closes on the plan and packing is next in line.
        $production = $this->batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($production, $this->operator);
        app(BatchService::class)->completeStep($production->fresh(), $this->operator);

        // Only one of the two pieces made it into a carton: a packed unit is a
        // good unit (P2.12), an unpacked one would not count at all.
        SerialUnit::create([
            'serial_no' => 'SN-1',
            'psn' => 'SN-1',
            'work_order_id' => $this->workOrder->id,
            'batch_id' => $this->batch->id,
            'status' => SerialUnit::STATUS_COMPLETED,
            'packed_at' => now(),
        ]);
    }

    private function url(): string
    {
        return "/api/v1/work-orders/{$this->workOrder->id}/close-short";
    }

    private function asSupervisor(array $payload = ['reason' => 'One unit was damaged beyond rework.'])
    {
        return $this->withHeader('Authorization', "Bearer {$this->supervisorToken}")
            ->postJson($this->url(), $payload);
    }

    private function packingStep(): BatchStep
    {
        return BatchStep::where('batch_id', $this->batch->id)
            ->where('step_number', 2)
            ->firstOrFail();
    }

    public function test_supervisor_closes_the_order_short_on_the_real_quantities(): void
    {
        $response = $this->asSupervisor([
            'reason' => 'One unit was damaged beyond rework.',
        ])->assertStatus(200)
            ->assertJsonPath('message', 'Work order closed short')
            ->assertJsonPath('data.status', WorkOrder::STATUS_DONE)
            ->assertJsonPath('data.short_close_reason', 'One unit was damaged beyond rework.');

        $this->assertSame(2.0, (float) $response->json('planned_qty'));
        $this->assertSame(1.0, (float) $response->json('produced_qty'));
        $this->assertSame(1.0, (float) $response->json('shortage_qty'));
        $this->assertNotEmpty($response->json('short_closed_at'));

        $order = $this->workOrder->fresh();
        $this->assertSame(WorkOrder::STATUS_DONE, $order->status);
        $this->assertNotNull($order->short_closed_at);
        $this->assertSame($this->supervisor->id, $order->short_closed_by_id);
        $this->assertSame('One unit was damaged beyond rework.', $order->short_close_reason);
        $this->assertSame(1.0, $order->shortageQty());
        // The plan is not written off: the order records what was packed.
        $this->assertSame(1.0, (float) $order->produced_qty);

        $step = $this->packingStep();
        $this->assertSame(BatchStep::STATUS_DONE, $step->status);
        $this->assertSame(1.0, (float) $step->passed_qty);
        $this->assertSame(1.0, (float) $this->batch->fresh()->produced_qty);

        $audit = AuditLog::where('action', 'short_closed')->get();
        $this->assertCount(1, $audit);
        $this->assertSame(WorkOrder::class, $audit->first()->entity_type);
        $this->assertSame($this->workOrder->id, (int) $audit->first()->entity_id);
        $this->assertSame($this->supervisor->id, $audit->first()->user_id);
        $this->assertSame(1.0, (float) $audit->first()->after_state['shortage_qty']);
        $this->assertSame('One unit was damaged beyond rework.', $audit->first()->after_state['reason']);
    }

    public function test_operator_cannot_close_an_order_short(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->operatorToken}")
            ->postJson($this->url(), ['reason' => 'Operator decision.'])
            ->assertStatus(403);

        $this->assertNull($this->workOrder->fresh()->short_closed_at);
    }

    public function test_an_order_that_met_its_plan_has_nothing_to_close(): void
    {
        $this->workOrder->update(['produced_qty' => 2]);

        $response = $this->asSupervisor()->assertStatus(422)
            ->assertJsonCount(1, 'errors')
            ->assertJsonCount(1, 'errors.work_order');
        $this->assertStringContainsString('short_close_not_needed', $response->json('message'));
        $this->assertStringContainsString('short_close_not_needed', $response->json('errors.work_order.0'));
        $this->assertNull($this->workOrder->fresh()->short_closed_at);
    }

    public function test_a_reason_is_required(): void
    {
        $this->asSupervisor(['reason' => ''])->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $this->withHeader('Authorization', "Bearer {$this->supervisorToken}")
            ->postJson($this->url(), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->assertNull($this->workOrder->fresh()->short_closed_at);
    }

    public function test_a_repeated_decision_keeps_the_first_reason_and_one_audit_entry(): void
    {
        $this->asSupervisor(['reason' => 'First decision, damaged unit.'])->assertStatus(200);

        $this->asSupervisor(['reason' => 'A completely different later reason.'])
            ->assertStatus(200)
            ->assertJsonPath('data.short_close_reason', 'First decision, damaged unit.');

        $this->assertSame('First decision, damaged unit.', $this->workOrder->fresh()->short_close_reason);
        $this->assertCount(1, AuditLog::where('action', 'short_closed')->get());
    }

    public function test_a_blocking_issue_refuses_the_decision_and_rolls_back(): void
    {
        Issue::factory()->create([
            'work_order_id' => $this->workOrder->id,
            'status' => Issue::STATUS_OPEN,
            'blocks_work_order' => true,
        ]);

        $response = $this->asSupervisor()->assertStatus(422)
            ->assertJsonCount(1, 'errors')
            ->assertJsonCount(1, 'errors.work_order');
        $this->assertStringContainsString('short_close_blocked_issue', $response->json('message'));
        $this->assertStringContainsString('short_close_blocked_issue', $response->json('errors.work_order.0'));

        $order = $this->workOrder->fresh();
        $this->assertNull($order->short_closed_at);
        $this->assertNotSame(WorkOrder::STATUS_DONE, $order->status);
        $this->assertSame(BatchStep::STATUS_READY, $this->packingStep()->status);
    }

    public function test_a_cancelled_order_cannot_be_closed_short(): void
    {
        $this->workOrder->update(['status' => WorkOrder::STATUS_CANCELLED]);

        $response = $this->asSupervisor()->assertStatus(422)
            ->assertJsonCount(1, 'errors')
            ->assertJsonCount(1, 'errors.work_order');
        $this->assertStringContainsString('short_close_invalid_status', $response->json('message'));
        $this->assertStringContainsString('short_close_invalid_status', $response->json('errors.work_order.0'));
        $this->assertNull($this->workOrder->fresh()->short_closed_at);
    }

    /**
     * X-5: the packing gate in `BatchService::completeStep()` raises plain
     * exceptions for business blocks (mandatory document, checklist, read
     * confirmation). The supervisor must see that reason as 422, not as an empty
     * 500 — and the decision written before the gate has to roll back with it.
     */
    public function test_an_unconfirmed_critical_instruction_is_a_422_and_the_decision_rolls_back(): void
    {
        $this->packingStep()->update(['requires_confirmation' => true]);

        $response = $this->asSupervisor()->assertStatus(422)
            ->assertJsonCount(1, 'errors')
            ->assertJsonCount(1, 'errors.work_order');
        $this->assertStringContainsString('confirm', $response->json('message'));
        $this->assertStringContainsString('confirm', $response->json('errors.work_order.0'));

        $order = $this->workOrder->fresh();
        $this->assertNull($order->short_closed_at);
        $this->assertNull($order->short_closed_by_id);
        $this->assertNull($order->short_close_reason);
        $this->assertNotSame(WorkOrder::STATUS_DONE, $order->status);
        // The packing step start that preceded the gate is rolled back too.
        $this->assertSame(BatchStep::STATUS_READY, $this->packingStep()->status);
        $this->assertFalse(AuditLog::where('action', 'short_closed')->exists());
    }

    /**
     * P1/M-3 integration: a bare status update would put a below-plan order back
     * to PENDING. The decision closes the packing step and the order together, so
     * no limbo state is ever created.
     */
    public function test_the_order_never_falls_back_to_pending_after_the_close(): void
    {
        $this->asSupervisor()->assertStatus(200);

        $order = WorkOrder::find($this->workOrder->id);
        $this->assertSame(WorkOrder::STATUS_DONE, $order->status);
        $this->assertNotSame(WorkOrder::STATUS_PENDING, $order->status);
        $this->assertSame(BatchStep::STATUS_DONE, $this->packingStep()->status);
        $this->assertSame(Batch::STATUS_DONE, $this->batch->fresh()->status);
        $this->assertNotNull($order->completed_at);
    }
}
