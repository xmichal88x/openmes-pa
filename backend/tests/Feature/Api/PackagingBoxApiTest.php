<?php

namespace Tests\Feature\Api;

use App\Models\PackagingBox;
use App\Models\PackagingDispatch;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PackagingBoxApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_supervisor_can_create_list_and_show_boxes(): void
    {
        $wo = $this->workOrder();

        $create = $this->auth('Supervisor')->postJson('/api/v1/packaging/boxes', [
            'work_order_id' => $wo->id,
        ]);

        $create->assertStatus(201)
            ->assertJsonPath('data.box_no', 1)
            ->assertJsonPath('data.box_id', 'BOX-'.$wo->order_no.'-1')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.part_count', 0);

        $this->auth('Supervisor')
            ->getJson('/api/v1/packaging/boxes?work_order_id='.$wo->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->auth('Supervisor')
            ->getJson('/api/v1/packaging/boxes/'.$create->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.box_id', 'BOX-'.$wo->order_no.'-1');
    }

    public function test_assign_close_reopen_and_pallet_flow(): void
    {
        $wo = $this->workOrder();
        $box = $this->apiBox($wo);
        $code = $this->code($wo, WorkOrderEan::STATE_SCANNED);

        $this->authForLine('PackingWorker', $wo)
            ->postJson("/api/v1/packaging/boxes/{$box->id}/eans", ['ean' => $code->ean])
            ->assertOk()
            ->assertJsonPath('data.part_count', 1);

        $this->authForLine('PackingWorker', $wo)
            ->getJson("/api/v1/packaging/boxes/{$box->id}")
            ->assertOk()
            ->assertJsonPath('data.parts.0.ean', $code->ean);

        $this->authForLine('PackingWorker', $wo)
            ->postJson("/api/v1/packaging/boxes/{$box->id}/close", ['weight_kg' => 1.5])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->authForLine('PackingWorker', $wo)
            ->postJson("/api/v1/packaging/boxes/{$box->id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'open');

        $this->authForLine('PackingWorker', $wo)
            ->postJson("/api/v1/packaging/boxes/{$box->id}/close")
            ->assertOk();

        $pallet = $this->authForLine('PackingWorker', $wo)
            ->postJson('/api/v1/packaging/pallets', ['work_order_id' => $wo->id])
            ->assertStatus(201)
            ->assertJsonPath('data.kind', 'boxes')
            ->json('data');

        $this->authForLine('PackingWorker', $wo)
            ->postJson("/api/v1/packaging/boxes/{$box->id}/pallet", ['pallet_id' => $pallet['id']])
            ->assertOk()
            ->assertJsonPath('data.pallet_no', $pallet['pallet_no'])
            ->assertJsonPath('pallet_qty', 1);

        $this->authForLine('PackingWorker', $wo)
            ->deleteJson("/api/v1/packaging/boxes/{$box->id}/pallet")
            ->assertOk()
            ->assertJsonPath('data.pallet_id', null)
            ->assertJsonPath('pallet_qty', 0);

        $this->authForLine('PackingWorker', $wo)
            ->getJson('/api/v1/packaging/pallets?work_order_id='.$wo->id)
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'boxes');
    }

    public function test_empty_box_can_be_deleted_but_a_closed_one_cannot(): void
    {
        $wo = $this->workOrder();
        $empty = $this->apiBox($wo);

        $this->auth('Supervisor')->deleteJson("/api/v1/packaging/boxes/{$empty->id}")->assertNoContent();
        $this->assertDatabaseMissing('packaging_boxes', ['id' => $empty->id]);

        $full = $this->packedBox($wo);
        $this->auth('Supervisor')->deleteJson("/api/v1/packaging/boxes/{$full->id}")->assertStatus(409);
    }

    public function test_api_scan_can_target_a_box(): void
    {
        $wo = $this->workOrder();
        $box = $this->apiBox($wo);
        $code = $this->code($wo, WorkOrderEan::STATE_EXPECTED);

        $this->authForLine('PackingWorker', $wo)
            ->postJson('/api/v1/packaging/scan', ['ean' => $code->ean, 'box_id' => $box->box_id])
            ->assertOk()
            ->assertJsonPath('data.scan.box_id', $box->box_id);

        $this->assertSame($box->id, $code->fresh()->packaging_box_id);
        $this->assertSame(1, $box->fresh()->part_count);
    }

    public function test_dispatch_boxes_claim_and_finish(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);
        $key = (string) Str::uuid();

        $create = $this->auth('Supervisor')->postJson('/api/v1/packaging/dispatch', [
            'work_order_id' => $wo->id,
            'idempotency_key' => $key,
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => 'test override',
        ]);

        $create->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.scanned_boxes.0', $box->box_id)
            ->assertJsonPath('data.source', 'operator');

        $id = $create->json('data.id');

        $this->auth('Supervisor')
            ->getJson('/api/v1/packaging/dispatches?work_order_id='.$wo->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        // Result reporting belongs to the bridge account, not to a human role.
        $this->asBridge()
            ->patchJson("/api/v1/packaging/dispatches/{$id}", [
                'status' => 'processing',
                'lock_token' => 'pa-1',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.lock_token', 'pa-1');

        $this->asBridge()
            ->patchJson("/api/v1/packaging/dispatches/{$id}", [
                'status' => 'done',
                'lock_token' => 'pa-1',
                'shipment_id' => 77,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.shipment_id', 77);
    }

    public function test_dispatch_payload_must_choose_boxes_or_pallets(): void
    {
        $wo = $this->workOrder();

        $this->auth('Supervisor')->postJson('/api/v1/packaging/dispatch', [
            'work_order_id' => $wo->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(422)->assertJsonValidationErrors(['scanned_boxes']);
    }

    public function test_dispatch_rejects_a_non_uuid_idempotency_key(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);

        $this->auth('Supervisor')->postJson('/api/v1/packaging/dispatch', [
            'work_order_id' => $wo->id,
            'idempotency_key' => 'not-a-uuid',
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => 'test override',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);

        $this->assertDatabaseMissing('packaging_dispatches', ['work_order_id' => $wo->id]);
    }

    public function test_direct_box_dispatch_needs_an_explicit_qc_override_and_reason(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);

        $this->auth('Supervisor')->postJson('/api/v1/packaging/dispatch', [
            'work_order_id' => $wo->id,
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$box->box_id],
        ])->assertStatus(409);

        $this->auth('Supervisor')->postJson('/api/v1/packaging/dispatch', [
            'work_order_id' => $wo->id,
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => 'customer collects cartons without a pallet',
        ])->assertCreated()
            ->assertJsonPath('data.reason', 'customer collects cartons without a pallet')
            ->assertJsonPath('data.scanned_boxes.0', $box->box_id);
    }

    public function test_pending_dispatch_can_be_cancelled(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);

        $id = $this->auth('Supervisor')->postJson('/api/v1/packaging/dispatch', [
            'work_order_id' => $wo->id,
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => 'test override',
        ])->json('data.id');

        $this->auth('Supervisor')
            ->postJson("/api/v1/packaging/dispatches/{$id}/cancel", ['reason' => 'Wrong carrier'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Wrong carrier');
    }

    public function test_external_close_is_idempotent_over_http(): void
    {
        $wo = $this->workOrder();
        $key = (string) Str::uuid();
        $payload = [
            'work_order_id' => $wo->id,
            'idempotency_key' => $key,
            'shipment_id' => 900,
            'shipment_status' => 'closed',
        ];

        $first = $this->auth('PackingBridge')->postJson('/api/v1/packaging/dispatches/external-close', $payload);
        $first->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.source', 'external');

        $second = $this->auth('PackingBridge')->postJson('/api/v1/packaging/dispatches/external-close', $payload);
        $second->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, PackagingDispatch::count());
    }

    public function test_external_close_rejects_a_non_uuid_idempotency_key(): void
    {
        $wo = $this->workOrder();

        $this->auth('PackingBridge')->postJson('/api/v1/packaging/dispatches/external-close', [
            'work_order_id' => $wo->id,
            'idempotency_key' => 'not-a-uuid',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);

        $this->assertDatabaseMissing('packaging_dispatches', ['work_order_id' => $wo->id]);
    }

    public function test_packing_worker_is_limited_to_their_line(): void
    {
        $wo = $this->workOrder();
        $worker = User::factory()->create();
        $worker->assignRole('PackingWorker');
        $token = $worker->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/packaging/boxes', ['work_order_id' => $wo->id])
            ->assertStatus(403);

        $worker->lines()->attach($wo->line_id);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/packaging/boxes', ['work_order_id' => $wo->id])
            ->assertStatus(201);
    }

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/v1/packaging/boxes')->assertStatus(401);
    }

    public function test_web_station_routes_share_the_same_contract(): void
    {
        $wo = $this->workOrder();
        $supervisor = User::factory()->create();
        $supervisor->assignRole('Supervisor');

        $create = $this->actingAs($supervisor)
            ->postJson('/packaging/boxes', ['work_order_id' => $wo->id])
            ->assertStatus(201);

        $this->actingAs($supervisor)
            ->getJson('/packaging/boxes?work_order_id='.$wo->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $create->json('data.id'));
    }

    private function workOrder(): WorkOrder
    {
        return WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 50,
        ]);
    }

    private function apiBox(WorkOrder $wo): PackagingBox
    {
        $id = $this->auth('Supervisor')->postJson('/api/v1/packaging/boxes', [
            'work_order_id' => $wo->id,
        ])->assertStatus(201)->json('data.id');

        return PackagingBox::findOrFail($id);
    }

    private function packedBox(WorkOrder $wo): PackagingBox
    {
        $box = app(\App\Services\Packaging\PackagingBoxService::class)->create($wo);
        $code = $this->code($wo, WorkOrderEan::STATE_SCANNED);
        app(\App\Services\Packaging\PackagingBoxService::class)->assignCode($box, $code);

        return app(\App\Services\Packaging\PackagingBoxService::class)->close($box);
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

    private function authForLine(string $role, WorkOrder $workOrder)
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        if (in_array($role, ['Operator', 'PackingWorker'], true)) {
            $user->lines()->attach($workOrder->line_id);
        }

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test')->plainTextToken);
    }

    private function auth(string $role)
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test')->plainTextToken);
    }

    private function asBridge()
    {
        $user = User::factory()->create();
        $user->assignRole('PackingBridge');

        return $this->actingAs($user, 'sanctum');
    }
}
