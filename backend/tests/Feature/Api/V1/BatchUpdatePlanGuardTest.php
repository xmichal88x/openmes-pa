<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Settings\RuntimeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P2.5 — changing a PENDING batch's `target_qty` must respect the order's plan.
 *
 * `store()` always guarded the plan; `update()` did not, so a PENDING batch could
 * be raised without limit and the same overproduction was reachable through
 * PATCH. Both endpoints now share one projection of the planned total, and every
 * accepted change leaves an audit entry (who, from what, to what, why).
 */
class BatchUpdatePlanGuardTest extends TestCase
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
        int $batchNumber,
        string $status = Batch::STATUS_PENDING,
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

    private function updateBatch(Batch $batch, float $targetQty, ?string $reason = null)
    {
        $payload = ['target_qty' => $targetQty];

        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        return $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->patchJson("/api/v1/batches/{$batch->id}", $payload);
    }

    private function storeBatch(float $targetQty)
    {
        return $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/v1/work-orders/{$this->workOrder->id}/batches", [
                'target_qty' => $targetQty,
            ]);
    }

    /** Store the switch as Settings -> System does (JSON), then read it afresh. */
    private function enableOverproduction(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => RuntimeSettings::ALLOW_OVERPRODUCTION],
            ['value' => json_encode(true), 'updated_at' => now()],
        );
        app(RuntimeSettings::class)->flush();
    }

    public function test_raise_above_plan_is_rejected_with_the_numbers(): void
    {
        $this->createBatch(8, 1);
        $batch = $this->createBatch(1, 2);

        $this->updateBatch($batch, 3)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Total batch quantity would exceed planned quantity (11 of 10)');

        $this->assertSame(1.0, (float) $batch->fresh()->target_qty);
        $this->assertSame(0, AuditLog::where('action', 'batch_target_qty_updated')->count());
    }

    public function test_raise_within_plan_is_accepted_and_audited(): void
    {
        $this->createBatch(8, 1);
        $batch = $this->createBatch(1, 2);

        $this->updateBatch($batch, 2, 'operator found a shorter sheet')
            ->assertStatus(200)
            ->assertJsonPath('data.target_qty', '2.00');

        $this->assertSame(2.0, (float) $batch->fresh()->target_qty);

        $audit = AuditLog::where('action', 'batch_target_qty_updated')->sole();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame(Batch::class, $audit->entity_type);
        $this->assertSame($batch->id, $audit->entity_id);
        $this->assertSame(['target_qty' => 1], $audit->before_state);
        $this->assertSame(['target_qty' => 2, 'reason' => 'operator found a shorter sheet'], $audit->after_state);
    }

    public function test_audit_records_a_missing_reason_as_null(): void
    {
        $batch = $this->createBatch(1, 1);

        $this->updateBatch($batch, 2)->assertStatus(200);

        $this->assertSame(
            ['target_qty' => 2, 'reason' => null],
            AuditLog::where('action', 'batch_target_qty_updated')->sole()->after_state,
        );
    }

    public function test_replacement_batch_may_exceed_the_plan(): void
    {
        $this->createBatch(10, 1);
        $replacement = $this->createBatch(1, 2, Batch::STATUS_PENDING, true);

        $this->updateBatch($replacement, 12)->assertStatus(200);

        $this->assertSame(12.0, (float) $replacement->fresh()->target_qty);
    }

    public function test_cancelled_batch_does_not_block_raising_another(): void
    {
        $this->createBatch(8, 1, Batch::STATUS_CANCELLED);
        $batch = $this->createBatch(2, 2);

        $this->updateBatch($batch, 9)
            ->assertStatus(200)
            ->assertJsonPath('data.target_qty', '9.00');
    }

    public function test_allow_overproduction_lets_the_raise_through(): void
    {
        $this->enableOverproduction();
        $this->createBatch(8, 1);
        $batch = $this->createBatch(1, 2);

        $this->updateBatch($batch, 3)->assertStatus(200);

        $this->assertSame(3.0, (float) $batch->fresh()->target_qty);
    }

    public function test_lowering_a_batch_at_the_plan_limit_is_allowed(): void
    {
        $this->createBatch(5, 1);
        $batch = $this->createBatch(5, 2);

        $this->updateBatch($batch, 4)
            ->assertStatus(200)
            ->assertJsonPath('data.target_qty', '4.00');
    }

    public function test_a_batch_at_the_plan_limit_is_not_counted_twice(): void
    {
        $batch = $this->createBatch(10, 1);

        $this->updateBatch($batch, 10)->assertStatus(200);
    }

    public function test_an_unchanged_quantity_leaves_no_audit_entry(): void
    {
        $batch = $this->createBatch(10, 1);

        $this->updateBatch($batch, 10, 'same number again')->assertStatus(200);

        $this->assertSame(0, AuditLog::where('action', 'batch_target_qty_updated')->count());
    }

    public function test_done_batch_still_cannot_be_updated(): void
    {
        $batch = $this->createBatch(1, 1, Batch::STATUS_DONE);

        $this->updateBatch($batch, 2)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only PENDING batches can be updated.');

        $this->assertSame(1.0, (float) $batch->fresh()->target_qty);
    }

    /**
     * Parity with `store()`: the extraction must not have changed what creating a
     * batch answers, message included.
     */
    public function test_store_keeps_the_same_guard_and_message(): void
    {
        $this->createBatch(8, 1);

        $this->storeBatch(3)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Total batch quantity would exceed planned quantity');

        $this->storeBatch(2)
            ->assertStatus(201)
            ->assertJsonPath('message', 'Batch created successfully');
    }

    public function test_store_ignores_replacement_and_cancelled_batches(): void
    {
        $this->createBatch(10, 1, Batch::STATUS_PENDING, true);
        $this->createBatch(10, 2, Batch::STATUS_CANCELLED);

        $this->storeBatch(10)->assertStatus(201);
    }

    // ── Lock proof (RQ-PA-273) ────────────────────────────────────────────
    //
    // SQLite cannot render `for update` (`SQLiteGrammar::compileLock` returns an
    // empty string), so the lock clause itself is invisible here. Its
    // precondition is not: the work order row is read *inside a transaction*
    // before the plan is projected, which is exactly what queues a sibling
    // request on the same row in PostgreSQL. Before RQ-PA-273 the projection
    // summed the batches with no work-order read at all, so two concurrent
    // calls both passed the check and together overshot the plan.

    /** The first statement index matching `$matches`, or null when absent. */
    private function firstStatement(array $statements, callable $matches): ?int
    {
        foreach ($statements as $index => $sql) {
            if ($matches($sql)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Index of the first work_orders read that happens inside a transaction of
     * its own (`$outerLevel` is the level the request starts at - the test suite
     * itself runs inside one), i.e. the row lock the projection and the write
     * share.
     */
    private function firstLockedOrderRead(array $statements, array $levels, int $outerLevel): ?int
    {
        foreach ($statements as $index => $sql) {
            if ($levels[$index] > $outerLevel
                && str_starts_with($sql, 'select')
                && str_contains($sql, 'from "work_orders"')) {
                return $index;
            }
        }

        return null;
    }

    private function isPlanProjection(string $sql): bool
    {
        return str_starts_with($sql, 'select')
            && str_contains($sql, 'from "batches"')
            && str_contains($sql, 'sum(');
    }

    public function test_store_reads_the_work_order_under_a_lock_before_it_plans_and_inserts(): void
    {
        $this->createBatch(8, 1);

        $outerLevel = DB::transactionLevel();
        $statements = [];
        $levels = [];
        DB::listen(function ($query) use (&$statements, &$levels): void {
            $statements[] = strtolower($query->sql);
            $levels[] = DB::transactionLevel();
        });

        $this->storeBatch(2)->assertStatus(201);

        $lockedOrderRead = $this->firstLockedOrderRead($statements, $levels, $outerLevel);
        $planProjection = $this->firstStatement($statements, $this->isPlanProjection(...));
        $batchInsert = $this->firstStatement($statements, fn (string $sql) => str_contains($sql, 'insert into "batches"'));

        $this->assertNotNull($lockedOrderRead, 'store() must read the work order row under a lock');
        $this->assertNotNull($planProjection, 'the plan projection has to run');
        $this->assertNotNull($batchInsert, 'the batch has to be written');

        $this->assertLessThan($planProjection, $lockedOrderRead, 'the plan is projected only under the work-order lock');
        $this->assertLessThan($batchInsert, $lockedOrderRead, 'the batch is written only under the work-order lock');
        $this->assertGreaterThan($outerLevel, $levels[$planProjection], 'the projection must read inside the transaction that holds the lock');
        $this->assertGreaterThan($outerLevel, $levels[$batchInsert], 'the insert must land inside the transaction that holds the lock');
    }

    public function test_update_reads_the_work_order_under_a_lock_before_the_guard_and_the_write(): void
    {
        $this->createBatch(8, 1);
        $batch = $this->createBatch(1, 2);

        $outerLevel = DB::transactionLevel();
        $statements = [];
        $levels = [];
        DB::listen(function ($query) use (&$statements, &$levels): void {
            $statements[] = strtolower($query->sql);
            $levels[] = DB::transactionLevel();
        });

        $this->updateBatch($batch, 2)->assertStatus(200);

        $lockedOrderRead = $this->firstLockedOrderRead($statements, $levels, $outerLevel);
        $planProjection = $this->firstStatement($statements, $this->isPlanProjection(...));
        $batchWrite = $this->firstStatement($statements, fn (string $sql) => str_contains($sql, 'update "batches"'));

        $this->assertNotNull($lockedOrderRead, 'update() must read the work order row under a lock');
        $this->assertNotNull($planProjection, 'the plan projection has to run');
        $this->assertNotNull($batchWrite, 'the accepted change has to be written');

        $this->assertLessThan($planProjection, $lockedOrderRead, 'the plan is projected only under the work-order lock');
        $this->assertLessThan($batchWrite, $lockedOrderRead, 'the batch is written only under the work-order lock');
        $this->assertGreaterThan($outerLevel, $levels[$planProjection], 'the projection must read inside the transaction that holds the lock');
        $this->assertGreaterThan($outerLevel, $levels[$batchWrite], 'the write must land inside the transaction that holds the lock');
    }

    public function test_a_refused_raise_writes_nothing_after_the_locked_check(): void
    {
        $this->createBatch(8, 1);
        $batch = $this->createBatch(1, 2);

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->updateBatch($batch, 3)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Total batch quantity would exceed planned quantity (11 of 10)');

        $this->assertSame(
            0,
            count(array_filter($statements, fn (string $sql) => str_contains($sql, 'update "batches"'))),
            'a refused raise must not touch the batch row',
        );
    }
}
