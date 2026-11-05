<?php

namespace Tests\Feature\MachineCommands;

use App\Models\MachineCommand;

class MachineCommandWebTest extends MachineCommandsTestCase
{
    public function test_operator_stores_a_command_through_the_web_form(): void
    {
        $this->actingAs($this->operator)
            ->from('/operator/queue')
            ->post($this->storeUrl(), [
                'batch_step_id' => $this->step->id,
                'type' => MachineCommand::TYPE_LOAD_NC,
                'payload' => ['nc_path' => '/nc/a.anc'],
            ])
            ->assertRedirect('/operator/queue')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('machine_commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_LOAD_NC,
            'requested_by_id' => $this->operator->id,
        ]);
    }

    public function test_operator_stores_a_command_as_json(): void
    {
        $this->actingAs($this->operator)
            ->postJson($this->storeUrl(), [
                'batch_step_id' => $this->step->id,
                'type' => MachineCommand::TYPE_GENERATE_LABELS,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', MachineCommand::TYPE_GENERATE_LABELS);
    }

    public function test_index_lists_the_commands_of_the_step(): void
    {
        $this->makeCommand(MachineCommand::TYPE_LOAD_NC);
        $this->makeCommand(MachineCommand::TYPE_GENERATE_LABELS);

        $response = $this->actingAs($this->operator)
            ->getJson(route('operator.batch-step.machine-commands.index', $this->step))
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertArrayNotHasKey('lock_token', $response->json('data.0'));
    }

    public function test_requester_cancels_a_pending_command(): void
    {
        $command = $this->makeCommand();

        $this->actingAs($this->operator)
            ->postJson(route('operator.machine-commands.cancel', $command), [
                'reason' => 'Pomyłka operatora',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', MachineCommand::STATUS_CANCELLED);
    }

    public function test_validation_errors_are_returned_for_a_missing_type(): void
    {
        $this->actingAs($this->operator)
            ->postJson($this->storeUrl(), [
                'batch_step_id' => $this->step->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_foreign_operator_cannot_list_or_create_on_the_step(): void
    {
        $this->actingAs($this->foreignOperator)
            ->getJson(route('operator.batch-step.machine-commands.index', $this->step))
            ->assertForbidden();

        $this->actingAs($this->foreignOperator)
            ->postJson($this->storeUrl(), [
                'batch_step_id' => $this->step->id,
                'type' => MachineCommand::TYPE_LOAD_NC,
            ])
            ->assertForbidden();
    }

    public function test_foreign_operator_cannot_view_a_command(): void
    {
        $command = $this->makeCommand();

        $this->actingAs($this->foreignOperator)
            ->getJson(route('operator.machine-commands.show', $command))
            ->assertForbidden();
    }

    private function storeUrl(): string
    {
        return route('operator.batch-step.machine-commands.store', $this->step);
    }
}
