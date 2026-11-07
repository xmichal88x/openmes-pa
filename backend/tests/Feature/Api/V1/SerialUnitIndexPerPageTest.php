<?php

namespace Tests\Feature\Api\V1;

use App\Models\SerialUnit;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SerialUnitIndexPerPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private WorkOrder $wo;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->wo = WorkOrder::factory()->create();

        for ($i = 1; $i <= 150; $i++) {
            SerialUnit::create([
                'serial_no' => 'SN-PER-PAGE-'.$i,
                'work_order_id' => $this->wo->id,
            ]);
        }
    }

    public function test_per_page_allows_more_than_one_hundred_units(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/serial-units?work_order_id='.$this->wo->id.'&per_page=1000');

        $response->assertOk();
        $this->assertCount(150, $response->json('data'));
    }

    public function test_default_page_size_stays_at_one_hundred(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/serial-units?work_order_id='.$this->wo->id);

        $response->assertOk();
        $this->assertCount(100, $response->json('data'));
    }

    public function test_per_page_above_maximum_is_clamped(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/serial-units?work_order_id='.$this->wo->id.'&per_page=5000');

        $response->assertOk();
        $this->assertLessThanOrEqual(1000, count($response->json('data')));
        $this->assertCount(150, $response->json('data'));
    }

    public function test_per_page_zero_is_clamped_to_one(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/serial-units?work_order_id='.$this->wo->id.'&per_page=0');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_per_page_negative_is_clamped_to_one(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/serial-units?work_order_id='.$this->wo->id.'&per_page=-5');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
