<?php

namespace Tests\Feature\Operator;

use App\Models\Line;
use App\Models\ProductType;
use App\Models\SerialUnit;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReworkPageTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $supervisor;

    private User $admin;

    private ProductType $productType;

    private Line $line;

    private WorkOrder $workOrder;

    private SerialUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Operator', 'Supervisor', 'Admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');
        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('Supervisor');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->productType = ProductType::factory()->create(['code' => 'MDF-18']);
        $this->line = Line::factory()->create();
        $this->workOrder = WorkOrder::factory()->create([
            'line_id' => $this->line->id,
            'product_type_id' => $this->productType->id,
        ]);

        $this->unit = SerialUnit::factory()->create([
            'serial_no' => 'SN-RW-001',
            'psn' => 'P-RW-001',
            'status' => SerialUnit::STATUS_BLOCKED,
            'work_order_id' => $this->workOrder->id,
            'extra_data' => ['hold' => [
                'source' => 'manual',
                'reason_code' => 'NC-01',
                'reason' => 'Cosmetic defect',
            ]],
        ]);
    }

    public function test_supervisor_sees_blocked_units_on_the_rework_page(): void
    {
        $this->actingAs($this->supervisor)
            ->get(route('operator.rework.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('operator/rework/Index')
                ->where('canUnblock', true)
                ->has('units', 1)
                ->where('units.0.id', $this->unit->id)
                ->where('units.0.serial_no', 'SN-RW-001')
                ->where('units.0.psn', 'P-RW-001')
                ->where('units.0.product_type_code', 'MDF-18')
                ->where('units.0.order_no', $this->workOrder->order_no)
                ->where('units.0.hold_reason', 'Cosmetic defect'));
    }

    public function test_admin_sees_the_rework_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('operator.rework.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('operator/rework/Index')
                ->where('canUnblock', true));
    }

    public function test_operator_is_forbidden_from_the_rework_page(): void
    {
        $this->actingAs($this->operator)
            ->get(route('operator.rework.index'))
            ->assertForbidden();
    }

    public function test_unblocking_with_a_note_returns_the_unit_to_production(): void
    {
        $this->actingAs($this->supervisor)
            ->postJson(route('operator.unit-labels.unblock', $this->unit), ['note' => 'Polished, OK'])
            ->assertOk()
            ->assertJsonPath('unit.status', SerialUnit::STATUS_IN_PRODUCTION)
            ->assertJsonPath('unit.hold', null);

        $this->assertSame(
            'unblocked',
            $this->unit->history()->where('parameters->event', 'unblocked')->firstOrFail()->parameters['event'],
        );

        $packed = SerialUnit::factory()->create([
            'serial_no' => 'SN-RW-PACKED',
            'status' => SerialUnit::STATUS_BLOCKED,
            'work_order_id' => $this->workOrder->id,
            'packed_at' => now(),
        ]);

        $this->actingAs($this->supervisor)
            ->postJson(route('operator.unit-labels.unblock', $packed), ['note' => 'Repacked'])
            ->assertOk()
            ->assertJsonPath('unit.status', SerialUnit::STATUS_COMPLETED);
    }

    public function test_scrapping_with_a_reason_marks_the_unit_scrapped(): void
    {
        $this->actingAs($this->supervisor)
            ->postJson(route('operator.unit-labels.scrap', $this->unit), ['reason' => 'Beyond repair'])
            ->assertOk()
            ->assertJsonPath('unit.status', SerialUnit::STATUS_SCRAPPED);

        $this->assertSame(
            'scrapped',
            $this->unit->history()->where('parameters->event', 'scrapped')->firstOrFail()->parameters['event'],
        );
    }

    public function test_unblocking_a_unit_that_is_not_blocked_is_refused(): void
    {
        $unit = SerialUnit::factory()->create([
            'serial_no' => 'SN-RW-OK',
            'status' => SerialUnit::STATUS_IN_PRODUCTION,
            'work_order_id' => $this->workOrder->id,
        ]);

        $this->actingAs($this->supervisor)
            ->postJson(route('operator.unit-labels.unblock', $unit), ['note' => 'Nothing to release'])
            ->assertStatus(422);
    }
}
