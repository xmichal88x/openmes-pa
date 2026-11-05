<?php

namespace Tests\Feature\MachineCommands;

use App\Models\Line;
use App\Models\MachineCommand;

class MachineCommandLineScopeTest extends MachineCommandsTestCase
{
    public function test_operator_may_command_their_own_line(): void
    {
        $command = $this->makeCommand(actor: $this->operator);

        $this->assertSame(MachineCommand::STATUS_PENDING, $command->status);
    }

    public function test_operator_may_not_command_a_foreign_line(): void
    {
        $this->assertMachineCommandError(
            'line_forbidden',
            fn () => $this->makeCommand(actor: $this->foreignOperator),
            403,
        );
    }

    public function test_supervisor_may_command_any_allowed_line(): void
    {
        $anotherLine = Line::factory()->create(['code' => 'CNC-2']);
        config()->set('machine_commands.line_codes', ['CNC', 'CNC-2']);
        [, , $workOrder, , $step] = $this->makeStepOn($anotherLine, 'NEST-02');

        $command = $this->makeCommand(actor: $this->supervisor, workOrder: $workOrder, step: $step);

        $this->assertSame($anotherLine->id, $command->line_id);
        $this->assertSame(MachineCommand::STATUS_PENDING, $command->status);
        $this->assertSame([], $this->supervisor->lines()->pluck('lines.id')->all());
    }

    public function test_admin_may_command_any_allowed_line(): void
    {
        $anotherLine = Line::factory()->create(['code' => 'CNC-3']);
        config()->set('machine_commands.line_codes', ['CNC', 'CNC-3']);
        [, , $workOrder, , $step] = $this->makeStepOn($anotherLine, 'NEST-03');

        $command = $this->makeCommand(actor: $this->admin, workOrder: $workOrder, step: $step);

        $this->assertSame($anotherLine->id, $command->line_id);
    }

    public function test_a_packing_line_is_refused_even_for_an_admin(): void
    {
        [$line, , , $workOrder, , $step] = $this->makeMachineStep('PACKING', 'packing', 'PACK-01', 'Pakowanie');
        $admin = $this->makeUser('Admin');

        $error = $this->assertMachineCommandError(
            'line_forbidden',
            fn () => $this->makeCommand(actor: $admin, workOrder: $workOrder, step: $step),
        );

        $this->assertContains($error->httpStatus, [403, 422]);
    }
}
