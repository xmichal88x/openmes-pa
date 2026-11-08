<?php

namespace Tests\Feature\Traceability;

use App\Models\SerialUnit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Quality\QualityGateService;
use App\Services\Traceability\SerialTraceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `unit_step_history.decision` is the durable, incremental source of QA
 * decisions (rework / recut / scrap). It is written only from inside the MES:
 * the public step API must never be able to set it.
 */
class UnitStepDecisionTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private WorkOrder $wo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create();
        $this->operator->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Operator', 'web'));

        $this->wo = WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
        ]);
    }

    private function unit(array $attributes = []): SerialUnit
    {
        return SerialUnit::create($attributes + [
            'serial_no' => 'SN-'.uniqid(),
            'psn' => 'P-'.uniqid(),
            'work_order_id' => $this->wo->id,
        ]);
    }

    private function qualityDefect(SerialUnit $unit)
    {
        return $unit->history()
            ->where('parameters->event', 'quality_defect')
            ->latest('id')
            ->firstOrFail();
    }

    public function test_qa_failure_persists_the_decision_column(): void
    {
        $gate = app(QualityGateService::class);

        foreach (['rework', 'recut', 'scrap'] as $decision) {
            $unit = $this->unit();

            $gate->applyFailure($unit, $this->operator, $decision, null, ['failed']);

            $this->assertSame($decision, $this->qualityDefect($unit)->decision, "decision={$decision}");
        }
    }

    public function test_qa_rework_blocks_and_recut_or_scrap_scraps_the_unit(): void
    {
        $gate = app(QualityGateService::class);

        $rework = $this->unit();
        $gate->applyFailure($rework, $this->operator, 'rework', null);
        $this->assertSame(SerialUnit::STATUS_BLOCKED, $rework->fresh()->status);

        $recut = $this->unit();
        $gate->applyFailure($recut, $this->operator, 'recut', null);
        $this->assertSame(SerialUnit::STATUS_SCRAPPED, $recut->fresh()->status);

        $scrap = $this->unit();
        $gate->applyFailure($scrap, $this->operator, 'scrap', null);
        $this->assertSame(SerialUnit::STATUS_SCRAPPED, $scrap->fresh()->status);
    }

    public function test_step_api_cannot_set_the_decision_column(): void
    {
        $unit = $this->unit();

        $this->actingAs($this->operator)
            ->postJson("/api/v1/serial-units/{$unit->id}/steps", [
                'parameters' => ['event' => 'test', 'value' => 1],
                'result' => 'pass',
                'notes' => 'API must not decide',
                'decision' => 'recut',
            ])
            ->assertCreated();

        $entry = $unit->history()->latest('id')->firstOrFail();

        $this->assertNull($entry->decision, 'An unknown validated key must not reach the column.');
        $this->assertSame('test', $entry->parameters['event']);
        $this->assertSame('pass', $entry->result);
        $this->assertSame(0, $unit->history()->where('decision', 'recut')->count());
    }

    public function test_plain_record_step_without_a_decision_stays_null(): void
    {
        $unit = $this->unit();

        $entry = app(SerialTraceService::class)->recordStep($unit, $this->operator, null, [
            'parameters' => ['event' => 'component_bound'],
        ]);

        $this->assertNull($entry->decision);
    }

    public function test_record_step_ignores_decisions_outside_the_allowed_set(): void
    {
        $unit = $this->unit();
        $serials = app(SerialTraceService::class);

        $unknown = $serials->recordStep($unit, $this->operator, null, ['decision' => 'rework-please']);
        $this->assertNull($unknown->decision);

        $known = $serials->recordStep($unit, $this->operator, null, ['decision' => 'recut']);
        $this->assertSame('recut', $known->decision);
    }

    public function test_qa_failure_rejects_an_unknown_decision(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(QualityGateService::class)->applyFailure($this->unit(), $this->operator, 'burn-it', null);
    }
}
