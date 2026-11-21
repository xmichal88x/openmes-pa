<?php

namespace Tests\Feature\Web\Operator;

use App\Models\Batch;
use App\Models\Line;
use App\Models\SerialUnit;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * P2.7: the operator screen shows the quality the server derived, with the
 * breakdown behind it - it no longer has a formula of its own (two different
 * percentages on one screen was the risk).
 */
class WorkOrderQualityPropTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_carries_the_quality_breakdown(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $line = Line::factory()->create();
        $operator = User::factory()->create();
        $operator->assignRole('Operator');
        $operator->lines()->attach($line->id);

        $workOrder = WorkOrder::factory()->create(['line_id' => $line->id, 'produced_qty' => 3]);
        $batch = Batch::factory()->create([
            'work_order_id' => $workOrder->id,
            'status' => Batch::STATUS_DONE,
            'produced_qty' => 3,
        ]);

        foreach (range(1, 3) as $_) {
            SerialUnit::factory()->create([
                'work_order_id' => $workOrder->id,
                'batch_id' => $batch->id,
                'status' => SerialUnit::STATUS_COMPLETED,
                'packed_at' => now(),
            ]);
        }
        SerialUnit::factory()->create([
            'work_order_id' => $workOrder->id,
            'batch_id' => $batch->id,
            'status' => SerialUnit::STATUS_SCRAPPED,
        ]);

        $this->actingAs($operator)
            ->withSession(['selected_line_id' => $line->id])
            ->get(route('operator.work-order.detail', $workOrder))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('operator/WorkOrderDetail')
                ->where('quality.basis', 'units')
                ->where('quality.good_qty', 3)
                ->where('quality.scrap_qty', 1)
                ->where('quality.scrap_entries_qty', 0)
                ->where('quality.scrapped_units_qty', 1)
                ->where('quality.total_qty', 4)
                ->where('quality.quality_pct', 75));
    }
}
