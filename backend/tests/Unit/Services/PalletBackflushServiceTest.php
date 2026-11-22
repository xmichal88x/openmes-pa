<?php

namespace Tests\Unit\Services;

use App\Exceptions\PalletBackflushDisabledException;
use App\Models\Batch;
use App\Models\Material;
use App\Models\Pallet;
use App\Models\StockMovement;
use App\Models\WorkOrder;
use App\Services\Production\PalletBackflushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Raw material is settled exactly once by the operation that consumes it.
 * Booking material from a pallet (create/close/retry/delete) is disabled by
 * force - the service must raise instead of writing any stock movement.
 */
class PalletBackflushServiceTest extends TestCase
{
    use RefreshDatabase;

    private PalletBackflushService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PalletBackflushService::class);
    }

    /** Store the historical setting value (read for diagnostics only). */
    private function storeSetting(string $rawValue): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => PalletBackflushService::SETTING_KEY],
            ['value' => $rawValue],
        );
    }

    /** Work order whose BOM snapshot consumes $material at qty_per_unit + scrap. */
    private function palletForBom(Material $material, float $perUnit, float $scrapPct): Pallet
    {
        $workOrder = WorkOrder::factory()->create([
            'process_snapshot' => [
                'bom' => [
                    ['material_id' => $material->id, 'quantity_per_unit' => $perUnit, 'scrap_percentage' => $scrapPct],
                ],
            ],
        ]);

        return Pallet::factory()->create(['work_order_id' => $workOrder->id]);
    }

    public function test_backflush_raises_and_books_nothing(): void
    {
        $material = Material::factory()->create(['stock_quantity' => 1000]);
        $pallet = $this->palletForBom($material, 2.0, 5.0);

        try {
            $this->service->backflush($pallet, 100);
            $this->fail('Backflush must raise: pallet material booking is disabled.');
        } catch (PalletBackflushDisabledException $e) {
            $this->assertSame(PalletBackflushDisabledException::CODE, $e->reasonCode);
        }

        $this->assertEqualsWithDelta(1000.0, (float) $material->fresh()->stock_quantity, 0.0001);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_backflush_for_pallet_raises_for_every_pallet_of_a_batch(): void
    {
        // A batch split across several pallets: neither pallet may consume it.
        $material = Material::factory()->create(['stock_quantity' => 1000]);
        $wo = WorkOrder::factory()->create([
            'process_snapshot' => ['bom' => [
                ['material_id' => $material->id, 'quantity_per_unit' => 2.0, 'scrap_percentage' => 0],
            ]],
        ]);
        $batch = Batch::factory()->create(['work_order_id' => $wo->id, 'produced_qty' => 100]);
        $p1 = Pallet::factory()->create(['work_order_id' => $wo->id, 'batch_id' => $batch->id]);
        $p2 = Pallet::factory()->create(['work_order_id' => $wo->id, 'batch_id' => $batch->id]);

        foreach ([$p1, $p2] as $pallet) {
            try {
                $this->service->backflushForPallet($pallet, null);
                $this->fail('Backflush must raise on every pallet of the batch.');
            } catch (PalletBackflushDisabledException) {
                // expected
            }
        }

        $this->assertEqualsWithDelta(1000.0, (float) $material->fresh()->stock_quantity, 0.0001);
        $this->assertSame(0, StockMovement::where('source_type', StockMovement::SOURCE_PALLET)->count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_pallet_with_no_bom_still_raises_and_consumes_nothing(): void
    {
        $material = Material::factory()->create(['stock_quantity' => 500]);
        $workOrder = WorkOrder::factory()->create(['process_snapshot' => ['bom' => []]]);
        $pallet = Pallet::factory()->create(['work_order_id' => $workOrder->id]);

        try {
            $this->service->backflush($pallet, 100);
            $this->fail('Backflush must raise even without a BOM.');
        } catch (PalletBackflushDisabledException) {
            // expected
        }

        $this->assertEqualsWithDelta(500.0, (float) $material->fresh()->stock_quantity, 0.0001);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_zero_quantity_still_raises_and_consumes_nothing(): void
    {
        // The block is unconditional: even a zero quantity must not be a silent
        // empty collection.
        $material = Material::factory()->create(['stock_quantity' => 500]);
        $pallet = $this->palletForBom($material, 2.0, 0.0);

        try {
            $this->service->backflush($pallet, 0);
            $this->fail('Backflush must raise even for a zero quantity.');
        } catch (PalletBackflushDisabledException) {
            // expected
        }

        $this->assertEqualsWithDelta(500.0, (float) $material->fresh()->stock_quantity, 0.0001);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_backflush_for_pallet_without_batch_and_with_explicit_qty_raises(): void
    {
        $material = Material::factory()->create(['stock_quantity' => 500]);
        $wo = WorkOrder::factory()->create([
            'process_snapshot' => ['bom' => [
                ['material_id' => $material->id, 'quantity_per_unit' => 2.0, 'scrap_percentage' => 0],
            ]],
        ]);
        $pallet = Pallet::factory()->create(['work_order_id' => $wo->id, 'batch_id' => null]);

        try {
            $this->service->backflushForPallet($pallet, 10.0);
            $this->fail('Backflush must raise instead of returning an empty collection.');
        } catch (PalletBackflushDisabledException) {
            // expected
        }

        $this->assertEqualsWithDelta(500.0, (float) $material->fresh()->stock_quantity, 0.0001);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_stored_true_is_reported_by_configured_enabled_but_never_enables_backflush(): void
    {
        $this->storeSetting(json_encode(true));

        $this->assertTrue($this->service->configuredEnabled());
        $this->assertFalse($this->service->isEnabled());

        $material = Material::factory()->create(['stock_quantity' => 1000]);
        $pallet = $this->palletForBom($material, 2.0, 5.0);

        foreach ([fn () => $this->service->backflush($pallet, 100), fn () => $this->service->backflushForPallet($pallet, 100)] as $call) {
            try {
                $call();
                $this->fail('Backflush must raise even when the stored setting is true.');
            } catch (PalletBackflushDisabledException) {
                // expected
            }
        }

        $this->assertEqualsWithDelta(1000.0, (float) $material->fresh()->stock_quantity, 0.0001);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_missing_setting_row_is_reported_as_null_and_keeps_backflush_off(): void
    {
        DB::table('system_settings')->where('key', PalletBackflushService::SETTING_KEY)->delete();

        $this->assertNull($this->service->configuredEnabled());
        $this->assertFalse($this->service->isEnabled());
    }

    public function test_invalid_setting_json_is_reported_as_null_without_throwing(): void
    {
        $this->storeSetting('{not-json');

        $this->assertNull($this->service->configuredEnabled());
        $this->assertFalse($this->service->isEnabled());
    }

    public function test_resolve_quantity_prefers_explicit_then_batch(): void
    {
        $wo = WorkOrder::factory()->create();
        $batch = Batch::factory()->create(['work_order_id' => $wo->id, 'produced_qty' => 42, 'target_qty' => 99]);
        $pallet = Pallet::factory()->create(['work_order_id' => $wo->id, 'batch_id' => $batch->id]);

        $this->assertSame(7.0, $this->service->resolveQuantity($pallet, 7.0)); // explicit wins
        $this->assertSame(42.0, $this->service->resolveQuantity($pallet, null)); // batch produced_qty
    }

    public function test_resolve_quantity_falls_back_to_target_then_zero(): void
    {
        $wo = WorkOrder::factory()->create();
        $batch = Batch::factory()->create(['work_order_id' => $wo->id, 'produced_qty' => 0, 'target_qty' => 30]);
        $pallet = Pallet::factory()->create(['work_order_id' => $wo->id, 'batch_id' => $batch->id]);
        $this->assertSame(30.0, $this->service->resolveQuantity($pallet, null));

        $bare = Pallet::factory()->create(['work_order_id' => $wo->id, 'batch_id' => null]);
        $this->assertSame(0.0, $this->service->resolveQuantity($bare, null));
    }
}
