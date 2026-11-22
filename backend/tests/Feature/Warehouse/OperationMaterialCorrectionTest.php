<?php

namespace Tests\Feature\Warehouse;

use App\Models\Batch;
use App\Models\BomItem;
use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\StockMovement;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WorkOrder;
use App\Services\Material\MaterialAllocationService;
use App\Services\Material\MaterialSettlementPolicy;
use App\Services\Material\MaterialSettlementScope;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * RQ-PA-265 A5 — correcting a settled pilot consumption.
 *
 * The correction is a new document, not an edit: the confirmation the operation
 * declared stays exactly as it was recorded and the difference is booked as a
 * compensating movement. Approving it is four-eyes (maker-checker) — the person
 * who declared the retrieval cannot approve its correction, and the plant's
 * existing `approve work order changes` right decides who may post one at all.
 * A retried request carrying the same key does not compensate twice.
 */
class OperationMaterialCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Material $material;

    private ProductType $product;

    private ProcessTemplate $template;

    private User $operator;

    private User $supervisor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('Supervisor');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->material = Material::factory()->create([
            'code' => 'FORMATKA-18',
            'name' => 'Formatka 18 mm',
            'unit_of_measure' => 'szt',
            'tracking_type' => 'none',
            'external_system' => null,
            'stock_quantity' => 100,
        ]);

        $this->product = ProductType::factory()->create([
            'code' => 'PROD-RQ265-CORRECT',
            'unit_of_measure' => 'szt',
        ]);

        $this->template = ProcessTemplate::factory()->create([
            'product_type_id' => $this->product->id,
            'name' => 'RQ265 correction routing',
            'is_active' => true,
        ]);

        $cutStep = TemplateStep::create([
            'process_template_id' => $this->template->id,
            'step_number' => 1,
            'name' => 'Cutting (CUT)',
            'kind' => TemplateStep::KIND_PRODUCTION,
        ]);

        BomItem::create([
            'process_template_id' => $this->template->id,
            'template_step_id' => $cutStep->id,
            'material_id' => $this->material->id,
            'quantity_per_unit' => 1,
            'scrap_percentage' => 0,
            'consumed_at' => 'start',
        ]);
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $extraData */
    private function workOrder(array $extraData, string $orderNo): WorkOrder
    {
        return WorkOrder::factory()->create([
            'order_no' => $orderNo,
            'product_type_id' => $this->product->id,
            'process_snapshot' => $this->template->fresh()->load(['steps', 'bomItems'])->toSnapshot(),
            'planned_qty' => 10,
            'produced_qty' => 0,
            'status' => WorkOrder::STATUS_PENDING,
            'extra_data' => $extraData,
        ]);
    }

    /** The opt-in: this material is settled by the operation that consumes it. */
    private function pilotExtraData(): array
    {
        return [
            MaterialSettlementPolicy::EXTRA_DATA_KEY => [
                'mode' => MaterialSettlementPolicy::MODE_OPERATION,
                'materials' => [$this->material->id],
            ],
        ];
    }

    /**
     * @return array{0: WorkOrder, 1: Batch, 2: MaterialAllocation}
     */
    private function startedBatch(array $extraData, string $orderNo = 'WO-RQ265-CORRECT'): array
    {
        $workOrder = $this->workOrder($extraData, $orderNo);
        $batch = app(WorkOrderService::class)->createBatch($workOrder, 10);
        $step = $batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($step, $this->operator);

        $allocation = MaterialAllocation::query()
            ->where('batch_id', $batch->id)
            ->where('material_id', $this->material->id)
            ->firstOrFail();

        return [$workOrder, $batch, $allocation];
    }

    /**
     * A pilot line that ran and settled: the operator declared 8 consumed plus
     * 2 scrap against the 10 withdrawn, and the batch closed on it.
     *
     * @return array{0: WorkOrder, 1: Batch, 2: MaterialAllocation}
     */
    private function settledBatch(
        string $orderNo = 'WO-RQ265-CORRECT',
        ?User $declarant = null,
    ): array {
        [$workOrder, $batch, $allocation] = $this->startedBatch($this->pilotExtraData(), $orderNo);

        app(MaterialAllocationService::class)
            ->recordConsumption($allocation, 8.0, 2.0, null, $declarant ?? $this->operator);
        app(MaterialAllocationService::class)->consumeForBatch($batch);

        return [$workOrder, $batch, $allocation->fresh()];
    }

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'consumed_qty' => 10,
            'scrap_qty' => 0,
            'reason' => 'Operator miscounted the sheets',
            'reference' => 'CONF-8-2',
        ], $overrides);
    }

    private function assertStock(float $expected): void
    {
        $this->assertEqualsWithDelta($expected, (float) $this->material->fresh()->stock_quantity, 0.0001);
    }

    /** Every ledger row for this material, in order — the "was anything booked" probe. */
    private function movementIds(): array
    {
        return StockMovement::query()
            ->where('material_id', $this->material->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    private function movementCount(string ...$types): int
    {
        return StockMovement::query()
            ->where('material_id', $this->material->id)
            ->whereIn('movement_type', $types)
            ->count();
    }

    /** A raw-material store that actually holds this material, so a booking has somewhere to land. */
    private function seededWarehouse(float $qty = 100): Warehouse
    {
        $warehouse = Warehouse::factory()->rawMaterial()->isDefault()->create(['code' => 'WH-RQ265-CORRECT-RAW']);

        WarehouseStock::factory()->create([
            'warehouse_id' => $warehouse->id,
            'material_id' => $this->material->id,
            'material_lot_id' => null,
            'quantity' => $qty,
        ]);

        return $warehouse;
    }

    private function locationBalance(Warehouse $warehouse): float
    {
        return (float) WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('material_id', $this->material->id)
            ->whereNull('material_lot_id')
            ->value('quantity');
    }

    // ── 1. same total, new document ──────────────────────────────────────────

    public function test_a_supervisor_corrects_the_settled_quantity_without_touching_the_original(): void
    {
        [, , $allocation] = $this->settledBatch();

        // 8 used + 2 scrapped was the confirmation; the correction says 10 used
        // and nothing scrapped — the same 10 left the store, split differently.
        $this->assertStock(90.0);
        $movementsBefore = $this->movementIds();

        $this->withToken($this->token($this->supervisor))
            ->postJson("/api/v1/material-allocations/{$allocation->id}/correct", $this->payload())
            ->assertOk()
            ->assertJsonPath('data.id', $allocation->id);

        $fresh = $allocation->fresh();
        $this->assertEqualsWithDelta(10.0, (float) $fresh->consumed_qty, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $fresh->scrap_qty, 0.0001);

        // No stock delta to book: the plant-wide balance already reflected 10.
        $this->assertStock(90.0);
        $this->assertSame($movementsBefore, $this->movementIds(), 'A correction that moves no quantity books no movement.');

        // The correction is its own document…
        $this->assertSame($this->supervisor->id, (int) $fresh->correction_recorded_by_id);
        $this->assertNotNull($fresh->correction_recorded_at);
        $this->assertSame('Operator miscounted the sheets', $fresh->correction_reason);
        $this->assertSame('CONF-8-2', $fresh->correction_reference);

        // …and the confirmation it corrects is left exactly as declared.
        $this->assertSame($this->operator->id, (int) $fresh->consumption_recorded_by_id);
        $this->assertSame(MaterialAllocation::STATUS_CONSUMED, $fresh->status);
        $this->assertSame('settled', $fresh->settlement_state);
    }

    // ── 2. a downward correction credits the store ───────────────────────────

    public function test_a_downward_correction_credits_the_stock_back(): void
    {
        [, $batch, $allocation] = $this->settledBatch();

        $this->assertStock(90.0);

        $this->withToken($this->token($this->supervisor))
            ->postJson("/api/v1/material-allocations/{$allocation->id}/correct", $this->payload([
                'consumed_qty' => 8,
                'scrap_qty' => 0,
            ]))
            ->assertOk();

        // 8 used instead of the 10 booked: the 2 the store had given up come back.
        $this->assertStock(92.0);

        $movement = StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_ADJUSTMENT)
            ->sole();

        $this->assertEqualsWithDelta(2.0, (float) $movement->quantity, 0.0001);
        $this->assertEqualsWithDelta(92.0, (float) $movement->balance_after, 0.0001);
        $this->assertSame(StockMovement::SOURCE_BATCH, $movement->source_type);
        $this->assertSame($batch->id, (int) $movement->source_id);
        $this->assertSame($this->supervisor->id, (int) $movement->performed_by);
        $this->assertStringContainsString('CONF-8-2', (string) $movement->reason);

        $this->assertSame(1, $this->movementCount(StockMovement::TYPE_ADJUSTMENT));
    }

    // ── 3. an upward correction books the extra ──────────────────────────────

    public function test_an_upward_correction_books_the_extra(): void
    {
        [, , $allocation] = $this->settledBatch();

        $this->assertStock(90.0);

        $this->withToken($this->token($this->supervisor))
            ->postJson("/api/v1/material-allocations/{$allocation->id}/correct", $this->payload([
                'consumed_qty' => 12,
                'scrap_qty' => 0,
            ]))
            ->assertOk();

        // 2 more than was withdrawn at allocation: they have to leave stock now.
        $this->assertStock(88.0);

        $movement = StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_ADJUSTMENT)
            ->sole();

        $this->assertEqualsWithDelta(-2.0, (float) $movement->quantity, 0.0001);
        $this->assertEqualsWithDelta(88.0, (float) $movement->balance_after, 0.0001);
        $this->assertSame($this->supervisor->id, (int) $movement->performed_by);
        $this->assertSame(1, $this->movementCount(StockMovement::TYPE_ADJUSTMENT));
    }

    // ── 4. four eyes: the declarant cannot approve their own correction ──────

    public function test_the_declarant_cannot_approve_their_own_correction(): void
    {
        // The declarant holds the review right — the refusal is four-eyes, not
        // a missing role: whoever signed the confirmation cannot sign it off.
        [, , $allocation] = $this->settledBatch(declarant: $this->supervisor);

        $movementsBefore = $this->movementIds();
        $this->assertStock(90.0);

        $this->withToken($this->token($this->supervisor))
            ->postJson("/api/v1/material-allocations/{$allocation->id}/correct", $this->payload([
                'consumed_qty' => 8,
            ]))
            ->assertStatus(422)
            ->assertJson([
                'code' => 'material_correction_requires_other_approver',
            ]);

        $fresh = $allocation->fresh();
        $this->assertEqualsWithDelta(8.0, (float) $fresh->consumed_qty, 0.0001);
        $this->assertNull($fresh->correction_recorded_by_id);
        $this->assertStock(90.0);
        $this->assertSame($movementsBefore, $this->movementIds());
    }

    // ── 5. the operator has no review right ──────────────────────────────────

    public function test_an_operator_without_the_permission_is_refused(): void
    {
        [, , $allocation] = $this->settledBatch();

        $movementsBefore = $this->movementIds();
        $this->assertStock(90.0);

        $this->withToken($this->token($this->operator))
            ->postJson("/api/v1/material-allocations/{$allocation->id}/correct", $this->payload([
                'consumed_qty' => 8,
            ]))
            ->assertForbidden();

        $this->assertStock(90.0);
        $this->assertSame($movementsBefore, $this->movementIds());
        $this->assertEqualsWithDelta(8.0, (float) $allocation->fresh()->consumed_qty, 0.0001);
    }

    // ── 6. a correction without a reason and a reference is not a document ───

    public function test_a_correction_needs_a_reason_and_a_reference(): void
    {
        [, , $allocation] = $this->settledBatch();

        $movementsBefore = $this->movementIds();

        $this->withToken($this->token($this->supervisor))
            ->postJson("/api/v1/material-allocations/{$allocation->id}/correct", $this->payload([
                'consumed_qty' => 8,
                'reason' => '',
                'reference' => '',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason', 'reference']);

        $this->assertStock(90.0);
        $this->assertSame($movementsBefore, $this->movementIds());
        $this->assertEqualsWithDelta(8.0, (float) $allocation->fresh()->consumed_qty, 0.0001);
    }

    // ── 7. one key, one compensation ─────────────────────────────────────────

    public function test_the_same_correction_key_does_not_compensate_twice(): void
    {
        [, , $allocation] = $this->settledBatch();

        $token = $this->token($this->supervisor);
        $payload = $this->payload([
            'consumed_qty' => 8,
            'idempotency_key' => 'correction-A',
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/material-allocations/{$allocation->id}/correct", $payload)
            ->assertOk();

        $this->assertStock(92.0);
        $movementsAfterFirst = $this->movementIds();
        $recordedAt = $allocation->fresh()->correction_recorded_at;
        $this->assertNotNull($recordedAt);

        $this->travel(2)->seconds();

        $this->withToken($token)
            ->postJson("/api/v1/material-allocations/{$allocation->id}/correct", $payload)
            ->assertOk();

        $fresh = $allocation->fresh();
        $this->assertStock(92.0);
        $this->assertSame($movementsAfterFirst, $this->movementIds(), 'A replayed key books no second compensation.');
        $this->assertSame(1, $this->movementCount(StockMovement::TYPE_ADJUSTMENT));
        $this->assertEqualsWithDelta(8.0, (float) $fresh->consumed_qty, 0.0001);
        $this->assertSame(
            $recordedAt->getTimestamp(),
            $fresh->correction_recorded_at->getTimestamp(),
            'A replay does not restamp the correction.',
        );
    }

    // ── 8. a key that would name two documents is a conflict ─────────────────

    public function test_the_same_key_with_other_values_is_a_conflict(): void
    {
        [, , $allocationA] = $this->settledBatch('WO-RQ265-CORRECT-A');
        [, , $allocationB] = $this->settledBatch('WO-RQ265-CORRECT-B');

        $token = $this->token($this->supervisor);

        $this->withToken($token)
            ->postJson("/api/v1/material-allocations/{$allocationA->id}/correct", $this->payload([
                'consumed_qty' => 8,
                'idempotency_key' => 'correction-B',
            ]))
            ->assertOk();

        $movementsAfterFirst = $this->movementIds();

        $this->withToken($token)
            ->postJson("/api/v1/material-allocations/{$allocationA->id}/correct", $this->payload([
                'consumed_qty' => 9,
                'idempotency_key' => 'correction-B',
            ]))
            ->assertStatus(422)
            ->assertJson(['code' => 'material_correction_key_conflict']);

        // The same key against another allocation is the same conflict.
        $this->withToken($token)
            ->postJson("/api/v1/material-allocations/{$allocationB->id}/correct", $this->payload([
                'consumed_qty' => 8,
                'idempotency_key' => 'correction-B',
            ]))
            ->assertStatus(422)
            ->assertJson(['code' => 'material_correction_key_conflict']);

        $this->assertSame($movementsAfterFirst, $this->movementIds());
        // Two withdrawals of 10 left 80; only the first correction's 2 came back.
        $this->assertStock(82.0);
        $this->assertEqualsWithDelta(8.0, (float) $allocationA->fresh()->consumed_qty, 0.0001);
        // The second line still holds the confirmation it settled on.
        $this->assertEqualsWithDelta(8.0, (float) $allocationB->fresh()->consumed_qty, 0.0001);
        $this->assertNull($allocationB->fresh()->correction_recorded_by_id);
    }

    // ── 9. a pending line is settled first, corrected later ──────────────────

    public function test_a_pending_settlement_cannot_be_corrected(): void
    {
        // Nothing was confirmed, so the line is still waiting for its operation.
        [, , $allocation] = $this->startedBatch($this->pilotExtraData());

        $this->assertSame('pending', $allocation->settlement_state);
        $movementsBefore = $this->movementIds();
        $this->assertStock(90.0);

        $this->withToken($this->token($this->admin))
            ->postJson("/api/v1/material-allocations/{$allocation->id}/correct", $this->payload())
            ->assertStatus(422)
            ->assertJson(['code' => 'material_correction_requires_settled_settlement']);

        $fresh = $allocation->fresh();
        $this->assertSame(MaterialAllocation::STATUS_ALLOCATED, $fresh->status);
        $this->assertEqualsWithDelta(0.0, (float) $fresh->consumed_qty, 0.0001);
        $this->assertNull($fresh->correction_recorded_by_id);
        $this->assertStock(90.0);
        $this->assertSame($movementsBefore, $this->movementIds());
    }

    // ── 10. the original documents are append-only ───────────────────────────

    public function test_the_original_movement_is_left_untouched(): void
    {
        [, , $allocation] = $this->settledBatch();

        $allocationMovement = StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_ALLOCATION)
            ->sole();
        $originalMovement = [
            'id' => $allocationMovement->id,
            'quantity' => (float) $allocationMovement->quantity,
            'created_at' => $allocationMovement->created_at->getTimestamp(),
        ];
        $originalConfirmation = [
            'by' => (int) $allocation->consumption_recorded_by_id,
            'at' => $allocation->consumption_recorded_at->getTimestamp(),
        ];

        $this->withToken($this->token($this->supervisor))
            ->postJson("/api/v1/material-allocations/{$allocation->id}/correct", $this->payload([
                'consumed_qty' => 8,
            ]))
            ->assertOk();

        $allocationMovement->refresh();
        $this->assertSame($originalMovement['id'], $allocationMovement->id);
        $this->assertEqualsWithDelta($originalMovement['quantity'], (float) $allocationMovement->quantity, 0.0001);
        $this->assertSame($originalMovement['created_at'], $allocationMovement->created_at->getTimestamp());

        // The compensation is a row of its own; the withdrawal was not rewritten.
        $this->assertSame(1, StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_ALLOCATION)
            ->count());
        $this->assertSame(1, $this->movementCount(StockMovement::TYPE_ADJUSTMENT));

        $fresh = $allocation->fresh();
        $this->assertSame($originalConfirmation['by'], (int) $fresh->consumption_recorded_by_id);
        $this->assertSame($originalConfirmation['at'], $fresh->consumption_recorded_at->getTimestamp());
        $this->assertSame($this->operator->id, (int) $fresh->consumption_recorded_by_id);
        // 8 consumed + 2 scrap was declared and stays declared.
        $this->assertEqualsWithDelta(8.0, (float) $fresh->consumed_qty, 0.0001);
    }

    // ── 11. the location that gave the material up gets it back ──────────────

    public function test_a_downward_correction_credits_the_location_it_came_from(): void
    {
        $warehouse = $this->seededWarehouse();

        [, , $allocation] = $this->settledBatch();

        // 8 used + 2 scrapped left the store: the location holds 90, not 100.
        $this->assertEqualsWithDelta(90.0, $this->locationBalance($warehouse), 0.0001);

        $this->withToken($this->token($this->supervisor))
            ->postJson("/api/v1/material-allocations/{$allocation->id}/correct", $this->payload([
                'consumed_qty' => 8,
                'scrap_qty' => 0,
            ]))
            ->assertOk();

        // The store is made whole for the 2 the operation never used, and the
        // correction froze the location it corrected on the allocation.
        $this->assertEqualsWithDelta(92.0, $this->locationBalance($warehouse), 0.0001);
        $this->assertStock(92.0);
        $this->assertSame($warehouse->id, (int) $allocation->fresh()->consumption_warehouse_id);

        $locationCredit = StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_RETURN)
            ->where('warehouse_id', $warehouse->id)
            ->sole();

        $this->assertEqualsWithDelta(2.0, (float) $locationCredit->quantity, 0.0001);
    }

    // ── 12. the review action reaches the admin page ─────────────────────────

    public function test_the_admin_page_offers_the_correction_to_a_reviewer(): void
    {
        [$workOrder] = $this->settledBatch();

        $this->actingAs($this->supervisor)
            ->get("/admin/work-orders/{$workOrder->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/work-orders/Show')
                ->where('canCorrectSettlement', true)
                ->where('canReclassify', true)
                ->has('workOrder.allocations', 1)
                ->where('workOrder.allocations.0.settlement_state', 'settled')
                ->where('workOrder.allocations.0.correction_recorded_by', null)
            );
    }

    // ── 13. only the pilot scope has a correction to post ────────────────────

    public function test_a_legacy_allocation_cannot_be_corrected(): void
    {
        [, $batch, $allocation] = $this->startedBatch([], 'WO-RQ265-CORRECT-LEGACY');

        app(MaterialAllocationService::class)->consumeForBatch($batch);
        $this->assertSame(MaterialAllocation::STATUS_CONSUMED, $allocation->fresh()->status);

        $movementsBefore = $this->movementIds();

        $this->withToken($this->token($this->admin))
            ->postJson("/api/v1/material-allocations/{$allocation->id}/correct", $this->payload())
            ->assertStatus(422)
            ->assertJson(['code' => 'material_correction_not_applicable']);

        $this->assertSame($movementsBefore, $this->movementIds());
        $this->assertNull($allocation->fresh()->correction_recorded_by_id);
    }

    // ── 14. the settlement terms are frozen on the allocation ─────────────────

    public function test_a_settled_pilot_line_is_still_correctable_after_the_scope_is_switched_off(): void
    {
        [$workOrder, , $allocation] = $this->settledBatch('WO-RQ265-CORRECT-OFF');

        $this->assertSame(MaterialSettlementPolicy::MODE_OPERATION, $allocation->settlement_mode);
        $this->assertStock(90.0);

        // The retrieval is settled, so the pilot may be switched off…
        app(MaterialSettlementScope::class)->disable($workOrder->fresh(), $this->admin);

        $this->assertSame(
            MaterialSettlementPolicy::MODE_LEGACY,
            app(MaterialSettlementPolicy::class)->modeFor($workOrder->fresh(), $this->material->id),
            'The read-only policy follows the order: the order is back on the legacy scope.',
        );

        // …and the correction still works, because the line carries the terms it
        // was settled under — not the terms the order happens to carry today.
        $this->withToken($this->token($this->supervisor))
            ->postJson("/api/v1/material-allocations/{$allocation->id}/correct", $this->payload([
                'consumed_qty' => 8,
                'scrap_qty' => 0,
            ]))
            ->assertOk();

        $this->assertStock(92.0);
        $this->assertEqualsWithDelta(8.0, (float) $allocation->fresh()->consumed_qty, 0.0001);
        $this->assertSame($this->supervisor->id, (int) $allocation->fresh()->correction_recorded_by_id);
    }

    public function test_the_settlement_mode_column_exists_on_allocations(): void
    {
        $this->assertTrue(Schema::hasColumn('material_allocations', 'settlement_mode'));
    }

    public function test_the_pilot_confirmation_freezes_the_operation_mode(): void
    {
        [, , $allocation] = $this->startedBatch($this->pilotExtraData(), 'WO-RQ265-CORRECT-FREEZE');

        $fresh = app(MaterialAllocationService::class)
            ->recordConsumption($allocation, 8.0, 2.0, null, $this->operator);

        $this->assertSame(MaterialSettlementPolicy::MODE_OPERATION, $fresh->settlement_mode);
        $this->assertSame(MaterialSettlementPolicy::MODE_OPERATION, $allocation->fresh()->settlement_mode);
    }

    public function test_a_legacy_confirmation_freezes_the_legacy_mode(): void
    {
        [, , $allocation] = $this->startedBatch([], 'WO-RQ265-CORRECT-FREEZE-LEGACY');

        // No actor and no pilot: a packaging/legacy call still freezes its terms,
        // because the mode is a fact about the settlement, not about the caller.
        $fresh = app(MaterialAllocationService::class)->recordConsumption($allocation, 5.0, 0.0);

        $this->assertSame(MaterialSettlementPolicy::MODE_LEGACY, $fresh->settlement_mode);
        $this->assertNull($fresh->consumption_recorded_by_id);
    }

    public function test_a_settled_legacy_confirmation_cannot_be_corrected(): void
    {
        [, $batch, $allocation] = $this->startedBatch([], 'WO-RQ265-CORRECT-LEGACY-FROZEN');

        app(MaterialAllocationService::class)->recordConsumption($allocation, 10.0, 0.0, null, $this->operator);
        app(MaterialAllocationService::class)->consumeForBatch($batch);

        $this->assertSame(MaterialSettlementPolicy::MODE_LEGACY, $allocation->fresh()->settlement_mode);

        $movementsBefore = $this->movementIds();

        $this->withToken($this->token($this->admin))
            ->postJson("/api/v1/material-allocations/{$allocation->id}/correct", $this->payload())
            ->assertStatus(422)
            ->assertJson(['code' => 'material_correction_not_applicable']);

        $this->assertSame($movementsBefore, $this->movementIds());
        $this->assertNull($allocation->fresh()->correction_recorded_by_id);
    }

    public function test_an_allocation_without_a_frozen_mode_cannot_be_corrected(): void
    {
        [, , $allocation] = $this->settledBatch('WO-RQ265-CORRECT-NO-MODE');

        // A line confirmed before the mode was recorded: nothing is frozen, so
        // there are no terms to correct against.
        MaterialAllocation::query()->whereKey($allocation->id)->update(['settlement_mode' => null]);

        $movementsBefore = $this->movementIds();

        $this->withToken($this->token($this->supervisor))
            ->postJson("/api/v1/material-allocations/{$allocation->id}/correct", $this->payload([
                'consumed_qty' => 8,
            ]))
            ->assertStatus(422)
            ->assertJson(['code' => 'material_correction_not_applicable']);

        $this->assertSame($movementsBefore, $this->movementIds());
        $this->assertNull($allocation->fresh()->correction_recorded_by_id);
    }
}
