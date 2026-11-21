<?php

namespace Tests\Feature\WorkOrder;

use App\Models\Batch;
use App\Models\SerialUnit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RQ-PA-214 / P2-T2 — recut endpoint, with the substitution gate of RQ-PA-263.
 *
 * A re-cut is a low-level capacity operation: it opens a replacement batch on
 * the same work order, outside `planned_qty`, and reopens a DONE order to
 * IN_PROGRESS. It never resets an order to PENDING.
 *
 * It also has to prove the substitution (lesson RC-32): the batch it replaces
 * must carry a scrapped unit, so a replacement substitutes a piece instead of
 * sitting next to the original and being counted twice.
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

        // A real re-cut always follows a scrapped piece (the queue turns that
        // history event into the ticket), so the fixture carries one: the gate is
        // exercised by every test below instead of being bypassed by an empty
        // batch. The "plan batch, nothing to scrap" path has its own test.
        $this->unit($this->original, SerialUnit::STATUS_SCRAPPED, 'SN-RECUT-1');
    }

    /** A unit on the given batch; `null` leaves it carrying no batch (I15). */
    private function unit(?Batch $batch, string $status, string $serialNo): SerialUnit
    {
        return SerialUnit::create([
            'serial_no' => $serialNo,
            'psn' => $serialNo,
            'work_order_id' => $this->workOrder->id,
            'batch_id' => $batch?->id,
            'status' => $status,
        ]);
    }

    /** Drop the fixture unit so a test can state the original's units itself. */
    private function dropOriginalUnits(): void
    {
        SerialUnit::where('batch_id', $this->original->id)->delete();
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

    // ── the substitution gate (RQ-PA-263 / RC-32) ────────────────────────────

    public function test_a_replacement_is_refused_while_the_original_units_are_not_scrapped(): void
    {
        $this->dropOriginalUnits();
        $this->unit($this->original, SerialUnit::STATUS_COMPLETED, 'SN-ALIVE-1');
        $this->unit($this->original, SerialUnit::STATUS_COMPLETED, 'SN-ALIVE-2');
        $this->workOrder->update(['status' => WorkOrder::STATUS_DONE]);

        $response = $this->postRecut($this->recutPayload());

        $response->assertStatus(422);

        $message = (string) $response->json('message');
        $this->assertStringContainsString('recut_original_not_scrapped', $message);
        $this->assertStringContainsString((string) $this->original->id, $message);
        $this->assertStringContainsString('completed x2', $message);
        $this->assertStringContainsString('/serial-units/{id}/recut', $message);
        $this->assertStringContainsString('top-up', $message);
        $this->assertStringContainsString(
            'recut_original_not_scrapped',
            (string) $response->json('errors.recut.0'),
        );

        // Nothing was cut and the order was not reopened.
        $this->assertSame(1, $this->workOrder->batches()->count());
        $this->assertSame(0, $this->workOrder->batches()->where('is_replacement', true)->count());
        $this->assertSame(WorkOrder::STATUS_DONE, $this->workOrder->fresh()->status);
    }

    public function test_one_scrapped_unit_proves_substitution_when_the_original_carries_several(): void
    {
        $this->unit($this->original, SerialUnit::STATUS_COMPLETED, 'SN-ALIVE-1');

        $this->postRecut($this->recutPayload())->assertStatus(201);

        $this->assertSame(1, $this->workOrder->batches()->where('is_replacement', true)->count());
    }

    public function test_a_blocked_unit_is_not_scrap_and_does_not_pass_the_gate(): void
    {
        $this->dropOriginalUnits();
        $this->unit($this->original, SerialUnit::STATUS_BLOCKED, 'SN-HELD-1');

        $response = $this->postRecut($this->recutPayload());

        $response->assertStatus(422);
        $this->assertStringContainsString('blocked x1', (string) $response->json('message'));
        $this->assertSame(0, $this->workOrder->batches()->where('is_replacement', true)->count());
    }

    public function test_a_plan_batch_without_units_needs_no_scrap(): void
    {
        // Polityka: brak jednostek = brak dowodu substytucji do zebrania; to
        // korekta planu przed produkcją, nie ma czego złomować.
        $workOrder = WorkOrder::factory()->create(['planned_qty' => 50]);
        $planBatch = app(WorkOrderService::class)->createBatch($workOrder, 50);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/v1/work-orders/{$workOrder->id}/recut-batches", [
                'target_qty' => 5,
                'replaces_batch_id' => $planBatch->id,
                'reason' => 'Plan correction before cutting',
                'idempotency_key' => 'recut-plan-1',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.replaces_batch_id', $planBatch->id);
    }

    public function test_a_cancelled_original_with_live_units_still_needs_a_scrapped_one(): void
    {
        // Cancelling does not remove the units, so it is not a way past the gate.
        $this->dropOriginalUnits();
        $this->unit($this->original, SerialUnit::STATUS_COMPLETED, 'SN-ALIVE-1');
        $this->original->update(['status' => Batch::STATUS_CANCELLED]);

        $this->postRecut($this->recutPayload())->assertStatus(422);

        $this->assertSame(0, $this->workOrder->batches()->where('is_replacement', true)->count());
    }

    public function test_a_loose_unit_on_a_single_batch_order_counts_as_the_original(): void
    {
        // I15: on a one-batch order a unit without a batch is that batch's unit,
        // so it is part of the proof too - otherwise the same double count could
        // come back through the loose-unit door.
        $this->dropOriginalUnits();
        $this->unit(null, SerialUnit::STATUS_COMPLETED, 'SN-LOOSE-1');

        $response = $this->postRecut($this->recutPayload());

        $response->assertStatus(422);
        $this->assertStringContainsString('completed x1', (string) $response->json('message'));
    }

    public function test_a_replacement_without_the_batch_it_replaces_is_refused(): void
    {
        $payload = $this->recutPayload();
        unset($payload['replaces_batch_id']);

        $response = $this->postRecut($payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('replaces_batch_id');
        $this->assertStringContainsString(
            'recut_replaces_batch_required',
            (string) $response->json('errors.replaces_batch_id.0'),
        );

        $this->assertSame(0, $this->workOrder->batches()->where('is_replacement', true)->count());
    }

    public function test_a_cancelled_original_without_units_needs_no_scrap(): void
    {
        // Nothing was ever cut for it: like a plan batch, there is no piece to
        // scrap and no genealogy to substitute.
        $workOrder = WorkOrder::factory()->create(['planned_qty' => 50]);
        $cancelled = app(WorkOrderService::class)->createBatch($workOrder, 50);
        $cancelled->update(['status' => Batch::STATUS_CANCELLED]);

        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/v1/work-orders/{$workOrder->id}/recut-batches", [
                'target_qty' => 5,
                'replaces_batch_id' => $cancelled->id,
                'reason' => 'Plan correction before cutting',
                'idempotency_key' => 'recut-cancelled-1',
            ])
            ->assertStatus(201);
    }

    /**
     * The queue (`create_recut_batch_idempotent`) sends exactly these four
     * fields, after scrapping the original unit: the gate must let it through.
     */
    public function test_the_production_automation_payload_is_accepted(): void
    {
        $this->workOrder->update(['status' => WorkOrder::STATUS_DONE]);

        $response = $this->postRecut([
            'target_qty' => 1.0,
            'replaces_batch_id' => $this->original->id,
            'reason' => 'recut',
            'idempotency_key' => 'recut:4711',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.is_replacement', true)
            ->assertJsonPath('data.replaces_batch_id', $this->original->id);

        $batch = Batch::findOrFail($response->json('data.id'));
        $this->assertSame('recut:4711', $batch->extra_data['idempotency_key']);

        // The same payload again is the queue's retry: 200, no second batch.
        $this->workOrder->update(['status' => WorkOrder::STATUS_DONE]);
        $this->postRecut([
            'target_qty' => 1.0,
            'replaces_batch_id' => $this->original->id,
            'reason' => 'recut',
            'idempotency_key' => 'recut:4711',
        ])->assertStatus(200)->assertJsonPath('data.id', $batch->id);
    }
}
