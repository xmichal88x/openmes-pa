<?php

namespace Tests\Feature\WorkOrder;

use App\Models\Batch;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RQ-PA-214 / P2 (M4) — the ordinary planned-qty guard in
 * BatchController::store must only sum real, live production batches.
 *
 * Replacement batches (re-cuts) are cut outside `planned_qty`; cancelled batches
 * no longer consume capacity; and legacy rows with a NULL `is_replacement` must
 * still count, otherwise old work orders could over-produce silently.
 */
class BatchStorePlannedSumTest extends TestCase
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

        $this->workOrder = WorkOrder::factory()->create(['planned_qty' => 10]);
    }

    private function createBatch(
        float $targetQty,
        string $status = Batch::STATUS_PENDING,
        int $batchNumber = 1,
        ?bool $isReplacement = false,
    ): Batch {
        $batch = Batch::create([
            'work_order_id' => $this->workOrder->id,
            'batch_number' => $batchNumber,
            'target_qty' => $targetQty,
            'produced_qty' => 0,
            'status' => $status,
        ]);

        // is_replacement is not mass-assignable on purpose; the re-cut path sets
        // it explicitly. NULL models legacy rows created before the column.
        if ($isReplacement !== false) {
            $batch->forceFill(['is_replacement' => $isReplacement])->save();
        }

        return $batch;
    }

    private function storeBatch(float $targetQty)
    {
        return $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/v1/work-orders/{$this->workOrder->id}/batches", [
                'target_qty' => $targetQty,
            ]);
    }

    public function test_replacement_and_cancelled_batches_do_not_count_toward_the_sum(): void
    {
        $this->createBatch(5, Batch::STATUS_PENDING, 1, true);
        $this->createBatch(3, Batch::STATUS_CANCELLED, 2, false);

        $this->storeBatch(7)
            ->assertStatus(201)
            ->assertJsonPath('message', 'Batch created successfully');

        $this->assertSame(3, $this->workOrder->batches()->count());
    }

    public function test_active_non_replacement_batch_still_counts_and_blocks_overproduction(): void
    {
        $this->createBatch(5, Batch::STATUS_PENDING, 1, false);

        $this->storeBatch(6)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Total batch quantity would exceed planned quantity');

        $this->assertSame(1, $this->workOrder->batches()->count());
    }

    public function test_legacy_null_is_replacement_batch_counts_toward_the_sum(): void
    {
        // The current schema declares is_replacement NOT NULL, but rows written
        // before the column existed are NULL. Relax the constraint for this test
        // so the legacy row can be reproduced; the transaction rollback restores
        // the schema afterwards.
        Schema::table('batches', function (Blueprint $table) {
            $table->boolean('is_replacement')->nullable()->default(null)->change();
        });

        $legacy = $this->createBatch(6, Batch::STATUS_PENDING, 1, null);

        $this->assertNull($legacy->fresh()->is_replacement);

        $this->storeBatch(5)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Total batch quantity would exceed planned quantity');

        $this->assertSame(1, $this->workOrder->batches()->count());
    }

    public function test_replacement_batch_does_not_increase_the_sum_for_the_next_store(): void
    {
        $this->createBatch(10, Batch::STATUS_PENDING, 1, true);

        $this->storeBatch(10)->assertStatus(201);

        $this->assertSame(
            20.0,
            (float) $this->workOrder->batches()->sum('target_qty'),
        );
    }

    public function test_cancelled_replacement_batch_is_excluded_from_the_sum(): void
    {
        $this->createBatch(10, Batch::STATUS_CANCELLED, 1, true);

        $this->storeBatch(10)->assertStatus(201);
    }
}
