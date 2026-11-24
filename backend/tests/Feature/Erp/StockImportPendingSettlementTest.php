<?php

namespace Tests\Feature\Erp;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\ProductType;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WorkOrder;
use App\Services\Erp\StockImportService;
use App\Services\Material\MaterialSettlementPolicy;
use App\Services\Material\MaterialSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F1-5 — the ERP stock snapshot may not overwrite a balance whose production
 * operation still owes its settlement: one item, one author.
 *
 * The material importer already refuses such a row; the balance importer has to
 * say the same thing, or a routine sync would silently undo the quantity the
 * operation withdrew and hide the discrepancy from the person syncing.
 */
class StockImportPendingSettlementTest extends TestCase
{
    use RefreshDatabase;

    /** The refusal, word for word — shared with MaterialSyncService. */
    private const PENDING_MESSAGE = 'Material FORMATKA-F15 is settled by its production operation; its stock cannot be overwritten while the settlement is pending.';

    private Warehouse $warehouse;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        // Mixed, so one store can hold both the component and the finished item.
        $this->warehouse = Warehouse::factory()->create(['code' => 'WH-F15']);

        $this->material = Material::factory()->create([
            'code' => 'FORMATKA-F15',
            'name' => 'Formatka 18 mm (F1-5)',
            'unit_of_measure' => 'szt',
            'stock_quantity' => 100,
        ]);

        WarehouseStock::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'material_lot_id' => null,
            'quantity' => 100,
        ]);
    }

    /**
     * The pilot scope on a work order, and an allocation its operation withdrew
     * but never confirmed — the exact shape the policy reports as pending.
     */
    private function pendingPilotAllocation(): MaterialAllocation
    {
        $workOrder = WorkOrder::factory()->create([
            'extra_data' => [
                MaterialSettlementPolicy::EXTRA_DATA_KEY => [
                    'mode' => MaterialSettlementPolicy::MODE_OPERATION,
                    'materials' => [$this->material->id],
                ],
            ],
        ]);

        $batch = Batch::factory()->create(['work_order_id' => $workOrder->id]);
        $step = BatchStep::factory()->create(['batch_id' => $batch->id]);

        return MaterialAllocation::factory()->create([
            'batch_id' => $batch->id,
            'batch_step_id' => $step->id,
            'work_order_id' => $workOrder->id,
            'material_id' => $this->material->id,
            'allocated_qty' => 10,
            'consumed_qty' => 0,
            'consumption_recorded' => false,
            'status' => MaterialAllocation::STATUS_ALLOCATED,
        ]);
    }

    /** @return array{imported: int, updated: int, skipped: int, errors: array<int, array<string, mixed>>} */
    private function import(string $materialCode, float $quantity): array
    {
        return app(StockImportService::class)->import([
            ['material_code' => $materialCode, 'quantity' => $quantity],
        ], $this->warehouse->code);
    }

    private function balance(?Material $material = null): float
    {
        return (float) WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('material_id', ($material ?? $this->material)->id)
            ->whereNull('material_lot_id')
            ->value('quantity');
    }

    // ── 1. the guard ─────────────────────────────────────────────────────────

    public function test_a_balance_awaiting_settlement_is_not_overwritten(): void
    {
        $allocation = $this->pendingPilotAllocation();

        $this->assertSame(
            MaterialSettlementPolicy::REASON_CONSUMPTION_NOT_CONFIRMED,
            $allocation->fresh()->settlement_pending_reason,
            'The fixture really is a pending settlement.',
        );

        $result = $this->import('FORMATKA-F15', 340.25);

        $this->assertSame(0, $result['imported'], 'The refused row is not counted as imported.');
        $this->assertSame(0, $result['updated'], 'Nor as an update.');
        $this->assertCount(1, $result['errors']);
        $this->assertSame('material_code', $result['errors'][0]['field']);
        $this->assertSame(self::PENDING_MESSAGE, $result['errors'][0]['message']);

        // Nothing moved: neither the warehouse balance nor the global figure,
        // and no silent adjustment in the ledger either.
        $this->assertEqualsWithDelta(100.0, $this->balance(), 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $this->material->fresh()->stock_quantity, 0.0001);
        $this->assertNull($this->material->fresh()->last_stock_sync_at);
        $this->assertSame(0, StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_ADJUSTMENT)
            ->count());
    }

    // ── 2. the importer still works where nobody owes anything ───────────────

    public function test_a_material_without_a_pending_settlement_is_imported(): void
    {
        $this->pendingPilotAllocation();

        $other = Material::factory()->create([
            'code' => 'KLEJ-F15',
            'unit_of_measure' => 'kg',
            'stock_quantity' => 5,
        ]);

        $result = $this->import('KLEJ-F15', 40.0);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);
        $this->assertEqualsWithDelta(40.0, $this->balance($other), 0.0001);
        $this->assertEqualsWithDelta(40.0, (float) $other->fresh()->stock_quantity, 0.0001);

        // The pending material is untouched by its neighbour's row.
        $this->assertEqualsWithDelta(100.0, $this->balance(), 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $this->material->fresh()->stock_quantity, 0.0001);
    }

    // ── 3. once settled, the ERP owns the balance again ──────────────────────

    public function test_a_settled_material_may_be_imported_again(): void
    {
        $allocation = $this->pendingPilotAllocation();

        $allocation->update([
            'status' => MaterialAllocation::STATUS_CONSUMED,
            'consumption_recorded' => true,
            'consumed_qty' => 10,
            'consumed_at' => now(),
        ]);

        $this->assertNull($allocation->fresh()->settlement_pending_reason, 'The settlement is closed.');

        $result = $this->import('FORMATKA-F15', 340.25);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['updated'], 'The existing balance was refreshed.');
        $this->assertEqualsWithDelta(340.25, $this->balance(), 0.0001);
        $this->assertEqualsWithDelta(340.25, (float) $this->material->fresh()->stock_quantity, 0.0001);
        $this->assertNotNull($this->material->fresh()->last_stock_sync_at);
    }

    // ── 4. finished goods are a different item, settled by nobody's operation ─

    public function test_a_product_row_is_imported_while_a_material_settlement_is_pending(): void
    {
        $this->pendingPilotAllocation();

        $product = ProductType::factory()->create(['code' => 'PROD-F15', 'unit_of_measure' => 'szt']);

        $result = app(StockImportService::class)->import([
            ['product_type_code' => $product->code, 'quantity' => 12],
        ], $this->warehouse->code);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);
        $this->assertEqualsWithDelta(12.0, (float) WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_type_id', $product->id)
            ->whereNull('material_lot_id')
            ->value('quantity'), 0.0001);

        $this->assertEqualsWithDelta(100.0, $this->balance(), 0.0001);
    }

    // ── 5. one wording, two importers ────────────────────────────────────────

    public function test_the_refusal_reads_exactly_as_the_material_importer_does(): void
    {
        $this->pendingPilotAllocation();

        $this->material->update(['external_code' => 'EXT-F15', 'external_system' => 'erp']);

        $materialImport = app(MaterialSyncService::class)->importFromExternalSystem('erp', [
            ['external_code' => 'EXT-F15', 'name' => 'Formatka 18 mm', 'stock_quantity' => 500],
        ]);

        $stockImport = $this->import('FORMATKA-F15', 340.25);

        $this->assertCount(1, $materialImport['errors']);
        $this->assertSame(
            $materialImport['errors'][0]['error'],
            $stockImport['errors'][0]['message'],
            'Both importers must refuse with the same sentence.',
        );
        $this->assertSame(self::PENDING_MESSAGE, $stockImport['errors'][0]['message']);
    }
}
