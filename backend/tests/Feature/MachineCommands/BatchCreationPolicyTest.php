<?php

namespace Tests\Feature\MachineCommands;

use App\Models\Line;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * RQ-190 — the machine bridge creates batches, not work orders.
 *
 * `createBatch` is line-scoped: the bridge may open a batch for a work order on
 * the line it runs, but not for another line and never for a new work order.
 */
class BatchCreationPolicyTest extends MachineCommandsTestCase
{
    private function foreignWorkOrder(): WorkOrder
    {
        $foreignLine = Line::factory()->create(['code' => 'LINIA-C']);

        return WorkOrder::factory()->create([
            'line_id' => $foreignLine->id,
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 100,
        ]);
    }

    public function test_machine_bridge_may_batch_only_its_own_line(): void
    {
        $bridge = $this->makeUser('MachineBridge', [$this->cncLine]);

        $this->assertTrue(Gate::forUser($bridge)->allows('createBatch', $this->workOrder));
        $this->assertFalse(Gate::forUser($bridge)->allows('createBatch', $this->foreignWorkOrder()));
    }

    public function test_machine_bridge_cannot_create_work_orders(): void
    {
        $bridge = $this->makeUser('MachineBridge', [$this->cncLine]);

        $this->assertFalse(Gate::forUser($bridge)->allows('create', WorkOrder::class));
    }

    public function test_admin_and_supervisor_may_batch_any_line(): void
    {
        $foreign = $this->foreignWorkOrder();

        $this->assertTrue(Gate::forUser($this->admin)->allows('createBatch', $this->workOrder));
        $this->assertTrue(Gate::forUser($this->admin)->allows('createBatch', $foreign));

        $this->assertTrue(Gate::forUser($this->supervisor)->allows('createBatch', $this->workOrder));
        $this->assertTrue(Gate::forUser($this->supervisor)->allows('createBatch', $foreign));
    }

    public function test_api_creates_a_batch_on_the_bridge_line(): void
    {
        $bridge = $this->makeUser('MachineBridge', [$this->cncLine]);
        $workOrder = WorkOrder::factory()->create([
            'line_id' => $this->cncLine->id,
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 100,
        ]);

        $this->api($bridge)
            ->postJson('/api/v1/work-orders/'.$workOrder->id.'/batches', [
                'target_qty' => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('data.work_order_id', $workOrder->id);

        $this->assertDatabaseHas('batches', [
            'work_order_id' => $workOrder->id,
            'target_qty' => 5,
        ]);
    }

    public function test_api_forbids_a_batch_on_a_foreign_line(): void
    {
        $bridge = $this->makeUser('MachineBridge', [$this->cncLine]);
        $foreign = $this->foreignWorkOrder();

        $this->api($bridge)
            ->postJson('/api/v1/work-orders/'.$foreign->id.'/batches', [
                'target_qty' => 5,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('batches', [
            'work_order_id' => $foreign->id,
            'target_qty' => 5,
        ]);
    }

    public function test_api_forbids_the_bridge_from_creating_work_orders(): void
    {
        $bridge = $this->makeUser('MachineBridge', [$this->cncLine]);

        $this->api($bridge)
            ->postJson('/api/v1/work-orders', [
                'order_no' => 'BRIDGE-'.Str::uuid(),
                'line_id' => $this->cncLine->id,
                'planned_qty' => 10,
            ])
            ->assertForbidden();
    }

    private function api(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }
}
