<?php

namespace Tests\Feature\Warehouse;

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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RQ-PA-265 A6 — the administrative tool that turns the pilot settlement scope
 * on and off for one work order.
 *
 * The tool exists because the scope used to be hand-edited into `extra_data`:
 * no validation, no record of who did it, and a typo quietly changing who books
 * the material. Every decision now goes through one service: it states why a
 * component cannot be settled by the operation, refuses the opt-in when the
 * order is already in flight, and leaves a trace on the order itself.
 */
class MaterialSettlementScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $operator;

    private ProductType $product;

    private ProcessTemplate $template;

    private TemplateStep $cutStep;

    private TemplateStep $packStep;

    private Material $piece;

    private Material $carton;

    private Material $endItem;

    private Material $serial;

    private Material $meter;

    private Material $duplicate;

    private Material $stepLess;

    private Material $trashed;

    /** The frozen BOM of the pilot routing, taken before the deletion in setUp. */
    private array $snapshot;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Admin', 'web');
        Role::findOrCreate('Operator', 'web');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');

        $this->piece = $this->bomMaterial('FORMATKA-18', 'szt', 'none');
        $this->carton = $this->bomMaterial('KARTON-1', 'szt', 'none');
        $this->endItem = $this->bomMaterial('ETYKIETA-END', 'szt', 'none');
        $this->serial = $this->bomMaterial('SERIAL-1', 'szt', 'serial');
        $this->meter = $this->bomMaterial('TASMA-M', 'm', 'none');
        $this->duplicate = $this->bomMaterial('SRUBA-1', 'szt', 'none');
        $this->stepLess = $this->bomMaterial('BEZ-KROKU', 'szt', 'none');
        $this->trashed = $this->bomMaterial('USUNIETY-1', 'szt', 'none');

        $this->product = ProductType::factory()->create([
            'code' => 'PROD-RQ265-SCOPE',
            'unit_of_measure' => 'szt',
        ]);

        $this->template = ProcessTemplate::factory()->create([
            'product_type_id' => $this->product->id,
            'name' => 'RQ265 scope routing',
            'is_active' => true,
        ]);

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

        // The pilot component: one piece per finished unit, withdrawn at cutting.
        $this->bomLine($this->piece, $this->cutStep);

        // Packaging bought per carton: not one withdrawal per piece.
        BomItem::create([
            'process_template_id' => $this->template->id,
            'template_step_id' => $this->packStep->id,
            'material_id' => $this->carton->id,
            'quantity_per_unit' => 1,
            'per' => BomItem::PER_CARTON,
            'scrap_percentage' => 0,
            'consumed_at' => 'during',
        ]);

        // Consumed when the batch closes, i.e. after the operation is over.
        BomItem::create([
            'process_template_id' => $this->template->id,
            'template_step_id' => $this->cutStep->id,
            'material_id' => $this->endItem->id,
            'quantity_per_unit' => 1,
            'scrap_percentage' => 0,
            'consumed_at' => 'end',
        ]);

        // Tracked per serial: the quantity is not a number the operation declares.
        $this->bomLine($this->serial, $this->cutStep);

        // Measured in metres: the pilot counts pieces.
        $this->bomLine($this->meter, $this->cutStep);

        // A component the routing names once; the duplicate-line case is built
        // from a merged snapshot below, where two BOMs may name the same material.
        $this->bomLine($this->duplicate, $this->cutStep);

        // No step attributed at all.
        BomItem::create([
            'process_template_id' => $this->template->id,
            'template_step_id' => null,
            'material_id' => $this->stepLess->id,
            'quantity_per_unit' => 1,
            'scrap_percentage' => 0,
            'consumed_at' => 'start',
        ]);

        // Present when the order was released, deleted afterwards.
        $this->bomLine($this->trashed, $this->cutStep);

        $this->snapshot = $this->template->fresh()->load(['steps', 'bomItems'])->toSnapshot();

        $this->trashed->delete();
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    private function bomMaterial(string $code, string $unit, string $tracking): Material
    {
        return Material::factory()->create([
            'code' => $code,
            'name' => 'Component '.$code,
            'unit_of_measure' => $unit,
            'tracking_type' => $tracking,
            'external_system' => null,
            'stock_quantity' => 100,
        ]);
    }

    private function bomLine(Material $material, ?TemplateStep $step, string $consumedAt = 'start'): BomItem
    {
        return BomItem::create([
            'process_template_id' => $this->template->id,
            'template_step_id' => $step?->id,
            'material_id' => $material->id,
            'quantity_per_unit' => 1,
            'scrap_percentage' => 0,
            'consumed_at' => $consumedAt,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extraData
     */
    private function workOrder(array $extraData = [], ?array $snapshot = null, string $orderNo = 'WO-RQ265-SCOPE'): WorkOrder
    {
        return WorkOrder::factory()->create([
            'order_no' => $orderNo,
            'product_type_id' => $this->product->id,
            'process_snapshot' => $snapshot ?? $this->snapshot,
            'planned_qty' => 10,
            'produced_qty' => 0,
            'status' => WorkOrder::STATUS_PENDING,
            'extra_data' => $extraData,
        ]);
    }

    private function scope(): MaterialSettlementScope
    {
        return app(MaterialSettlementScope::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function candidateFor(array $candidates, Material $material): array
    {
        foreach ($candidates as $candidate) {
            if ((int) $candidate['material_id'] === $material->id) {
                return $candidate;
            }
        }

        $this->fail('No candidate row for material '.$material->code);
    }

    /**
     * A started batch: the components are already allocated to the order, so the
     * scope may no longer be changed under a running operation.
     *
     * @return array{0: WorkOrder, 1: Material}
     */
    private function inFlightOrder(array $extraData = []): array
    {
        $material = $this->bomMaterial('FORMATKA-INFLIGHT', 'szt', 'none');
        $product = ProductType::factory()->create(['code' => 'PROD-RQ265-INFLIGHT', 'unit_of_measure' => 'szt']);
        $template = ProcessTemplate::factory()->create([
            'product_type_id' => $product->id,
            'name' => 'RQ265 in-flight routing',
            'is_active' => true,
        ]);

        $cut = TemplateStep::create([
            'process_template_id' => $template->id,
            'step_number' => 1,
            'name' => 'Cutting (CUT)',
            'kind' => TemplateStep::KIND_PRODUCTION,
        ]);

        $pack = TemplateStep::create([
            'process_template_id' => $template->id,
            'step_number' => 2,
            'name' => 'Packing',
            'kind' => TemplateStep::KIND_PACKING,
            'config' => ['unit' => 'carton', 'carton_capacity' => 1, 'pallet_capacity' => 0],
        ]);

        BomItem::create([
            'process_template_id' => $template->id,
            'template_step_id' => $cut->id,
            'material_id' => $material->id,
            'quantity_per_unit' => 1,
            'scrap_percentage' => 0,
            'consumed_at' => 'start',
        ]);

        // A line that would be refused on its own terms — in flight it must still
        // be reported as in flight, never as "eligible".
        BomItem::create([
            'process_template_id' => $template->id,
            'template_step_id' => $pack->id,
            'material_id' => $this->carton->id,
            'quantity_per_unit' => 1,
            'per' => BomItem::PER_CARTON,
            'scrap_percentage' => 0,
            'consumed_at' => 'during',
        ]);

        $workOrder = WorkOrder::factory()->create([
            'order_no' => 'WO-RQ265-INFLIGHT',
            'product_type_id' => $product->id,
            'process_snapshot' => $template->fresh()->load(['steps', 'bomItems'])->toSnapshot(),
            'planned_qty' => 10,
            'produced_qty' => 0,
            'status' => WorkOrder::STATUS_PENDING,
            'extra_data' => $extraData,
        ]);

        $batch = app(WorkOrderService::class)->createBatch($workOrder, 10);
        app(BatchService::class)->startStep($batch->steps()->where('step_number', 1)->firstOrFail(), $this->operator);

        return [$workOrder->fresh(), $material];
    }

    // ── 1. qualification ─────────────────────────────────────────────────────

    public function test_a_piece_component_with_a_step_is_eligible(): void
    {
        $row = $this->candidateFor($this->scope()->candidates($this->workOrder()), $this->piece);

        $this->assertTrue($row['eligible']);
        $this->assertNull($row['reason']);
        $this->assertSame('FORMATKA-18', $row['material_code']);
        $this->assertSame('szt', $row['unit_of_measure']);
        $this->assertSame(1, (int) $row['step_number']);
        $this->assertSame('Cutting (CUT)', $row['step_name']);
        $this->assertSame('start', $row['consumed_at']);
        $this->assertSame(BomItem::PER_UNIT, $row['per']);
    }

    public function test_a_carton_line_is_not_per_unit(): void
    {
        $row = $this->candidateFor($this->scope()->candidates($this->workOrder()), $this->carton);

        $this->assertFalse($row['eligible']);
        $this->assertSame(MaterialSettlementScope::REASON_NOT_PER_UNIT, $row['reason']);
    }

    public function test_a_component_consumed_at_batch_end_is_outside_the_operation(): void
    {
        $row = $this->candidateFor($this->scope()->candidates($this->workOrder()), $this->endItem);

        $this->assertFalse($row['eligible']);
        $this->assertSame(MaterialSettlementScope::REASON_SETTLED_AT_END, $row['reason']);
    }

    public function test_a_serial_tracked_component_is_not_settleable_by_quantity(): void
    {
        $row = $this->candidateFor($this->scope()->candidates($this->workOrder()), $this->serial);

        $this->assertFalse($row['eligible']);
        $this->assertSame(MaterialSettlementScope::REASON_SERIAL_TRACKED, $row['reason']);
    }

    public function test_a_component_measured_in_metres_is_not_per_piece(): void
    {
        $row = $this->candidateFor($this->scope()->candidates($this->workOrder()), $this->meter);

        $this->assertFalse($row['eligible']);
        $this->assertSame(MaterialSettlementScope::REASON_NOT_PER_PIECE, $row['reason']);
    }

    public function test_a_component_on_two_bom_lines_cannot_pick_one(): void
    {
        // Merged BOMs (one order, several templates) may name the same material
        // twice — the database cannot forbid it across templates.
        $snapshot = $this->snapshot;
        $snapshot['bom'][] = [
            'component_kind' => 'material',
            'material_id' => $this->duplicate->id,
            'material_code' => 'SRUBA-1',
            'material_name' => 'Component SRUBA-1',
            'material_type' => null,
            'tracking_type' => 'none',
            'unit_of_measure' => 'szt',
            'quantity_per_unit' => 1,
            'per' => BomItem::PER_UNIT,
            'scrap_percentage' => 0,
            'consumed_at' => 'start',
            'step_number' => 1,
            'external_code' => null,
            'external_system' => null,
        ];

        $rows = array_values(array_filter(
            $this->scope()->candidates($this->workOrder(snapshot: $snapshot)),
            fn (array $candidate) => (int) $candidate['material_id'] === $this->duplicate->id,
        ));

        $this->assertCount(2, $rows, 'Both BOM lines stay visible so the conflict is seen, not hidden.');
        foreach ($rows as $row) {
            $this->assertFalse($row['eligible']);
            $this->assertSame(MaterialSettlementScope::REASON_MULTIPLE_BOM_LINES, $row['reason']);
        }
    }

    public function test_a_line_without_an_operation_is_reported(): void
    {
        $row = $this->candidateFor($this->scope()->candidates($this->workOrder()), $this->stepLess);

        $this->assertFalse($row['eligible']);
        $this->assertNull($row['step_number']);
        $this->assertSame(MaterialSettlementScope::REASON_OPERATION_MISSING, $row['reason']);
    }

    public function test_a_line_pointing_at_a_step_outside_the_snapshot_is_reported(): void
    {
        $snapshot = $this->snapshot;
        $snapshot['bom'] = array_values(array_filter(
            $snapshot['bom'],
            fn (array $line) => (int) ($line['material_id'] ?? 0) !== $this->stepLess->id,
        ));
        $snapshot['bom'][] = [
            'component_kind' => 'material',
            'material_id' => $this->stepLess->id,
            'material_code' => 'BEZ-KROKU',
            'material_name' => 'Component BEZ-KROKU',
            'material_type' => null,
            'tracking_type' => 'none',
            'unit_of_measure' => 'szt',
            'quantity_per_unit' => 1,
            'per' => BomItem::PER_UNIT,
            'scrap_percentage' => 0,
            'consumed_at' => 'start',
            'step_number' => 9,
            'external_code' => null,
            'external_system' => null,
        ];

        $row = $this->candidateFor(
            $this->scope()->candidates($this->workOrder(snapshot: $snapshot)),
            $this->stepLess,
        );

        $this->assertSame(9, (int) $row['step_number']);
        $this->assertFalse($row['eligible']);
        $this->assertSame(MaterialSettlementScope::REASON_OPERATION_MISSING, $row['reason']);
    }

    public function test_a_component_missing_from_the_catalogue_is_reported(): void
    {
        $snapshot = $this->snapshot;
        $snapshot['bom'][] = [
            'component_kind' => 'material',
            'material_id' => 999999,
            'material_code' => 'GHOST-1',
            'material_name' => 'Ghost component',
            'material_type' => null,
            'tracking_type' => 'none',
            'unit_of_measure' => 'szt',
            'quantity_per_unit' => 1,
            'per' => BomItem::PER_UNIT,
            'scrap_percentage' => 0,
            'consumed_at' => 'start',
            'step_number' => 1,
            'external_code' => null,
            'external_system' => null,
        ];

        $candidates = $this->scope()->candidates($this->workOrder(snapshot: $snapshot));

        $ghost = null;
        foreach ($candidates as $candidate) {
            if ((int) $candidate['material_id'] === 999999) {
                $ghost = $candidate;
            }
        }

        $this->assertNotNull($ghost, 'A line whose material vanished must still be listed.');
        $this->assertFalse($ghost['eligible']);
        $this->assertSame(MaterialSettlementScope::REASON_MATERIAL_MISSING, $ghost['reason']);
    }

    public function test_a_soft_deleted_component_is_reported(): void
    {
        $row = $this->candidateFor($this->scope()->candidates($this->workOrder()), $this->trashed);

        $this->assertFalse($row['eligible']);
        $this->assertSame(MaterialSettlementScope::REASON_MATERIAL_MISSING, $row['reason']);
    }

    public function test_an_order_with_an_allocation_is_in_flight_for_every_line(): void
    {
        [$workOrder] = $this->inFlightOrder();

        $this->assertGreaterThan(
            0,
            MaterialAllocation::query()->where('work_order_id', $workOrder->id)->count(),
            'The fixture must actually withdraw the material.',
        );

        $candidates = $this->scope()->candidates($workOrder);

        $this->assertCount(2, $candidates);
        foreach ($candidates as $candidate) {
            $this->assertFalse($candidate['eligible']);
            $this->assertSame(MaterialSettlementScope::REASON_WORK_ORDER_IN_FLIGHT, $candidate['reason']);
        }
    }

    // ── 2. reading the current scope ─────────────────────────────────────────

    public function test_current_reads_a_well_formed_scope(): void
    {
        $workOrder = $this->workOrder([
            'material_settlement' => [
                'mode' => MaterialSettlementPolicy::MODE_OPERATION,
                'materials' => [$this->piece->id],
                'enabled_by' => $this->admin->id,
                'enabled_at' => '2026-11-20T10:00:00+00:00',
            ],
        ]);

        $current = $this->scope()->current($workOrder);

        $this->assertTrue($current['enabled']);
        $this->assertSame([$this->piece->id], $current['materials']);
        $this->assertSame($this->admin->id, $current['enabled_by']);
        $this->assertSame('2026-11-20T10:00:00+00:00', $current['enabled_at']);
        $this->assertNull($current['disabled_by']);
        $this->assertNull($current['disabled_at']);
    }

    public function test_current_treats_a_malformed_scope_as_disabled(): void
    {
        $shapes = [
            'not-an-array' => ['material_settlement' => 'on'],
            'mode-only' => ['material_settlement' => ['mode' => MaterialSettlementPolicy::MODE_OPERATION]],
            'materials-not-a-list' => ['material_settlement' => [
                'mode' => MaterialSettlementPolicy::MODE_OPERATION,
                'materials' => 'all',
            ]],
            'empty-materials' => ['material_settlement' => [
                'mode' => MaterialSettlementPolicy::MODE_OPERATION,
                'materials' => [],
            ]],
        ];

        foreach ($shapes as $label => $extraData) {
            $current = $this->scope()->current($this->workOrder($extraData, orderNo: 'WO-RQ265-'.$label));

            $this->assertFalse($current['enabled'], $label.' must read as disabled');
            $this->assertSame([], $current['materials'], $label.' must expose no materials');
        }
    }

    public function test_current_reports_the_disable_trace(): void
    {
        $workOrder = $this->workOrder([
            'material_settlement' => [
                'mode' => MaterialSettlementPolicy::MODE_LEGACY,
                'disabled_by' => $this->admin->id,
                'disabled_at' => '2026-11-21T08:30:00+00:00',
            ],
        ]);

        $current = $this->scope()->current($workOrder);

        $this->assertFalse($current['enabled']);
        $this->assertSame($this->admin->id, $current['disabled_by']);
        $this->assertSame('2026-11-21T08:30:00+00:00', $current['disabled_at']);
    }

    // ── 3. enabling and disabling ────────────────────────────────────────────

    public function test_enable_writes_the_scope_and_keeps_other_extra_data(): void
    {
        $workOrder = $this->workOrder(['print_note' => 'keep me', 'labels' => ['A', 'B']]);

        $updated = $this->scope()->enable($workOrder, [$this->piece->id], $this->admin);

        $extra = $updated->fresh()->extra_data;
        $this->assertSame('keep me', $extra['print_note']);
        $this->assertSame(['A', 'B'], $extra['labels']);

        $current = $this->scope()->current($updated->fresh());
        $this->assertTrue($current['enabled']);
        $this->assertSame([$this->piece->id], $current['materials']);
        $this->assertSame($this->admin->id, $current['enabled_by']);
        $this->assertNotNull($current['enabled_at']);
        $this->assertSame(MaterialSettlementPolicy::MODE_OPERATION, $extra['material_settlement']['mode']);
    }

    public function test_enable_leaves_an_audit_trail_on_the_work_order(): void
    {
        $workOrder = $this->workOrder();

        $this->scope()->enable($workOrder, [$this->piece->id], $this->admin);

        $log = AuditLog::query()
            ->where('entity_type', WorkOrder::class)
            ->where('entity_id', $workOrder->id)
            ->where('action', 'updated')
            ->orderBy('id')
            ->get()
            ->last(fn (AuditLog $candidate) => str_contains((string) json_encode($candidate->after_state), 'material_settlement'));

        $this->assertNotNull($log, 'Enabling the scope must be audited on the work order.');

        $after = $log->after_state;
        if (is_string($after)) {
            $after = json_decode($after, true);
        }

        $this->assertArrayHasKey('extra_data', $after);
        $extra = is_string($after['extra_data']) ? json_decode($after['extra_data'], true) : $after['extra_data'];
        $this->assertSame(MaterialSettlementPolicy::MODE_OPERATION, $extra['material_settlement']['mode']);
        $this->assertSame([$this->piece->id], $extra['material_settlement']['materials']);
    }

    public function test_enabling_writes_one_audit_entry_not_two(): void
    {
        $workOrder = $this->workOrder();

        $this->scope()->enable($workOrder, [$this->piece->id], $this->admin);

        // The model trait already writes the entry here, so the console
        // fallback must stay silent — two rows would read as two decisions.
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('entity_type', WorkOrder::class)
                ->where('entity_id', $workOrder->id)
                ->where('action', 'updated')
                ->count(),
        );
    }

    public function test_the_console_audit_entry_names_the_actor_and_the_scope(): void
    {
        // PHPUnit always runs as unit tests, so the console branch of the
        // service cannot be reached through enable() here; the entry itself is
        // exercised directly, without depending on the environment.
        $consoleScope = new class extends MaterialSettlementScope
        {
            /**
             * @param  array<string, mixed>  $before
             */
            public function writeConsoleAudit(WorkOrder $workOrder, User $actor, array $before): void
            {
                $this->recordConsoleAudit($workOrder, $actor, $before);
            }
        };

        $workOrder = $this->workOrder(['print_note' => 'keep me']);
        $before = $workOrder->extra_data;

        $consoleScope->enable($workOrder, [$this->piece->id], $this->admin);
        $consoleScope->writeConsoleAudit($workOrder->fresh(), $this->admin, $before);

        $logs = AuditLog::query()
            ->where('entity_type', WorkOrder::class)
            ->where('entity_id', $workOrder->id)
            ->where('action', 'updated')
            ->where('user_agent', 'console')
            ->get();

        $this->assertCount(1, $logs, 'A console change must leave exactly one audit entry.');

        $log = $logs->first();
        $this->assertSame($this->admin->id, (int) $log->user_id);
        $this->assertSame(['extra_data' => $before], $log->before_state);
        $this->assertNull($log->ip_address);
        $this->assertSame(
            MaterialSettlementPolicy::MODE_OPERATION,
            $log->after_state['extra_data']['material_settlement']['mode'],
        );
        $this->assertSame([$this->piece->id], $log->after_state['extra_data']['material_settlement']['materials']);
        $this->assertSame($this->admin->id, (int) $log->after_state['extra_data']['material_settlement']['enabled_by']);
    }

    public function test_enable_refuses_an_empty_selection(): void
    {
        $workOrder = $this->workOrder();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(MaterialSettlementScope::ERROR_EMPTY);

        $this->scope()->enable($workOrder, [], $this->admin);
    }

    public function test_enable_refuses_a_material_that_is_not_eligible(): void
    {
        $workOrder = $this->workOrder();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(MaterialSettlementScope::ERROR_INELIGIBLE);

        $this->scope()->enable($workOrder, [$this->carton->id], $this->admin);
    }

    public function test_enable_refuses_an_already_in_flight_order(): void
    {
        [$workOrder, $material] = $this->inFlightOrder();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(MaterialSettlementScope::ERROR_WORK_ORDER_IN_FLIGHT);

        $this->scope()->enable($workOrder, [$material->id], $this->admin);
    }

    public function test_enable_refuses_an_empty_selection_before_anything_is_written(): void
    {
        $workOrder = $this->workOrder();

        try {
            $this->scope()->enable($workOrder, [], $this->admin);
        } catch (\DomainException) {
            // expected
        }

        $this->assertEmpty($workOrder->fresh()->extra_data, 'Nothing may be written.');
    }

    public function test_disable_records_the_decision_instead_of_erasing_it(): void
    {
        $workOrder = $this->workOrder(['print_note' => 'keep me']);
        $this->scope()->enable($workOrder, [$this->piece->id], $this->admin);

        $updated = $this->scope()->disable($workOrder->fresh(), $this->admin);

        $extra = $updated->fresh()->extra_data;
        $this->assertSame('keep me', $extra['print_note']);
        $this->assertSame(MaterialSettlementPolicy::MODE_LEGACY, $extra['material_settlement']['mode']);
        $this->assertSame($this->admin->id, $extra['material_settlement']['disabled_by']);
        $this->assertNotNull($extra['material_settlement']['disabled_at']);

        $current = $this->scope()->current($updated->fresh());
        $this->assertFalse($current['enabled']);
        $this->assertSame($this->admin->id, $current['disabled_by']);

        // The policy follows: the material is back on the legacy settlement.
        $this->assertSame(
            MaterialSettlementPolicy::MODE_LEGACY,
            app(MaterialSettlementPolicy::class)->modeFor($updated->fresh(), $this->piece->id),
        );
    }

    public function test_disable_refuses_an_order_with_an_open_allocation(): void
    {
        [$workOrder] = $this->inFlightOrder([
            'print_note' => 'keep me',
            'material_settlement' => [
                'mode' => MaterialSettlementPolicy::MODE_OPERATION,
                'materials' => [$this->carton->id],
                'enabled_by' => $this->admin->id,
                'enabled_at' => '2026-11-20T10:00:00+00:00',
            ],
        ]);

        $before = $workOrder->fresh()->extra_data;

        try {
            $this->scope()->disable($workOrder->fresh(), $this->admin);
            $this->fail('Disabling under an outstanding withdrawal must be refused.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString(MaterialSettlementScope::ERROR_WORK_ORDER_IN_FLIGHT, $exception->getMessage());
        }

        // Refused means nothing changed: the pilot stays on and the rest of the
        // order's extra_data is untouched.
        $after = $workOrder->fresh()->extra_data;
        $this->assertSame($before, $after);
        $this->assertSame(MaterialSettlementPolicy::MODE_OPERATION, $after['material_settlement']['mode']);
        $this->assertSame('keep me', $after['print_note']);
        $this->assertSame(
            MaterialSettlementPolicy::MODE_OPERATION,
            app(MaterialSettlementPolicy::class)->modeFor($workOrder->fresh(), $this->carton->id),
        );
    }

    public function test_disable_works_once_the_allocation_is_settled(): void
    {
        [$workOrder] = $this->inFlightOrder();

        MaterialAllocation::query()
            ->where('work_order_id', $workOrder->id)
            ->update(['status' => MaterialAllocation::STATUS_CONSUMED, 'consumed_qty' => 10]);

        $this->assertFalse($this->scope()->hasOpenAllocation($workOrder));

        $updated = $this->scope()->disable($workOrder->fresh(), $this->admin);

        $extra = $updated->fresh()->extra_data;
        $this->assertSame(MaterialSettlementPolicy::MODE_LEGACY, $extra['material_settlement']['mode']);
        $this->assertSame($this->admin->id, $extra['material_settlement']['disabled_by']);
    }

    // ── 4. the scope actually reaches the policy ─────────────────────────────

    public function test_an_enabled_scope_parks_an_allocation_as_unconfirmed(): void
    {
        $workOrder = $this->workOrder();

        // Enabled before anything is withdrawn — the only moment the tool allows.
        $this->scope()->enable($workOrder, [$this->piece->id], $this->admin);

        $batch = app(WorkOrderService::class)->createBatch($workOrder->fresh(), 10);
        app(BatchService::class)->startStep($batch->steps()->where('step_number', 1)->firstOrFail(), $this->operator);

        $allocation = MaterialAllocation::query()
            ->where('batch_id', $batch->id)
            ->where('material_id', $this->piece->id)
            ->firstOrFail();

        $policy = app(MaterialSettlementPolicy::class);

        $this->assertSame(
            MaterialSettlementPolicy::REASON_CONSUMPTION_NOT_CONFIRMED,
            $policy->pendingReason($allocation),
        );
        $this->assertSame(MaterialSettlementPolicy::STATE_PENDING, $policy->state($allocation));
    }
}
