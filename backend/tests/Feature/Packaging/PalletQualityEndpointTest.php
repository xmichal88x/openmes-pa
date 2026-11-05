<?php

namespace Tests\Feature\Packaging;

use App\Enums\PalletStatus;
use App\Models\Line;
use App\Models\PackagingDispatch;
use App\Models\Pallet;
use App\Models\PalletQualityDecision;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PalletQualityEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Line $packingLine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->packingLine = Line::factory()->create(['code' => 'PACKING']);
    }

    public function test_packing_worker_records_a_quality_pass_and_audit(): void
    {
        $worker = $this->user('PackingWorker');
        $pallet = $this->pallet();

        $this->auth($worker)
            ->postJson("/api/v1/packaging/pallets/{$pallet->id}/quality", ['status' => 'pass'])
            ->assertOk()
            ->assertJsonPath('data.pallet_no', $pallet->pallet_no)
            ->assertJsonPath('data.quality_status', Pallet::QUALITY_PASS);

        $this->assertDatabaseHas('pallets', [
            'id' => $pallet->id,
            'quality_status' => Pallet::QUALITY_PASS,
        ]);
        $this->assertDatabaseHas('pallet_quality_decisions', [
            'pallet_id' => $pallet->id,
            'status' => Pallet::QUALITY_PASS,
            'decided_by_id' => $worker->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => Pallet::class,
            'entity_id' => $pallet->id,
            'action' => 'quality_status_changed',
            'user_id' => $worker->id,
        ]);
    }

    public function test_failed_quality_requires_and_stores_a_reason(): void
    {
        $worker = $this->user('PackingWorker');
        $pallet = $this->pallet();

        $this->auth($worker)
            ->postJson("/api/v1/packaging/pallets/{$pallet->id}/quality", [
                'status' => 'fail',
                'reason' => ' cracked edge ',
            ])
            ->assertOk()
            ->assertJsonPath('data.quality_status', Pallet::QUALITY_FAIL)
            ->assertJsonPath('data.quality_reason', 'cracked edge');

        $this->assertDatabaseHas('pallet_quality_decisions', [
            'pallet_id' => $pallet->id,
            'status' => Pallet::QUALITY_FAIL,
            'reason' => 'cracked edge',
        ]);
    }

    public function test_web_station_route_uses_the_same_contract(): void
    {
        $worker = $this->user('PackingWorker');
        $pallet = $this->pallet();

        $this->actingAs($worker)
            ->postJson("/packaging/pallets/{$pallet->id}/quality", ['status' => 'pass'])
            ->assertOk()
            ->assertJsonPath('data.quality_status', Pallet::QUALITY_PASS);
    }

    public function test_user_without_dispatch_packing_cannot_record_quality(): void
    {
        $operator = $this->user('Operator');
        $pallet = $this->pallet();

        $this->auth($operator)
            ->postJson("/api/v1/packaging/pallets/{$pallet->id}/quality", ['status' => 'pass'])
            ->assertForbidden();

        $this->assertSame(Pallet::QUALITY_PENDING, $pallet->fresh()->quality_status);
        $this->assertSame(0, PalletQualityDecision::count());
    }

    public function test_quality_cannot_change_after_the_pallet_entered_a_dispatch(): void
    {
        $worker = $this->user('PackingWorker');
        $pallet = $this->pallet();

        PackagingDispatch::create([
            'work_order_id' => $pallet->work_order_id,
            'order_no' => 'WC-880',
            'customer_order_no' => 'WC-EDE-880',
            'status' => PackagingDispatch::STATUS_PENDING,
            'source' => PackagingDispatch::SOURCE_OPERATOR,
            'idempotency_key' => (string) Str::uuid(),
            'scanned_pallets' => [$pallet->pallet_no],
            'pallet_manifest' => [],
        ]);

        $this->auth($worker)
            ->postJson("/api/v1/packaging/pallets/{$pallet->id}/quality", ['status' => 'pass'])
            ->assertStatus(409);

        $this->assertSame(Pallet::QUALITY_PENDING, $pallet->fresh()->quality_status);
        $this->assertSame(0, PalletQualityDecision::count());
    }

    public function test_quality_cannot_change_after_the_pallet_was_shipped(): void
    {
        $worker = $this->user('PackingWorker');
        $pallet = $this->pallet(PalletStatus::Shipped->value);

        $this->auth($worker)
            ->postJson("/api/v1/packaging/pallets/{$pallet->id}/quality", ['status' => 'pass'])
            ->assertStatus(409);
    }

    public function test_unknown_status_is_rejected(): void
    {
        $worker = $this->user('PackingWorker');
        $pallet = $this->pallet();

        $this->auth($worker)
            ->postJson("/api/v1/packaging/pallets/{$pallet->id}/quality", ['status' => 'maybe'])
            ->assertStatus(422);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->lines()->sync([$this->packingLine->id]);

        return $user;
    }

    private function auth(User $user)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer '.$user->createToken('test')->plainTextToken,
        );
    }

    private function pallet(string $status = PalletStatus::Closed->value): Pallet
    {
        $workOrder = WorkOrder::factory()->create(['line_id' => $this->packingLine->id]);

        return Pallet::factory()->create([
            'work_order_id' => $workOrder->id,
            'status' => $status,
            'kind' => Pallet::KIND_BOXES,
        ]);
    }
}
