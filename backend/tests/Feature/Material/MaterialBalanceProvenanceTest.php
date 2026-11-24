<?php

namespace Tests\Feature\Material;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\MaterialType;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Material\MaterialAllocationService;
use App\Services\WorkOrder\BatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * F1-3: a balance nobody vouched for is not a zero.
 *
 * Availability and the production hold must tell a declared number apart from
 * one that is unknown, seeded for a demo or read too long ago. An undeclared
 * balance may not pass silently: no numeric availability, and the hold refuses
 * the start with a code that names what has to be declared.
 */
class MaterialBalanceProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Supervisor', 'web');
        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('Supervisor');
    }

    private function hold(bool $on): void
    {
        DB::table('system_settings')->updateOrInsert(['key' => 'hold_on_material_shortage'], ['value' => json_encode($on)]);
    }

    /**
     * An order for 10 needing 2 of a part per unit, with the given stock and
     * balance provenance, and its first step.
     */
    private function firstStep(
        float $stock,
        string $owner = Material::STOCK_OWNER_UNKNOWN,
        string $source = Material::BALANCE_SOURCE_UNKNOWN,
        ?string $asOf = null,
    ): BatchStep {
        $part = Material::factory()->create([
            'code' => 'PART-P',
            'unit_of_measure' => 'pcs',
            'material_type_id' => MaterialType::factory()->create()->id,
            'stock_quantity' => $stock,
            'reserved_quantity' => 0,
            'stock_owner' => $owner,
            'balance_source' => $source,
            'balance_as_of' => $asOf,
        ]);
        $order = WorkOrder::factory()->create(['planned_qty' => 10, 'status' => WorkOrder::STATUS_IN_PROGRESS, 'process_snapshot' => ['bom' => [[
            'material_id' => $part->id, 'material_code' => 'PART-P', 'material_name' => $part->name, 'unit_of_measure' => 'pcs',
            'quantity_per_unit' => 2, 'scrap_percentage' => 0, 'consumed_at' => 'during',
        ]]]]);
        $batch = Batch::factory()->create(['work_order_id' => $order->id, 'status' => Batch::STATUS_PENDING, 'target_qty' => 10]);

        return BatchStep::factory()->create(['batch_id' => $batch->id, 'step_number' => 1, 'status' => BatchStep::STATUS_READY]);
    }

    private function assertHeldWithCode(BatchStep $step, string $code): void
    {
        try {
            app(BatchService::class)->startStep($step, $this->supervisor);
            $this->fail('the start should have been held: '.$code);
        } catch (\DomainException $e) {
            $this->assertStringStartsWith($code.': ', $e->getMessage());
            $this->assertStringContainsString('PART-P', $e->getMessage());
        }

        $this->assertSame(BatchStep::STATUS_READY, $step->fresh()->status);
        $this->assertSame(0, StockMovement::query()->count(), 'nothing may move on an unverified balance');
        $this->assertSame(0, MaterialAllocation::query()->count(), 'nothing may be allocated on an unverified balance');
    }

    public function test_the_balance_state_names_who_vouched_for_the_number(): void
    {
        $material = fn (string $owner, string $source): Material => Material::factory()->create([
            'material_type_id' => MaterialType::factory()->create()->id,
            'stock_owner' => $owner,
            'balance_source' => $source,
        ]);

        $declared = $material(Material::STOCK_OWNER_ERP, Material::BALANCE_SOURCE_OPENING_DECLARED);
        $this->assertSame(Material::BALANCE_STATE_OK, $declared->balanceState());
        $this->assertTrue($declared->balanceIsKnown());

        $this->assertSame(Material::BALANCE_STATE_UNKNOWN, $material(Material::STOCK_OWNER_UNKNOWN, Material::BALANCE_SOURCE_UNKNOWN)->balanceState());
        // A source that does not count for the owner is not a declaration.
        $notDeclared = $material(Material::STOCK_OWNER_ERP, Material::BALANCE_SOURCE_MES_LEDGER);
        $this->assertSame(Material::BALANCE_STATE_UNKNOWN, $notDeclared->balanceState());
        $this->assertFalse($notDeclared->balanceIsKnown());

        $stale = $material(Material::STOCK_OWNER_ERP, Material::BALANCE_SOURCE_STALE);
        $this->assertSame(Material::BALANCE_STATE_STALE, $stale->balanceState());
        $this->assertFalse($stale->balanceIsKnown());

        $demo = $material(Material::STOCK_OWNER_ERP, Material::BALANCE_SOURCE_DEMO_SEED);
        $this->assertSame(Material::BALANCE_STATE_DEMO_SEED, $demo->balanceState());
        $this->assertFalse($demo->balanceIsKnown());
    }

    public function test_an_unknown_balance_holds_the_batch_and_asks_for_a_declaration(): void
    {
        $this->hold(true);
        $step = $this->firstStep(stock: 500);

        try {
            app(BatchService::class)->startStep($step, $this->supervisor);
            $this->fail('the start should have been held');
        } catch (\DomainException $e) {
            $this->assertStringStartsWith('material_balance_unknown: ', $e->getMessage());
            $this->assertStringContainsString('PART-P', $e->getMessage());
            $this->assertStringContainsString('state: unknown', $e->getMessage());
            $this->assertStringContainsString('source: unknown', $e->getMessage());
            $this->assertStringContainsString('Declare the balance from the ERP', $e->getMessage());
        }

        $this->assertSame(BatchStep::STATUS_READY, $step->fresh()->status);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, MaterialAllocation::query()->count());
    }

    public function test_a_demo_seed_balance_is_refused_as_a_demo_seed(): void
    {
        $this->hold(true);
        $step = $this->firstStep(stock: 500, owner: Material::STOCK_OWNER_ERP, source: Material::BALANCE_SOURCE_DEMO_SEED);

        $this->assertHeldWithCode($step, 'material_balance_demo_seed');
    }

    public function test_a_stale_balance_is_refused_as_stale_and_carries_when_it_was_read(): void
    {
        $this->hold(true);
        $step = $this->firstStep(
            stock: 500,
            owner: Material::STOCK_OWNER_ERP,
            source: Material::BALANCE_SOURCE_STALE,
            asOf: '2026-01-05 06:30:00',
        );

        try {
            app(BatchService::class)->startStep($step, $this->supervisor);
            $this->fail('the start should have been held');
        } catch (\DomainException $e) {
            $this->assertStringStartsWith('material_balance_stale: ', $e->getMessage());
            $this->assertStringContainsString('as of: 2026-01-05T06:30:00', $e->getMessage());
        }

        $this->assertSame(BatchStep::STATUS_READY, $step->fresh()->status);
    }

    public function test_a_declared_balance_lets_a_covered_order_start(): void
    {
        $this->hold(true);
        $step = $this->firstStep(stock: 100, owner: Material::STOCK_OWNER_ERP, source: Material::BALANCE_SOURCE_OPENING_DECLARED);

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, app(BatchService::class)->startStep($step, $this->supervisor)->status);
    }

    public function test_a_declared_balance_that_falls_short_keeps_the_shortage_message(): void
    {
        $this->hold(true);
        $step = $this->firstStep(stock: 5, owner: Material::STOCK_OWNER_ERP, source: Material::BALANCE_SOURCE_ERP_PROJECTION);

        try {
            app(BatchService::class)->startStep($step, $this->supervisor);
            $this->fail('the start should have been held');
        } catch (\DomainException $e) {
            $this->assertStringStartsWith('Production hold: stock does not cover order', $e->getMessage());
            // 20 needed, 5 on hand.
            $this->assertStringContainsString('15', $e->getMessage());
        }

        $this->assertSame(BatchStep::STATUS_READY, $step->fresh()->status);
    }

    public function test_the_hold_being_off_lets_an_unknown_balance_start(): void
    {
        $step = $this->firstStep(stock: 500);

        $this->assertFalse(BatchService::holdsOnMaterialShortage());
        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, app(BatchService::class)->startStep($step, $this->supervisor)->status);
    }

    public function test_shortage_lines_report_an_unknown_balance_without_inventing_a_number(): void
    {
        $part = Material::factory()->create([
            'code' => 'PART-U',
            'unit_of_measure' => 'pcs',
            'material_type_id' => MaterialType::factory()->create()->id,
            'stock_quantity' => 500,
            'reserved_quantity' => 0,
        ]);
        $order = WorkOrder::factory()->create(['planned_qty' => 10, 'status' => WorkOrder::STATUS_PENDING, 'process_snapshot' => ['bom' => [[
            'material_id' => $part->id, 'material_code' => 'PART-U', 'material_name' => $part->name, 'unit_of_measure' => 'pcs',
            'quantity_per_unit' => 2, 'scrap_percentage' => 0, 'consumed_at' => 'start',
        ]]]]);

        $short = app(MaterialAllocationService::class)->shortagesForWorkOrders(collect([$order]))[$order->id] ?? [];

        $this->assertCount(1, $short, 'plenty of stock on paper, but nobody declared the balance');
        $this->assertFalse($short[0]['balance_known']);
        $this->assertSame(Material::BALANCE_STATE_UNKNOWN, $short[0]['balance_state']);
        $this->assertSame(Material::BALANCE_SOURCE_UNKNOWN, $short[0]['balance_source']);
        $this->assertSame(Material::STOCK_OWNER_UNKNOWN, $short[0]['balance_owner']);
        $this->assertNull($short[0]['balance_as_of']);
        $this->assertNull($short[0]['available_qty']);
        $this->assertNull($short[0]['on_hand_qty']);
        $this->assertSame(20.0, $short[0]['required_qty']);
        $this->assertSame(20.0, $short[0]['missing_qty'], 'an unverified balance covers nothing');
    }

    public function test_the_preview_cannot_call_an_unknown_balance_sufficient(): void
    {
        $part = Material::factory()->create([
            'code' => 'PART-W',
            'unit_of_measure' => 'pcs',
            'material_type_id' => MaterialType::factory()->create()->id,
            'stock_quantity' => 500,
            'reserved_quantity' => 0,
        ]);
        $order = WorkOrder::factory()->create(['planned_qty' => 10, 'status' => WorkOrder::STATUS_PENDING, 'process_snapshot' => ['bom' => [[
            'material_id' => $part->id, 'material_code' => 'PART-W', 'material_name' => $part->name, 'unit_of_measure' => 'pcs',
            'quantity_per_unit' => 2, 'scrap_percentage' => 0, 'consumed_at' => 'start',
        ]]]]);

        $preview = app(MaterialAllocationService::class)->previewForWorkOrder($order);

        $this->assertCount(1, $preview);
        $this->assertFalse($preview[0]['balance_known']);
        $this->assertSame(Material::BALANCE_STATE_UNKNOWN, $preview[0]['balance_state']);
        $this->assertNull($preview[0]['available_qty']);
        $this->assertNull($preview[0]['on_hand_qty']);
        $this->assertFalse($preview[0]['sufficient']);
        $this->assertTrue($preview[0]['material_exists']);
    }

    public function test_a_declared_balance_keeps_the_preview_numbers(): void
    {
        $part = Material::factory()->create([
            'code' => 'PART-D',
            'unit_of_measure' => 'pcs',
            'material_type_id' => MaterialType::factory()->create()->id,
            'stock_quantity' => 500,
            'reserved_quantity' => 0,
            'stock_owner' => Material::STOCK_OWNER_ERP,
            'balance_source' => Material::BALANCE_SOURCE_OPENING_DECLARED,
            'balance_as_of' => '2026-02-01 08:00:00',
        ]);
        $order = WorkOrder::factory()->create(['planned_qty' => 10, 'status' => WorkOrder::STATUS_PENDING, 'process_snapshot' => ['bom' => [[
            'material_id' => $part->id, 'material_code' => 'PART-D', 'material_name' => $part->name, 'unit_of_measure' => 'pcs',
            'quantity_per_unit' => 2, 'scrap_percentage' => 0, 'consumed_at' => 'start',
        ]]]]);

        $preview = app(MaterialAllocationService::class)->previewForWorkOrder($order);

        $this->assertTrue($preview[0]['balance_known']);
        $this->assertSame(Material::BALANCE_STATE_OK, $preview[0]['balance_state']);
        $this->assertSame(Material::BALANCE_SOURCE_OPENING_DECLARED, $preview[0]['balance_source']);
        $this->assertSame(Material::STOCK_OWNER_ERP, $preview[0]['balance_owner']);
        $this->assertSame('2026-02-01T08:00:00+00:00', $preview[0]['balance_as_of']);
        $this->assertSame(500.0, $preview[0]['available_qty']);
        $this->assertSame(500.0, $preview[0]['on_hand_qty']);
        $this->assertTrue($preview[0]['sufficient']);
    }
}
