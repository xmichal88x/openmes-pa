<?php

namespace Tests\Feature\MachineCommands;

use App\Models\MachineCommand;
use App\Models\User;
use Illuminate\Support\Str;

class MachineCommandApiTest extends MachineCommandsTestCase
{
    public function test_operator_creates_a_load_nc_command(): void
    {
        $response = $this->api($this->operator)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_LOAD_NC,
            'payload' => ['nc_path' => '/nc/a.anc'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', MachineCommand::TYPE_LOAD_NC)
            ->assertJsonPath('data.status', MachineCommand::STATUS_PENDING)
            ->assertJsonPath('data.batch_step_id', $this->step->id);

        $this->assertArrayNotHasKey('lock_token', $response->json('data'));
        $this->assertDatabaseHas('machine_commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_LOAD_NC,
            'requested_by_id' => $this->operator->id,
        ]);
    }

    public function test_load_nc_on_merged_source_is_accepted(): void
    {
        $this->api($this->operator)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_LOAD_NC,
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', MachineCommand::TYPE_LOAD_NC)
            ->assertJsonPath('data.status', MachineCommand::STATUS_PENDING);

        $this->assertDatabaseHas('machine_commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_LOAD_NC,
            'requested_by_id' => $this->operator->id,
        ]);
    }

    public function test_idempotent_replay_returns_200_and_the_same_row(): void
    {
        $key = (string) Str::uuid();
        $body = [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_LOAD_NC,
            'idempotency_key' => $key,
            'payload' => ['nc_path' => '/nc/a.anc'],
        ];

        $first = $this->api($this->operator)->postJson('/api/v1/machine/commands', $body)->assertCreated();
        $second = $this->api($this->operator)->postJson('/api/v1/machine/commands', $body)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, MachineCommand::where('idempotency_key', $key)->count());
    }

    public function test_reusing_a_key_with_a_different_payload_conflicts(): void
    {
        $key = (string) Str::uuid();

        $this->api($this->operator)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_LOAD_NC,
            'idempotency_key' => $key,
            'payload' => ['nc_path' => '/nc/a.anc'],
        ])->assertCreated();

        $this->api($this->operator)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_LOAD_NC,
            'idempotency_key' => $key,
            'payload' => ['nc_path' => '/nc/other.anc'],
        ])->assertStatus(409)
            ->assertJsonPath('reason', 'idempotency_conflict');
    }

    public function test_unknown_batch_step_fails_validation(): void
    {
        $this->api($this->operator)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => 999999,
            'type' => MachineCommand::TYPE_LOAD_NC,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('batch_step_id');
    }

    public function test_non_cnc_step_is_rejected(): void
    {
        config()->set('machine_commands.line_codes', ['LINIA-A']);
        [, , , $workOrder, , $step] = $this->makeMachineStep('LINIA-A', 'packing', 'PACK-01', 'Pakowanie');
        $supervisor = $this->makeUser('Supervisor');

        $this->api($supervisor)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $step->id,
            'work_order_id' => $workOrder->id,
            'type' => MachineCommand::TYPE_LOAD_NC,
        ])->assertStatus(422)
            ->assertJsonPath('reason', 'not_cnc_step');
    }

    public function test_manual_m30_requires_a_reason(): void
    {
        $this->api($this->supervisor)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_MANUAL_M30,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_feature_switch_off_returns_409(): void
    {
        config()->set('machine_commands.enabled', false);

        $this->api($this->operator)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_LOAD_NC,
        ])->assertStatus(409)
            ->assertJsonPath('reason', 'feature_disabled');
    }

    public function test_operator_cannot_command_a_foreign_line(): void
    {
        $this->api($this->foreignOperator)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_LOAD_NC,
        ])->assertForbidden();
    }

    public function test_operator_cannot_request_manual_m30(): void
    {
        $this->api($this->operator)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_MANUAL_M30,
            'reason' => 'Zmiana narzędzia',
        ])->assertForbidden();
    }

    public function test_supervisor_can_request_manual_m30(): void
    {
        $this->api($this->supervisor)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_MANUAL_M30,
            'reason' => 'Zmiana narzędzia',
        ])->assertCreated()
            ->assertJsonPath('data.type', MachineCommand::TYPE_MANUAL_M30);
    }

    public function test_force_flag_is_stored_in_the_manual_m30_payload(): void
    {
        $response = $this->api($this->supervisor)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_MANUAL_M30,
            'reason' => 'Świadome domknięcie cyklu',
            'force' => true,
        ])->assertCreated();

        $this->assertTrue($response->json('data.payload.force'));

        $command = MachineCommand::findOrFail($response->json('data.id'));
        $this->assertSame(true, $command->payload['force']);
    }

    public function test_manual_m30_without_force_has_no_force_key_in_the_payload(): void
    {
        $response = $this->api($this->supervisor)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_MANUAL_M30,
            'reason' => 'Zwykłe domknięcie cyklu',
        ])->assertCreated();

        $command = MachineCommand::findOrFail($response->json('data.id'));

        $this->assertArrayNotHasKey('force', $command->payload ?? []);
    }

    public function test_bridge_claims_a_command_and_gets_the_lock_token(): void
    {
        $command = $this->makeCommand();
        $bridge = $this->makeUser('MachineBridge');

        $this->api($bridge)->postJson('/api/v1/machine/commands/claim', [
            'workstation_codes' => [$this->nest->code],
            'lease_seconds' => 60,
        ])->assertOk()
            ->assertJsonPath('data.id', $command->id)
            ->assertJsonPath('data.status', MachineCommand::STATUS_PROCESSING)
            ->assertJsonStructure(['data' => ['lock_token']]);
    }

    public function test_a_second_claim_returns_204(): void
    {
        $this->makeCommand();
        $bridge = $this->makeUser('MachineBridge');

        $this->api($bridge)->postJson('/api/v1/machine/commands/claim', [
            'workstation_codes' => [$this->nest->code],
        ])->assertOk();

        $this->api($bridge)->postJson('/api/v1/machine/commands/claim', [
            'workstation_codes' => [$this->nest->code],
        ])->assertNoContent();
    }

    public function test_claim_with_types_filter_skips_other_types(): void
    {
        $this->makeCommand(
            MachineCommand::TYPE_MANUAL_M30,
            reason: 'Awaria wrzeciona',
        );
        $load = $this->makeCommand(MachineCommand::TYPE_LOAD_NC);
        $bridge = $this->makeUser('MachineBridge');

        $this->api($bridge)->postJson('/api/v1/machine/commands/claim', [
            'workstation_codes' => [$this->nest->code],
            'types' => [MachineCommand::TYPE_LOAD_NC],
        ])->assertOk()
            ->assertJsonPath('data.id', $load->id)
            ->assertJsonPath('data.type', MachineCommand::TYPE_LOAD_NC);
    }

    public function test_claim_with_an_empty_types_filter_returns_204(): void
    {
        $this->makeCommand();
        $bridge = $this->makeUser('MachineBridge');

        $this->api($bridge)->postJson('/api/v1/machine/commands/claim', [
            'workstation_codes' => [$this->nest->code],
            'types' => [],
        ])->assertNoContent();

        $this->assertDatabaseHas('machine_commands', [
            'batch_step_id' => $this->step->id,
            'status' => MachineCommand::STATUS_PENDING,
        ]);
    }

    public function test_claim_without_types_still_claims_as_before(): void
    {
        $command = $this->makeCommand();
        $bridge = $this->makeUser('MachineBridge');

        $this->api($bridge)->postJson('/api/v1/machine/commands/claim', [
            'workstation_codes' => [$this->nest->code],
        ])->assertOk()
            ->assertJsonPath('data.id', $command->id);
    }

    public function test_claim_rejects_unknown_types(): void
    {
        $bridge = $this->makeUser('MachineBridge');

        $this->api($bridge)->postJson('/api/v1/machine/commands/claim', [
            'workstation_codes' => [$this->nest->code],
            'types' => ['teleport'],
        ])->assertStatus(422);
    }

    public function test_bridge_completes_a_command(): void
    {
        $command = $this->makeCommand();
        $bridge = $this->makeUser('MachineBridge');

        $token = $this->api($bridge)->postJson('/api/v1/machine/commands/claim', [
            'workstation_codes' => [$this->nest->code],
        ])->json('data.lock_token');

        $this->api($bridge)->patchJson('/api/v1/machine/commands/'.$command->id, [
            'lock_token' => $token,
            'status' => MachineCommand::STATUS_DONE,
            'outcome' => MachineCommand::OUTCOME_OK,
        ])->assertOk()
            ->assertJsonPath('data.status', MachineCommand::STATUS_DONE)
            ->assertJsonPath('data.outcome', MachineCommand::OUTCOME_OK);
    }

    public function test_wrong_lease_token_is_rejected_with_409(): void
    {
        $command = $this->makeCommand();
        $bridge = $this->makeUser('MachineBridge');

        $this->api($bridge)->postJson('/api/v1/machine/commands/claim', [
            'workstation_codes' => [$this->nest->code],
        ])->assertOk();

        $this->api($bridge)->patchJson('/api/v1/machine/commands/'.$command->id, [
            'lock_token' => 'not-the-token',
            'status' => MachineCommand::STATUS_DONE,
            'outcome' => MachineCommand::OUTCOME_OK,
        ])->assertStatus(409)
            ->assertJsonPath('reason', 'lease_lost');
    }

    public function test_requester_cancels_a_pending_command(): void
    {
        $command = $this->makeCommand();

        $this->api($this->operator)->postJson('/api/v1/machine/commands/'.$command->id.'/cancel', [
            'reason' => 'Nie jest już potrzebne',
        ])->assertOk()
            ->assertJsonPath('data.status', MachineCommand::STATUS_CANCELLED);
    }

    public function test_cancelling_a_processing_command_returns_409(): void
    {
        $command = $this->makeCommand();
        $bridge = $this->makeUser('MachineBridge');

        $this->api($bridge)->postJson('/api/v1/machine/commands/claim', [
            'workstation_codes' => [$this->nest->code],
        ])->assertOk();

        $this->api($this->operator)->postJson('/api/v1/machine/commands/'.$command->id.'/cancel', [
            'reason' => 'Za późno',
        ])->assertStatus(409)
            ->assertJsonPath('reason', 'invalid_transition');
    }

    public function test_index_is_scoped_to_the_operators_line(): void
    {
        $this->makeCommand(actor: $this->operator);

        $own = $this->api($this->operator)->getJson('/api/v1/machine/commands')->assertOk();
        $this->assertCount(1, $own->json('data'));

        $foreign = $this->api($this->foreignOperator)->getJson('/api/v1/machine/commands')->assertOk();
        $this->assertCount(0, $foreign->json('data'));
    }

    public function test_index_and_show_never_expose_the_lock_token(): void
    {
        $command = $this->makeCommand();

        $index = $this->api($this->operator)->getJson('/api/v1/machine/commands')->assertOk();
        $this->assertArrayNotHasKey('lock_token', $index->json('data.0'));

        $show = $this->api($this->operator)->getJson('/api/v1/machine/commands/'.$command->id)->assertOk();
        $this->assertArrayNotHasKey('lock_token', $show->json('data'));
    }

    private function api(User $user): static
    {
        // The test app reuses the resolved Sanctum user between requests.
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }
}
