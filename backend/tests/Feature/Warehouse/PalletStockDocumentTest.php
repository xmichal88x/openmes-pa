<?php

namespace Tests\Feature\Warehouse;

use App\Enums\PalletStatus;
use App\Models\Line;
use App\Models\Material;
use App\Models\Pallet;
use App\Models\ProductType;
use App\Models\SerialUnit;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Services\Production\PalletBackflushService;
use App\Services\Warehouse\WarehouseStockService;
use App\Services\Warehouse\WorkOrderStockDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Finished goods booked pallet by pallet: received into the finished-goods
 * warehouse when a pallet closes, issued from it when the pallet ships.
 *
 * Raw material is never booked from a pallet - the pallet milestone backflush
 * is retired and forced OFF - so no pallet transition may write a material
 * movement, not even while the historical setting is still stored as true.
 */
class PalletStockDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $fg;

    private WorkOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fg = Warehouse::factory()->finishedGoods()->isDefault()->create(['code' => 'FG-1']);
        $product = ProductType::factory()->create(['code' => 'PRD-FG', 'unit_of_measure' => 'pcs']);
        $this->order = WorkOrder::factory()->create(['line_id' => Line::factory(), 'product_type_id' => $product->id, 'planned_qty' => 3, 'produced_qty' => 3, 'status' => WorkOrder::STATUS_IN_PROGRESS]);
    }

    private function mode(string $mode): void
    {
        DB::table('system_settings')->updateOrInsert(['key' => 'pallet_stock_documents'], ['value' => json_encode($mode)]);
    }

    /** A pallet with three tested units and one scrapped. */
    private function pallet(): Pallet
    {
        $pallet = Pallet::create(['work_order_id' => $this->order->id, 'qty' => 4, 'status' => PalletStatus::Open->value, 'quality_status' => Pallet::QUALITY_PASS]);
        foreach (['A', 'B', 'C'] as $sn) {
            SerialUnit::create(['serial_no' => "SN-$sn", 'work_order_id' => $this->order->id, 'pallet_id' => $pallet->id, 'status' => SerialUnit::STATUS_COMPLETED]);
        }
        SerialUnit::create(['serial_no' => 'SN-X', 'work_order_id' => $this->order->id, 'pallet_id' => $pallet->id, 'status' => SerialUnit::STATUS_SCRAPPED]);

        return $pallet;
    }

    private function stock(): float
    {
        return app(WarehouseStockService::class)->available(['warehouse_id' => $this->fg->id, 'product_type_id' => $this->order->product_type_id]);
    }

    public function test_posted_mode_moves_stock_with_the_pallet_and_books_each_document_once(): void
    {
        $this->mode('post');
        $pallet = $this->pallet();

        $pallet->update(['status' => PalletStatus::Closed->value]);
        $receipt = StockDocument::where('pallet_id', $pallet->id)->where('type', StockDocument::TYPE_PRODUCT_RECEIPT)->with('lines')->sole();
        $this->assertSame(StockDocument::STATUS_POSTED, $receipt->status);
        $this->assertEquals(3, $receipt->lines->first()->quantity, 'units on the pallet, scrap excepted');
        $this->assertEquals(3, $this->stock());

        // Completing the order - it packs onto pallets - does not receive the same goods a second time.
        $batch = \App\Models\Batch::factory()->create(['work_order_id' => $this->order->id]);
        \App\Models\BatchStep::factory()->create(['batch_id' => $batch->id, 'step_number' => 1, 'kind' => \App\Models\TemplateStep::KIND_PACKING]);
        app(WorkOrderStockDocumentService::class)->generateForCompletion($this->order);
        $this->assertSame(1, StockDocument::where('work_order_id', $this->order->id)->where('type', StockDocument::TYPE_PRODUCT_RECEIPT)->count());

        $pallet->update(['status' => PalletStatus::Shipped->value]);
        $issue = StockDocument::where('pallet_id', $pallet->id)->where('type', StockDocument::TYPE_PRODUCT_ISSUE)->sole();
        $this->assertSame(StockDocument::STATUS_POSTED, $issue->status);
        $this->assertEquals(0, $this->stock());

        // Touching the shipped pallet again books nothing more.
        $pallet->update(['location' => 'Dock 2']);
        $this->assertSame(2, StockDocument::where('pallet_id', $pallet->id)->count());
    }

    public function test_an_order_that_never_palletises_keeps_its_completion_receipt(): void
    {
        $this->mode('post');

        app(WorkOrderStockDocumentService::class)->generateForCompletion($this->order);

        $this->assertSame(1, StockDocument::where('work_order_id', $this->order->id)->where('type', StockDocument::TYPE_PRODUCT_RECEIPT)->whereNull('pallet_id')->count());
    }

    public function test_a_pallet_shipped_straight_from_open_is_received_before_it_leaves(): void
    {
        $this->mode('post');
        $pallet = $this->pallet();

        $pallet->update(['status' => PalletStatus::Shipped->value]);

        $this->assertSame([StockDocument::TYPE_PRODUCT_RECEIPT, StockDocument::TYPE_PRODUCT_ISSUE], StockDocument::where('pallet_id', $pallet->id)->orderBy('id')->pluck('type')->all());
        $this->assertEquals(0, $this->stock());
    }

    public function test_a_pallet_recorded_straight_as_closed_is_received_and_held_units_neither_ship_nor_count(): void
    {
        $this->mode('post');
        $closed = Pallet::create(['work_order_id' => $this->order->id, 'qty' => 5, 'status' => PalletStatus::Closed->value]);
        $this->assertEquals(5, StockDocument::where('pallet_id', $closed->id)->where('type', StockDocument::TYPE_PRODUCT_RECEIPT)->sole()->lines()->sum('quantity'));

        $pallet = $this->pallet();
        $held = SerialUnit::create(['serial_no' => 'SN-H', 'work_order_id' => $this->order->id, 'pallet_id' => $pallet->id, 'status' => SerialUnit::STATUS_BLOCKED]);
        $pallet->update(['status' => PalletStatus::Closed->value]);
        $this->assertEquals(3, StockDocument::where('pallet_id', $pallet->id)->sole()->lines()->sum('quantity'), 'the held unit is not received');

        app(\App\Services\Packaging\UnitPackingService::class)->markPalletShipped($pallet);
        $this->assertSame(SerialUnit::STATUS_BLOCKED, $held->fresh()->status);
    }

    public function test_draft_mode_leaves_documents_for_the_warehouse_and_off_books_nothing(): void
    {
        $this->mode('draft');
        $drafted = $this->pallet();
        $drafted->update(['status' => PalletStatus::Closed->value]);
        $this->assertSame(StockDocument::STATUS_DRAFT, StockDocument::where('pallet_id', $drafted->id)->sole()->status);
        $this->assertEquals(0, $this->stock());

        $this->mode('off');
        $other = Pallet::create(['work_order_id' => $this->order->id, 'qty' => 2, 'status' => PalletStatus::Open->value]);
        $other->update(['status' => PalletStatus::Closed->value]);
        $this->assertSame(0, StockDocument::where('pallet_id', $other->id)->count());
    }

    /**
     * The retired backflush stays stored as enabled and the work order's BOM
     * snapshot carries material the old backflush would have consumed, so a
     * regression would have something to book. Returns the ledgers' before state.
     *
     * @return array{material: Material, balance: float, movement_ids: array<int, int>, material_movement_ids: array<int, int>}
     */
    private function withLegacyBackflush(): array
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => PalletBackflushService::SETTING_KEY],
            ['value' => json_encode(true)],
        );

        $material = Material::factory()->create(['stock_quantity' => 100, 'unit_of_measure' => 'szt']);
        $this->order->update(['process_snapshot' => ['bom' => [[
            'material_id' => $material->id,
            'quantity_per_unit' => 1,
            'scrap_percentage' => 0,
        ]]]]);

        return [
            'material' => $material,
            'balance' => 100.0,
            'movement_ids' => StockMovement::orderBy('id')->pluck('id')->all(),
            'material_movement_ids' => StockMovement::whereNotNull('material_id')->orderBy('id')->pluck('id')->all(),
        ];
    }

    /**
     * Material is exactly as before the pallet transition: same balance, same
     * ledger rows, no new material movement and none sourced from a pallet.
     *
     * @param  array{material: Material, balance: float, movement_ids: array<int, int>, material_movement_ids: array<int, int>}  $baseline
     */
    private function assertMaterialUnchanged(array $baseline): void
    {
        $this->assertEqualsWithDelta($baseline['balance'], (float) $baseline['material']->fresh()->stock_quantity, 0.0001, 'material balance is untouched');
        $this->assertSame($baseline['movement_ids'], StockMovement::orderBy('id')->pluck('id')->all(), 'no new stock movement of any kind');
        $this->assertSame($baseline['material_movement_ids'], StockMovement::whereNotNull('material_id')->orderBy('id')->pluck('id')->all(), 'no new material movement');
        $this->assertSame(0, StockMovement::where('source_type', StockMovement::SOURCE_PALLET)->count(), 'no pallet-sourced movement');
    }

    public function test_off_books_no_documents_and_no_material_when_the_legacy_backflush_setting_is_still_true(): void
    {
        $baseline = $this->withLegacyBackflush();
        $this->mode('off');
        $pallet = $this->pallet();

        $pallet->update(['status' => PalletStatus::Closed->value]);

        $this->assertSame(0, StockDocument::where('pallet_id', $pallet->id)->count(), 'off books nothing for the pallet');
        $this->assertEquals(0, $this->stock(), 'nothing arrives in the finished-goods warehouse');
        $this->assertSame(0, $pallet->stockMovements()->count());
        $this->assertMaterialUnchanged($baseline);
    }

    public function test_draft_leaves_one_draft_receipt_and_moves_neither_finished_goods_nor_material(): void
    {
        $baseline = $this->withLegacyBackflush();
        $this->mode('draft');
        $pallet = $this->pallet();

        $pallet->update(['status' => PalletStatus::Closed->value]);

        $documents = StockDocument::where('pallet_id', $pallet->id)->get();
        $this->assertCount(1, $documents, 'exactly one document for the closed pallet');
        $this->assertSame(StockDocument::TYPE_PRODUCT_RECEIPT, $documents->first()->type);
        $this->assertSame(StockDocument::STATUS_DRAFT, $documents->first()->status);
        $this->assertEquals(0, $this->stock(), 'a draft does not move finished goods');
        $this->assertSame(0, $pallet->stockMovements()->count());
        $this->assertMaterialUnchanged($baseline);
    }

    public function test_post_moves_finished_goods_with_the_pallet_and_leaves_material_alone(): void
    {
        $baseline = $this->withLegacyBackflush();
        $this->mode('post');
        $pallet = $this->pallet();

        $pallet->update(['status' => PalletStatus::Closed->value]);

        $receipt = StockDocument::where('pallet_id', $pallet->id)->where('type', StockDocument::TYPE_PRODUCT_RECEIPT)->sole();
        $this->assertSame(StockDocument::STATUS_POSTED, $receipt->status);
        $this->assertEquals(3, $receipt->lines->sum('quantity'), 'units on the pallet, scrap excepted');
        $this->assertEquals(3, $this->stock(), 'the goods are in the finished-goods warehouse');
        $this->assertSame(0, $pallet->stockMovements()->count());
        $this->assertMaterialUnchanged($baseline);

        $pallet->update(['status' => PalletStatus::Shipped->value]);

        $issue = StockDocument::where('pallet_id', $pallet->id)->where('type', StockDocument::TYPE_PRODUCT_ISSUE)->sole();
        $this->assertSame(StockDocument::STATUS_POSTED, $issue->status);
        $this->assertEquals(0, $this->stock(), 'the goods leave the finished-goods warehouse');
        $this->assertMaterialUnchanged($baseline);

        // Touching the shipped pallet books nothing more, and no material either.
        $pallet->update(['location' => 'Dock 2']);
        $this->assertSame(2, StockDocument::where('pallet_id', $pallet->id)->count());
        $this->assertMaterialUnchanged($baseline);
    }

    public function test_the_model_hook_books_one_document_per_pallet_transition_in_post_mode(): void
    {
        $baseline = $this->withLegacyBackflush();
        $this->mode('post');
        $pallet = $this->pallet();

        // The document is produced by Pallet::saved alone - the test never calls
        // the document service, and no pallet-sourced movement may appear.
        $pallet->update(['status' => PalletStatus::Closed->value]);
        $this->assertSame(1, StockDocument::where('pallet_id', $pallet->id)->count());
        $this->assertMaterialUnchanged($baseline);

        $pallet->update(['location' => 'Dock 1']);
        $pallet->update(['location' => 'Dock 2']);
        $this->assertSame(1, StockDocument::where('pallet_id', $pallet->id)->count(), 'further updates book nothing');
        $this->assertSame(0, $pallet->stockMovements()->count());
        $this->assertMaterialUnchanged($baseline);
    }
}
