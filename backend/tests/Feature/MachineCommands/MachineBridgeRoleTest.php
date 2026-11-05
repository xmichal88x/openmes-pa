<?php

namespace Tests\Feature\MachineCommands;

use App\Models\Line;
use App\Models\MachineConnection;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

/**
 * RQ-189 — the machine gateway runs as a least-privilege `MachineBridge` role
 * instead of Admin.
 *
 * Tenant note: the production spike saw a 404/403 because the gateway user had
 * `tenant_id = 1` while the `machine_connections` row had `tenant_id = NULL`,
 * so TenantScope hid the row before the policy ran. The fixtures here mirror
 * the fixed deployment shape: both the user and the connection are tenant-less,
 * which keeps the row visible and lets the test exercise the policy itself
 * (Admin or MachineBridge) rather than the tenant filter.
 */
class MachineBridgeRoleTest extends MachineCommandsTestCase
{
    private function makeConnection(array $attrs = []): MachineConnection
    {
        return MachineConnection::create(array_merge([
            'name' => 'CNC gateway',
            'protocol' => MachineConnection::PROTOCOL_MQTT,
            'is_active' => true,
            'status' => MachineConnection::STATUS_DISCONNECTED,
        ], $attrs));
    }

    public function test_machine_bridge_can_view_connections(): void
    {
        $bridge = $this->makeUser('MachineBridge', [$this->cncLine]);
        $connection = $this->makeConnection();

        $this->assertNull($bridge->tenant_id);
        $this->assertNull($connection->tenant_id);

        $this->assertTrue(Gate::forUser($bridge)->allows('viewAny', MachineConnection::class));
        $this->assertTrue(Gate::forUser($bridge)->allows('view', $connection));

        $this->app['auth']->forgetGuards();
        $this->actingAs($bridge, 'sanctum')
            ->getJson('/api/v1/connectivity/connections')
            ->assertOk()
            ->assertJsonPath('data.0.id', $connection->id);

        $this->app['auth']->forgetGuards();
        $this->actingAs($bridge, 'sanctum')
            ->getJson('/api/v1/connectivity/connections/'.$connection->id)
            ->assertOk()
            ->assertJsonPath('data.id', $connection->id);
    }

    public function test_machine_bridge_cannot_create_update_or_delete_connections(): void
    {
        $bridge = $this->makeUser('MachineBridge', [$this->cncLine]);
        $connection = $this->makeConnection();

        $this->assertFalse(Gate::forUser($bridge)->allows('create', MachineConnection::class));
        $this->assertFalse(Gate::forUser($bridge)->allows('update', $connection));
        $this->assertFalse(Gate::forUser($bridge)->allows('delete', $connection));

        $this->app['auth']->forgetGuards();
        $this->actingAs($bridge, 'sanctum')
            ->postJson('/api/v1/connectivity/connections', ['name' => 'Nope'])
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($bridge, 'sanctum')
            ->patchJson('/api/v1/connectivity/connections/'.$connection->id, ['name' => 'Nope'])
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($bridge, 'sanctum')
            ->deleteJson('/api/v1/connectivity/connections/'.$connection->id)
            ->assertForbidden();

        $this->assertDatabaseHas('machine_connections', [
            'id' => $connection->id,
            'name' => 'CNC gateway',
            'deleted_at' => null,
        ]);
    }

    public function test_admin_keeps_full_connection_access(): void
    {
        $connection = $this->makeConnection();

        $this->assertTrue(Gate::forUser($this->admin)->allows('viewAny', MachineConnection::class));
        $this->assertTrue(Gate::forUser($this->admin)->allows('create', MachineConnection::class));
        $this->assertTrue(Gate::forUser($this->admin)->allows('update', $connection));
        $this->assertTrue(Gate::forUser($this->admin)->allows('delete', $connection));
    }

    public function test_machine_bridge_role_holds_only_the_required_permissions(): void
    {
        $role = Role::findByName('MachineBridge', 'web');

        foreach ([
            'view work orders',
            'create batches',
            'start batch step',
            'start system fanout',
            'complete batch step',
            'process machine commands',
            'view lines',
            'view workstation types',
        ] as $permission) {
            $this->assertTrue(
                $role->hasPermissionTo($permission),
                "MachineBridge should hold '{$permission}'.",
            );
        }

        foreach ([
            'create work orders',
            'edit work orders',
            'delete work orders',
            'dispatch packing',
            'manage packing work orders',
            'process packing dispatch',
            'request machine load',
            'request machine labels',
            'request machine manual m30',
            'manage users',
            'manage system settings',
            'manage lines',
            'manage workstation types',
        ] as $permission) {
            $this->assertFalse(
                $role->hasPermissionTo($permission),
                "MachineBridge must not hold '{$permission}'.",
            );
        }
    }

    public function test_work_order_view_is_scoped_to_the_bridge_line(): void
    {
        $bridge = $this->makeUser('MachineBridge', [$this->cncLine]);
        $foreignLine = Line::factory()->create(['code' => 'LINIA-C']);
        $foreignWorkOrder = WorkOrder::factory()->create([
            'line_id' => $foreignLine->id,
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 5,
        ]);

        $this->assertTrue(Gate::forUser($bridge)->allows('view', $this->workOrder));
        $this->assertFalse(Gate::forUser($bridge)->allows('view', $foreignWorkOrder));

        $this->assertFalse(Gate::forUser($bridge)->allows('create', WorkOrder::class));
        $this->assertTrue(Gate::forUser($bridge)->allows('createBatch', $this->workOrder));
        $this->assertFalse(Gate::forUser($bridge)->allows('createBatch', $foreignWorkOrder));
        $this->assertFalse(Gate::forUser($bridge)->allows('update', $this->workOrder));
        $this->assertFalse(Gate::forUser($bridge)->allows('delete', $this->workOrder));
    }
}
