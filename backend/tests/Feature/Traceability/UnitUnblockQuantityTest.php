<?php

namespace Tests\Feature\Traceability;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\ScrapReason;
use App\Models\SerialUnit;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\UnitProducedQtySync;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * P2.11 - what a hold and a release do to the numbers (RQ-PA-263).
 *
 * A hold is a physical fact and always lands; only the quantities that follow
 * from a declared source are re-derived (`UnitProducedQtySync`). For a `units`
 * batch the contribution is recounted twice - down on `block`, back up on
 * `unblock` - while a declared figure (manual/machine) is never rewritten and
 * the answer says so in `warnings[]`.
 *
 * One asymmetry is deliberate (P2.12): a good unit is a *packed* unit, so a
 * released piece that was never packed comes back as `in_production` and stays
 * out of the count. Nothing about a hold may invent a piece that never left the
 * line.
 */
class UnitUnblockQuantityTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $supervisor;

    private ScrapReason $reason;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Admin', 'Operator', 'Supervisor', 'PackingWorker'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('Supervisor');

        $this->reason = ScrapReason::factory()->create([
            'code' => 'NC-11',
            'name' => 'Held for review',
            'is_active' => true,
        ]);
    }

    /** An order whose only routing step is packing (the batch closes on it). */
    private function makeWorkOrder(float $plannedQty, array $overrides = []): WorkOrder
    {
        $product = ProductType::factory()->create();
        $template = ProcessTemplate::factory()->create(['product_type_id' => $product->id]);
        TemplateStep::create([
            'process_template_id' => $template->id, 'step_number' => 1, 'name' => 'Pack',
            'kind' => TemplateStep::KIND_PACKING, 'config' => ['unit' => 'carton', 'carton_capacity' => 5],
        ]);

        return WorkOrder::factory()->create($overrides + [
            'product_type_id' => $product->id,
            'process_snapshot' => $template->fresh()->toSnapshot(),
            'planned_qty' => $plannedQty,
            'status' => WorkOrder::STATUS_IN_PROGRESS,
        ]);
    }

    private function makeBatch(WorkOrder $workOrder, float $targetQty): Batch
    {
        return app(WorkOrderService::class)->createBatch($workOrder, $targetQty)->fresh();
    }

    /**
     * A unit from the line: a packed one carries `packed_at` and counts as good,
     * an unpacked one is still work in progress and never counts (P2.12).
     */
    private function unit(
        WorkOrder $workOrder,
        ?Batch $batch,
        string $no,
        string $status = SerialUnit::STATUS_COMPLETED,
        bool $packed = true,
    ): SerialUnit {
        return SerialUnit::create([
            'serial_no' => $no,
            'psn' => $no,
            'work_order_id' => $workOrder->id,
            'batch_id' => $batch?->id,
            'status' => $status,
            'packed_at' => $packed ? now() : null,
        ]);
    }

    /** Packing on a finished order reopens it, as `BatchController::recut()` does. */
    private function keepProducing(WorkOrder $workOrder): void
    {
        if ($workOrder->fresh()->status === WorkOrder::STATUS_DONE) {
            $workOrder->fresh()->update(['status' => WorkOrder::STATUS_IN_PROGRESS, 'completed_at' => null]);
        }
    }

    private function pack(Batch $batch, ?float $statedQty = null): BatchStep
    {
        $this->keepProducing($batch->workOrder);

        $step = $batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($step, $this->operator);

        return app(BatchService::class)->completeStep(
            $step->fresh(),
            $this->operator,
            $statedQty === null ? [] : ['produced_qty' => $statedQty],
        );
    }

    private function block(SerialUnit $unit): TestResponse
    {
        return $this->actingAs($this->operator)
            ->postJson("/api/v1/serial-units/{$unit->id}/block", [
                'scrap_reason_id' => $this->reason->id,
                'note' => 'Held for review',
            ]);
    }

    private function unblock(SerialUnit $unit): TestResponse
    {
        return $this->actingAs($this->supervisor)
            ->postJson("/api/v1/serial-units/{$unit->id}/unblock", ['note' => 'Released']);
    }

    private function warningCodes(TestResponse $response): array
    {
        return array_column($response->json('warnings') ?? [], 'code');
    }

    // ── units: the count follows the physical fact, both ways ────────────────

    public function test_a_held_packed_unit_leaves_the_count_and_the_release_brings_it_back(): void
    {
        $wo = $this->makeWorkOrder(1);
        $batch = $this->makeBatch($wo, 1);
        $unit = $this->unit($wo, $batch, 'SN-1');
        $this->pack($batch);

        $this->assertSame(1.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(1.0, (float) $wo->fresh()->produced_qty);
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_UNITS, $batch->fresh()->producedQtySource());

        $this->block($unit)->assertOk()
            ->assertJsonPath('data.status', SerialUnit::STATUS_BLOCKED)
            ->assertJsonPath('warnings', []);

        // A held piece is not a good piece: the recount follows it down.
        $this->assertSame(0.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(0.0, (float) $wo->fresh()->produced_qty);

        $this->unblock($unit)->assertOk()
            ->assertJsonPath('data.status', SerialUnit::STATUS_COMPLETED)
            ->assertJsonPath('warnings', []);

        // The same unit recounts itself: the figure returns, it is not remembered.
        $this->assertSame(1.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(1.0, (float) $wo->fresh()->produced_qty);

        $events = $unit->history()->whereIn('parameters->event', ['blocked', 'unblocked'])->get();
        $this->assertSame(['blocked', 'unblocked'], $events->pluck('parameters.event')->all());
        $this->assertSame(SerialUnit::STATUS_COMPLETED, $unit->fresh()->status);
    }

    public function test_an_unpacked_unit_never_enters_the_count_through_a_hold_or_release(): void
    {
        $wo = $this->makeWorkOrder(1);
        $batch = $this->makeBatch($wo, 1);
        $unit = $this->unit($wo, $batch, 'SN-1', SerialUnit::STATUS_IN_PRODUCTION, packed: false);

        // The batch declares the unit flow and counts its packed units: none yet.
        $batch->setProducedQtySource(Batch::PRODUCED_QTY_SOURCE_UNITS);
        $batch->update(['produced_qty' => 0, 'extra_data' => $batch->extra_data]);
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_UNITS, $batch->fresh()->producedQtySource());

        $this->block($unit)->assertOk()
            ->assertJsonPath('data.status', SerialUnit::STATUS_BLOCKED)
            ->assertJsonPath('warnings', []);

        $this->assertSame(0.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(0.0, (float) $wo->fresh()->produced_qty);

        $this->unblock($unit)->assertOk()
            // Released, but never packed - so back to work in progress, not to goods.
            ->assertJsonPath('data.status', SerialUnit::STATUS_IN_PRODUCTION)
            ->assertJsonPath('warnings', []);

        // A release must not conjure a piece that never left the line.
        $this->assertSame(0.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(0.0, (float) $wo->fresh()->produced_qty);
        $this->assertNull($unit->fresh()->packed_at);
    }

    // ── manual: a stated figure is never rewritten, and the answer says so ───

    public function test_a_manual_batch_keeps_its_declared_figure_through_hold_and_release(): void
    {
        $wo = $this->makeWorkOrder(5);
        $batch = $this->makeBatch($wo, 5);
        $units = [];
        foreach (range(1, 5) as $n) {
            $units[] = $this->unit($wo, $batch, 'SN-'.$n);
        }
        $this->pack($batch, 5);

        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_MANUAL, $batch->fresh()->producedQtySource());
        $this->assertSame(5.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(5.0, (float) $wo->fresh()->produced_qty);

        $response = $this->block($units[0])->assertOk()
            ->assertJsonPath('data.status', SerialUnit::STATUS_BLOCKED);

        $this->assertSame([UnitProducedQtySync::DECLARED_FIGURE], $this->warningCodes($response));
        $this->assertSame(5.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(5.0, (float) $wo->fresh()->produced_qty);

        $response = $this->unblock($units[0])->assertOk()
            ->assertJsonPath('data.status', SerialUnit::STATUS_COMPLETED);

        // Both directions say the same thing: a human stated 5, a unit does not move it.
        $this->assertSame([UnitProducedQtySync::DECLARED_FIGURE], $this->warningCodes($response));
        $this->assertSame(5.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(5.0, (float) $wo->fresh()->produced_qty);
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_MANUAL, $batch->fresh()->producedQtySource());
    }

    // ── machine: the PLC counter owns the number ─────────────────────────────

    public function test_a_machine_counted_order_keeps_the_counter_through_hold_and_release(): void
    {
        $wo = $this->makeWorkOrder(10, [
            'counting_source' => WorkOrder::COUNTING_MACHINE,
            'produced_qty' => 9,
        ]);
        $batch = $this->makeBatch($wo, 1);
        $unit = $this->unit($wo, $batch, 'SN-1');
        $this->pack($batch);

        $this->assertSame(1.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(9.0, (float) $wo->fresh()->produced_qty);

        $response = $this->block($unit)->assertOk()
            ->assertJsonPath('data.status', SerialUnit::STATUS_BLOCKED);

        $this->assertSame([UnitProducedQtySync::MACHINE_COUNTER], $this->warningCodes($response));
        $this->assertSame(1.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(9.0, (float) $wo->fresh()->produced_qty);

        $response = $this->unblock($unit)->assertOk()
            ->assertJsonPath('data.status', SerialUnit::STATUS_COMPLETED);

        $this->assertSame([UnitProducedQtySync::MACHINE_COUNTER], $this->warningCodes($response));
        $this->assertSame(1.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(9.0, (float) $wo->fresh()->produced_qty);
    }

    // ── the API contract: the notice travels in the answer, not in a log ─────

    public function test_the_unblock_answer_carries_the_quantity_notice_when_there_is_one(): void
    {
        $wo = $this->makeWorkOrder(1);
        $batch = $this->makeBatch($wo, 1);
        $unit = $this->unit($wo, $batch, 'SN-1');
        $this->pack($batch, 1);

        $this->block($unit)->assertOk();

        $response = $this->unblock($unit)->assertOk();

        $response->assertJsonStructure(['data' => ['id', 'status'], 'warnings']);
        $warnings = $response->json('warnings');
        $this->assertCount(1, $warnings);
        $this->assertSame(UnitProducedQtySync::DECLARED_FIGURE, $warnings[0]['code']);
        $this->assertNotSame('', $warnings[0]['message']);
    }

    public function test_releasing_a_unit_that_is_not_blocked_is_refused(): void
    {
        $wo = $this->makeWorkOrder(1);
        $batch = $this->makeBatch($wo, 1);
        $unit = $this->unit($wo, $batch, 'SN-1');
        $this->pack($batch);

        $response = $this->unblock($unit)->assertStatus(422);

        $this->assertStringContainsString('is not blocked', (string) $response->json('message'));
        $this->assertSame(SerialUnit::STATUS_COMPLETED, $unit->fresh()->status);
        $this->assertSame(1.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(1.0, (float) $wo->fresh()->produced_qty);
        $this->assertSame(0, $unit->history()->where('parameters->event', 'unblocked')->count());
    }
}
