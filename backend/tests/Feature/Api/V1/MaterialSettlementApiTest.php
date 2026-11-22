<?php

namespace Tests\Feature\Api\V1;

use App\Models\BomItem;
use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Material\MaterialSettlementPolicy;
use App\Services\Material\MaterialSettlementScope;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RQ-PA-265 A6 — the API that replaces hand-edited `extra_data`.
 *
 * Reading is open to anyone who may view the order; changing the scope is an
 * administrative decision and carries that role requirement. A refusal always
 * names its reason in `code`, so a caller can tell "not eligible" from
 * "already in flight" without parsing prose.
 */
class MaterialSettlementApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $operator;

    private Material $piece;

    private Material $carton;

    private ProductType $product;

    private ProcessTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');

        $this->piece = Material::factory()->create([
            'code' => 'FORMATKA-API',
            'name' => 'Formatka API',
            'unit_of_measure' => 'szt',
            'tracking_type' => 'none',
            'external_system' => null,
            'stock_quantity' => 100,
        ]);

        $this->carton = Material::factory()->create([
            'code' => 'KARTON-API',
            'name' => 'Karton API',
            'unit_of_measure' => 'szt',
            'tracking_type' => 'none',
            'external_system' => null,
            'stock_quantity' => 100,
        ]);

        $this->product = ProductType::factory()->create([
            'code' => 'PROD-RQ265-API-SCOPE',
            'unit_of_measure' => 'szt',
        ]);

        $this->template = ProcessTemplate::factory()->create([
            'product_type_id' => $this->product->id,
            'name' => 'RQ265 API scope routing',
            'is_active' => true,
        ]);

        $cut = TemplateStep::create([
            'process_template_id' => $this->template->id,
            'step_number' => 1,
            'name' => 'Cutting (CUT)',
            'kind' => TemplateStep::KIND_PRODUCTION,
        ]);

        $pack = TemplateStep::create([
            'process_template_id' => $this->template->id,
            'step_number' => 2,
            'name' => 'Packing',
            'kind' => TemplateStep::KIND_PACKING,
            'config' => ['unit' => 'carton', 'carton_capacity' => 1, 'pallet_capacity' => 0],
        ]);

        BomItem::create([
            'process_template_id' => $this->template->id,
            'template_step_id' => $cut->id,
            'material_id' => $this->piece->id,
            'quantity_per_unit' => 1,
            'scrap_percentage' => 0,
            'consumed_at' => 'start',
        ]);

        BomItem::create([
            'process_template_id' => $this->template->id,
            'template_step_id' => $pack->id,
            'material_id' => $this->carton->id,
            'quantity_per_unit' => 1,
            'per' => BomItem::PER_CARTON,
            'scrap_percentage' => 0,
            'consumed_at' => 'during',
        ]);
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $extraData
     */
    private function workOrder(array $extraData = [], string $orderNo = 'WO-RQ265-API-SCOPE'): WorkOrder
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

    /**
     * The same order, with its component already withdrawn by a started batch
     * and the withdrawal still owed a settlement.
     *
     * Only the piece is a pilot withdrawal: it is the component the cutting
     * operation consumes, and its confirmation froze the `operation` mode on the
     * row while the row is still `allocated`. The carton is bought per carton,
     * sits outside the pilot scope and must not make the fixture pass for the
     * wrong reason.
     */
    private function inFlightWorkOrder(): WorkOrder
    {
        $workOrder = $this->workOrder();

        $batch = app(WorkOrderService::class)->createBatch($workOrder, 10);
        app(BatchService::class)->startStep(
            $batch->steps()->where('step_number', 1)->firstOrFail(),
            $this->operator,
        );

        $pilotRows = MaterialAllocation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('material_id', $this->piece->id)
            ->where('status', MaterialAllocation::STATUS_ALLOCATED)
            ->update(['settlement_mode' => MaterialSettlementPolicy::MODE_OPERATION]);

        $this->assertGreaterThan(
            0,
            $pilotRows,
            'The fixture must leave an open pilot withdrawal of the component.',
        );

        return $workOrder->fresh();
    }

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function url(WorkOrder $workOrder): string
    {
        return "/api/v1/work-orders/{$workOrder->id}/material-settlement";
    }

    // ── reading ──────────────────────────────────────────────────────────────

    public function test_an_admin_sees_the_current_scope_and_the_candidates(): void
    {
        $workOrder = $this->workOrder();

        $this->withToken($this->token($this->admin))
            ->getJson($this->url($workOrder))
            ->assertOk()
            ->assertJsonPath('current.enabled', false)
            ->assertJsonPath('current.materials', [])
            ->assertJsonPath('can_enable', true)
            ->assertJsonCount(2, 'candidates')
            ->assertJsonPath('candidates.0.material_id', $this->piece->id)
            ->assertJsonPath('candidates.0.eligible', true)
            ->assertJsonPath('candidates.1.material_id', $this->carton->id)
            ->assertJsonPath('candidates.1.eligible', false)
            ->assertJsonPath('candidates.1.reason', MaterialSettlementScope::REASON_NOT_PER_UNIT);
    }

    public function test_an_operator_may_read_an_order_of_their_own_line_only(): void
    {
        $workOrder = $this->workOrder();

        // No line assignment: the policy refuses the view.
        $this->withToken($this->token($this->operator))
            ->getJson($this->url($workOrder))
            ->assertForbidden();
    }

    public function test_a_missing_work_order_is_not_found(): void
    {
        $this->withToken($this->token($this->admin))
            ->getJson('/api/v1/work-orders/999999/material-settlement')
            ->assertNotFound();
    }

    public function test_a_soft_deleted_work_order_is_not_found(): void
    {
        $workOrder = $this->workOrder();
        $workOrder->delete();

        $this->withToken($this->token($this->admin))
            ->getJson($this->url($workOrder))
            ->assertNotFound();
    }

    // ── enabling ─────────────────────────────────────────────────────────────

    public function test_an_admin_enables_the_scope_and_it_reads_back_as_enabled(): void
    {
        $workOrder = $this->workOrder();

        $this->withToken($this->token($this->admin))
            ->postJson($this->url($workOrder), ['materials' => [$this->piece->id]])
            ->assertOk()
            ->assertJsonPath('current.enabled', true)
            ->assertJsonPath('current.materials', [$this->piece->id])
            ->assertJsonPath('current.enabled_by', $this->admin->id);

        $this->withToken($this->token($this->admin))
            ->getJson($this->url($workOrder))
            ->assertOk()
            ->assertJsonPath('current.enabled', true)
            ->assertJsonPath('current.materials', [$this->piece->id]);

        // The policy agrees with the endpoint.
        $this->assertSame(
            MaterialSettlementPolicy::MODE_OPERATION,
            app(MaterialSettlementPolicy::class)->modeFor($workOrder->fresh(), $this->piece->id),
        );
    }

    public function test_an_operator_may_not_enable_the_scope(): void
    {
        $workOrder = $this->workOrder();

        $this->withToken($this->token($this->operator))
            ->postJson($this->url($workOrder), ['materials' => [$this->piece->id]])
            ->assertForbidden();

        $this->assertEmpty($workOrder->fresh()->extra_data, 'Nothing may be written.');
    }

    public function test_an_ineligible_material_is_refused_with_a_code(): void
    {
        $workOrder = $this->workOrder();

        $response = $this->withToken($this->token($this->admin))
            ->postJson($this->url($workOrder), ['materials' => [$this->carton->id]])
            ->assertStatus(422)
            ->assertJsonPath('code', MaterialSettlementScope::ERROR_INELIGIBLE);

        $this->assertStringContainsString(MaterialSettlementScope::ERROR_INELIGIBLE, $response->json('message'));
        $this->assertEmpty($workOrder->fresh()->extra_data, 'A refused request writes nothing.');
    }

    public function test_an_empty_selection_is_refused_by_validation(): void
    {
        $workOrder = $this->workOrder();

        $this->withToken($this->token($this->admin))
            ->postJson($this->url($workOrder), ['materials' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('materials');

        $this->assertEmpty($workOrder->fresh()->extra_data, 'Nothing may be written.');
    }

    public function test_a_material_outside_the_catalogue_is_refused_by_validation(): void
    {
        $workOrder = $this->workOrder();

        $this->withToken($this->token($this->admin))
            ->postJson($this->url($workOrder), ['materials' => [999999]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('materials.0');
    }

    // ── disabling ────────────────────────────────────────────────────────────

    public function test_an_admin_disables_the_scope_and_the_trace_stays(): void
    {
        $workOrder = $this->workOrder();

        $this->withToken($this->token($this->admin))
            ->postJson($this->url($workOrder), ['materials' => [$this->piece->id]])
            ->assertOk();

        $this->withToken($this->token($this->admin))
            ->deleteJson($this->url($workOrder))
            ->assertOk()
            ->assertJsonPath('current.enabled', false)
            ->assertJsonPath('current.disabled_by', $this->admin->id);

        $extra = $workOrder->fresh()->extra_data;
        $this->assertSame(MaterialSettlementPolicy::MODE_LEGACY, $extra['material_settlement']['mode']);
        $this->assertSame($this->admin->id, $extra['material_settlement']['disabled_by']);
        $this->assertNotNull($extra['material_settlement']['disabled_at']);
    }

    public function test_disabling_is_refused_while_material_is_outstanding(): void
    {
        $workOrder = $this->inFlightWorkOrder();
        $before = $workOrder->extra_data;
        $updatedAt = $workOrder->updated_at?->toIso8601String();

        $this->withToken($this->token($this->admin))
            ->deleteJson($this->url($workOrder))
            ->assertStatus(422)
            ->assertJsonPath('code', MaterialSettlementScope::ERROR_WORK_ORDER_IN_FLIGHT);

        // Refused means refused: the row was not touched at all — no scope key,
        // no audit-worthy update.
        $fresh = $workOrder->fresh();
        $this->assertSame($before, $fresh->extra_data);
        $this->assertSame($updatedAt, $fresh->updated_at?->toIso8601String(), 'A refused disable must not write the order.');
        $this->assertNull($fresh->extra_data['material_settlement'] ?? null);
    }

    public function test_disabling_works_once_the_allocation_is_settled(): void
    {
        $workOrder = $this->inFlightWorkOrder();

        MaterialAllocation::query()
            ->where('work_order_id', $workOrder->id)
            ->update(['status' => MaterialAllocation::STATUS_CONSUMED, 'consumed_qty' => 10]);

        $this->withToken($this->token($this->admin))
            ->deleteJson($this->url($workOrder))
            ->assertOk()
            ->assertJsonPath('current.enabled', false);
    }

    public function test_an_operator_may_not_disable_the_scope(): void
    {
        $workOrder = $this->workOrder([
            'material_settlement' => [
                'mode' => MaterialSettlementPolicy::MODE_OPERATION,
                'materials' => [$this->piece->id],
            ],
        ]);

        $this->withToken($this->token($this->operator))
            ->deleteJson($this->url($workOrder))
            ->assertForbidden();

        $this->assertSame(
            MaterialSettlementPolicy::MODE_OPERATION,
            $workOrder->fresh()->extra_data['material_settlement']['mode'],
        );
    }
}
