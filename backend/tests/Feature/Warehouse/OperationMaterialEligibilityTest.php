<?php

namespace Tests\Feature\Warehouse;

use App\Models\Batch;
use App\Models\BomItem;
use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\Pallet;
use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\SerialUnit;
use App\Models\StockMovement;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Services\Material\MaterialAllocationService;
use App\Services\Packaging\UnitPackingService;
use App\Services\Traceability\SerialTraceService;
use App\Services\Warehouse\StockDocumentService;
use App\Services\Warehouse\WorkOrderStockDocumentService;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RQ-PA-265 A1 — qualification of the raw-material withdrawal source, proven on
 * one pilot fixture only (`WO-RQ265-PILOT`, `FORMATKA-18`, steps 1/2).
 *
 * The governing rule under test: the raw material is settled exactly once, by
 * the operation that consumes it. A pallet never withdraws raw material. This
 * file changes no production code; each case asserts on balances and ledger
 * rows, not merely on statuses, so "who books the material" is a fact and not
 * an interpretation. Where today's behaviour is wrong-but-documented (silent
 * plan standing in for a missing retrieval, the material-issue author collision)
 * the case records the state instead of fixing it — A2/A3 own those changes.
 */
class OperationMaterialEligibilityTest extends TestCase
{
    use RefreshDatabase;

    /** The pilot component: counted in pieces, untracked by lot or serial. */
    private Material $material;

    private ProductType $product;

    private ProcessTemplate $template;

    private TemplateStep $cutStep;

    private TemplateStep $packStep;

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

        // 1 — cutting (CUT) owns the raw material; 2 — packing closes the
        // cartons/pallets and never settles raw material.
        $this->cutStep = TemplateStep::create([
            'process_template_id' => $this->template->id,
            'step_number' => 1,
            'name' => 'Cutting (CUT)',
            'kind' => TemplateStep::KIND_PRODUCTION,
        ]);
        $this->packStep = TemplateStep::create([
            'process_template_id' => $this->template->id,
            'step_number' => 2,
            'name' => 'Packing',
            'kind' => TemplateStep::KIND_PACKING,
            'config' => ['unit' => 'carton', 'carton_capacity' => 1, 'pallet_capacity' => 0],
        ]);

        // The per-unit component, frozen into the snapshot at release time and
        // also present on the template so the material-issue document can read it.
        BomItem::create([
            'process_template_id' => $this->template->id,
            'template_step_id' => $this->cutStep->id,
            'material_id' => $this->material->id,
            'quantity_per_unit' => 1,
            'scrap_percentage' => 0,
            'consumed_at' => 'start',
        ]);
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    private function pilotWorkOrder(float $planned = 10): WorkOrder
    {
        return WorkOrder::factory()->create([
            'order_no' => 'WO-RQ265-PILOT',
            'product_type_id' => $this->product->id,
            'process_snapshot' => $this->template->fresh()->load(['steps', 'bomItems'])->toSnapshot(),
            'planned_qty' => $planned,
            'produced_qty' => 0,
            'status' => WorkOrder::STATUS_PENDING,
        ]);
    }

    /** @return array{0: WorkOrder, 1: Batch} */
    private function pilotBatch(): array
    {
        $workOrder = $this->pilotWorkOrder();
        $batch = app(WorkOrderService::class)->createBatch($workOrder, 10);

        return [$workOrder, $batch];
    }

    /** Start the cutting step: this is the moment the component is withdrawn. */
    private function startCutting(Batch $batch): self
    {
        $step = $batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($step, $this->operator);

        return $this;
    }

    private function allocation(Batch $batch): MaterialAllocation
    {
        return MaterialAllocation::query()
            ->where('batch_id', $batch->id)
            ->where('material_id', $this->material->id)
            ->firstOrFail();
    }

    private function stock(): float
    {
        return (float) $this->material->fresh()->stock_quantity;
    }

    /** @return array<int, int> */
    private function materialMovementIds(): array
    {
        return StockMovement::query()
            ->where('material_id', $this->material->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    private function assertStock(float $expected): void
    {
        $this->assertEqualsWithDelta($expected, $this->stock(), 0.0001);
    }

    // ── 1. the operation owns the withdrawal ─────────────────────────────────

    public function test_the_operation_owns_the_global_withdrawal_and_books_it_once(): void
    {
        [, $batch] = $this->pilotBatch();
        $cutStep = $batch->steps()->where('step_number', 1)->firstOrFail();

        $this->startCutting($batch);

        $allocations = MaterialAllocation::query()
            ->where('batch_id', $batch->id)
            ->where('material_id', $this->material->id)
            ->get();

        $this->assertCount(1, $allocations, 'One material, one allocation row for the batch.');
        $allocation = $allocations->first();
        // The allocation names the operation, not the batch: that is what makes
        // the consuming step the owner of the withdrawal.
        $this->assertSame($cutStep->id, (int) $allocation->batch_step_id);
        $this->assertEqualsWithDelta(10.0, (float) $allocation->allocated_qty, 0.0001);
        $this->assertFalse((bool) $allocation->consumption_recorded);

        $this->assertStock(90.0);

        $allocationsLedger = StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_ALLOCATION)
            ->get();

        $this->assertCount(1, $allocationsLedger, 'The withdrawal is booked exactly once.');
        $this->assertEqualsWithDelta(-10.0, (float) $allocationsLedger->first()->quantity, 0.0001);
        $this->assertContains($allocationsLedger->first()->source_type, [
            StockMovement::SOURCE_BATCH,
            StockMovement::SOURCE_BATCH_STEP,
        ]);

        // No pallet ever withdraws raw material.
        $this->assertSame(0, StockMovement::where('source_type', StockMovement::SOURCE_PALLET)->count());
    }

    // ── 2. the declared retrieval is the quantity source ─────────────────────

    public function test_the_declared_retrieval_is_the_quantity_source_not_the_plan(): void
    {
        [, $batch] = $this->pilotBatch();
        $this->startCutting($batch);
        $allocation = $this->allocation($batch);

        $movementsBefore = $this->materialMovementIds();

        app(MaterialAllocationService::class)->recordConsumption($allocation, 7.0, 1.0, null, $this->operator);

        $fresh = $allocation->fresh();
        $this->assertTrue((bool) $fresh->consumption_recorded);
        $this->assertEqualsWithDelta(7.0, (float) $fresh->consumed_qty, 0.0001);
        $this->assertEqualsWithDelta(1.0, (float) $fresh->scrap_qty, 0.0001);
        // The retrieval is expressed in the material's own unit.
        $this->assertSame('szt', $fresh->material->unit_of_measure);

        // Declaring what was taken does not move global stock a second time.
        $this->assertStock(90.0);
        $this->assertSame($movementsBefore, $this->materialMovementIds());

        // 8 declared against a plan of 10: the quantity cannot come from
        // target_qty or allocated_qty, only from the operator's retrieval.
        $this->assertEqualsWithDelta(8.0, (float) $fresh->consumed_qty + (float) $fresh->scrap_qty, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $fresh->allocated_qty, 0.0001);
    }

    // ── 3. confirmation: 10 = 8 good + 2 scrapped, no second write-off ───────

    public function test_a_confirmed_ten_of_eight_good_and_two_scrapped_nets_ten(): void
    {
        [, $batch] = $this->pilotBatch();
        $this->startCutting($batch);

        $allocation = $this->allocation($batch);
        $this->assertStock(90.0);

        app(MaterialAllocationService::class)->recordConsumption($allocation, 8.0, 2.0, null, $this->operator);
        app(MaterialAllocationService::class)->consumeForBatch($batch);

        // 8 used + 2 scrapped = the whole 10 that was already taken at
        // allocation, so completion returns nothing and withdraws nothing.
        $this->assertStock(90.0);

        $fresh = $allocation->fresh();
        $this->assertSame(MaterialAllocation::STATUS_CONSUMED, $fresh->status);
        $this->assertEqualsWithDelta(8.0, (float) $fresh->consumed_qty, 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $fresh->scrap_qty, 0.0001);

        $this->assertSame(0, StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_CONSUME)
            ->count(), 'Settling the batch must not consume the material a second time.');

        $this->assertSame(0, StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_RETURN)
            ->count(), 'Nothing was left over, so nothing is returned.');
    }

    // ── 4. a late scrap books no material ────────────────────────────────────

    public function test_a_late_scrap_of_an_already_settled_unit_books_no_material(): void
    {
        [$workOrder, $batch] = $this->pilotBatch();
        $this->startCutting($batch);

        $allocation = $this->allocation($batch);
        app(MaterialAllocationService::class)->recordConsumption($allocation, 8.0, 2.0, null, $this->operator);
        app(MaterialAllocationService::class)->consumeForBatch($batch);

        $stockAfterSettlement = $this->stock();
        $movementsAfterSettlement = $this->materialMovementIds();

        $unit = SerialUnit::factory()->create([
            'work_order_id' => $workOrder->id,
            'batch_id' => $batch->id,
            'status' => SerialUnit::STATUS_COMPLETED,
            'produced_at' => now(),
            'packed_at' => now(),
        ]);

        app(SerialTraceService::class)->scrapUnit($unit, $this->operator, 'test scrap');

        $this->assertSame(SerialUnit::STATUS_SCRAPPED, $unit->fresh()->status);
        // Scrapping the piece is a quality event: the raw material it was made
        // from left stock when the operation consumed it, not now.
        $this->assertEqualsWithDelta($stockAfterSettlement, $this->stock(), 0.0001);
        $this->assertSame($movementsAfterSettlement, $this->materialMovementIds());
    }

    // ── 5. a confirmed replacement adds exactly one ──────────────────────────

    public function test_a_confirmed_replacement_retrieval_adds_exactly_one(): void
    {
        [, $batch] = $this->pilotBatch();
        $this->startCutting($batch);
        $allocation = $this->allocation($batch);

        app(MaterialAllocationService::class)->adjustAllocation(
            $allocation,
            1.0,
            $this->operator,
            'replacement',
        );

        $this->assertStock(89.0);
        $this->assertEqualsWithDelta(11.0, (float) $allocation->fresh()->allocated_qty, 0.0001);

        app(MaterialAllocationService::class)->recordConsumption($allocation->fresh(), 11.0, 0, null, $this->operator);
        app(MaterialAllocationService::class)->consumeForBatch($batch);

        // The extra piece was already booked by the adjustment: settling the
        // batch must not deduct it again.
        $this->assertStock(89.0);
        $this->assertEqualsWithDelta(11.0, (float) $allocation->fresh()->consumed_qty, 0.0001);
        $this->assertEqualsWithDelta(11.0, (float) $allocation->fresh()->allocated_qty, 0.0001);
    }

    // ── 6. pallets never move raw material ───────────────────────────────────

    public function test_pallets_never_move_the_raw_material(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'backflush_on_pallet_creation'],
            ['value' => json_encode(true)],
        );

        [$workOrder] = $this->pilotBatch();

        $assertUntouched = function (string $moment): void {
            $this->assertStock(100.0);
            $this->assertSame(0, StockMovement::where('material_id', $this->material->id)->count(), "No raw-material movement after: {$moment}.");
            $this->assertSame(0, StockMovement::where('source_type', StockMovement::SOURCE_PALLET)->count(), "No pallet-sourced movement after: {$moment}.");
        };

        $firstId = $this->actingAs($this->operator)
            ->postJson(route('packaging.pallets.create'), [
                'work_order_id' => $workOrder->id,
                'kind' => Pallet::KIND_LOOSE,
                'produced_qty' => 5,
            ])
            ->assertCreated()
            ->json('pallet.id');
        $assertUntouched('creating the first pallet');

        $this->actingAs($this->operator)
            ->postJson(route('packaging.pallets.close', $firstId))
            ->assertOk();
        $assertUntouched('closing the first pallet');

        // Closing twice is refused, and still books nothing.
        $this->actingAs($this->operator)
            ->postJson(route('packaging.pallets.close', $firstId))
            ->assertStatus(422);
        $assertUntouched('the refused second close');

        $secondId = $this->actingAs($this->operator)
            ->postJson(route('packaging.pallets.create'), [
                'work_order_id' => $workOrder->id,
                'kind' => Pallet::KIND_LOOSE,
                'produced_qty' => 5,
            ])
            ->assertCreated()
            ->json('pallet.id');
        $this->actingAs($this->operator)
            ->postJson(route('packaging.pallets.close', $secondId))
            ->assertOk();
        $assertUntouched('creating and closing the second pallet');

        // Soft-deleting the closed pallet is not a material event either.
        Pallet::findOrFail($firstId)->delete();
        $this->assertTrue(Pallet::withTrashed()->findOrFail($firstId)->trashed());
        $assertUntouched('soft-deleting the first pallet');
    }

    // ── 7. today a reservation without confirmation counts as use ────────────

    public function test_a_reservation_without_confirmation_is_today_silently_treated_as_use(): void
    {
        [, $batch] = $this->pilotBatch();
        $this->startCutting($batch);
        $allocation = $this->allocation($batch);

        $this->assertFalse((bool) $allocation->consumption_recorded);

        app(MaterialAllocationService::class)->consumeForBatch($batch);

        $fresh = $allocation->fresh();
        // DOCUMENTED PRESENT-DAY BEHAVIOUR (RQ-PA-265 A3 is the owner of the
        // change): with no retrieval declared, the plan is written in as if it
        // had been — allocated_qty becomes consumed_qty and the allocation is
        // marked consumed. There is no identifier for a missing retrieval and no
        // `pending` state, so nothing distinguishes "10 confirmed" from "nobody
        // said anything". This test pins that state; it does not endorse it.
        $this->assertSame(MaterialAllocation::STATUS_CONSUMED, $fresh->status);
        $this->assertEqualsWithDelta(10.0, (float) $fresh->consumed_qty, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $fresh->allocated_qty, 0.0001);
        $this->assertStock(90.0);
    }

    // ── 8. one material, one operation per batch (schema) ────────────────────

    public function test_a_material_cannot_have_two_operations_in_one_batch(): void
    {
        [, $batch] = $this->pilotBatch();
        $this->startCutting($batch);

        // The unique index on (batch_id, material_id) refuses a second row, so a
        // batch cannot name two operations as the owner of the same material.
        $this->expectException(QueryException::class);

        MaterialAllocation::create([
            'batch_id' => $batch->id,
            'batch_step_id' => $batch->steps()->where('step_number', 2)->value('id'),
            'material_id' => $this->material->id,
            'work_order_id' => $batch->work_order_id,
            'allocated_qty' => 1,
            'expected_qty' => 1,
            'status' => MaterialAllocation::STATUS_ALLOCATED,
            'allocated_by' => $this->operator->id,
            'allocated_at' => now(),
        ]);
    }

    // ── 9. the packing step does not consume a per-unit component ────────────

    public function test_the_packing_step_does_not_consume_a_per_unit_component(): void
    {
        $carton = Material::factory()->create([
            'code' => 'KARTON-RQ265',
            'unit_of_measure' => 'szt',
            'tracking_type' => 'none',
            'stock_quantity' => 50,
        ]);

        BomItem::create([
            'process_template_id' => $this->template->id,
            'template_step_id' => $this->packStep->id,
            'material_id' => $carton->id,
            'quantity_per_unit' => 1,
            'per' => BomItem::PER_CARTON,
            'scrap_percentage' => 0,
            'consumed_at' => 'during',
        ]);

        [$workOrder, $batch] = $this->pilotBatch();

        $step1 = $batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($step1, $this->operator);
        app(BatchService::class)->completeStep($step1->fresh(), $this->operator);

        $stockBeforePacking = $this->stock();
        $movementsBeforePacking = $this->materialMovementIds();
        $this->assertStock(90.0);

        // The packing channel runs for real: a unit goes into a carton and the
        // carton closes, so its per-carton line is booked.
        $unit = SerialUnit::factory()->create([
            'work_order_id' => $workOrder->id,
            'batch_id' => $batch->id,
            'status' => SerialUnit::STATUS_IN_PRODUCTION,
        ]);

        $packing = app(UnitPackingService::class);
        $cartonBox = $packing->openCarton($this->operator, $workOrder);
        $packing->packUnit($unit, $this->operator, $cartonBox);
        $packing->closeCarton($cartonBox, $this->operator);

        // The channel demonstrably worked for the carton...
        $cartonAllocation = MaterialAllocation::query()
            ->where('batch_id', $batch->id)
            ->where('material_id', $carton->id)
            ->firstOrFail();
        $this->assertTrue((bool) $cartonAllocation->consumption_recorded);
        $this->assertEqualsWithDelta(1.0, (float) $cartonAllocation->consumed_qty, 0.0001);

        // ...and the per-unit component was not touched by it.
        $this->assertEqualsWithDelta($stockBeforePacking, $this->stock(), 0.0001);
        $this->assertSame($movementsBeforePacking, $this->materialMovementIds());
    }

    // ── 10. a posted material issue is a second author ───────────────────────

    public function test_a_posted_material_issue_is_a_second_author_for_the_same_material(): void
    {
        Warehouse::factory()->rawMaterial()->isDefault()->create(['code' => 'RAW-RQ265']);

        [$workOrder, $batch] = $this->pilotBatch();
        $this->startCutting($batch);

        // The operation already withdrew the 10 pieces.
        $this->assertStock(90.0);

        $document = app(WorkOrderStockDocumentService::class)->generateMaterialIssue($workOrder);
        $this->assertNotNull($document);
        $this->assertTrue($document->isDraft());

        $line = $document->lines()->firstOrFail();
        $this->assertSame($this->material->id, (int) $line->material_id);
        $this->assertEqualsWithDelta(10.0, (float) $line->quantity, 0.0001);

        // OPEN IN A2: a posted material issue writes the same material off a
        // second time. This is the author collision A2 has to exclude — the
        // operation already settled it, so the warehouse document must not.
        app(StockDocumentService::class)->post($document, $this->operator);

        $this->assertStock(80.0);
        $this->assertSame(1, StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_CONSUME)
            ->where('source_type', StockMovement::SOURCE_STOCK_DOCUMENT)
            ->count());
    }

    // ── 11. the isolated PostgreSQL run really uses PostgreSQL ───────────────

    public function test_the_isolated_postgres_driver_is_actually_used(): void
    {
        if (getenv('EXPECT_PGSQL_DRIVER') !== '1') {
            $this->markTestSkipped('SQLite run: the isolated PostgreSQL driver check belongs to phpunit.backflush.pgsql.xml.');
        }

        $this->assertSame('pgsql', DB::connection()->getDriverName());
    }
}
