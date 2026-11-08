<?php

namespace Tests\Feature\Api\V1\Quality;

use App\Models\SerialUnit;
use App\Models\Tenant;
use App\Models\UnitStepHistory;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RQ-PA-214 P2 — incremental re-cut candidate feed.
 *
 * The queue runtime consumes this feed by `history_id`; a malformed cursor or
 * page size must fail closed (422), and tenancy must be inherited through
 * `serialUnit` because `unit_step_history` has no tenant column.
 */
class RecutCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private WorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Admin', 'web');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->workOrder = WorkOrder::factory()->create();
    }

    private function unit(?WorkOrder $workOrder = null, array $attributes = []): SerialUnit
    {
        return SerialUnit::create($attributes + [
            'serial_no' => 'SN-'.uniqid(),
            'psn' => 'PSN-'.uniqid(),
            'work_order_id' => ($workOrder ?? $this->workOrder)->id,
        ]);
    }

    private function history(SerialUnit $unit, ?string $decision, array $attributes = []): UnitStepHistory
    {
        return UnitStepHistory::create($attributes + [
            'serial_unit_id' => $unit->id,
            'decision' => $decision,
            'processed_at' => now(),
        ]);
    }

    private function index(User $user, array $query = [])
    {
        $url = '/api/v1/quality/recut-candidates';

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $this->actingAs($user)->getJson($url);
    }

    public function test_only_recut_decisions_are_returned(): void
    {
        $unit = $this->unit();

        $recut = $this->history($unit, 'recut');
        $this->history($unit, 'rework');
        $this->history($unit, 'scrap');
        $this->history($unit, null);

        $response = $this->index($this->admin);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($recut->id, $response->json('data.0.history_id'));
        $this->assertSame('recut', $response->json('data.0.decision'));
    }

    public function test_cursor_pages_forward_without_duplicates(): void
    {
        $unit = $this->unit();

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->history($unit, 'recut')->id;
        }

        $first = $this->index($this->admin, ['limit' => 2]);

        $first->assertOk()->assertJsonPath('meta.has_more', true);
        $this->assertSame([$ids[0], $ids[1]], array_column($first->json('data'), 'history_id'));
        $this->assertSame($ids[1], $first->json('meta.last_id'));

        $second = $this->index($this->admin, [
            'after_id' => $first->json('meta.last_id'),
            'limit' => 2,
        ]);

        $second->assertOk()->assertJsonPath('meta.has_more', false);
        $this->assertSame([$ids[2]], array_column($second->json('data'), 'history_id'));
        $this->assertSame($ids[2], $second->json('meta.last_id'));

        $seen = array_merge(
            array_column($first->json('data'), 'history_id'),
            array_column($second->json('data'), 'history_id'),
        );
        $this->assertSame($seen, array_values(array_unique($seen)));
    }

    public function test_empty_page_echoes_the_cursor(): void
    {
        $this->history($this->unit(), 'recut');

        $response = $this->index($this->admin, ['after_id' => 999]);

        $response->assertOk()
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.last_id', 999);

        $this->assertCount(0, $response->json('data'));
    }

    public function test_invalid_cursor_and_limit_are_rejected(): void
    {
        foreach ([
            ['limit' => 0],
            ['limit' => 501],
            ['after_id' => -1],
            ['limit' => 'abc'],
            ['after_id' => 'abc'],
        ] as $query) {
            $this->index($this->admin, $query)->assertStatus(422);
        }
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/quality/recut-candidates')->assertStatus(401);
    }

    public function test_payload_contains_the_queue_contract_fields(): void
    {
        $unit = $this->unit(null, ['serial_no' => 'SN-PAYLOAD', 'psn' => 'PSN-PAYLOAD']);
        $history = $this->history($unit, 'recut');

        $row = $this->index($this->admin)->assertOk()->json('data.0');

        $this->assertSame([
            'history_id',
            'serial_unit_id',
            'serial_no',
            'psn',
            'work_order_id',
            'order_no',
            'batch_id',
            'decision',
            'processed_at',
            'unit_status',
        ], array_keys($row));

        $this->assertSame($history->id, $row['history_id']);
        $this->assertSame($unit->id, $row['serial_unit_id']);
        $this->assertSame('SN-PAYLOAD', $row['serial_no']);
        $this->assertSame('PSN-PAYLOAD', $row['psn']);
        $this->assertSame($this->workOrder->id, $row['work_order_id']);
        $this->assertSame($this->workOrder->order_no, $row['order_no']);
        $this->assertNull($row['batch_id']);
        $this->assertSame('recut', $row['decision']);
        $this->assertSame(SerialUnit::STATUS_IN_PRODUCTION, $row['unit_status']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $row['processed_at'],
        );
    }

    public function test_tenant_a_does_not_see_tenant_b_events(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A']);
        $tenantB = Tenant::create(['name' => 'Tenant B']);

        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $userA->assignRole('Admin');

        $this->actingAs($userA);
        $workOrderA = WorkOrder::factory()->create();
        $historyA = $this->history($this->unit($workOrderA), 'recut');

        $this->actingAs($userB);
        $workOrderB = WorkOrder::factory()->create();
        $this->history($this->unit($workOrderB), 'recut');

        $response = $this->index($userA);

        $response->assertOk();
        $this->assertSame([$historyA->id], array_column($response->json('data'), 'history_id'));
    }
}
