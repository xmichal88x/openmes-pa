<?php

namespace Tests\Feature\Warehouse;

use App\Enums\PalletStatus;
use App\Models\Batch;
use App\Models\BomItem;
use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\Pallet;
use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\SerialUnit;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WorkOrder;
use App\Services\Material\MaterialAllocationService;
use App\Services\Material\MaterialSettlementPolicy;
use App\Services\Packaging\UnitPackingService;
use App\Services\Traceability\SerialTraceService;
use App\Services\Warehouse\WarehouseStockService;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RQ-PA-265 A2a — the pilot scope, per material, carried in the work order's
 * frozen snapshot, together with the explicit "pending settlement" state.
 * Without the opt-in (`extra_data.material_settlement`) every path behaves
 * exactly as it did before this change.
 *
 * The rules under test, taken from SAP and Oracle:
 *  - an unconfirmed consumption does not fall back to the plan; the line waits
 *    to be settled (COGI / Pending Transactions) while the rest of the batch
 *    books normally;
 *  - one item has one author of the withdrawal, and that author is the operation.
 */
class OperationMaterialSettlementTest extends TestCase
{
    use RefreshDatabase;

    private Material $material;

    private ProductType $product;

    private ProcessTemplate $template;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Operator', 'web');

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');

        $this->material = Material::factory()->create([
            'code' => 'FORMATKA-18',
            'name' => 'Formatka 18 mm',
            'unit_of_measure' => 'szt',
            'tracking_type' => 'none',
            'external_system' => null,
            'stock_quantity' => 100,
        ]);

        $this->product = ProductType::factory()->create([
            'code' => 'PROD-RQ265',
            'unit_of_measure' => 'szt',
        ]);

        $this->template = ProcessTemplate::factory()->create([
            'product_type_id' => $this->product->id,
            'name' => 'RQ265 pilot routing',
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
    private function workOrder(array $extraData = [], float $planned = 10, string $orderNo = 'WO-RQ265-SETTLE'): WorkOrder
    {
        return WorkOrder::factory()->create([
            'order_no' => $orderNo,
            'product_type_id' => $this->product->id,
            'process_snapshot' => $this->template->fresh()->load(['steps', 'bomItems'])->toSnapshot(),
            'planned_qty' => $planned,
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
     * A batch whose cutting step was started: the component was withdrawn by the
     * operation and sits in an `allocated` allocation.
     *
     * @return array{0: WorkOrder, 1: Batch, 2: MaterialAllocation}
     */
    private function startedBatch(array $extraData = [], string $orderNo = 'WO-RQ265-SETTLE'): array
    {
        $workOrder = $this->workOrder($extraData, orderNo: $orderNo);
        $batch = app(WorkOrderService::class)->createBatch($workOrder, 10);
        $step = $batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($step, $this->operator);

        $allocation = MaterialAllocation::query()
            ->where('batch_id', $batch->id)
            ->where('material_id', $this->material->id)
            ->firstOrFail();

        return [$workOrder, $batch, $allocation];
    }

    private function assertStock(float $expected): void
    {
        $this->assertEqualsWithDelta($expected, (float) $this->material->fresh()->stock_quantity, 0.0001);
    }

    /** A raw-material store that actually holds this material, so a booking has somewhere to land. */
    private function seededWarehouse(float $qty = 100): Warehouse
    {
        $warehouse = Warehouse::factory()->rawMaterial()->isDefault()->create(['code' => 'WH-RQ265-RAW']);

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

    /** The same opt-in, for a component of a routing the test built itself. */
    private function pilotScopeFor(Material ...$materials): array
    {
        return [
            MaterialSettlementPolicy::EXTRA_DATA_KEY => [
                'mode' => MaterialSettlementPolicy::MODE_OPERATION,
                'materials' => array_map(fn (Material $material) => $material->id, $materials),
            ],
        ];
    }

    /**
     * Grouped (movement_type, source_type) counts for one material: the shape of
     * its ledger. Two runs that book the same thing share the same shape, so a
     * side channel that writes even one extra row cannot hide behind a balance.
     *
     * @return array<string, int>
     */
    private function movementSignature(Material $material): array
    {
        return StockMovement::query()
            ->where('material_id', $material->id)
            ->selectRaw('movement_type, source_type, count(*) as rows')
            ->groupBy('movement_type', 'source_type')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->movement_type.'/'.($row->source_type ?? 'none') => (int) $row->rows])
            ->sortKeys()
            ->all();
    }

    /**
     * A routing of its own — component, product, template, cutting and packing
     * steps — so one test can measure two runs without the first run's ledger
     * bleeding into the second.
     *
     * @return array{material: Material, template: ProcessTemplate}
     */
    private function isolatedPilotRouting(string $suffix, float $stock = 100.0): array
    {
        $material = Material::factory()->create([
            'code' => 'FORMATKA-18-'.$suffix,
            'name' => 'Formatka 18 mm ('.$suffix.')',
            'unit_of_measure' => 'szt',
            'tracking_type' => 'none',
            'external_system' => null,
            'stock_quantity' => $stock,
        ]);

        $product = ProductType::factory()->create([
            'code' => 'PROD-RQ265-'.$suffix,
            'unit_of_measure' => 'szt',
        ]);

        $template = ProcessTemplate::factory()->create([
            'product_type_id' => $product->id,
            'name' => 'RQ265 pilot routing '.$suffix,
            'is_active' => true,
        ]);

        $cutStep = TemplateStep::create([
            'process_template_id' => $template->id,
            'step_number' => 1,
            'name' => 'Cutting (CUT)',
            'kind' => TemplateStep::KIND_PRODUCTION,
        ]);

        TemplateStep::create([
            'process_template_id' => $template->id,
            'step_number' => 2,
            'name' => 'Packing',
            'kind' => TemplateStep::KIND_PACKING,
            'config' => ['unit' => 'carton', 'carton_capacity' => 1, 'pallet_capacity' => 0],
        ]);

        BomItem::create([
            'process_template_id' => $template->id,
            'template_step_id' => $cutStep->id,
            'material_id' => $material->id,
            'quantity_per_unit' => 1,
            'scrap_percentage' => 0,
            'consumed_at' => 'start',
        ]);

        return ['material' => $material, 'template' => $template];
    }

    /** `post` mode and a finished-goods store to book into. */
    private function postModeFinishedGoods(): Warehouse
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'pallet_stock_documents'],
            ['value' => json_encode('post')],
        );

        return Warehouse::factory()->finishedGoods()->isDefault()->create(['code' => 'FG-RQ265']);
    }

    /**
     * A pilot order with `$units` good serialised units on one loose pallet,
     * created through the station exactly as the bench creates it.
     *
     * @return array{0: WorkOrder, 1: Pallet}
     */
    private function palletWithUnits(int $units): array
    {
        [$workOrder, , $allocation] = $this->startedBatch($this->pilotExtraData());
        app(MaterialAllocationService::class)->recordConsumption($allocation, 8.0, 2.0, null, $this->operator);

        $palletId = $this->actingAs($this->operator)
            ->postJson(route('packaging.pallets.create'), [
                'work_order_id' => $workOrder->id,
                'kind' => Pallet::KIND_LOOSE,
                'produced_qty' => $units,
            ])
            ->assertCreated()
            ->json('pallet.id');

        $pallet = Pallet::findOrFail($palletId);
        $pallet->update(['qty' => $units]);

        for ($index = 1; $index <= $units; $index++) {
            SerialUnit::factory()->create([
                'serial_no' => 'SN-RQ265-FG-'.$index.'-'.$workOrder->id,
                'work_order_id' => $workOrder->id,
                'batch_id' => $pallet->batch_id,
                'pallet_id' => $pallet->id,
                'status' => SerialUnit::STATUS_COMPLETED,
                'produced_at' => now(),
                'packed_at' => now(),
            ]);
        }

        return [$workOrder, $pallet];
    }

    /** Create, close, close again and take a pallet at the bench — no raw material may move. */
    private function churnPallets(int $workOrderId): void
    {
        $closed = null;

        foreach ([5, 5] as $produced) {
            $closed = $this->actingAs($this->operator)
                ->postJson(route('packaging.pallets.create'), [
                    'work_order_id' => $workOrderId,
                    'kind' => Pallet::KIND_LOOSE,
                    'produced_qty' => $produced,
                ])
                ->assertCreated()
                ->json('pallet.id');

            $this->actingAs($this->operator)
                ->postJson(route('packaging.pallets.close', $closed))
                ->assertOk();
        }

        // The same pallet closed twice is refused, and books nothing either.
        $this->actingAs($this->operator)
            ->postJson(route('packaging.pallets.close', $closed))
            ->assertStatus(422);

        // A bench that takes the work up again: a fresh pallet, taken and put down.
        $reopened = $this->actingAs($this->operator)
            ->postJson(route('packaging.pallets.create'), [
                'work_order_id' => $workOrderId,
                'kind' => Pallet::KIND_LOOSE,
            ])
            ->assertCreated()
            ->json('pallet.id');

        $this->actingAs($this->operator)
            ->postJson(route('packaging.pallets.activate', $reopened))
            ->assertOk();
        $this->actingAs($this->operator)
            ->postJson(route('packaging.pallets.release', $reopened))
            ->assertOk();
    }

    /**
     * One pilot operation from withdrawal to settlement: 10 pieces withdrawn,
     * 8 declared good and 2 scrapped, then settled. `$withPallets` runs the
     * pallet transitions in between, as the packing bench would.
     *
     * @return array{material: Material, signature: array<string, int>, stock: float, consumed: float, scrap: float, settled: int, pending: int, allocation: MaterialAllocation}
     */
    private function settledPilotRun(string $suffix, bool $withPallets): array
    {
        ['material' => $material, 'template' => $template] = $this->isolatedPilotRouting($suffix);

        $workOrder = WorkOrder::factory()->create([
            'order_no' => 'WO-RQ265-'.$suffix,
            'product_type_id' => $template->product_type_id,
            'process_snapshot' => $template->fresh()->load(['steps', 'bomItems'])->toSnapshot(),
            'planned_qty' => 10,
            'produced_qty' => 0,
            'status' => WorkOrder::STATUS_PENDING,
            'extra_data' => $this->pilotScopeFor($material),
        ]);

        $batch = app(WorkOrderService::class)->createBatch($workOrder, 10);
        $cutStep = $batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($cutStep, $this->operator);

        $allocation = MaterialAllocation::query()
            ->where('batch_id', $batch->id)
            ->where('material_id', $material->id)
            ->firstOrFail();

        $this->assertEqualsWithDelta(90.0, (float) $material->fresh()->stock_quantity, 0.0001, 'The operation withdrew the 10 pieces.');

        if ($withPallets) {
            $this->churnPallets($workOrder->id);
            $this->assertEqualsWithDelta(90.0, (float) $material->fresh()->stock_quantity, 0.0001, 'Pallet transitions withdraw no raw material.');
        }

        app(MaterialAllocationService::class)->recordConsumption($allocation, 8.0, 2.0, null, $this->operator);
        $result = app(MaterialAllocationService::class)->consumeForBatch($batch);

        $fresh = $allocation->fresh();

        return [
            'material' => $material,
            'signature' => $this->movementSignature($material),
            'stock' => (float) $material->fresh()->stock_quantity,
            'consumed' => (float) $fresh->consumed_qty,
            'scrap' => (float) $fresh->scrap_qty,
            'settled' => (int) $result['settled'],
            'pending' => count($result['pending']),
            'allocation' => $fresh,
        ];
    }

    // ── 1. no confirmation, no settlement ────────────────────────────────────

    public function test_an_unconfirmed_pilot_allocation_stays_pending_and_keeps_its_reservation(): void
    {
        [, $batch, $allocation] = $this->startedBatch($this->pilotExtraData());

        $this->assertFalse((bool) $allocation->consumption_recorded);
        $this->assertStock(90.0);

        $result = app(MaterialAllocationService::class)->consumeForBatch($batch);

        $fresh = $allocation->fresh();
        // Nothing was declared, so nothing is settled: no plan is written in.
        $this->assertSame(MaterialAllocation::STATUS_ALLOCATED, $fresh->status);
        $this->assertEqualsWithDelta(0.0, (float) $fresh->consumed_qty, 0.0001);
        $this->assertNull($fresh->consumed_at);

        // The stock picture is untouched: the withdrawal still stands and is
        // still reserved, so the material cannot be promised to another batch.
        $this->assertStock(90.0);
        $this->assertEqualsWithDelta(10.0, (float) $this->material->fresh()->reserved_quantity, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $fresh->location_deducted_qty, 0.0001);

        // Zero side effects: no return, no second write-off.
        $this->assertSame(0, $this->movementCount(StockMovement::TYPE_RETURN));
        $this->assertSame(0, $this->movementCount(StockMovement::TYPE_CONSUME));

        // The state is explicit, not inferred from an empty column.
        $this->assertSame('pending', $fresh->settlement_state);
        $this->assertSame('consumption_not_confirmed', $fresh->settlement_pending_reason);

        $this->assertSame(0, $result['settled']);
        $this->assertCount(1, $result['pending']);
        $this->assertSame($allocation->id, $result['pending'][0]['allocation_id']);
        $this->assertSame($this->material->id, $result['pending'][0]['material_id']);
        $this->assertSame('consumption_not_confirmed', $result['pending'][0]['reason']);
    }

    // ── 2. a confirmed pilot allocation settles ──────────────────────────────

    public function test_a_confirmed_pilot_allocation_settles_normally(): void
    {
        [, $batch, $allocation] = $this->startedBatch($this->pilotExtraData());

        app(MaterialAllocationService::class)->recordConsumption($allocation, 8.0, 2.0, null, $this->operator);
        $this->assertStock(90.0);

        $result = app(MaterialAllocationService::class)->consumeForBatch($batch);

        $fresh = $allocation->fresh();
        $this->assertSame(MaterialAllocation::STATUS_CONSUMED, $fresh->status);
        $this->assertEqualsWithDelta(8.0, (float) $fresh->consumed_qty, 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $fresh->scrap_qty, 0.0001);

        // 8 used + 2 scrapped = the 10 already withdrawn: no second deduction.
        $this->assertStock(90.0);
        $this->assertSame(0, $this->movementCount(StockMovement::TYPE_CONSUME));

        $this->assertSame('settled', $fresh->settlement_state);
        $this->assertNull($fresh->settlement_pending_reason);

        $this->assertSame(1, $result['settled']);
        $this->assertSame([], $result['pending']);
    }

    // ── 3. no opt-in: today's behaviour, unchanged ───────────────────────────

    public function test_the_legacy_scope_still_settles_with_the_plan_when_nothing_was_confirmed(): void
    {
        [, $batch, $allocation] = $this->startedBatch();
        $this->assertFalse((bool) $allocation->consumption_recorded);

        $result = app(MaterialAllocationService::class)->consumeForBatch($batch);

        $fresh = $allocation->fresh();
        $this->assertSame(MaterialAllocation::STATUS_CONSUMED, $fresh->status);
        $this->assertEqualsWithDelta(10.0, (float) $fresh->consumed_qty, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $fresh->allocated_qty, 0.0001);
        $this->assertStock(90.0);

        $this->assertSame(1, $result['settled']);
        $this->assertSame([], $result['pending']);
    }

    // ── 4. an allocation with no operation cannot settle ─────────────────────

    public function test_an_allocation_without_an_operation_is_pending_not_settled(): void
    {
        [, $batch, $allocation] = $this->startedBatch($this->pilotExtraData());

        // An allocation nobody attributed to a step: no operation answers for it.
        $allocation->update(['batch_step_id' => null]);

        $result = app(MaterialAllocationService::class)->consumeForBatch($batch);

        $fresh = $allocation->fresh();
        $this->assertSame(MaterialAllocation::STATUS_ALLOCATED, $fresh->status);
        $this->assertEqualsWithDelta(0.0, (float) $fresh->consumed_qty, 0.0001);
        $this->assertStock(90.0);

        $this->assertSame('pending', $fresh->settlement_state);
        $this->assertSame('operation_not_attributed', $fresh->settlement_pending_reason);

        $this->assertSame(0, $result['settled']);
        $this->assertCount(1, $result['pending']);
        $this->assertSame('operation_not_attributed', $result['pending'][0]['reason']);
    }

    // ── 5. the state reaches the admin page ──────────────────────────────────

    public function test_the_admin_work_order_page_exposes_the_settlement_state(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        [$workOrder, $batch] = $this->startedBatch($this->pilotExtraData());
        app(MaterialAllocationService::class)->consumeForBatch($batch);

        $this->actingAs($admin)
            ->get("/admin/work-orders/{$workOrder->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/work-orders/Show')
                ->has('workOrder.allocations', 1)
                ->where('workOrder.allocations.0.settlement_state', 'pending')
                ->where('workOrder.allocations.0.settlement_reason', 'consumption_not_confirmed')
                ->where('workOrder.allocations.0.step_number', 1)
            );
    }

    // ── 6. the confirmation carries the identity of the document ─────────────

    public function test_the_confirmation_records_actor_time_unit_and_source(): void
    {
        [, , $allocation] = $this->startedBatch($this->pilotExtraData());

        $before = now()->subMinute();

        $fresh = app(MaterialAllocationService::class)->recordConsumption(
            $allocation,
            8.0,
            2.0,
            'operator retrieval',
            $this->operator,
            'operator',
            'settle-cut-1',
        );

        $this->assertSame($this->operator->id, (int) $fresh->consumption_recorded_by_id);
        $this->assertSame($this->operator->name, $fresh->consumptionRecordedBy?->name);
        $this->assertNotNull($fresh->consumption_recorded_at);
        $this->assertTrue($fresh->consumption_recorded_at->greaterThanOrEqualTo($before));
        $this->assertSame('operator', $fresh->consumption_source);
        $this->assertSame('settle-cut-1', $fresh->consumption_key);

        // The retrieval is expressed in the material's own unit — the evidence is
        // the quantity plus who, when and from where, not a unit of its own.
        $this->assertSame('szt', $fresh->material->unit_of_measure);
        $this->assertEqualsWithDelta(10.0, (float) $fresh->consumed_qty + (float) $fresh->scrap_qty, 0.0001);
    }

    // ── 7. one key, one booking ──────────────────────────────────────────────

    public function test_the_same_idempotency_key_does_not_book_twice(): void
    {
        $warehouse = $this->seededWarehouse();
        [, , $allocation] = $this->startedBatch($this->pilotExtraData());

        $service = app(MaterialAllocationService::class);
        $first = $service->recordConsumption($allocation, 8.0, 1.0, null, $this->operator, 'operator', 'confirm-A');

        $movementsAfterFirst = $this->movementIds();
        $deductedAfterFirst = (float) $allocation->fresh()->location_deducted_qty;
        $balanceAfterFirst = $this->locationBalance($warehouse);
        $recordedAt = $first->consumption_recorded_at;

        $this->assertCount(1, StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_CONSUME)
            ->whereNotNull('warehouse_id')
            ->get(), 'The confirmation books one location movement.');

        $this->travel(2)->seconds();

        $second = $service->recordConsumption($allocation->fresh(), 8.0, 1.0, null, $this->operator, 'operator', 'confirm-A');

        // Same row, same values: the replay is answered, not booked again.
        $this->assertSame($first->id, $second->id);
        $this->assertEqualsWithDelta(9.0, (float) $second->consumed_qty + (float) $second->scrap_qty, 0.0001);
        $this->assertSame($movementsAfterFirst, $this->movementIds(), 'A replayed key books no new movement.');
        $this->assertEqualsWithDelta($deductedAfterFirst, (float) $allocation->fresh()->location_deducted_qty, 0.0001);
        $this->assertEqualsWithDelta($balanceAfterFirst, $this->locationBalance($warehouse), 0.0001);
        $this->assertSame(
            $recordedAt->getTimestamp(),
            $second->consumption_recorded_at->getTimestamp(),
            'A replay does not restamp the confirmation.',
        );
    }

    public function test_the_same_idempotency_key_with_different_values_is_a_conflict(): void
    {
        [, , $allocationA] = $this->startedBatch($this->pilotExtraData(), 'WO-RQ265-SETTLE-A');
        [, , $allocationB] = $this->startedBatch($this->pilotExtraData(), 'WO-RQ265-SETTLE-B');

        $service = app(MaterialAllocationService::class);
        $service->recordConsumption($allocationA, 8.0, 0.0, null, $this->operator, 'operator', 'confirm-B');

        $differentValues = null;
        try {
            $service->recordConsumption($allocationA->fresh(), 7.0, 0.0, null, $this->operator, 'operator', 'confirm-B');
        } catch (\DomainException $e) {
            $differentValues = $e;
        }

        $this->assertNotNull($differentValues, 'A reused key with other values must be refused.');
        $this->assertStringContainsString('material_consumption_key_conflict', $differentValues->getMessage());

        $otherRow = null;
        try {
            $service->recordConsumption($allocationB, 8.0, 0.0, null, $this->operator, 'operator', 'confirm-B');
        } catch (\DomainException $e) {
            $otherRow = $e;
        }

        $this->assertNotNull($otherRow, 'A key already used on another allocation must be refused.');
        $this->assertStringContainsString('material_consumption_key_conflict', $otherRow->getMessage());

        // Neither refusal changed anything: the first confirmation stands.
        $fresh = $allocationA->fresh();
        $this->assertEqualsWithDelta(8.0, (float) $fresh->consumed_qty, 0.0001);
        $this->assertSame(MaterialAllocation::STATUS_ALLOCATED, $fresh->status);
        $this->assertFalse((bool) $allocationB->fresh()->consumption_recorded);
    }

    // ── 8. a pilot confirmation without a person is not a document ───────────

    public function test_a_pilot_confirmation_without_an_actor_is_refused(): void
    {
        [, , $allocation] = $this->startedBatch($this->pilotExtraData());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('A pilot consumption needs the operator who declared it.');

        app(MaterialAllocationService::class)->recordConsumption($allocation, 8.0, 2.0, null, null, 'operator');
    }

    // ── 9. over-confirmation is unplanned consumption ────────────────────────

    public function test_an_over_confirmation_posts_the_excess_as_consumption(): void
    {
        [, $batch, $allocation] = $this->startedBatch($this->pilotExtraData());
        $this->assertStock(90.0);

        app(MaterialAllocationService::class)->recordConsumption($allocation, 12.0, 0.0, null, $this->operator);

        $result = app(MaterialAllocationService::class)->consumeForBatch($batch);

        // 10 were withdrawn at allocation; the operator declared 12 used, so the
        // extra 2 must leave stock as unplanned consumption or the plant-wide
        // balance would claim material that is physically gone.
        $this->assertStock(88.0);
        $this->assertSame(1, $this->movementCount(StockMovement::TYPE_CONSUME));

        $excess = StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_CONSUME)
            ->sole();
        $this->assertEqualsWithDelta(-2.0, (float) $excess->quantity, 0.0001);
        $this->assertSame(StockMovement::SOURCE_BATCH, $excess->source_type);
        $this->assertSame($batch->id, (int) $excess->source_id);

        $this->assertSame(1, $result['settled']);
        $this->assertCount(1, $result['unplanned']);
        $this->assertSame($allocation->id, $result['unplanned'][0]['allocation_id']);
        $this->assertSame($this->material->id, $result['unplanned'][0]['material_id']);
        $this->assertEqualsWithDelta(2.0, (float) $result['unplanned'][0]['quantity'], 0.0001);

        // Reservation was only ever 10, so only 10 is given back.
        $this->assertEqualsWithDelta(0.0, (float) $this->material->fresh()->reserved_quantity, 0.0001);
    }

    public function test_a_legacy_allocation_is_not_touched_by_unplanned_posting(): void
    {
        [, $batch, $allocation] = $this->startedBatch();

        app(MaterialAllocationService::class)->recordConsumption($allocation, 12.0, 0.0, null, $this->operator);

        $result = app(MaterialAllocationService::class)->consumeForBatch($batch);

        // Pre-existing behaviour, deliberately unchanged: without the opt-in the
        // over-declaration books nothing extra.
        $this->assertStock(90.0);
        $this->assertSame(0, $this->movementCount(StockMovement::TYPE_CONSUME));
        $this->assertSame([], $result['unplanned']);
        $this->assertSame(1, $result['settled']);
    }

    // ── 10. a scrapped end product does not re-write what its operation consumed ─

    public function test_full_product_scrap_after_a_confirmed_retrieval_still_consumes_ten(): void
    {
        [$workOrder, $batch, $allocation] = $this->startedBatch($this->pilotExtraData());

        app(MaterialAllocationService::class)->recordConsumption($allocation, 10.0, 0.0, null, $this->operator);
        $movementsAfterConfirmation = $this->movementIds();

        // The piece cut from the retrieved material fails inspection afterwards.
        $unit = SerialUnit::factory()->create([
            'work_order_id' => $workOrder->id,
            'batch_id' => $batch->id,
            'status' => SerialUnit::STATUS_COMPLETED,
            'produced_at' => now(),
            'packed_at' => now(),
        ]);
        app(SerialTraceService::class)->scrapUnit($unit, $this->operator, 'test scrap');

        $result = app(MaterialAllocationService::class)->consumeForBatch($batch);

        $fresh = $allocation->fresh();
        $this->assertSame(MaterialAllocation::STATUS_CONSUMED, $fresh->status);
        $this->assertEqualsWithDelta(10.0, (float) $fresh->consumed_qty, 0.0001);
        $this->assertSame(1, $result['settled']);
        $this->assertSame([], $result['pending']);
        $this->assertSame([], $result['unplanned']);

        // The material was consumed by the operation that cut it, not by the
        // scrapping of the piece: the balance and the ledger stay exactly put.
        $this->assertStock(90.0);
        $this->assertSame($movementsAfterConfirmation, $this->movementIds());
        $this->assertCount(1, $this->movementIds(), 'Only the withdrawal itself is on the material ledger.');
        $this->assertSame(0, $this->movementCount(
            StockMovement::TYPE_SCRAP,
            StockMovement::TYPE_CONSUME,
            StockMovement::TYPE_RETURN,
        ));
        $this->assertEqualsWithDelta(0.0, (float) $this->material->fresh()->reserved_quantity, 0.0001);
    }

    // ── 11. a scrap cannot stand in for the missing retrieval ────────────────

    public function test_scrap_before_any_confirmation_leaves_the_material_pending(): void
    {
        [$workOrder, $batch, $allocation] = $this->startedBatch($this->pilotExtraData());
        $this->assertStock(90.0);
        $movementsAfterWithdrawal = $this->movementIds();

        $unit = SerialUnit::factory()->create([
            'work_order_id' => $workOrder->id,
            'batch_id' => $batch->id,
            'status' => SerialUnit::STATUS_COMPLETED,
            'produced_at' => now(),
        ]);
        app(SerialTraceService::class)->scrapUnit($unit, $this->operator, 'test scrap');

        $result = app(MaterialAllocationService::class)->consumeForBatch($batch);

        $fresh = $allocation->fresh();
        $this->assertSame(MaterialAllocation::STATUS_ALLOCATED, $fresh->status);
        $this->assertSame(MaterialSettlementPolicy::STATE_PENDING, $fresh->settlement_state);
        $this->assertSame(MaterialSettlementPolicy::REASON_CONSUMPTION_NOT_CONFIRMED, $fresh->settlement_pending_reason);

        $this->assertSame(0, $result['settled']);
        $this->assertCount(1, $result['pending']);
        $this->assertSame(MaterialSettlementPolicy::REASON_CONSUMPTION_NOT_CONFIRMED, $result['pending'][0]['reason']);
        $this->assertSame([], $result['unplanned']);

        // A scrapped product is a quality event, not a retrieval: the withdrawal
        // still stands, its reservation is still held, and nothing else is booked.
        $this->assertStock(90.0);
        $this->assertEqualsWithDelta(10.0, (float) $this->material->fresh()->reserved_quantity, 0.0001);
        $this->assertSame($movementsAfterWithdrawal, $this->movementIds());
        $this->assertSame(0, $this->movementCount(
            StockMovement::TYPE_SCRAP,
            StockMovement::TYPE_CONSUME,
            StockMovement::TYPE_RETURN,
        ));
    }

    // ── 12. pallets are not a material author ────────────────────────────────

    public function test_pallets_do_not_change_the_operation_result(): void
    {
        // The retired trigger stays stored as enabled, so a regression would have
        // something to fire on.
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'backflush_on_pallet_creation'],
            ['value' => json_encode(true)],
        );

        $control = $this->settledPilotRun('CTRL', false);
        $withPallets = $this->settledPilotRun('PALLET', true);

        // 8 good + 2 scrapped out of the 10 withdrawn: the net is 10, both ways.
        foreach (['control' => $control, 'with pallets' => $withPallets] as $label => $run) {
            $this->assertEqualsWithDelta(90.0, $run['stock'], 0.0001, "Material balance ({$label}).");
            $this->assertEqualsWithDelta(8.0, $run['consumed'], 0.0001, "Consumed ({$label}).");
            $this->assertEqualsWithDelta(2.0, $run['scrap'], 0.0001, "Scrapped ({$label}).");
            $this->assertSame(1, $run['settled'], "Settled allocations ({$label}).");
            $this->assertSame(0, $run['pending'], "Pending allocations ({$label}).");
        }

        // The ledger has the same shape in both runs: the pallet churn added no row.
        $this->assertSame($control['signature'], $withPallets['signature']);
        $this->assertSame([
            StockMovement::TYPE_ALLOCATION.'/'.StockMovement::SOURCE_BATCH => 1,
            StockMovement::TYPE_SCRAP.'/'.StockMovement::SOURCE_BATCH => 1,
        ], $control['signature'], 'One withdrawal and one scrap-status row, nothing else.');
        $this->assertSame(0, StockMovement::query()->where('source_type', StockMovement::SOURCE_PALLET)->count());
    }

    // ── 13. packaging keeps its own channel ──────────────────────────────────

    public function test_packaging_materials_keep_their_own_booking(): void
    {
        $carton = Material::factory()->create([
            'code' => 'KARTON-RQ265',
            'unit_of_measure' => 'szt',
            'tracking_type' => 'none',
            'external_system' => null,
            'stock_quantity' => 50,
        ]);

        $packStep = TemplateStep::create([
            'process_template_id' => $this->template->id,
            'step_number' => 2,
            'name' => 'Packing',
            'kind' => TemplateStep::KIND_PACKING,
            'config' => ['unit' => 'carton', 'carton_capacity' => 1, 'pallet_capacity' => 0],
        ]);

        BomItem::create([
            'process_template_id' => $this->template->id,
            'template_step_id' => $packStep->id,
            'material_id' => $carton->id,
            'quantity_per_unit' => 1,
            'per' => BomItem::PER_CARTON,
            'scrap_percentage' => 0,
            'consumed_at' => 'during',
        ]);

        [$workOrder, $batch, $allocation] = $this->startedBatch($this->pilotExtraData());

        $cutStep = $batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->completeStep($cutStep->fresh(), $this->operator);

        // Cutting is done and the pilot line is still waiting for its operation:
        // the packaging channel may not settle it on the operation's behalf.
        $this->assertStock(90.0);
        $formatkaMovements = $this->movementIds();

        $unit = SerialUnit::factory()->create([
            'work_order_id' => $workOrder->id,
            'batch_id' => $batch->id,
            'status' => SerialUnit::STATUS_IN_PRODUCTION,
        ]);

        $packing = app(UnitPackingService::class);
        $box = $packing->openCarton($this->operator, $workOrder);
        $packing->packUnit($unit, $this->operator, $box);
        $packing->closeCarton($box, $this->operator);

        $cartonAllocation = MaterialAllocation::query()
            ->where('batch_id', $batch->id)
            ->where('material_id', $carton->id)
            ->firstOrFail();

        // The carton is booked by the channel that always booked it...
        $this->assertTrue((bool) $cartonAllocation->consumption_recorded);
        $this->assertEqualsWithDelta(1.0, (float) $cartonAllocation->consumed_qty, 0.0001);
        $this->assertSame(MaterialAllocationService::SOURCE_SYSTEM, $cartonAllocation->consumption_source);

        // ...while the per-unit component of the operation was not touched by it.
        $this->assertStock(90.0);
        $this->assertSame($formatkaMovements, $this->movementIds());
        $this->assertSame(MaterialSettlementPolicy::STATE_PENDING, $allocation->fresh()->settlement_state);

        // A pallet transition is not a second packaging booking either.
        $palletId = $this->actingAs($this->operator)
            ->postJson(route('packaging.pallets.create'), [
                'work_order_id' => $workOrder->id,
                'kind' => Pallet::KIND_LOOSE,
                'produced_qty' => 1,
            ])
            ->assertCreated()
            ->json('pallet.id');

        $this->actingAs($this->operator)
            ->postJson(route('packaging.pallets.close', $palletId))
            ->assertOk();

        $this->assertEqualsWithDelta(1.0, (float) $cartonAllocation->fresh()->consumed_qty, 0.0001);
        $this->assertSame($formatkaMovements, $this->movementIds());
    }

    // ── 14. a repeated settlement event books once ───────────────────────────

    public function test_a_repeated_settlement_event_is_booked_once(): void
    {
        [, $batch, $allocation] = $this->startedBatch($this->pilotExtraData());

        app(MaterialAllocationService::class)->recordConsumption($allocation, 8.0, 2.0, null, $this->operator);

        $first = app(MaterialAllocationService::class)->consumeForBatch($batch);
        $movementsAfterFirst = $this->movementIds();
        $settledAt = $allocation->fresh()->consumed_at;

        $second = app(MaterialAllocationService::class)->consumeForBatch($batch);

        $this->assertSame(1, $first['settled']);
        $this->assertSame(0, $second['settled'], 'The repeated event finds nothing left to settle.');
        $this->assertSame([], $second['pending']);
        $this->assertSame([], $second['unplanned']);

        $fresh = $allocation->fresh();
        $this->assertSame(MaterialAllocation::STATUS_CONSUMED, $fresh->status);
        $this->assertEqualsWithDelta(8.0, (float) $fresh->consumed_qty, 0.0001);
        $this->assertStock(90.0);
        $this->assertEqualsWithDelta(0.0, (float) $this->material->fresh()->reserved_quantity, 0.0001);
        $this->assertSame($movementsAfterFirst, $this->movementIds(), 'A repeated settlement event adds no row.');
        $this->assertNotNull($settledAt);
        $this->assertSame($settledAt->getTimestamp(), $fresh->consumed_at->getTimestamp(), 'A repeated event does not re-stamp the settlement.');
    }

    // ── 15. finished-goods documents keep their own ledger ───────────────────

    public function test_finished_goods_documents_are_unchanged_by_the_pilot(): void
    {
        $finishedGoods = $this->postModeFinishedGoods();
        [$workOrder, $pallet] = $this->palletWithUnits(3);

        $movementsBeforeClosing = $this->movementIds();

        // The transition that books the receipt today: the pallet's own status
        // change (Pallet::saved -> PalletStockDocumentService).
        $pallet->update(['status' => PalletStatus::Closed->value]);

        $receipt = StockDocument::query()
            ->where('pallet_id', $pallet->id)
            ->where('type', StockDocument::TYPE_PRODUCT_RECEIPT)
            ->sole();

        $this->assertSame(StockDocument::STATUS_POSTED, $receipt->status);
        $this->assertEqualsWithDelta(3.0, (float) $receipt->lines()->sum('quantity'), 0.0001);
        $this->assertEqualsWithDelta(3.0, (float) app(WarehouseStockService::class)->available([
            'warehouse_id' => $finishedGoods->id,
            'product_type_id' => $workOrder->product_type_id,
        ]), 0.0001);

        // The receipt is a finished-goods event, not a material one: the pilot
        // line still waits for the operation that owes its confirmation.
        $this->assertSame($movementsBeforeClosing, $this->movementIds());
        $this->assertStock(90.0);
        $this->assertSame(0, StockMovement::query()->where('source_type', StockMovement::SOURCE_PALLET)->count());
        $line = MaterialAllocation::query()
            ->where('batch_id', $pallet->batch_id)
            ->where('material_id', $this->material->id)
            ->sole();

        $this->assertSame(
            MaterialSettlementPolicy::STATE_OPEN,
            $line->settlement_state,
            'Receiving finished goods neither confirms nor settles the operation line.',
        );
        $this->assertSame(MaterialAllocation::STATUS_ALLOCATED, $line->status);
        $this->assertNull($line->consumed_at, 'The declared retrieval is not a settlement.');
        $this->assertEqualsWithDelta(8.0, (float) $line->consumed_qty, 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $line->scrap_qty, 0.0001);
    }

    /**
     * Found by A4, recorded rather than fixed: the station's own close endpoint
     * updates the pallet through a query builder (`Pallet::whereKey(...)->update`),
     * so `Pallet::saved` never fires and no finished-goods document is booked —
     * whatever `pallet_stock_documents` says. Whether the endpoint should book it
     * there is a decision of its own, not part of the pilot.
     */
    public function test_closing_a_pallet_from_the_station_books_no_finished_goods_document_today(): void
    {
        $this->postModeFinishedGoods();
        [, $pallet] = $this->palletWithUnits(3);

        $movementsBeforeClosing = $this->movementIds();

        $this->actingAs($this->operator)
            ->postJson(route('packaging.pallets.close', $pallet->id))
            ->assertOk();

        $this->assertSame(PalletStatus::Closed, $pallet->fresh()->status);
        $this->assertSame(
            0,
            StockDocument::query()->where('pallet_id', $pallet->id)->count(),
            'DOCUMENTED PRESENT-DAY GAP: the close endpoint never reaches PalletStockDocumentService, so no FG document is booked.',
        );
        $this->assertSame($movementsBeforeClosing, $this->movementIds());
        $this->assertSame(0, StockMovement::query()->where('source_type', StockMovement::SOURCE_PALLET)->count());
    }
}
