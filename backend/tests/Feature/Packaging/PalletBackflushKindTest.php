<?php

namespace Tests\Feature\Packaging;

use App\Models\Material;
use App\Models\Pallet;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use App\Services\Packaging\PackagingBoxService;
use App\Services\Packaging\PackagingScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * R07-15 by pallet kind, under the forced-off backflush policy: raw material is
 * settled once by the consuming operation, so no pallet kind (loose or boxes,
 * created, closed, scanned or reopened) may ever write a stock movement.
 */
class PalletBackflushKindTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    private function enableBackflush(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'backflush_on_pallet_creation'],
            ['value' => json_encode(true)],
        );
    }

    private function workOrderConsuming(Material $material): WorkOrder
    {
        return WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'process_snapshot' => [
                'bom' => [
                    ['material_id' => $material->id, 'quantity_per_unit' => 2.0, 'scrap_percentage' => 5.0],
                ],
            ],
        ]);
    }

    private function scannedCode(WorkOrder $workOrder, string $state = WorkOrderEan::STATE_SCANNED): WorkOrderEan
    {
        return WorkOrderEan::create([
            'work_order_id' => $workOrder->id,
            'ean' => 'LBL-'.uniqid(),
            'code_type' => WorkOrderEan::CODE_TYPE_PART_LABEL,
            'state' => $state,
        ]);
    }

    public function test_a_loose_pallet_never_backflushes_on_creation(): void
    {
        $this->enableBackflush();
        $material = Material::factory()->create(['stock_quantity' => 1000]);
        $wo = $this->workOrderConsuming($material);

        $movementsBefore = StockMovement::orderBy('id')->pluck('id')->all();
        $stockBefore = (float) $material->fresh()->stock_quantity;

        $response = $this->actingAs($this->admin)
            ->postJson(route('packaging.pallets.create'), [
                'work_order_id' => $wo->id,
                'kind' => Pallet::KIND_LOOSE,
                'produced_qty' => 100,
            ])
            ->assertCreated();

        // The disabled policy travels as a plain status, never as a warning.
        $response
            ->assertJsonPath('material_backflush.status', 'disabled')
            ->assertJsonPath('material_backflush.code', 'backflush_disabled_pending_reconciliation')
            ->assertJsonPath('material_backflush.reason', 'raw_material_settled_by_operation');
        $this->assertArrayNotHasKey('warning', $response->json());

        // Creating a loose pallet books no raw material.
        $this->assertEqualsWithDelta(1000.0, (float) $material->fresh()->stock_quantity, 0.0001);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame($movementsBefore, StockMovement::orderBy('id')->pluck('id')->all());
        $this->assertEqualsWithDelta($stockBefore, (float) $material->fresh()->stock_quantity, 0.0001);
    }

    public function test_a_boxes_pallet_does_not_backflush_on_close_but_closes_once(): void
    {
        $this->enableBackflush();
        $material = Material::factory()->create(['stock_quantity' => 1000]);
        $wo = $this->workOrderConsuming($material);

        $palletId = $this->actingAs($this->admin)
            ->postJson(route('packaging.pallets.create'), [
                'work_order_id' => $wo->id,
                'kind' => Pallet::KIND_BOXES,
                'produced_qty' => 100,
            ])
            ->assertCreated()
            ->json('pallet.id');

        // Creating a boxes pallet (even with a produced_qty) books nothing: its
        // qty is not known until boxes are put on it.
        $this->assertEqualsWithDelta(1000.0, (float) $material->fresh()->stock_quantity, 0.0001);
        $this->assertSame(0, StockMovement::count());

        $boxes = app(PackagingBoxService::class);
        $pallet = Pallet::findOrFail($palletId);
        $box = $boxes->create($wo);
        $boxes->assignCode($box, $this->scannedCode($wo));
        $boxes->assignCode($box, $this->scannedCode($wo));
        $boxes->assignPallet($boxes->close($box), $pallet);

        $this->assertSame(2, $pallet->fresh()->qty);
        $this->assertSame(0, StockMovement::count(), 'Adding boxes and scanning must not backflush.');

        $movementsBefore = StockMovement::orderBy('id')->pluck('id')->all();
        $stockBefore = (float) $material->fresh()->stock_quantity;

        $this->actingAs($this->admin)
            ->postJson(route('packaging.pallets.close', $pallet))
            ->assertOk()
            ->assertJsonPath('material_backflush.status', 'disabled')
            ->assertJsonPath('material_backflush.code', 'backflush_disabled_pending_reconciliation')
            ->assertJsonPath('material_backflush.reason', 'raw_material_settled_by_operation');

        $this->assertSame($movementsBefore, StockMovement::orderBy('id')->pluck('id')->all());
        $this->assertEqualsWithDelta($stockBefore, (float) $material->fresh()->stock_quantity, 0.0001);

        // Closing the boxes pallet still works, but books no raw material.
        $this->assertEqualsWithDelta(1000.0, (float) $material->fresh()->stock_quantity, 0.0001);
        $this->assertSame(0, StockMovement::count());

        $this->actingAs($this->admin)
            ->postJson(route('packaging.pallets.close', $pallet))
            ->assertStatus(422);

        $this->assertEqualsWithDelta(1000.0, (float) $material->fresh()->stock_quantity, 0.0001);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_an_empty_boxes_pallet_does_not_backflush_the_batch_on_close(): void
    {
        $this->enableBackflush();
        $material = Material::factory()->create(['stock_quantity' => 1000]);
        $wo = $this->workOrderConsuming($material);

        $palletId = $this->actingAs($this->admin)
            ->postJson(route('packaging.pallets.create'), [
                'work_order_id' => $wo->id,
                'kind' => Pallet::KIND_BOXES,
            ])
            ->assertCreated()
            ->json('pallet.id');

        $this->actingAs($this->admin)
            ->postJson(route('packaging.pallets.close', $palletId))
            ->assertOk();

        $this->assertEqualsWithDelta(1000.0, (float) $material->fresh()->stock_quantity, 0.0001);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_scanning_into_a_reopened_box_on_a_boxes_pallet_recomputes_qty_instead_of_doubling_it(): void
    {
        $this->enableBackflush();
        $material = Material::factory()->create(['stock_quantity' => 1000]);
        $wo = $this->workOrderConsuming($material);

        $pallet = Pallet::create([
            'work_order_id' => $wo->id,
            'kind' => Pallet::KIND_BOXES,
            'status' => 'open',
            'qty' => 0,
        ]);

        $boxes = app(PackagingBoxService::class);
        $box = $boxes->create($wo);
        $boxes->assignCode($box, $this->scannedCode($wo));
        $boxes->assignPallet($boxes->close($box), $pallet);
        $this->assertSame(1, $pallet->fresh()->qty);

        $boxes->reopen($box);

        app(PackagingScanService::class)->scan(
            $this->scannedCode($wo, WorkOrderEan::STATE_EXPECTED),
            $wo,
            $pallet,
            $this->admin,
            $box->fresh(),
        );

        $this->assertSame(2, $pallet->fresh()->qty, 'The pallet qty is the sum of box part counts.');
        $this->assertSame(0, StockMovement::count());
    }
}
