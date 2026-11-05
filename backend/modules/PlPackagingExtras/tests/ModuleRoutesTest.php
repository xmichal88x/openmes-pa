<?php

namespace Modules\PlPackagingExtras\tests;

use App\Models\Line;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PlPackagingExtras\Providers\PlPackagingExtrasServiceProvider;
use Tests\TestCase;

/**
 * The module's routes must be reachable only by authenticated production roles,
 * and the public health probe must keep working without a token.
 */
class ModuleRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(PlPackagingExtrasServiceProvider::class);
        app('router')->getRoutes()->refreshNameLookups();
    }

    public function test_health_endpoint_is_public(): void
    {
        $this->getJson('/api/v1/pl-packaging/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('module', 'PlPackagingExtras');
    }

    public function test_p1_routes_are_registered(): void
    {
        $router = app('router');

        foreach ([
            'pl-packaging.health',
            'pl-packaging.items',
            'pl-packaging.stats',
            'pl-packaging.eans.index',
            'pl-packaging.eans.show',
            'pl-packaging.eans.store',
            'pl-packaging.eans.destroy',
            'pl-packaging.scan-logs',
            'pl-packaging.scan',
            'pl-packaging.expected-codes',
            'pl-packaging.work-orders.store',
            'pl-packaging.complete',
            'pl-packaging.work-orders.start',
        ] as $name) {
            $this->assertTrue($router->has($name), "Route [{$name}] is not registered.");
        }
    }

    public function test_packaging_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/packaging/items')->assertUnauthorized();
        $this->getJson('/api/v1/packaging/stats')->assertUnauthorized();
        $this->getJson('/api/v1/packaging/eans')->assertUnauthorized();
        $this->postJson('/api/v1/packaging/scan', ['ean' => 'X'])->assertUnauthorized();
        $this->postJson('/api/v1/packaging/work-orders')->assertUnauthorized();
        $this->postJson('/api/v1/work-orders/1/start')->assertUnauthorized();
    }

    public function test_packaging_endpoints_require_a_production_role(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/packaging/items')
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/packaging/scan', ['ean' => 'X'])
            ->assertForbidden();
    }

    public function test_operator_can_reach_the_packaging_api(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $operator = User::factory()->create();
        $operator->assignRole('Operator');

        $line = Line::firstOrCreate(
            ['code' => 'PACKING'],
            ['name' => 'Pakowanie', 'is_active' => true]
        );
        $operator->lines()->attach($line->id);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/packaging/items')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/packaging/stats')
            ->assertOk()
            ->assertJsonStructure(['data' => ['today_packed', 'plan', 'total_packed', 'backlog', 'shift_start']]);
    }

    public function test_start_route_belongs_to_the_module_and_is_idempotent(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $supervisor = User::factory()->create();
        $supervisor->assignRole('Supervisor');

        $line = Line::firstOrCreate(
            ['code' => 'PACKING'],
            ['name' => 'Pakowanie', 'is_active' => true]
        );

        $workOrder = WorkOrder::factory()->create([
            'line_id' => $line->id,
            'status' => WorkOrder::STATUS_PENDING,
        ]);

        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/work-orders/{$workOrder->id}/start")
            ->assertOk();

        $this->assertSame(WorkOrder::STATUS_IN_PROGRESS, $workOrder->fresh()->status);

        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/v1/work-orders/{$workOrder->id}/start")
            ->assertOk();
    }
}
