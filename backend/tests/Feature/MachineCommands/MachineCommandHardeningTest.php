<?php

namespace Tests\Feature\MachineCommands;

use App\Models\MachineCommand;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * P1c2 hardening: type visibility, authorization order, web step binding,
 * manual_m30 role gate, kill-switch flags, force scope and request validation.
 */
class MachineCommandHardeningTest extends MachineCommandsTestCase
{
    // ── A) type visibility ───────────────────────────────────────────────────

    public function test_operator_does_not_see_manual_m30_in_the_api_index(): void
    {
        $this->makeCommand(MachineCommand::TYPE_LOAD_NC, actor: $this->operator);
        $this->makeCommand(MachineCommand::TYPE_GENERATE_LABELS, actor: $this->operator);
        $this->makeCommand(MachineCommand::TYPE_MANUAL_M30, actor: $this->supervisor, reason: 'Zmiana narzędzia');

        $types = collect($this->api($this->operator)->getJson('/api/v1/machine/commands')->assertOk()->json('data'))
            ->pluck('type')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([MachineCommand::TYPE_GENERATE_LABELS, MachineCommand::TYPE_LOAD_NC], $types);
    }

    public function test_operator_does_not_see_manual_m30_in_the_web_index(): void
    {
        $this->makeCommand(MachineCommand::TYPE_LOAD_NC, actor: $this->operator);
        $this->makeCommand(MachineCommand::TYPE_GENERATE_LABELS, actor: $this->operator);
        $this->makeCommand(MachineCommand::TYPE_MANUAL_M30, actor: $this->supervisor, reason: 'Zmiana narzędzia');

        $types = collect($this->actingAs($this->operator)
            ->getJson(route('operator.batch-step.machine-commands.index', $this->step))
            ->assertOk()
            ->json('data'))
            ->pluck('type')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([MachineCommand::TYPE_GENERATE_LABELS, MachineCommand::TYPE_LOAD_NC], $types);
    }

    public function test_supervisor_sees_every_type_in_the_api_index(): void
    {
        $this->makeCommand(MachineCommand::TYPE_LOAD_NC, actor: $this->operator);
        $this->makeCommand(MachineCommand::TYPE_GENERATE_LABELS, actor: $this->operator);
        $this->makeCommand(MachineCommand::TYPE_MANUAL_M30, actor: $this->supervisor, reason: 'Zmiana narzędzia');

        $this->assertCount(
            3,
            $this->api($this->supervisor)->getJson('/api/v1/machine/commands')->assertOk()->json('data'),
        );
    }

    public function test_machine_bridge_sees_every_type_in_the_api_index(): void
    {
        $this->makeCommand(MachineCommand::TYPE_LOAD_NC, actor: $this->operator);
        $this->makeCommand(MachineCommand::TYPE_MANUAL_M30, actor: $this->supervisor, reason: 'Zmiana narzędzia');

        $bridge = $this->makeUser('MachineBridge');

        $this->assertCount(
            2,
            $this->api($bridge)->getJson('/api/v1/machine/commands')->assertOk()->json('data'),
        );
    }

    public function test_operator_with_a_hand_granted_permission_still_cannot_see_manual_m30(): void
    {
        $this->operator->givePermissionTo('request machine manual m30');

        $command = $this->makeCommand(
            MachineCommand::TYPE_MANUAL_M30,
            actor: $this->supervisor,
            reason: 'Zmiana narzędzia',
        );

        $policy = Gate::getPolicyFor(MachineCommand::class);

        $this->assertNotContains(MachineCommand::TYPE_MANUAL_M30, $policy->visibleTypes($this->operator));
        $this->assertContains(MachineCommand::TYPE_MANUAL_M30, $policy->visibleTypes($this->supervisor));

        $this->assertSame(
            [],
            $this->actingAs($this->operator)
                ->getJson(route('operator.batch-step.machine-commands.index', $this->step))
                ->assertOk()
                ->json('data'),
        );

        $this->actingAs($this->operator)
            ->getJson(route('operator.machine-commands.show', $command))
            ->assertForbidden();

        $this->assertSame(
            [],
            $this->api($this->operator)->getJson('/api/v1/machine/commands')->assertOk()->json('data'),
        );

        $this->api($this->operator)
            ->getJson('/api/v1/machine/commands/'.$command->id)
            ->assertForbidden();

        $this->api($this->supervisor)
            ->getJson('/api/v1/machine/commands/'.$command->id)
            ->assertOk();
    }

    public function test_api_store_denies_before_revealing_a_missing_step(): void
    {
        $this->api($this->operator)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => 999999,
            'type' => MachineCommand::TYPE_MANUAL_M30,
            'reason' => 'Nie powinno dojść do walidacji',
        ])->assertForbidden();
    }

    public function test_web_store_denies_before_revealing_a_missing_step(): void
    {
        $this->actingAs($this->operator)->postJson($this->storeUrl(), [
            'batch_step_id' => 999999,
            'type' => MachineCommand::TYPE_MANUAL_M30,
            'reason' => 'Nie powinno dojść do walidacji',
        ])->assertForbidden();
    }

    public function test_api_cancel_denies_before_validating_the_reason(): void
    {
        $command = $this->makeCommand(actor: $this->operator);

        $this->api($this->foreignOperator)
            ->postJson('/api/v1/machine/commands/'.$command->id.'/cancel', [
                'reason' => 'x',
            ])
            ->assertForbidden();
    }

    public function test_web_cancel_denies_before_validating_the_reason(): void
    {
        $command = $this->makeCommand(actor: $this->operator);

        $this->actingAs($this->foreignOperator)
            ->postJson(route('operator.machine-commands.cancel', $command), [
                'reason' => 'x',
            ])
            ->assertForbidden();
    }

    public function test_machine_bridge_cannot_cancel_a_command(): void
    {
        $command = $this->makeCommand(actor: $this->operator);
        $bridge = $this->makeUser('MachineBridge');

        $this->api($bridge)
            ->postJson('/api/v1/machine/commands/'.$command->id.'/cancel', [
                'reason' => 'Mostek nie anuluje pracy',
            ])
            ->assertForbidden();
    }

    // ── C) web batch_step_id must match the route ────────────────────────────

    public function test_web_store_rejects_a_body_step_that_differs_from_the_route(): void
    {
        [, , , , $otherStep] = $this->makeStepOn($this->cncLine, 'NEST-02', 'Cięcie CNC');

        $this->actingAs($this->operator)->postJson($this->storeUrl(), [
            'batch_step_id' => $otherStep->id,
            'type' => MachineCommand::TYPE_LOAD_NC,
        ])->assertStatus(422)
            ->assertJsonPath('reason', 'batch_step_mismatch');
    }

    // ── D) manual_m30 requires the role, not just the permission ─────────────

    public function test_operator_with_a_hand_granted_permission_still_cannot_request_manual_m30(): void
    {
        $this->operator->givePermissionTo('request machine manual m30');

        $this->api($this->operator)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_MANUAL_M30,
            'reason' => 'Ręcznie nadane uprawnienie',
        ])->assertForbidden();
    }

    public function test_service_refuses_manual_m30_from_an_operator_even_with_the_permission(): void
    {
        $this->operator->givePermissionTo('request machine manual m30');

        $this->assertMachineCommandError(
            'forbidden',
            fn () => $this->makeCommand(
                MachineCommand::TYPE_MANUAL_M30,
                actor: $this->operator,
                reason: 'Ręcznie nadane uprawnienie',
            ),
            403,
        );
    }

    public function test_supervisor_still_creates_manual_m30(): void
    {
        $this->api($this->supervisor)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_MANUAL_M30,
            'reason' => 'Zmiana narzędzia',
        ])->assertCreated();
    }

    // ── E) flags as a kill switch ────────────────────────────────────────────

    public function test_claim_is_refused_when_the_feature_is_disabled(): void
    {
        $this->makeCommand(actor: $this->operator);

        config()->set('machine_commands.enabled', false);

        $this->api($this->makeUser('MachineBridge'))
            ->postJson('/api/v1/machine/commands/claim', [
                'workstation_codes' => [$this->nest->code],
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'feature_disabled');
    }

    public function test_claim_skips_commands_of_a_disabled_type(): void
    {
        $this->makeCommand(MachineCommand::TYPE_LOAD_NC, actor: $this->operator);
        $labels = $this->makeCommand(MachineCommand::TYPE_GENERATE_LABELS, actor: $this->operator);

        config()->set('machine_commands.types.'.MachineCommand::TYPE_LOAD_NC, false);

        $this->api($this->makeUser('MachineBridge'))
            ->postJson('/api/v1/machine/commands/claim', [
                'workstation_codes' => [$this->nest->code],
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $labels->id);

        $this->assertSame(
            MachineCommand::STATUS_PENDING,
            MachineCommand::where('type', MachineCommand::TYPE_LOAD_NC)->firstOrFail()->status,
        );
    }

    public function test_complete_still_works_when_the_feature_is_disabled(): void
    {
        $command = $this->makeCommand(actor: $this->operator);
        $bridge = $this->makeUser('MachineBridge');

        $token = $this->api($bridge)->postJson('/api/v1/machine/commands/claim', [
            'workstation_codes' => [$this->nest->code],
        ])->assertOk()->json('data.lock_token');

        config()->set('machine_commands.enabled', false);

        $this->api($bridge)->patchJson('/api/v1/machine/commands/'.$command->id, [
            'lock_token' => $token,
            'status' => MachineCommand::STATUS_DONE,
            'outcome' => MachineCommand::OUTCOME_OK,
        ])->assertOk()
            ->assertJsonPath('data.status', MachineCommand::STATUS_DONE);
    }

    // ── F) force is manual_m30 only ──────────────────────────────────────────

    public function test_force_is_rejected_for_load_nc(): void
    {
        $this->api($this->operator)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_LOAD_NC,
            'force' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('force');
    }

    public function test_force_is_rejected_for_generate_labels(): void
    {
        $this->api($this->operator)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_GENERATE_LABELS,
            'force' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('force');
    }

    public function test_manual_m30_keeps_the_force_flag(): void
    {
        $response = $this->api($this->supervisor)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_MANUAL_M30,
            'reason' => 'Świadome domknięcie cyklu',
            'force' => true,
        ])->assertCreated();

        $this->assertTrue($response->json('data.payload.force'));
    }

    public function test_force_false_is_accepted_for_load_nc(): void
    {
        $this->api($this->operator)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_LOAD_NC,
            'force' => false,
        ])->assertCreated();
    }

    public function test_force_in_the_payload_is_rejected_for_load_nc(): void
    {
        $this->api($this->operator)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_LOAD_NC,
            'payload' => ['force' => true],
        ])->assertStatus(422)
            ->assertJsonPath('reason', 'force_not_allowed');
    }

    public function test_manual_m30_accepts_force_inside_the_payload(): void
    {
        $response = $this->api($this->supervisor)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_MANUAL_M30,
            'reason' => 'Świadome domknięcie cyklu',
            'payload' => ['force' => true],
        ])->assertCreated();

        $this->assertTrue($response->json('data.payload.force'));
    }

    public function test_manual_m30_rejects_a_non_boolean_force_in_the_payload(): void
    {
        $this->api($this->supervisor)->postJson('/api/v1/machine/commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_MANUAL_M30,
            'reason' => 'Świadome domknięcie cyklu',
            'payload' => ['force' => 'yes'],
        ])->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_payload');
    }

    // ── G) web form may omit the route step id ───────────────────────────────

    public function test_web_store_uses_the_route_step_when_batch_step_id_is_missing(): void
    {
        $this->actingAs($this->operator)
            ->from('/operator/queue')
            ->post($this->storeUrl(), [
                'type' => MachineCommand::TYPE_LOAD_NC,
            ])
            ->assertRedirect('/operator/queue')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('machine_commands', [
            'batch_step_id' => $this->step->id,
            'type' => MachineCommand::TYPE_LOAD_NC,
        ]);
    }

    // ── H) request validation details ────────────────────────────────────────

    public function test_claim_rejects_duplicate_workstation_codes(): void
    {
        $this->api($this->makeUser('MachineBridge'))
            ->postJson('/api/v1/machine/commands/claim', [
                'workstation_codes' => [$this->nest->code, $this->nest->code],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('workstation_codes.0');
    }

    public function test_claim_rejects_more_than_fifty_workstation_codes(): void
    {
        $codes = array_map(fn (int $i) => 'WS-'.$i, range(1, 51));

        $this->api($this->makeUser('MachineBridge'))
            ->postJson('/api/v1/machine/commands/claim', [
                'workstation_codes' => $codes,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('workstation_codes');
    }

    public function test_requeue_requires_a_transient_error_class(): void
    {
        $command = $this->makeCommand(actor: $this->operator);
        $bridge = $this->makeUser('MachineBridge');

        $token = $this->api($bridge)->postJson('/api/v1/machine/commands/claim', [
            'workstation_codes' => [$this->nest->code],
        ])->assertOk()->json('data.lock_token');

        $this->api($bridge)->patchJson('/api/v1/machine/commands/'.$command->id, [
            'lock_token' => $token,
            'status' => MachineCommand::STATUS_PENDING,
            'error' => 'Machine busy',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('error_class');

        $this->api($bridge)->patchJson('/api/v1/machine/commands/'.$command->id, [
            'lock_token' => $token,
            'status' => MachineCommand::STATUS_PENDING,
            'error' => 'Machine busy',
            'error_class' => MachineCommand::ERROR_PERMANENT,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('error_class');
    }

    private function storeUrl(): string
    {
        return route('operator.batch-step.machine-commands.store', $this->step);
    }

    private function api(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }
}
