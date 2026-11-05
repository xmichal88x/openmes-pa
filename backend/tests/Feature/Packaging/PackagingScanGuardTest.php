<?php

namespace Tests\Feature\Packaging;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1 scan guards on the web station: one EAN = one piece.
 *
 * Duplicate, voided and cross-order codes are refused before they touch
 * packed_qty; a pinned (active) order rejects codes from another order.
 */
class PackagingScanGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');
    }

    public function test_duplicate_scan_is_rejected_and_does_not_double_count(): void
    {
        $wo = WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 5,
            'packed_qty' => 0,
        ]);
        WorkOrderEan::create(['work_order_id' => $wo->id, 'ean' => 'DUP-1']);

        $this->actingAs($this->operator)
            ->postJson('/packaging/scan', ['ean' => 'DUP-1'])
            ->assertOk()
            ->assertJsonPath('remaining', 0)
            ->assertJsonPath('expected_total', 1);

        $this->actingAs($this->operator)
            ->postJson('/packaging/scan', ['ean' => 'DUP-1'])
            ->assertStatus(409);

        $this->assertSame(1, (int) $wo->fresh()->packed_qty);
        $this->assertDatabaseCount('packaging_scan_logs', 1);
        $this->assertSame(WorkOrderEan::STATE_SCANNED, WorkOrderEan::where('ean', 'DUP-1')->value('state'));
    }

    public function test_voided_code_is_rejected(): void
    {
        $wo = WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 5,
            'packed_qty' => 0,
        ]);
        WorkOrderEan::create([
            'work_order_id' => $wo->id,
            'ean' => 'VOID-1',
            'state' => WorkOrderEan::STATE_VOID,
        ]);

        $this->actingAs($this->operator)
            ->postJson('/packaging/scan', ['ean' => 'VOID-1'])
            ->assertStatus(409);

        $this->assertSame(0, (int) $wo->fresh()->packed_qty);
        $this->assertDatabaseCount('packaging_scan_logs', 0);
        $this->assertSame(WorkOrderEan::STATE_VOID, WorkOrderEan::where('ean', 'VOID-1')->value('state'));
    }

    public function test_code_from_another_work_order_is_rejected_when_pinned(): void
    {
        $woA = WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 5,
            'packed_qty' => 0,
        ]);
        $woB = WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 5,
            'packed_qty' => 0,
        ]);
        WorkOrderEan::create(['work_order_id' => $woA->id, 'ean' => 'PIN-A']);

        $this->actingAs($this->operator)
            ->postJson('/packaging/scan', ['ean' => 'PIN-A', 'work_order_id' => $woB->id])
            ->assertStatus(409);

        $this->assertSame(0, (int) $woA->fresh()->packed_qty);
        $this->assertSame(0, (int) $woB->fresh()->packed_qty);
        $this->assertDatabaseCount('packaging_scan_logs', 0);
    }

    public function test_happy_path_packs_piece_and_remaining_drops(): void
    {
        $wo = WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 3,
            'packed_qty' => 0,
        ]);
        foreach (['HAPPY-1', 'HAPPY-2', 'HAPPY-3'] as $ean) {
            WorkOrderEan::create(['work_order_id' => $wo->id, 'ean' => $ean]);
        }

        $this->actingAs($this->operator)
            ->postJson('/packaging/scan', ['ean' => 'HAPPY-1', 'work_order_id' => $wo->id])
            ->assertOk()
            ->assertJsonPath('work_order.packed_qty', 1)
            ->assertJsonPath('remaining', 2)
            ->assertJsonPath('expected_total', 3);

        $this->assertSame(1, (int) $wo->fresh()->packed_qty);
        $this->assertSame(WorkOrderEan::STATE_SCANNED, WorkOrderEan::where('ean', 'HAPPY-1')->value('state'));
        $this->assertDatabaseCount('packaging_scan_logs', 1);
    }

    /**
     * 0.27 keeps the upstream behaviour: without a pin the code is packed
     * against its own work order, never against a guessed "active" order.
     */
    public function test_code_from_another_work_order_scans_its_own_order_without_pin(): void
    {
        $woA = WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 5,
            'packed_qty' => 0,
        ]);
        $woB = WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 5,
            'packed_qty' => 0,
        ]);
        WorkOrderEan::create(['work_order_id' => $woA->id, 'ean' => 'OTHER-A']);
        WorkOrderEan::create(['work_order_id' => $woB->id, 'ean' => 'OTHER-B']);

        $this->actingAs($this->operator)
            ->postJson('/packaging/scan', ['ean' => 'OTHER-B'])
            ->assertOk()
            ->assertJsonPath('work_order.id', $woB->id);

        $this->assertSame(0, (int) $woA->fresh()->packed_qty);
        $this->assertSame(1, (int) $woB->fresh()->packed_qty);
    }

    public function test_fully_packed_order_is_rejected(): void
    {
        $wo = WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_DONE,
            'planned_qty' => 5,
            'packed_qty' => 5,
        ]);
        WorkOrderEan::create(['work_order_id' => $wo->id, 'ean' => 'FULL-1']);

        $this->actingAs($this->operator)
            ->postJson('/packaging/scan', ['ean' => 'FULL-1'])
            ->assertStatus(422);

        $this->assertSame(5, (int) $wo->fresh()->packed_qty);
        $this->assertDatabaseCount('packaging_scan_logs', 0);
    }
}
