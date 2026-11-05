<?php

namespace Tests\Feature\Packaging;

use App\Models\Line;
use App\Models\PackagingBox;
use App\Models\PackagingDispatch;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PackagingLegacyTest extends TestCase
{
    use RefreshDatabase;

    private Line $packingLine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->packingLine = Line::factory()->create(['code' => 'PACKING']);
    }

    public function test_legacy_done_order_without_cartons_is_flagged_and_dispatch_returns_409(): void
    {
        $wo = $this->workOrder(WorkOrder::STATUS_DONE);

        $this->actingAs($this->supervisor())
            ->getJson('/api/v1/packaging/boxes?work_order_id='.$wo->id)
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.legacy_without_boxes', true);

        $this->actingAs($this->supervisor())
            ->postJson('/api/v1/packaging/dispatch', [
                'work_order_id' => $wo->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This work order has no cartons - create cartons in the packing station before dispatch.');

        $this->assertSame(0, PackagingDispatch::count());
    }

    public function test_completing_a_packing_order_does_not_create_default_cartons(): void
    {
        $wo = $this->workOrder(WorkOrder::STATUS_IN_PROGRESS);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->postJson('/api/v1/work-orders/'.$wo->id.'/complete')
            ->assertOk()
            ->assertJsonPath('data.status', WorkOrder::STATUS_DONE);

        $this->assertSame(0, PackagingBox::where('work_order_id', $wo->id)->count());
    }

    public function test_done_order_with_cartons_keeps_the_normal_dispatch_path(): void
    {
        $wo = $this->workOrder(WorkOrder::STATUS_DONE);
        $box = app(\App\Services\Packaging\PackagingBoxService::class)->create($wo);
        $code = \App\Models\WorkOrderEan::create([
            'work_order_id' => $wo->id,
            'ean' => 'LBL-'.Str::random(10),
            'code_type' => \App\Models\WorkOrderEan::CODE_TYPE_PART_LABEL,
            'state' => \App\Models\WorkOrderEan::STATE_SCANNED,
        ]);
        app(\App\Services\Packaging\PackagingBoxService::class)->assignCode($box, $code);
        app(\App\Services\Packaging\PackagingBoxService::class)->close($box);

        $this->actingAs($this->supervisor())
            ->getJson('/api/v1/packaging/boxes?work_order_id='.$wo->id)
            ->assertOk()
            ->assertJsonPath('meta.legacy_without_boxes', false);
    }

    private function supervisor(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Supervisor');

        return $user;
    }

    private function workOrder(string $status): WorkOrder
    {
        return WorkOrder::factory()->create([
            'line_id' => $this->packingLine->id,
            'status' => $status,
            'planned_qty' => 50,
        ]);
    }
}
