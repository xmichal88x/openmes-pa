<?php

namespace Tests\Feature\Api;

use App\Models\IssueType;
use App\Models\Line;
use App\Models\ProductType;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The API counterpart of the operator dialog: a MACHINE_CYCLE_SUSPENDED issue
 * must describe what is on the machine and since when.
 */
class SuspendedCycleIssueApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Line $line;

    protected WorkOrder $workOrder;

    protected IssueType $suspendedType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole('Operator');
        $this->line = Line::factory()->create();
        $this->user->lines()->attach($this->line);

        $productType = ProductType::factory()->create();

        $this->workOrder = WorkOrder::factory()->create([
            'line_id' => $this->line->id,
            'product_type_id' => $productType->id,
            'status' => 'PENDING',
        ]);

        $this->suspendedType = IssueType::where('code', 'MACHINE_CYCLE_SUSPENDED')->firstOrFail();
    }

    #[Test]
    public function it_rejects_a_suspended_cycle_issue_without_a_description()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/issues', [
                'work_order_id' => $this->workOrder->id,
                'issue_type_id' => $this->suspendedType->id,
                'title' => 'Suspended cycle',
                'description' => '',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('description');

        $this->assertDatabaseCount('issues', 0);
    }

    #[Test]
    public function it_accepts_a_suspended_cycle_issue_with_a_description()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/issues', [
                'work_order_id' => $this->workOrder->id,
                'issue_type_id' => $this->suspendedType->id,
                'title' => 'Suspended cycle',
                'description' => 'Sheet 2 clamped, suspended since 09:10.',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('issues', [
            'work_order_id' => $this->workOrder->id,
            'issue_type_id' => $this->suspendedType->id,
            'title' => 'Suspended cycle',
            'status' => 'OPEN',
        ]);
    }
}
