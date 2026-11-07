<?php

namespace Tests\Feature\WorkOrder;

use App\Models\Batch;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RQ-PA-214 / P2-T2 — recut endpoint.
 *
 * A re-cut is a low-level capacity operation: it opens a replacement batch on
 * the same work order, outside `planned_qty`, and reopens a DONE order to
 * IN_PROGRESS. It never resets an order to PENDING.
 */
class RecutBatchesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $adminToken;

    private WorkOrder $workOrder;

    private Batch $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $this->adminToken = $this->admin->createToken('test')->plainTextToken;

        $this->workOrder = WorkOrder::factory()->create(['planned_qty' => 100]);
        $this->original = app(WorkOrderService::class)->createBatch($this->workOrder, 100);
    }

    private function recutPayload(array $overrides = []): array
    {
        return array_merge([
            'target_qty' => 10,
            'replaces_batch_id' => $this->original->id,
            'reason' => 'Defective edge, re-cut required',
            'idempotency_key' => 'recut-key-1',
        ], $overrides);
    }

    private function postRecut(array $payload, ?string $token = null)
    {
        return $this->withHeader('Authorization', 'Bearer '.($token ?? $this->adminToken))
            ->postJson("/api/v1/work-orders/{$this->workOrder->id}/recut-batches", $payload);
    }

    public function test_recut_on_done_work_order_reopens_it_and_creates_replacement_batch(): void
    {
        $this->workOrder->update(['status' => WorkOrder::STATUS_DONE]);

        $response = $this->postRecut($this->recutPayload());

        $response->assertStatus(201)
            ->assertJsonPath('data.is_replacement', true)
            ->assertJsonPath('data.target_qty', '10.00');

        $this->assertSame(WorkOrder::STATUS_IN_PROGRESS, $this->workOrder->fresh()->status);

        $batch = Batch::findOrFail($response->json('data.id'));
        $this->assertTrue($batch->is_replacement);
        $this->assertSame($this->original->id, $batch->replaces_batch_id);
        $this->assertSame(Batch::STATUS_PENDING, $batch->status);
        $this->assertSame('recut-key-1', $batch->extra_data['idempotency_key']);
        $this->assertSame('Defective edge, re-cut required', $batch->extra_data['reason']);

        // The replacement lives outside planned_qty: only the original counts.
        $this->assertSame(100.0, (float) $this->workOrder->batches()->where('is_replacement', false)->sum('target_qty'));
    }

    public function test_recut_does_not_change_status_of_pending_or_in_progress_order(): void
    {
        $this->workOrder->update(['status' => WorkOrder::STATUS_IN_PROGRESS]);

        $this->postRecut($this->recutPayload())->assertStatus(201);

        $this->assertSame(WorkOrder::STATUS_IN_PROGRESS, $this->workOrder->fresh()->status);
    }

    public function test_same_idempotency_key_returns_existing_batch_without_duplicate(): void
    {
        $this->workOrder->update(['status' => WorkOrder::STATUS_DONE]);

        $first = $this->postRecut($this->recutPayload())->assertStatus(201);
        $firstId = $first->json('data.id');

        $this->workOrder->update(['status' => WorkOrder::STATUS_DONE]);

        $second = $this->postRecut($this->recutPayload())->assertStatus(200);

        $second->assertJsonPath('data.id', $firstId);
        $this->assertSame(
            1,
            $this->workOrder->batches()->where('is_replacement', true)->count(),
        );
    }

    public function test_replaces_batch_must_belong_to_same_work_order(): void
    {
        $otherWorkOrder = WorkOrder::factory()->create(['planned_qty' => 100]);
        $foreignBatch = app(WorkOrderService::class)->createBatch($otherWorkOrder, 50);

        $this->postRecut($this->recutPayload(['replaces_batch_id' => $foreignBatch->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('replaces_batch_id');
    }

    public function test_idempotency_key_and_reason_are_required(): void
    {
        $response = $this->postRecut([
            'target_qty' => 10,
            'replaces_batch_id' => $this->original->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key', 'reason']);
    }

    public function test_user_without_create_batch_permission_gets_forbidden(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('Operator');
        $operator->lines()->attach($this->workOrder->line_id);
        $operatorToken = $operator->createToken('test')->plainTextToken;

        $this->postRecut($this->recutPayload(), $operatorToken)->assertStatus(403);
    }

    public function test_normal_batch_endpoint_still_respects_planned_quantity(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/v1/work-orders/{$this->workOrder->id}/batches", [
                'target_qty' => 10,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Total batch quantity would exceed planned quantity');

        $this->assertSame(1, $this->workOrder->batches()->count());
    }
}
