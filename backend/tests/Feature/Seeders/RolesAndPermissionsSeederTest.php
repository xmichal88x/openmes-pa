<?php

namespace Tests\Feature\Seeders;

use App\Support\InspectionPermissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The role seed runs on every container boot, so it is also the place where an
 * upstream rename (v0.28: $keepTabs -> $keepGranted) becomes a fatal error on
 * the PA roles. This test boots the seeder for real and pins the PA grants.
 */
class RolesAndPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_pa_roles_and_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $packingWorker = Role::findByName('PackingWorker', 'web');
        $this->assertNotNull($packingWorker);
        $this->assertTrue($packingWorker->hasPermissionTo('view work orders'));
        $this->assertTrue($packingWorker->hasPermissionTo('dispatch packing'));

        $packingBridge = Role::findByName('PackingBridge', 'web');
        $this->assertNotNull($packingBridge);
        $this->assertTrue($packingBridge->hasPermissionTo('manage packing work orders'));
        $this->assertTrue($packingBridge->hasPermissionTo('process packing dispatch'));

        $machineBridge = Role::findByName('MachineBridge', 'web');
        $this->assertNotNull($machineBridge);
        $this->assertTrue($machineBridge->hasPermissionTo('create batches'));
        $this->assertTrue($machineBridge->hasPermissionTo('start batch step'));
        $this->assertTrue($machineBridge->hasPermissionTo('start system fanout'));
        $this->assertTrue($machineBridge->hasPermissionTo('complete batch step'));
        $this->assertTrue($machineBridge->hasPermissionTo('process machine commands'));
    }

    public function test_the_admin_role_holds_the_inspection_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = Role::findByName('Admin', 'web');

        foreach (InspectionPermissions::ALL as $permission) {
            $this->assertTrue(
                $admin->hasPermissionTo($permission),
                "Admin must hold {$permission}.",
            );
        }
    }

    public function test_supervisor_gets_inspection_permissions_on_the_first_seed(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $supervisor = Role::findByName('Supervisor', 'web');

        foreach (InspectionPermissions::ALL as $permission) {
            $this->assertTrue(
                $supervisor->hasPermissionTo($permission),
                "Supervisor must get {$permission} on the first seed.",
            );
        }
    }

    public function test_running_it_again_is_safe(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $roles = Role::count();
        $permissions = \Spatie\Permission\Models\Permission::count();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame($roles, Role::count());
        $this->assertSame($permissions, \Spatie\Permission\Models\Permission::count());
    }
}
