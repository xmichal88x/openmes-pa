<?php

namespace Tests\Feature\WorkOrder;

use App\Models\Batch;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RQ-PA-214 / P2-T1 — replacement batches live outside `planned_qty`.
 *
 * The ordinary batch guard in BatchController::store is untouched: a normal
 * batch may never push the sum of `target_qty` above `planned_qty`. A
 * replacement batch is a separate concept — it compensates for a defective
 * batch and is created outside that guard, so several re-cuts of the same batch
 * are possible.
 */
class BatchReplacementGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $adminToken;

    private WorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $this->adminToken = $this->admin->createToken('test')->plainTextToken;

        $this->workOrder = WorkOrder::factory()->create(['planned_qty' => 100]);
    }

    private function createReplacement(Batch $replaces, float $targetQty = 10, int $batchNumber = 2): Batch
    {
        $batch = Batch::create([
            'work_order_id' => $this->workOrder->id,
            'batch_number' => $batchNumber,
            'target_qty' => $targetQty,
            'produced_qty' => 0,
            'status' => Batch::STATUS_PENDING,
        ]);

        // Replacement flags are not mass-assignable on purpose, so the dedicated
        // replacement path opts in explicitly.
        $batch->forceFill([
            'is_replacement' => true,
            'replaces_batch_id' => $replaces->id,
        ])->save();

        return $batch;
    }

    public function test_normal_batch_exceeding_planned_qty_is_still_rejected(): void
    {
        $service = app(WorkOrderService::class);
        $service->createBatch($this->workOrder, 100);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/v1/work-orders/{$this->workOrder->id}/batches", [
                'target_qty' => 10,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Total batch quantity would exceed planned quantity');

        $this->assertSame(1, $this->workOrder->batches()->count());
    }

    public function test_replacement_batch_is_allowed_when_planned_qty_is_already_met(): void
    {
        $service = app(WorkOrderService::class);
        $original = $service->createBatch($this->workOrder, 100);

        $this->assertSame(100.0, (float) $this->workOrder->batches()->sum('target_qty'));

        $replacement = $this->createReplacement($original, 10);

        $this->assertDatabaseHas('batches', [
            'id' => $replacement->id,
            'is_replacement' => true,
            'replaces_batch_id' => $original->id,
            'target_qty' => 10,
        ]);
        $this->assertTrue($replacement->fresh()->is_replacement);
    }

    public function test_multiple_replacements_for_the_same_batch_are_allowed(): void
    {
        $service = app(WorkOrderService::class);
        $original = $service->createBatch($this->workOrder, 100);

        $this->createReplacement($original, 10, 2);
        $this->createReplacement($original, 10, 3);

        $this->assertSame(
            2,
            Batch::where('replaces_batch_id', $original->id)->where('is_replacement', true)->count(),
        );
        $this->assertCount(2, $original->fresh()->replacements);
        $this->assertSame($original->id, $original->fresh()->replacements->first()->replacesBatch->id);
    }

    public function test_is_replacement_is_not_mass_assignable(): void
    {
        $batch = Batch::create([
            'work_order_id' => $this->workOrder->id,
            'batch_number' => 1,
            'target_qty' => 10,
            'produced_qty' => 0,
            'status' => Batch::STATUS_PENDING,
            // Must be ignored: the flag may only be set by the replacement path.
            'is_replacement' => true,
            'replaces_batch_id' => null,
        ]);

        $this->assertFalse($batch->fresh()->is_replacement);
        $this->assertDatabaseHas('batches', [
            'id' => $batch->id,
            'is_replacement' => false,
        ]);
    }
}
