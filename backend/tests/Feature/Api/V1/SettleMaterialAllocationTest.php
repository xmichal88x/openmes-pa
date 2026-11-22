<?php

namespace Tests\Feature\Api\V1;

use App\Models\Batch;
use App\Models\BomItem;
use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\StockMovement;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Material\MaterialAllocationService;
use App\Services\Material\MaterialSettlementPolicy;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An explicit settlement request (RQ-PA-265 A3).
 *
 * A pilot line is confirmed by the operation that consumed it. When the
 * confirmation is missing the request is refused with a named reason and
 * nothing moves — the COGI / Pending Transactions rule. When it is present the
 * settlement is posted once: a repeated request finds nothing left to settle
 * and books no second movement.
 */
class SettleMaterialAllocationTest extends TestCase
{
    use RefreshDatabase;

    private Material $material;

    private ProductType $product;

    private ProcessTemplate $template;

    private User $operator;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->material = Material::factory()->create([
            'code' => 'FORMATKA-SETTLE',
            'name' => 'Formatka 18 mm',
            'unit_of_measure' => 'szt',
            'tracking_type' => 'none',
            'external_system' => null,
            'stock_quantity' => 100,
        ]);

        $this->product = ProductType::factory()->create([
            'code' => 'PROD-SETTLE',
            'unit_of_measure' => 'szt',
        ]);

        $this->template = ProcessTemplate::factory()->create([
            'product_type_id' => $this->product->id,
            'name' => 'Settlement pilot routing',
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
    private function workOrder(array $extraData): WorkOrder
    {
        return WorkOrder::factory()->create([
            'order_no' => 'WO-RQ265-API-SETTLE',
            'product_type_id' => $this->product->id,
            'process_snapshot' => $this->template->fresh()->load(['steps', 'bomItems'])->toSnapshot(),
            'planned_qty' => 10,
            'produced_qty' => 0,
            'status' => WorkOrder::STATUS_PENDING,
            'extra_data' => $extraData,
        ]);
    }

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
    private function startedBatch(array $extraData): array
    {
        $workOrder = $this->workOrder($extraData);
        $batch = app(WorkOrderService::class)->createBatch($workOrder, 10);
        $step = $batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($step, $this->operator);

        $allocation = MaterialAllocation::query()
            ->where('batch_id', $batch->id)
            ->where('material_id', $this->material->id)
            ->firstOrFail();

        return [$workOrder, $batch, $allocation];
    }

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function assertStock(float $expected): void
    {
        $this->assertEqualsWithDelta($expected, (float) $this->material->fresh()->stock_quantity, 0.0001);
    }

    private function movementIds(): array
    {
        return StockMovement::query()
            ->where('material_id', $this->material->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    // ── 1. pending: refused with a named reason, nothing moves ───────────────

    public function test_a_pending_settlement_request_is_refused_with_a_named_reason(): void
    {
        [, , $allocation] = $this->startedBatch($this->pilotExtraData());

        $movementsBefore = $this->movementIds();
        $this->assertStock(90.0);

        $this->withToken($this->token($this->admin))
            ->postJson("/api/v1/material-allocations/{$allocation->id}/settle")
            ->assertStatus(422)
            ->assertJson([
                'code' => 'material_settlement_pending',
                'reason' => MaterialSettlementPolicy::REASON_CONSUMPTION_NOT_CONFIRMED,
            ]);

        $fresh = $allocation->fresh();
        // Refused means refused: no status change, no stock movement, the
        // withdrawal still stands and its reservation is still held.
        $this->assertSame(MaterialAllocation::STATUS_ALLOCATED, $fresh->status);
        $this->assertEqualsWithDelta(0.0, (float) $fresh->consumed_qty, 0.0001);
        $this->assertNull($fresh->consumed_at);
        $this->assertStock(90.0);
        $this->assertEqualsWithDelta(10.0, (float) $this->material->fresh()->reserved_quantity, 0.0001);
        $this->assertSame($movementsBefore, $this->movementIds());
    }

    // ── 2. confirmed: posted once ────────────────────────────────────────────

    public function test_a_confirmed_settlement_request_posts_once(): void
    {
        [, $batch, $allocation] = $this->startedBatch($this->pilotExtraData());

        app(MaterialAllocationService::class)->recordConsumption($allocation, 8.0, 2.0, null, $this->operator);

        $token = $this->token($this->admin);

        $this->withToken($token)
            ->postJson("/api/v1/material-allocations/{$allocation->id}/settle")
            ->assertOk()
            ->assertJsonPath('summary.settled', 1)
            ->assertJsonPath('data.status', MaterialAllocation::STATUS_CONSUMED);

        $fresh = $allocation->fresh();
        $this->assertSame(MaterialAllocation::STATUS_CONSUMED, $fresh->status);
        $this->assertStock(90.0);

        $movementsAfterFirst = $this->movementIds();

        // A retried request must not settle — or book — a second time.
        $this->withToken($token)
            ->postJson("/api/v1/material-allocations/{$allocation->id}/settle")
            ->assertOk()
            ->assertJsonPath('summary.settled', 0);

        $this->assertSame($movementsAfterFirst, $this->movementIds(), 'A repeated settlement books no new movement.');
        $this->assertStock(90.0);
        $this->assertSame($batch->id, (int) $allocation->fresh()->batch_id);
    }

    // ── 3. an explicit zero is a confirmation too ────────────────────────────

    public function test_a_confirmed_zero_returns_the_whole_allocation_and_posts_nothing(): void
    {
        [, , $allocation] = $this->startedBatch($this->pilotExtraData());

        app(MaterialAllocationService::class)->recordConsumption($allocation, 0.0, 0.0, null, $this->operator);
        $this->assertStock(90.0);

        $this->withToken($this->token($this->admin))
            ->postJson("/api/v1/material-allocations/{$allocation->id}/settle")
            ->assertOk()
            ->assertJsonPath('summary.settled', 1)
            ->assertJsonPath('summary.pending', []);

        $fresh = $allocation->fresh();
        $this->assertSame(MaterialAllocation::STATUS_CONSUMED, $fresh->status);
        $this->assertEqualsWithDelta(0.0, (float) $fresh->consumed_qty, 0.0001);
        $this->assertTrue((bool) $fresh->consumption_recorded);

        // Nothing was used, so the whole withdrawal comes back.
        $this->assertStock(100.0);
        $this->assertEqualsWithDelta(0.0, (float) $this->material->fresh()->reserved_quantity, 0.0001);
        $this->assertSame(1, StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_RETURN)
            ->count());
    }

    // ── 4. the legacy scope has nothing to settle ────────────────────────────

    public function test_a_legacy_allocation_cannot_be_settled_by_request(): void
    {
        [, , $allocation] = $this->startedBatch([]);

        $movementsBefore = $this->movementIds();

        $this->withToken($this->token($this->admin))
            ->postJson("/api/v1/material-allocations/{$allocation->id}/settle")
            ->assertStatus(422)
            ->assertJson(['code' => 'material_settlement_not_applicable']);

        $this->assertSame(MaterialAllocation::STATUS_ALLOCATED, $allocation->fresh()->status);
        $this->assertSame($movementsBefore, $this->movementIds());
    }
}
