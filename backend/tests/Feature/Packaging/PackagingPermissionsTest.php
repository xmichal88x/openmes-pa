<?php

namespace Tests\Feature\Packaging;

use App\Models\Line;
use App\Models\PackagingBox;
use App\Models\PackagingDispatch;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use App\Services\Packaging\PackagingBoxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PackagingPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Line $packingLine;

    private Line $cncLine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->packingLine = Line::factory()->create(['code' => 'PACKING']);
        $this->cncLine = Line::factory()->create(['code' => 'CNC']);
    }

    public function test_roles_carry_only_their_narrow_packaging_grants(): void
    {
        $worker = Role::findByName('PackingWorker', 'web');
        $bridge = Role::findByName('PackingBridge', 'web');

        $this->assertTrue($worker->hasPermissionTo('view work orders'));
        $this->assertTrue($worker->hasPermissionTo('dispatch packing'));
        $this->assertFalse($worker->hasPermissionTo('manage packing work orders'));
        $this->assertFalse($worker->hasPermissionTo('process packing dispatch'));

        $this->assertTrue($bridge->hasPermissionTo('view work orders'));
        $this->assertTrue($bridge->hasPermissionTo('manage packing work orders'));
        $this->assertTrue($bridge->hasPermissionTo('process packing dispatch'));
        $this->assertFalse($bridge->hasPermissionTo('dispatch packing'));
        $this->assertFalse($bridge->hasPermissionTo('create work orders'));
    }

    public function test_operator_cannot_dispatch_or_create_cartons_even_on_own_line(): void
    {
        $wo = $this->workOrder($this->packingLine);
        $operator = $this->user('Operator', [$this->packingLine]);

        $this->auth($operator)
            ->postJson('/api/v1/packaging/boxes', ['work_order_id' => $wo->id])
            ->assertForbidden();

        $box = $this->packedBox($wo);

        $this->auth($operator)
            ->postJson('/api/v1/packaging/dispatch', [
                'work_order_id' => $wo->id,
                'idempotency_key' => (string) Str::uuid(),
                'scanned_boxes' => [$box->box_id],
                'qc_override' => true,
                'reason' => 'test override',
            ])
            ->assertForbidden();
    }

    public function test_packing_worker_creates_cartons_and_dispatches_on_packing_line(): void
    {
        $wo = $this->workOrder($this->packingLine, WorkOrder::STATUS_DONE);
        $worker = $this->user('PackingWorker', [$this->packingLine]);

        $boxId = $this->auth($worker)
            ->postJson('/api/v1/packaging/boxes', ['work_order_id' => $wo->id])
            ->assertCreated()
            ->json('data.id');

        $box = PackagingBox::findOrFail($boxId);
        app(PackagingBoxService::class)->assignCode($box, $this->code($wo, WorkOrderEan::STATE_SCANNED));
        app(PackagingBoxService::class)->close($box);

        $this->auth($worker)
            ->postJson('/api/v1/packaging/dispatch', [
                'work_order_id' => $wo->id,
                'idempotency_key' => (string) Str::uuid(),
                'scanned_boxes' => [$box->box_id],
                'qc_override' => true,
                'reason' => 'test override',
            ])
            ->assertCreated();
    }

    public function test_packing_worker_is_rejected_outside_their_line(): void
    {
        $packingWo = $this->workOrder($this->packingLine);
        $cncWo = $this->workOrder($this->cncLine);

        $worker = $this->user('PackingWorker', [$this->cncLine]);

        $this->auth($worker)
            ->postJson('/api/v1/packaging/boxes', ['work_order_id' => $packingWo->id])
            ->assertForbidden();

        $worker->lines()->sync([$this->packingLine->id]);

        $this->auth($worker)
            ->postJson('/api/v1/packaging/boxes', ['work_order_id' => $cncWo->id])
            ->assertForbidden();
    }

    public function test_bridge_manages_only_packing_work_orders(): void
    {
        $bridge = $this->user('PackingBridge', [$this->packingLine]);

        $created = $this->auth($bridge)->postJson('/api/v1/work-orders', [
            'order_no' => 'PACK-'.Str::random(6),
            'line_id' => $this->packingLine->id,
            'planned_qty' => 3,
            'status' => WorkOrder::STATUS_IN_PROGRESS,
        ])->assertCreated()->json('data');

        $packWo = WorkOrder::findOrFail($created['id']);
        $packWo->update(['status' => WorkOrder::STATUS_IN_PROGRESS]);

        $this->auth($bridge)
            ->patchJson('/api/v1/work-orders/'.$packWo->id, ['planned_qty' => 4])
            ->assertOk();

        $this->auth($bridge)
            ->postJson('/api/v1/work-orders/'.$packWo->id.'/complete')
            ->assertOk()
            ->assertJsonPath('data.status', WorkOrder::STATUS_DONE);

        $cncWo = $this->workOrder($this->cncLine, WorkOrder::STATUS_IN_PROGRESS);

        $this->auth($bridge)->postJson('/api/v1/work-orders', [
            'order_no' => 'CNC-'.Str::random(6),
            'line_id' => $this->cncLine->id,
            'planned_qty' => 3,
        ])->assertForbidden();

        $this->auth($bridge)
            ->patchJson('/api/v1/work-orders/'.$cncWo->id, ['planned_qty' => 4])
            ->assertForbidden();

        $this->auth($bridge)
            ->postJson('/api/v1/work-orders/'.$cncWo->id.'/complete')
            ->assertForbidden();
    }

    public function test_bridge_processes_the_outbox_but_cannot_create_cartons_or_dispatch(): void
    {
        $wo = $this->workOrder($this->packingLine, WorkOrder::STATUS_DONE);
        $bridge = $this->user('PackingBridge', [$this->packingLine]);
        $worker = $this->user('PackingWorker', [$this->packingLine]);

        $dispatch = PackagingDispatch::create([
            'work_order_id' => $wo->id,
            'order_no' => $wo->order_no,
            'idempotency_key' => (string) Str::uuid(),
            'status' => PackagingDispatch::STATUS_PENDING,
        ]);

        $this->auth($worker)
            ->patchJson('/api/v1/packaging/dispatches/'.$dispatch->id, [
                'status' => PackagingDispatch::STATUS_PROCESSING,
                'lock_token' => 'worker-token',
            ])
            ->assertForbidden();

        // The test application reuses the resolved Sanctum user between requests.
        $this->app['auth']->forgetGuards();

        $this->auth($bridge)
            ->patchJson('/api/v1/packaging/dispatches/'.$dispatch->id, [
                'status' => PackagingDispatch::STATUS_PROCESSING,
                'lock_token' => 'bridge-token',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', PackagingDispatch::STATUS_PROCESSING);

        $this->auth($bridge)
            ->postJson('/api/v1/packaging/boxes', ['work_order_id' => $wo->id])
            ->assertForbidden();
    }

    private function user(string $role, array $lines = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        if ($lines !== []) {
            $user->lines()->sync(array_map(fn (Line $line) => $line->id, $lines));
        }

        return $user;
    }

    private function auth(User $user)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer '.$user->createToken('test')->plainTextToken,
        );
    }

    private function workOrder(Line $line, string $status = WorkOrder::STATUS_IN_PROGRESS): WorkOrder
    {
        return WorkOrder::factory()->create([
            'line_id' => $line->id,
            'status' => $status,
            'planned_qty' => 50,
        ]);
    }

    private function code(WorkOrder $wo, string $state): WorkOrderEan
    {
        return WorkOrderEan::create([
            'work_order_id' => $wo->id,
            'ean' => 'LBL-'.Str::random(10),
            'code_type' => WorkOrderEan::CODE_TYPE_PART_LABEL,
            'state' => $state,
        ]);
    }

    private function packedBox(WorkOrder $wo): PackagingBox
    {
        $box = app(PackagingBoxService::class)->create($wo);
        app(PackagingBoxService::class)->assignCode($box, $this->code($wo, WorkOrderEan::STATE_SCANNED));

        return app(PackagingBoxService::class)->close($box);
    }
}
