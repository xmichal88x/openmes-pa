<?php

namespace Tests\Feature\StartPreflight;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemFanoutPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_and_machine_bridge_hold_start_system_fanout(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertTrue(Role::findByName('Admin')->hasPermissionTo('start system fanout'));
        $this->assertTrue(Role::findByName('MachineBridge')->hasPermissionTo('start system fanout'));

        foreach (['Operator', 'Supervisor', 'PackingWorker', 'PackingBridge'] as $role) {
            $this->assertFalse(Role::findByName($role)->hasPermissionTo('start system fanout'));
        }
    }
}
