<?php

namespace Tests\Feature\Web\Operator;

use App\Models\Batch;
use App\Models\Line;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Settings\RuntimeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RQ-PA-273 — the planned-quantity guard belongs to the work order, not to one
 * endpoint. The operator's web path (`operator.batch.store`) used to call
 * `WorkOrderService::createBatch()` straight away, so the plan could be
 * overshot from the shop floor while the API said no.
 *
 * Both paths now read the same `BatchPlanGuard`, and a refusal on this path is
 * an operator-visible `error` naming the numbers instead of a silent create.
 */
class BatchPlanGuardWebTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Line $line;

    private WorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Operator', 'web');
        Role::findOrCreate('Supervisor', 'web');

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');

        $this->line = Line::factory()->create();
        $this->workOrder = WorkOrder::factory()->create([
            'line_id' => $this->line->id,
            'planned_qty' => 10,
        ]);
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

    private function storeBatch(float $targetQty, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->operator)
            ->withSession(['selected_line_id' => $this->line->id])
            ->post(route('operator.batch.store'), [
                'work_order_id' => $this->workOrder->id,
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

    public function test_operator_creates_a_batch_within_the_plan(): void
    {
        $this->createBatch(5, 1);

        $this->storeBatch(5)
            ->assertRedirect(route('operator.work-order.detail', $this->workOrder))
            ->assertSessionHas('success')
            ->assertSessionMissing('error');

        $this->assertSame(2, $this->workOrder->batches()->count());
        $this->assertSame(5.0, (float) $this->workOrder->batches()->where('batch_number', 2)->sole()->target_qty);
    }

    public function test_supervisor_creates_a_batch_within_the_plan(): void
    {
        $supervisor = User::factory()->create();
        $supervisor->assignRole('Supervisor');

        $this->storeBatch(10, $supervisor)->assertSessionHas('success');

        $this->assertSame(1, $this->workOrder->batches()->count());
    }

    public function test_going_over_the_plan_is_refused_with_the_numbers_and_no_batch(): void
    {
        $this->createBatch(8, 1);

        $response = $this->storeBatch(3);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Total batch quantity would exceed planned quantity (11 of 10)');

        $this->assertSame(1, $this->workOrder->batches()->count());
    }

    public function test_a_refusal_keeps_the_operator_input(): void
    {
        $this->createBatch(10, 1);

        $this->storeBatch(1)->assertSessionHasInput('target_qty');

        $this->assertSame(1, $this->workOrder->batches()->count());
    }

    public function test_cancelled_batch_does_not_consume_the_plan(): void
    {
        $this->createBatch(10, 1, Batch::STATUS_CANCELLED);

        $this->storeBatch(10)->assertSessionHas('success');

        $this->assertSame(2, $this->workOrder->batches()->count());
    }

    /**
     * A replacement batch (re-cut) lives outside the plan - see recut(). The
     * operator's form never creates one (replacement flags are set by the recut
     * path only), but one created there must not eat the plan of a new batch.
     */
    public function test_replacement_batch_does_not_consume_the_plan(): void
    {
        $this->createBatch(10, 1, Batch::STATUS_PENDING, true);

        $this->storeBatch(10)->assertSessionHas('success');

        $this->assertSame(2, $this->workOrder->batches()->count());
    }

    public function test_allow_overproduction_lets_the_batch_through(): void
    {
        $this->enableOverproduction();
        $this->createBatch(8, 1);

        $this->storeBatch(3)->assertSessionHas('success')->assertSessionMissing('error');

        $this->assertSame(2, $this->workOrder->batches()->count());
    }

    public function test_work_order_from_another_line_is_still_refused(): void
    {
        $otherLine = Line::factory()->create();

        $response = $this->actingAs($this->operator)
            ->withSession(['selected_line_id' => $otherLine->id])
            ->post(route('operator.batch.store'), [
                'work_order_id' => $this->workOrder->id,
                'target_qty' => 1,
            ]);

        $response->assertSessionHas('error', 'This work order does not belong to the selected line.');
        $this->assertSame(0, $this->workOrder->batches()->count());
    }
}
