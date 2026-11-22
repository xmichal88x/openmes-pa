<?php

namespace Tests\Feature\Console;

use App\Models\AuditLog;
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
 * RQ-PA-265 A6 — `material:pilot`, the console form of the same decision the API
 * makes. It reports what the routing allows, changes nothing without being
 * asked, and refuses with the same codes the API returns.
 */
class MaterialPilotCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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

        $this->piece = Material::factory()->create([
            'code' => 'FORMATKA-CLI',
            'name' => 'Formatka CLI',
            'unit_of_measure' => 'szt',
            'tracking_type' => 'none',
            'external_system' => null,
            'stock_quantity' => 100,
        ]);

        $this->carton = Material::factory()->create([
            'code' => 'KARTON-CLI',
            'name' => 'Karton CLI',
            'unit_of_measure' => 'szt',
            'tracking_type' => 'none',
            'external_system' => null,
            'stock_quantity' => 100,
        ]);

        $this->product = ProductType::factory()->create([
            'code' => 'PROD-RQ265-CLI',
            'unit_of_measure' => 'szt',
        ]);

        $this->template = ProcessTemplate::factory()->create([
            'product_type_id' => $this->product->id,
            'name' => 'RQ265 CLI routing',
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

    /**
     * @param  array<string, mixed>  $extraData
     */
    private function workOrder(array $extraData = [], string $orderNo = 'WO-RQ265-CLI'): WorkOrder
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

        $operator = User::factory()->create();
        $operator->assignRole('Operator');

        $batch = app(WorkOrderService::class)->createBatch($workOrder, 10);
        app(BatchService::class)->startStep(
            $batch->steps()->where('step_number', 1)->firstOrFail(),
            $operator,
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

    // ── preview ──────────────────────────────────────────────────────────────

    public function test_a_preview_lists_the_candidates_and_writes_nothing(): void
    {
        $workOrder = $this->workOrder();

        $this->artisan('material:pilot', ['order_no' => $workOrder->order_no])
            ->expectsOutputToContain($this->piece->code)
            ->expectsOutputToContain($this->carton->code)
            ->assertExitCode(0);

        $this->assertEmpty($workOrder->fresh()->extra_data, 'Nothing may be written.');
    }

    public function test_dry_run_changes_nothing_even_when_asked_to_enable(): void
    {
        $workOrder = $this->workOrder();

        $this->artisan('material:pilot', [
            'order_no' => $workOrder->order_no,
            '--enable' => [$this->piece->code],
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertEmpty($workOrder->fresh()->extra_data, 'A dry run must not write the scope.');
    }

    public function test_dry_run_changes_nothing_even_when_asked_to_disable(): void
    {
        $workOrder = $this->workOrder();
        app(MaterialSettlementScope::class)->enable($workOrder, [$this->piece->id], $this->admin);

        $this->artisan('material:pilot', [
            'order_no' => $workOrder->order_no,
            '--disable' => true,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame(
            MaterialSettlementPolicy::MODE_OPERATION,
            $workOrder->fresh()->extra_data['material_settlement']['mode'],
        );
    }

    public function test_dry_run_names_the_codes_a_real_run_would_refuse(): void
    {
        $workOrder = $this->workOrder();

        $this->artisan('material:pilot', [
            'order_no' => $workOrder->order_no,
            '--enable' => [$this->carton->code],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain(MaterialSettlementScope::REASON_NOT_PER_UNIT)
            ->assertExitCode(0);

        $this->assertEmpty($workOrder->fresh()->extra_data, 'A dry run must not write the scope.');
    }

    // ── changing the scope ───────────────────────────────────────────────────

    public function test_enabling_by_material_code_turns_the_scope_on(): void
    {
        $workOrder = $this->workOrder();

        $this->artisan('material:pilot', [
            'order_no' => $workOrder->order_no,
            '--enable' => [$this->piece->code],
        ])->assertExitCode(0);

        $current = app(MaterialSettlementScope::class)->current($workOrder->fresh());

        $this->assertTrue($current['enabled']);
        $this->assertSame([$this->piece->id], $current['materials']);
        $this->assertSame($this->admin->id, $current['enabled_by'], 'The admin running the command is the recorded actor.');
    }

    public function test_disabling_turns_the_scope_off_with_a_trace(): void
    {
        $workOrder = $this->workOrder();
        app(MaterialSettlementScope::class)->enable($workOrder, [$this->piece->id], $this->admin);

        $this->artisan('material:pilot', [
            'order_no' => $workOrder->order_no,
            '--disable' => true,
        ])->assertExitCode(0);

        $extra = $workOrder->fresh()->extra_data;
        $this->assertSame(MaterialSettlementPolicy::MODE_LEGACY, $extra['material_settlement']['mode']);
        $this->assertSame($this->admin->id, $extra['material_settlement']['disabled_by']);
    }

    public function test_a_console_run_is_audited_even_though_the_trait_skips_it(): void
    {
        $workOrder = $this->workOrder();

        // What a real `php artisan material:pilot` sees: console, not unit
        // tests. The model's audit trait returns early here, so the service has
        // to leave the row itself.
        $this->app->instance('env', 'production');

        try {
            $this->artisan('material:pilot', [
                'order_no' => $workOrder->order_no,
                '--enable' => [$this->piece->code],
            ])->assertExitCode(0);
        } finally {
            $this->app->instance('env', 'testing');
        }

        $logs = AuditLog::query()
            ->where('entity_type', WorkOrder::class)
            ->where('entity_id', $workOrder->id)
            ->where('action', 'updated')
            ->get();

        $this->assertCount(1, $logs, 'The console change must leave exactly one audit entry.');

        $log = $logs->first();
        $this->assertSame($this->admin->id, (int) $log->user_id, 'The entry names the actor the command wrote against.');
        $this->assertSame('console', $log->user_agent);
        $this->assertNull($log->ip_address);
        $this->assertSame([], $log->before_state['extra_data']);
        $this->assertSame(
            MaterialSettlementPolicy::MODE_OPERATION,
            $log->after_state['extra_data']['material_settlement']['mode'],
        );
        $this->assertSame([$this->piece->id], $log->after_state['extra_data']['material_settlement']['materials']);
    }

    public function test_a_console_run_of_the_command_leaves_no_duplicate_audit_entry(): void
    {
        $workOrder = $this->workOrder();

        // The unit-test environment keeps the trait writing, so the console
        // fallback must not add a second row for the same change.
        $this->artisan('material:pilot', [
            'order_no' => $workOrder->order_no,
            '--enable' => [$this->piece->code],
        ])->assertExitCode(0);

        $this->assertSame(
            0,
            AuditLog::query()
                ->where('entity_type', WorkOrder::class)
                ->where('entity_id', $workOrder->id)
                ->where('user_agent', 'console')
                ->count(),
            'The fallback must stay silent when the trait already wrote the entry.',
        );
    }

    public function test_disabling_in_flight_is_refused_and_writes_nothing(): void
    {
        $workOrder = $this->inFlightWorkOrder();
        $before = $workOrder->extra_data;

        $this->artisan('material:pilot', [
            'order_no' => $workOrder->order_no,
            '--disable' => true,
        ])
            ->expectsOutputToContain(MaterialSettlementScope::ERROR_WORK_ORDER_IN_FLIGHT)
            ->assertExitCode(1);

        $this->assertSame($before, $workOrder->fresh()->extra_data, 'A refused disable writes nothing.');
    }

    public function test_dry_run_disable_in_flight_reports_the_refusal_and_writes_nothing(): void
    {
        $workOrder = $this->inFlightWorkOrder();
        $before = $workOrder->extra_data;

        $this->artisan('material:pilot', [
            'order_no' => $workOrder->order_no,
            '--disable' => true,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain(MaterialSettlementScope::ERROR_WORK_ORDER_IN_FLIGHT)
            ->assertExitCode(1);

        $this->assertSame($before, $workOrder->fresh()->extra_data, 'A dry run never writes.');
    }

    // ── refusals ─────────────────────────────────────────────────────────────

    public function test_an_unknown_material_code_fails_without_writing(): void
    {
        $workOrder = $this->workOrder();

        $this->artisan('material:pilot', [
            'order_no' => $workOrder->order_no,
            '--enable' => ['NIE-MA-TAKIEGO'],
        ])->assertExitCode(1);

        $this->assertEmpty($workOrder->fresh()->extra_data, 'Nothing may be written.');
    }

    public function test_an_ineligible_material_is_refused_with_the_service_code(): void
    {
        $workOrder = $this->workOrder();

        $this->artisan('material:pilot', [
            'order_no' => $workOrder->order_no,
            '--enable' => [$this->carton->code],
        ])
            ->expectsOutputToContain(MaterialSettlementScope::ERROR_INELIGIBLE)
            ->assertExitCode(1);

        $this->assertEmpty($workOrder->fresh()->extra_data, 'Nothing may be written.');
    }

    public function test_an_unknown_order_number_fails(): void
    {
        $this->artisan('material:pilot', ['order_no' => 'WO-NIE-ISTNIEJE'])
            ->assertExitCode(1);
    }

    public function test_a_soft_deleted_order_is_not_picked_up(): void
    {
        $workOrder = $this->workOrder();
        $workOrder->delete();

        $this->artisan('material:pilot', ['order_no' => $workOrder->order_no])
            ->assertExitCode(1);
    }
}
