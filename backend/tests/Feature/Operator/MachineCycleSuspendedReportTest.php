<?php

namespace Tests\Feature\Operator;

use App\Models\Issue;
use App\Models\IssueType;
use App\Models\Line;
use App\Models\ProductType;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\IssueTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MachineCycleSuspendedReportTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'MACHINE_CYCLE_SUSPENDED';

    private User $operator;

    private User $supervisor;

    private User $admin;

    private ProductType $productType;

    private Line $line;

    private WorkOrder $workOrder;

    private string $initialWorkOrderStatus;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Operator', 'Supervisor', 'Admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');
        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('Supervisor');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->productType = ProductType::factory()->create(['code' => 'MDF-18']);
        $this->line = Line::factory()->create();
        $this->workOrder = WorkOrder::factory()->create([
            'line_id' => $this->line->id,
            'product_type_id' => $this->productType->id,
        ]);

        $this->initialWorkOrderStatus = $this->workOrder->status;
    }

    private function suspendedType(): IssueType
    {
        return IssueType::where('code', self::CODE)->firstOrFail();
    }

    private function report(array $payload)
    {
        return $this->actingAs($this->operator)
            ->withSession(['selected_line_id' => $this->line->id])
            ->post('/operator/issue', $payload);
    }

    public function test_machine_cycle_suspended_type_exists_with_expected_attributes(): void
    {
        $type = $this->suspendedType();

        $this->assertSame('HIGH', $type->severity);
        $this->assertFalse($type->is_blocking);
        $this->assertTrue($type->is_active);
        $this->assertNull($type->tenant_id);
        $this->assertNull($type->deleted_at);
    }

    public function test_issue_types_seeder_is_idempotent_for_the_suspended_type(): void
    {
        $this->seed(IssueTypesSeeder::class);
        $this->seed(IssueTypesSeeder::class);

        $this->assertSame(1, IssueType::where('code', self::CODE)->count());
    }

    public function test_operator_reports_a_suspended_cycle_with_a_valid_description(): void
    {
        $type = $this->suspendedType();

        $this->report([
            'work_order_id' => $this->workOrder->id,
            'issue_type_id' => $type->id,
            'title' => 'Suspended cycle',
            'description' => 'Sheet 3 still clamped, suspended since 08:15.',
        ])->assertRedirect();

        $this->assertDatabaseHas('issues', [
            'work_order_id' => $this->workOrder->id,
            'issue_type_id' => $type->id,
            'status' => Issue::STATUS_OPEN,
            'reported_by_id' => $this->operator->id,
            'blocks_work_order' => false,
        ]);

        $this->assertSame(
            $this->initialWorkOrderStatus,
            $this->workOrder->fresh()->status,
        );
        $this->assertNotSame(WorkOrder::STATUS_BLOCKED, $this->workOrder->fresh()->status);
    }

    public function test_empty_description_is_rejected_for_a_suspended_cycle(): void
    {
        $type = $this->suspendedType();

        $this->report([
            'work_order_id' => $this->workOrder->id,
            'issue_type_id' => $type->id,
            'title' => 'Suspended cycle',
            'description' => '',
        ])->assertSessionHasErrors('description');

        $this->assertDatabaseCount('issues', 0);
    }

    public function test_description_of_exactly_ten_characters_is_accepted(): void
    {
        $type = $this->suspendedType();

        $this->report([
            'work_order_id' => $this->workOrder->id,
            'issue_type_id' => $type->id,
            'title' => 'Suspended cycle',
            'description' => '1234567890',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('issues', [
            'work_order_id' => $this->workOrder->id,
            'issue_type_id' => $type->id,
            'status' => Issue::STATUS_OPEN,
            'reported_by_id' => $this->operator->id,
        ]);
    }

    public function test_description_shorter_than_ten_characters_is_rejected(): void
    {
        $type = $this->suspendedType();

        $this->report([
            'work_order_id' => $this->workOrder->id,
            'issue_type_id' => $type->id,
            'title' => 'Suspended cycle',
            'description' => '123456789',
        ])->assertSessionHasErrors('description');

        $this->assertDatabaseCount('issues', 0);
    }

    public function test_managed_issue_page_rejects_a_suspended_cycle_without_a_description(): void
    {
        $type = $this->suspendedType();

        $this->actingAs($this->supervisor)
            ->post('/supervisor/issues', [
                'work_order_id' => $this->workOrder->id,
                'issue_type_id' => $type->id,
                'title' => 'Suspended cycle',
                'description' => '',
                'effect' => 'none',
            ])
            ->assertSessionHasErrors('description');

        $this->assertDatabaseCount('issues', 0);
    }

    public function test_managed_issue_page_accepts_a_suspended_cycle_with_a_description(): void
    {
        $type = $this->suspendedType();

        $this->actingAs($this->supervisor)
            ->post('/supervisor/issues', [
                'work_order_id' => $this->workOrder->id,
                'issue_type_id' => $type->id,
                'title' => 'Suspended cycle',
                'description' => 'Sheet 5 still clamped, suspended since 11:20.',
                'effect' => 'none',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('issues', [
            'work_order_id' => $this->workOrder->id,
            'issue_type_id' => $type->id,
            'status' => Issue::STATUS_OPEN,
            'reported_by_id' => $this->supervisor->id,
        ]);
    }

    public function test_shared_issues_page_exposes_the_suspended_issue_type(): void
    {
        $this->actingAs($this->supervisor)
            ->get('/supervisor/issues')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('issueTypes', fn ($types) => collect($types)->contains('code', self::CODE)));
    }

    public function test_description_of_only_whitespace_is_rejected(): void
    {
        $type = $this->suspendedType();

        $this->report([
            'work_order_id' => $this->workOrder->id,
            'issue_type_id' => $type->id,
            'title' => 'Suspended cycle',
            'description' => str_repeat(' ', 10),
        ])->assertSessionHasErrors('description');

        $this->assertDatabaseCount('issues', 0);
    }

    public function test_other_type_still_accepts_an_empty_description(): void
    {
        $this->seed(IssueTypesSeeder::class);
        $type = IssueType::where('code', 'OTHER')->firstOrFail();

        $this->report([
            'work_order_id' => $this->workOrder->id,
            'issue_type_id' => $type->id,
            'title' => 'Something else',
            'description' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('issues', [
            'issue_type_id' => $type->id,
            'status' => Issue::STATUS_OPEN,
            'reported_by_id' => $this->operator->id,
        ]);
    }

    public function test_seed_migration_is_idempotent_and_keeps_created_at(): void
    {
        $migration = require database_path('migrations/2026_11_10_000001_seed_machine_cycle_suspended_issue_type.php');

        $createdAt = DB::table('issue_types')->where('code', self::CODE)->value('created_at');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('issue_types')->where('code', self::CODE)->count());
        $this->assertSame(1, DB::table('issue_types')->where('code', self::CODE)->whereNull('deleted_at')->count());
        $this->assertSame($createdAt, DB::table('issue_types')->where('code', self::CODE)->value('created_at'));
    }

    public function test_seed_migration_resurrects_a_soft_deleted_row(): void
    {
        DB::table('issue_types')->where('code', self::CODE)->delete();

        $now = now();
        DB::table('issue_types')->insert([
            'code' => self::CODE,
            'name' => 'Stale suspended type',
            'severity' => 'LOW',
            'is_blocking' => true,
            'is_active' => false,
            'tenant_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => $now,
            'deleted_by_id' => null,
        ]);

        $migration = require database_path('migrations/2026_11_10_000001_seed_machine_cycle_suspended_issue_type.php');
        $migration->up();

        $this->assertSame(1, DB::table('issue_types')->where('code', self::CODE)->count());

        $row = DB::table('issue_types')->where('code', self::CODE)->first();
        $this->assertNull($row->deleted_at);
        $this->assertSame('HIGH', $row->severity);
        $this->assertFalse((bool) $row->is_blocking);
        $this->assertTrue((bool) $row->is_active);
    }

    public function test_operator_detail_page_exposes_the_suspended_issue_type(): void
    {
        $this->actingAs($this->operator)
            ->withSession(['selected_line_id' => $this->line->id])
            ->get(route('operator.work-order.detail', $this->workOrder))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('issueTypes', fn ($types) => collect($types)->contains('code', self::CODE)));
    }

    public function test_supervisor_page_exposes_the_suspended_issue_type_code(): void
    {
        $type = $this->suspendedType();

        Issue::create([
            'work_order_id' => $this->workOrder->id,
            'issue_type_id' => $type->id,
            'title' => 'Suspended cycle',
            'description' => 'Sheet 2 stuck under the clamp since 09:40.',
            'status' => Issue::STATUS_OPEN,
            'reported_by_id' => $this->operator->id,
            'reported_at' => now(),
        ]);

        $this->actingAs($this->supervisor)
            ->get(route('supervisor.work-orders.show', $this->workOrder))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workOrder.issues', fn ($issues) => collect($issues)->contains('issue_type_code', self::CODE)));
    }

    public function test_admin_page_exposes_the_suspended_issue_type_code(): void
    {
        $type = $this->suspendedType();

        Issue::create([
            'work_order_id' => $this->workOrder->id,
            'issue_type_id' => $type->id,
            'title' => 'Suspended cycle',
            'description' => 'Sheet 4 clamped since 10:10.',
            'status' => Issue::STATUS_OPEN,
            'reported_by_id' => $this->operator->id,
            'reported_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.work-orders.show', $this->workOrder))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workOrder.issues', fn ($issues) => collect($issues)->contains('issue_type_code', self::CODE)));
    }
}
