<?php

namespace Tests\Feature\Operator;

use App\Models\Line;
use App\Models\ProductType;
use App\Models\SerialUnit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Traceability\SerialTraceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A unit whose rework failed can be sent to re-cut: it is scrapped like a
 * scrap, but the history carries `decision='recut'` - the durable event the
 * queue turns into a re-cut ticket (RQ-PA-214 P2 / M3).
 */
class ReworkRecutTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $supervisor;

    private User $packingWorker;

    private WorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Operator', 'Supervisor', 'Admin', 'PackingWorker'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');
        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('Supervisor');
        $this->packingWorker = User::factory()->create();
        $this->packingWorker->assignRole('PackingWorker');

        $this->workOrder = WorkOrder::factory()->create([
            'line_id' => Line::factory(),
            'product_type_id' => ProductType::factory(),
            'status' => WorkOrder::STATUS_IN_PROGRESS,
        ]);
    }

    private function unit(array $attributes = []): SerialUnit
    {
        return SerialUnit::create($attributes + [
            'serial_no' => 'SN-'.uniqid(),
            'psn' => 'P-'.uniqid(),
            'work_order_id' => $this->workOrder->id,
            'status' => SerialUnit::STATUS_BLOCKED,
            'extra_data' => ['hold' => ['source' => 'manual', 'reason' => 'Cosmetic defect']],
        ]);
    }

    private function lastScrappedEvent(SerialUnit $unit)
    {
        return $unit->history()->where('parameters->event', 'scrapped')->latest('id')->firstOrFail();
    }

    public function test_the_service_scraps_the_unit_and_persists_the_recut_decision(): void
    {
        $unit = $this->unit();

        $result = app(SerialTraceService::class)->recutUnit($unit, $this->operator, 'Beyond rework');

        $this->assertSame(SerialUnit::STATUS_SCRAPPED, $result->status);

        $event = $this->lastScrappedEvent($unit);
        $this->assertSame('recut', $event->decision);
        $this->assertSame('fail', $event->result);
        $this->assertSame('rework', $event->parameters['source']);
        $this->assertSame('Beyond rework', $event->notes);
    }

    public function test_a_second_recut_is_a_no_op_with_no_duplicate_history(): void
    {
        $unit = $this->unit();
        $serials = app(SerialTraceService::class);

        $serials->recutUnit($unit, $this->operator, 'First');
        $serials->recutUnit($unit->fresh(), $this->operator, 'Second');

        $this->assertSame(1, $unit->history()->where('parameters->event', 'scrapped')->count());
    }

    public function test_the_web_route_recuts_a_blocked_unit_for_an_allowed_role(): void
    {
        $unit = $this->unit();

        $this->actingAs($this->supervisor)
            ->postJson(route('operator.unit-labels.recut', $unit), ['reason' => 'Rework impossible'])
            ->assertOk()
            ->assertJsonPath('unit.status', SerialUnit::STATUS_SCRAPPED);

        $this->assertSame('recut', $this->lastScrappedEvent($unit)->decision);
    }

    public function test_the_web_route_requires_a_reason(): void
    {
        $unit = $this->unit();

        $this->actingAs($this->supervisor)
            ->postJson(route('operator.unit-labels.recut', $unit), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_the_api_recut_works_for_an_operator_and_returns_the_unit(): void
    {
        $unit = $this->unit();

        $this->actingAs($this->operator)
            ->postJson("/api/v1/serial-units/{$unit->id}/recut", ['reason' => 'Cut again'])
            ->assertOk()
            ->assertJsonPath('data.status', SerialUnit::STATUS_SCRAPPED);

        $this->assertSame('recut', $this->lastScrappedEvent($unit)->decision);
    }

    public function test_the_api_recut_requires_an_allowed_role(): void
    {
        $unit = $this->unit();

        $this->actingAs($this->packingWorker)
            ->postJson("/api/v1/serial-units/{$unit->id}/recut", ['reason' => 'Cut again'])
            ->assertForbidden();

        $this->assertSame(SerialUnit::STATUS_BLOCKED, $unit->fresh()->status);
        $this->assertSame(0, $unit->history()->where('parameters->event', 'scrapped')->count());
    }

    public function test_a_shipped_unit_cannot_be_recut(): void
    {
        $unit = $this->unit(['status' => SerialUnit::STATUS_SHIPPED]);

        $this->actingAs($this->operator)
            ->postJson("/api/v1/serial-units/{$unit->id}/recut", ['reason' => 'Too late'])
            ->assertStatus(422);

        $this->assertSame(SerialUnit::STATUS_SHIPPED, $unit->fresh()->status);
        $this->assertSame(0, $unit->history()->where('parameters->event', 'scrapped')->count());
    }

    public function test_the_public_step_api_still_cannot_set_the_decision(): void
    {
        $unit = $this->unit(['status' => SerialUnit::STATUS_IN_PRODUCTION]);

        $this->actingAs($this->operator)
            ->postJson("/api/v1/serial-units/{$unit->id}/steps", [
                'parameters' => ['event' => 'test', 'value' => 1],
                'result' => 'pass',
                'decision' => 'recut',
            ])
            ->assertCreated();

        $entry = $unit->history()->latest('id')->firstOrFail();
        $this->assertNull($entry->decision);
        $this->assertSame(0, $unit->history()->where('decision', 'recut')->count());
    }
}
