<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\Pallet;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Milestone backflush at the packaging station is disabled by force: raw
 * material is settled once by the operation that consumes it, so creating a
 * pallet never writes a stock movement - not even when the historical
 * `backflush_on_pallet_creation` setting still stores true.
 */
class PalletBackflushEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Admin', 'guard_name' => 'web']);
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
            'process_snapshot' => [
                'bom' => [
                    ['material_id' => $material->id, 'quantity_per_unit' => 2.0, 'scrap_percentage' => 5.0],
                ],
            ],
        ]);
    }

    public function test_creating_a_pallet_never_backflushes_even_when_setting_is_true(): void
    {
        $this->enableBackflush();
        $material = Material::factory()->create(['stock_quantity' => 1000]);
        $wo = $this->workOrderConsuming($material);

        $movementsBefore = StockMovement::orderBy('id')->pluck('id')->all();
        $stockBefore = (float) $material->fresh()->stock_quantity;

        $response = $this->actingAs($this->admin)
            ->postJson(route('packaging.pallets.create'), ['work_order_id' => $wo->id, 'produced_qty' => 100])
            ->assertCreated();

        // The policy is stated as information, not as a warning: the operator
        // packs on and nothing is booked.
        $response
            ->assertJsonPath('material_backflush.status', 'disabled')
            ->assertJsonPath('material_backflush.code', 'backflush_disabled_pending_reconciliation')
            ->assertJsonPath('material_backflush.reason', 'raw_material_settled_by_operation');
        $this->assertArrayNotHasKey('warning', $response->json());

        // No raw material is deducted at pallet creation.
        $this->assertEqualsWithDelta(1000.0, (float) $material->fresh()->stock_quantity, 0.0001);
        $this->assertSame(0, StockMovement::where('source_type', StockMovement::SOURCE_PALLET)->count());
        $this->assertSame(0, StockMovement::count());

        // Baseline held: the same movements and the same balance as before.
        $this->assertSame($movementsBefore, StockMovement::orderBy('id')->pluck('id')->all());
        $this->assertEqualsWithDelta($stockBefore, (float) $material->fresh()->stock_quantity, 0.0001);

        $pallet = Pallet::where('work_order_id', $wo->id)->firstOrFail();
        $this->assertFalse($pallet->stockMovements()->exists());
    }

    public function test_creating_a_pallet_does_not_backflush_when_disabled(): void
    {
        // Setting left at its default (off).
        $material = Material::factory()->create(['stock_quantity' => 1000]);
        $wo = $this->workOrderConsuming($material);

        $this->actingAs($this->admin)
            ->postJson(route('packaging.pallets.create'), ['work_order_id' => $wo->id, 'produced_qty' => 100])
            ->assertCreated();

        $this->assertEqualsWithDelta(1000.0, (float) $material->fresh()->stock_quantity, 0.0001);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_pallet_with_no_bom_creates_no_consumption(): void
    {
        $this->enableBackflush();
        $wo = WorkOrder::factory()->create(['process_snapshot' => ['bom' => []]]);

        $this->actingAs($this->admin)
            ->postJson(route('packaging.pallets.create'), ['work_order_id' => $wo->id, 'produced_qty' => 100])
            ->assertCreated();

        $this->assertSame(0, StockMovement::count());
    }
}
