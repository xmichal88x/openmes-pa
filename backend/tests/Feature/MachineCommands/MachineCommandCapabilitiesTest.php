<?php

namespace Tests\Feature\MachineCommands;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\Line;
use App\Models\MachineCommand;
use App\Models\WorkOrder;
use App\Models\Workstation;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Backend capabilities for the operator action panel (P2a).
 *
 * The UI must not guess permissions or CNC-ness from step names, so these
 * tests pin the contract of MachineCommandService::capabilitiesFor().
 */
class MachineCommandCapabilitiesTest extends MachineCommandsTestCase
{
    public function test_operator_on_the_line_can_load_and_label_but_not_m30(): void
    {
        $caps = $this->service->capabilitiesFor($this->operator, $this->step);

        $this->assertTrue($caps['is_cnc']);
        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $caps['step_status']);
        $this->assertTrue($caps['can_load']);
        $this->assertTrue($caps['can_labels']);
        $this->assertFalse($caps['can_m30']);
        $this->assertTrue($caps['feature_enabled']);
        $this->assertNull($caps['reason']);
        $this->assertNull($caps['active_command']);

        $this->assertIsArray($caps['visible_types']);
        $this->assertContains(MachineCommand::TYPE_LOAD_NC, $caps['visible_types']);
        $this->assertContains(MachineCommand::TYPE_GENERATE_LABELS, $caps['visible_types']);
        $this->assertNotContains(MachineCommand::TYPE_MANUAL_M30, $caps['visible_types']);
    }

    public function test_supervisor_may_trigger_m30_and_sees_it_in_visible_types(): void
    {
        $caps = $this->service->capabilitiesFor($this->supervisor, $this->step);

        $this->assertTrue($caps['can_m30']);
        $this->assertContains(MachineCommand::TYPE_MANUAL_M30, $caps['visible_types']);
    }

    public function test_packing_step_is_not_cnc(): void
    {
        [, , , , $step] = $this->makeStepOn(
            $this->cncLine,
            'PACK-01',
            'Pakowanie',
            BatchStep::STATUS_IN_PROGRESS,
            'packing',
            'packing',
        );

        $caps = $this->service->capabilitiesFor($this->operator, $step);

        $this->assertFalse($caps['is_cnc']);
        $this->assertFalse($caps['can_load']);
        $this->assertFalse($caps['can_labels']);
        $this->assertFalse($caps['can_m30']);
        $this->assertSame('not_cnc', $caps['reason']);
    }

    public function test_step_not_in_progress_blocks_every_action(): void
    {
        foreach ([BatchStep::STATUS_DONE, BatchStep::STATUS_PENDING] as $status) {
            [, , , , $step] = $this->makeStepOn(
                $this->cncLine,
                'CNC-'.mb_strtolower($status),
                'Cięcie CNC',
                $status,
            );

            $caps = $this->service->capabilitiesFor($this->operator, $step);

            $this->assertFalse($caps['can_load']);
            $this->assertFalse($caps['can_labels']);
            $this->assertFalse($caps['can_m30']);
            $this->assertSame('step_not_in_progress', $caps['reason']);
        }
    }

    public function test_feature_switch_off_disables_everything(): void
    {
        config()->set('machine_commands.enabled', false);

        $caps = $this->service->capabilitiesFor($this->operator, $this->step);

        $this->assertFalse($caps['feature_enabled']);
        $this->assertFalse($caps['can_load']);
        $this->assertFalse($caps['can_labels']);
        $this->assertFalse($caps['can_m30']);
        $this->assertSame('feature_disabled', $caps['reason']);
    }

    public function test_operator_from_another_line_is_forbidden(): void
    {
        $caps = $this->service->capabilitiesFor($this->foreignOperator, $this->step);

        $this->assertTrue($caps['is_cnc']);
        $this->assertFalse($caps['can_load']);
        $this->assertFalse($caps['can_labels']);
        $this->assertFalse($caps['can_m30']);
        $this->assertSame('forbidden', $caps['reason']);
    }

    public function test_operator_does_not_see_a_manual_m30_owned_by_a_supervisor(): void
    {
        $this->makeCommand(
            MachineCommand::TYPE_MANUAL_M30,
            $this->supervisor,
            null,
            'Awaria wrzeciona',
        );

        $operatorCaps = $this->service->capabilitiesFor($this->operator, $this->step);

        $this->assertNull($operatorCaps['active_command']);
        $this->assertNotContains(MachineCommand::TYPE_MANUAL_M30, $operatorCaps['visible_types']);

        $this->actingAs($this->operator)
            ->getJson(route('operator.batch-step.machine-commands.index', $this->step))
            ->assertOk()
            ->assertJsonPath('capabilities.active_command', null)
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->supervisor)
            ->getJson(route('operator.batch-step.machine-commands.index', $this->step))
            ->assertOk()
            ->assertJsonPath('capabilities.active_command.type', MachineCommand::TYPE_MANUAL_M30)
            ->assertJsonCount(1, 'data');
    }

    public function test_work_order_line_outside_line_codes_blocks_actions(): void
    {
        [, , , $workOrder, , $step] = $this->makeMachineStep('PACKING', 'pack_router', 'PACK-02', 'Cięcie CNC');

        $caps = $this->service->capabilitiesFor($this->supervisor, $step, null, $workOrder);

        $this->assertFalse($caps['can_load']);
        $this->assertFalse($caps['can_labels']);
        $this->assertFalse($caps['can_m30']);
        $this->assertSame('line_not_allowed', $caps['reason']);
    }

    public function test_workstation_on_another_line_than_the_work_order_is_a_mismatch(): void
    {
        [$workOrder, $step] = $this->stepWithWorkstationOnAnotherLine();

        $caps = $this->service->capabilitiesFor($this->supervisor, $step, null, $workOrder);

        $this->assertTrue($caps['is_cnc']);
        $this->assertFalse($caps['can_load']);
        $this->assertFalse($caps['can_labels']);
        $this->assertFalse($caps['can_m30']);
        $this->assertSame('line_mismatch', $caps['reason']);
    }

    public function test_operator_with_the_work_order_line_but_a_station_on_another_line_is_refused(): void
    {
        [$workOrder, $step] = $this->stepWithWorkstationOnAnotherLine();

        $caps = $this->service->capabilitiesFor($this->operator, $step, null, $workOrder);

        $this->assertFalse($caps['can_load']);
        $this->assertFalse($caps['can_labels']);
        $this->assertFalse($caps['can_m30']);
        $this->assertSame('line_mismatch', $caps['reason']);
    }

    public function test_capabilities_reuse_line_access_across_steps(): void
    {
        [, , $secondOrder, , $secondStep] = $this->makeStepOn($this->cncLine, 'NEST-02');
        [, , $thirdOrder, , $thirdStep] = $this->makeStepOn($this->cncLine, 'NEST-03');

        $this->workOrder->load('line');
        $secondOrder->load('line');
        $thirdOrder->load('line');

        $lineQueries = 0;
        DB::listen(function ($query) use (&$lineQueries): void {
            if (str_contains($query->sql, 'from "lines"') || str_contains($query->sql, 'from `lines`')) {
                $lineQueries++;
            }
        });

        $this->service->capabilitiesFor($this->operator, $this->step, null, $this->workOrder);
        $this->service->capabilitiesFor($this->operator, $secondStep, null, $secondOrder);
        $this->service->capabilitiesFor($this->operator, $thirdStep, null, $thirdOrder);

        $this->assertLessThanOrEqual(1, $lineQueries);
    }

    public function test_work_order_view_does_not_query_active_commands_per_step(): void
    {
        // Three steps with no commands at all: the controller preloads the
        // active map once and the service must not look it up again per step.
        foreach ([2, 3] as $stepNumber) {
            BatchStep::factory()->create([
                'batch_id' => $this->batch->id,
                'step_number' => $stepNumber,
                'name' => 'Cięcie CNC',
                'kind' => 'production',
                'workstation_id' => $this->nest->id,
                'workstation_type_id' => $this->cncType->id,
                'status' => BatchStep::STATUS_IN_PROGRESS,
            ]);
        }

        $commandQueries = 0;
        DB::listen(function ($query) use (&$commandQueries): void {
            if (str_contains($query->sql, 'from "machine_commands"')
                || str_contains($query->sql, 'from `machine_commands`')) {
                $commandQueries++;
            }
        });

        $this->actingAs($this->operator)
            ->withSession(['selected_line_id' => $this->cncLine->id])
            ->get(route('operator.work-order.detail', $this->workOrder))
            ->assertOk();

        $this->assertLessThanOrEqual(2, $commandQueries);
    }

    /**
     * @return array{0: WorkOrder, 1: BatchStep}
     */
    private function stepWithWorkstationOnAnotherLine(): array
    {
        $foreignLine = Line::factory()->create(['code' => 'LINIA-X']);
        $type = $this->cncType;
        $workstation = Workstation::factory()->create([
            'line_id' => $foreignLine->id,
            'workstation_type_id' => $type->id,
            'code' => 'NEST-X',
        ]);

        $workOrder = WorkOrder::factory()->create([
            'line_id' => $this->cncLine->id,
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 10,
        ]);
        $batch = Batch::factory()->create([
            'work_order_id' => $workOrder->id,
            'status' => Batch::STATUS_IN_PROGRESS,
        ]);
        $step = BatchStep::factory()->create([
            'batch_id' => $batch->id,
            'step_number' => 1,
            'name' => 'Cięcie CNC',
            'kind' => 'production',
            'workstation_id' => $workstation->id,
            'workstation_type_id' => $type->id,
            'status' => BatchStep::STATUS_IN_PROGRESS,
        ]);

        return [$workOrder, $step];
    }

    public function test_active_command_is_reported_without_duplicates(): void
    {
        $command = $this->makeCommand(MachineCommand::TYPE_LOAD_NC);

        $caps = $this->service->capabilitiesFor($this->operator, $this->step);

        $this->assertSame([
            'id' => $command->id,
            'type' => MachineCommand::TYPE_LOAD_NC,
            'status' => MachineCommand::STATUS_PENDING,
        ], $caps['active_command']);

        // Passing the row the controller already looked up gives the same shape.
        $explicit = $this->service->capabilitiesFor($this->operator, $this->step, $command);
        $this->assertSame($caps['active_command'], $explicit['active_command']);
    }

    public function test_work_order_view_carries_capabilities_per_step(): void
    {
        $this->actingAs($this->operator)
            ->withSession(['selected_line_id' => $this->cncLine->id])
            ->get(route('operator.work-order.detail', $this->workOrder))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('operator/WorkOrderDetail')
                ->has('machineCommandCaps.'.$this->step->id)
                ->where('machineCommandCaps.'.$this->step->id.'.is_cnc', true)
                ->where('machineCommandCaps.'.$this->step->id.'.step_status', BatchStep::STATUS_IN_PROGRESS)
                ->where('machineCommandCaps.'.$this->step->id.'.can_load', true)
                ->where('machineCommandCaps.'.$this->step->id.'.can_labels', true)
                ->where('machineCommandCaps.'.$this->step->id.'.can_m30', false)
                ->where('machineCommandCaps.'.$this->step->id.'.reason', null));
    }

    public function test_polling_index_carries_capabilities(): void
    {
        $this->makeCommand(MachineCommand::TYPE_LOAD_NC);

        $this->actingAs($this->operator)
            ->getJson(route('operator.batch-step.machine-commands.index', $this->step))
            ->assertOk()
            ->assertJsonPath('capabilities.is_cnc', true)
            ->assertJsonPath('capabilities.can_load', true)
            ->assertJsonPath('capabilities.can_m30', false)
            ->assertJsonPath('capabilities.reason', null)
            ->assertJsonPath('capabilities.active_command.type', MachineCommand::TYPE_LOAD_NC)
            ->assertJsonCount(1, 'data');
    }
}
