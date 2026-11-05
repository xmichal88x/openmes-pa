<?php

namespace Tests\Feature\MachineCommands;

use App\Models\MachineCommand;
use App\Models\User;
use Spatie\Permission\Models\Role;

class MachineCommandPermissionsTest extends MachineCommandsTestCase
{
    public function test_operator_may_request_load_and_labels_but_not_manual_m30(): void
    {
        $role = Role::findByName('Operator', 'web');

        $this->assertTrue($role->hasPermissionTo('request machine load'));
        $this->assertTrue($role->hasPermissionTo('request machine labels'));
        $this->assertFalse($role->hasPermissionTo('request machine manual m30'));
        $this->assertFalse($role->hasPermissionTo('process machine commands'));
    }

    public function test_supervisor_may_request_every_command_type_but_not_process(): void
    {
        $role = Role::findByName('Supervisor', 'web');

        $this->assertTrue($role->hasPermissionTo('request machine load'));
        $this->assertTrue($role->hasPermissionTo('request machine labels'));
        $this->assertTrue($role->hasPermissionTo('request machine manual m30'));
        $this->assertFalse($role->hasPermissionTo('process machine commands'));
        $this->assertTrue($role->hasPermissionTo('create work orders'));
        $this->assertTrue($role->hasPermissionTo('create batches'));
    }

    public function test_admin_holds_every_machine_permission(): void
    {
        $role = Role::findByName('Admin', 'web');

        $this->assertTrue($role->hasPermissionTo('request machine load'));
        $this->assertTrue($role->hasPermissionTo('request machine labels'));
        $this->assertTrue($role->hasPermissionTo('request machine manual m30'));
        $this->assertTrue($role->hasPermissionTo('process machine commands'));
        $this->assertTrue($role->hasPermissionTo('create work orders'));
        $this->assertTrue($role->hasPermissionTo('create batches'));
    }

    public function test_machine_bridge_is_least_privilege(): void
    {
        $role = Role::findByName('MachineBridge', 'web');

        $this->assertTrue($role->hasPermissionTo('view work orders'));
        $this->assertTrue($role->hasPermissionTo('process machine commands'));
        $this->assertTrue($role->hasPermissionTo('create batches'));
        $this->assertFalse($role->hasPermissionTo('create work orders'));
        $this->assertTrue($role->hasPermissionTo('start batch step'));
        $this->assertTrue($role->hasPermissionTo('complete batch step'));
        $this->assertTrue($role->hasPermissionTo('view lines'));
        $this->assertTrue($role->hasPermissionTo('view workstation types'));
        $this->assertFalse($role->hasPermissionTo('request machine load'));
        $this->assertFalse($role->hasPermissionTo('request machine labels'));
        $this->assertFalse($role->hasPermissionTo('request machine manual m30'));
        $this->assertFalse($role->hasPermissionTo('edit work orders'));
        $this->assertFalse($role->hasPermissionTo('delete work orders'));
        $this->assertFalse($role->hasPermissionTo('dispatch packing'));
        $this->assertFalse($role->hasPermissionTo('manage users'));
    }

    public function test_packing_roles_gain_no_machine_processing_rights(): void
    {
        $bridge = Role::findByName('PackingBridge', 'web');
        $worker = Role::findByName('PackingWorker', 'web');

        $this->assertFalse($bridge->hasPermissionTo('process machine commands'));
        $this->assertFalse($bridge->hasPermissionTo('request machine load'));

        $this->assertFalse($worker->hasPermissionTo('process machine commands'));
        $this->assertFalse($worker->hasPermissionTo('request machine load'));
        $this->assertFalse($worker->hasPermissionTo('request machine labels'));
        $this->assertFalse($worker->hasPermissionTo('request machine manual m30'));
    }

    public function test_machine_bridge_can_claim_but_cannot_request_a_command(): void
    {
        $command = $this->makeCommand();
        $bridge = $this->makeUser('MachineBridge');

        $this->api($bridge)->postJson('/api/v1/machine/commands/claim', [
            'workstation_codes' => [$this->nest->code],
        ])->assertOk()
            ->assertJsonPath('data.id', $command->id);

        $this->api($bridge)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_LOAD_NC,
        ])->assertForbidden();
    }

    public function test_packing_bridge_cannot_claim_machine_commands(): void
    {
        $this->makeCommand();
        $bridge = $this->makeUser('PackingBridge');

        $this->api($bridge)->postJson('/api/v1/machine/commands/claim', [
            'workstation_codes' => [$this->nest->code],
        ])->assertForbidden();
    }

    private function api(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }
}
