<?php

namespace Tests\Feature\MachineCommands;

use App\Models\AuditLog;
use App\Models\BatchStep;
use App\Models\MachineCommand;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class MachineCommandServiceTest extends MachineCommandsTestCase
{
    public function test_feature_switch_off_blocks_every_type(): void
    {
        config()->set('machine_commands.enabled', false);

        $this->assertMachineCommandError(
            'feature_disabled',
            fn () => $this->makeCommand(),
            409,
        );
    }

    public function test_disabled_type_is_refused_even_when_the_feature_is_on(): void
    {
        config()->set('machine_commands.types.'.MachineCommand::TYPE_LOAD_NC, false);

        $this->assertMachineCommandError(
            'feature_disabled',
            fn () => $this->makeCommand(MachineCommand::TYPE_LOAD_NC),
            409,
        );
    }

    public function test_unknown_type_is_refused(): void
    {
        $this->assertMachineCommandError(
            'invalid_type',
            fn () => $this->makeCommand('teleport'),
            422,
        );
    }

    public function test_line_outside_the_allowlist_is_forbidden(): void
    {
        [$line, , , $workOrder, , $step] = $this->makeMachineStep('PACKING', 'packing', 'PACK-01', 'Pakowanie');

        $this->assertMachineCommandError(
            'line_forbidden',
            fn () => $this->makeCommand(workOrder: $workOrder, step: $step),
            403,
        );
    }

    public function test_non_cnc_step_is_refused(): void
    {
        config()->set('machine_commands.line_codes', ['LINIA-A']);
        [$line, , , $workOrder, , $step] = $this->makeMachineStep('LINIA-A', 'packing', 'PACK-01', 'Pakowanie');

        $this->assertMachineCommandError(
            'not_cnc_step',
            fn () => $this->makeCommand(workOrder: $workOrder, step: $step),
            422,
        );
    }

    public function test_workstation_type_allowlist_is_enforced(): void
    {
        config()->set('machine_commands.workstation_type_codes', ['laser']);

        $this->assertMachineCommandError(
            'not_cnc_step',
            fn () => $this->makeCommand(),
            422,
        );
    }

    public function test_step_outside_in_progress_is_refused(): void
    {
        $this->step->update(['status' => BatchStep::STATUS_DONE]);

        $this->assertMachineCommandError(
            'invalid_step_status',
            fn () => $this->makeCommand(),
            422,
        );
    }

    public function test_manual_m30_requires_a_reason(): void
    {
        $this->assertMachineCommandError(
            'reason_required',
            fn () => $this->makeCommand(MachineCommand::TYPE_MANUAL_M30),
            422,
        );

        $command = $this->makeCommand(MachineCommand::TYPE_MANUAL_M30, reason: 'Zmiana narzędzia');

        $this->assertSame('Zmiana narzędzia', $command->reason);
    }

    public function test_idempotent_retry_returns_the_same_row(): void
    {
        $key = (string) Str::uuid();
        $payload = ['nc_path' => '/nc/a.anc'];

        $first = $this->makeCommand(payload: $payload, key: $key);
        $second = $this->makeCommand(payload: $payload, key: $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MachineCommand::query()->where('idempotency_key', $key)->count());
        $this->assertSame(
            1,
            AuditLog::where('action', 'machine_command.requested')->where('entity_id', $first->id)->count(),
        );
    }

    public function test_idempotency_key_with_a_different_payload_conflicts(): void
    {
        $key = (string) Str::uuid();
        $this->makeCommand(payload: ['nc_path' => '/nc/a.anc'], key: $key);

        $this->assertMachineCommandError(
            'idempotency_conflict',
            fn () => $this->makeCommand(payload: ['nc_path' => '/nc/b.anc'], key: $key),
            409,
        );
    }

    public function test_an_active_command_blocks_a_second_one_for_the_same_step_and_type(): void
    {
        $this->makeCommand();

        $this->assertMachineCommandError(
            'command_in_progress',
            fn () => $this->makeCommand(),
            409,
        );
    }

    public function test_create_snapshots_the_step_and_writes_an_audit_entry(): void
    {
        $command = $this->makeCommand(payload: ['nc_path' => '/nc/a.anc'], reason: 'Start programu');

        $this->assertSame($this->cncLine->id, $command->line_id);
        $this->assertSame($this->nest->id, $command->workstation_id);
        $this->assertSame('NEST-01', $command->workstation_code);
        $this->assertSame('Cięcie CNC', $command->step_name);
        $this->assertSame('production', $command->step_kind);
        $this->assertSame($this->step->id, $command->batch_step_id);
        $this->assertSame(MachineCommand::STATUS_PENDING, $command->status);

        $audit = AuditLog::where('action', 'machine_command.requested')
            ->where('entity_type', MachineCommand::class)
            ->where('entity_id', $command->id)
            ->firstOrFail();

        $this->assertSame($this->operator->id, $audit->user_id);
        $this->assertSame(MachineCommand::TYPE_LOAD_NC, $audit->after_state['type']);
        $this->assertSame('Start programu', $audit->after_state['reason']);
    }

    public function test_claim_takes_the_oldest_pending_command_and_leases_it(): void
    {
        $command = $this->makeCommand();

        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);

        $this->assertNotNull($claimed);
        $this->assertSame($command->id, $claimed->id);
        $this->assertSame(MachineCommand::STATUS_PROCESSING, $claimed->status);
        $this->assertSame('worker-a', $claimed->lock_token);
        $this->assertSame(1, $claimed->attempt_count);
        $this->assertNotNull($claimed->locked_until);
        $this->assertNotNull($claimed->started_at);

        $this->assertNull($this->service->claim(['NEST-01'], 'worker-b', 60));
    }

    public function test_claim_returns_null_without_work(): void
    {
        $this->assertNull($this->service->claim(['NEST-01'], 'worker-a', 60));
    }

    public function test_claim_filters_by_requested_types(): void
    {
        $manual = $this->makeCommand(
            MachineCommand::TYPE_MANUAL_M30,
            reason: 'Awaria wrzeciona',
        );
        $load = $this->makeCommand(MachineCommand::TYPE_LOAD_NC);

        $claimed = $this->service->claim(
            ['NEST-01'],
            'worker-a',
            60,
            null,
            null,
            [MachineCommand::TYPE_LOAD_NC],
        );

        $this->assertNotNull($claimed);
        $this->assertSame($load->id, $claimed->id);
        $this->assertSame(MachineCommand::STATUS_PENDING, $manual->fresh()->status);
    }

    public function test_claim_with_an_empty_type_filter_reclaims_but_returns_no_work(): void
    {
        $command = $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);
        $claimed->forceFill(['locked_until' => now()->subMinute()])->save();

        $this->assertNull(
            $this->service->claim(['NEST-01'], 'worker-b', 60, null, null, []),
        );

        $command->refresh();
        $this->assertSame(MachineCommand::STATUS_PENDING, $command->status);
        $this->assertNull($command->lock_token);
        $this->assertNotNull($command->next_attempt_at);
    }

    public function test_claim_reclaims_an_expired_lease_and_respects_the_retry_delay(): void
    {
        $command = $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);
        $claimed->forceFill(['locked_until' => now()->subMinute()])->save();

        $this->assertNull($this->service->claim(['NEST-01'], 'worker-b', 60));

        $command->refresh();
        $this->assertSame(MachineCommand::STATUS_PENDING, $command->status);
        $this->assertNull($command->lock_token);
        $this->assertNotNull($command->next_attempt_at);

        $this->travel(31)->seconds();

        $again = $this->service->claim(['NEST-01'], 'worker-b', 60);

        $this->assertNotNull($again);
        $this->assertSame($command->id, $again->id);
        $this->assertSame('worker-b', $again->lock_token);
        $this->assertSame(2, $again->attempt_count);
    }

    public function test_complete_done_stores_the_result(): void
    {
        $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);

        $done = $this->service->complete(
            $claimed,
            'worker-a',
            MachineCommand::STATUS_DONE,
            MachineCommand::OUTCOME_OK,
            ['loaded' => true],
        );

        $this->assertSame(MachineCommand::STATUS_DONE, $done->status);
        $this->assertSame(MachineCommand::OUTCOME_OK, $done->outcome);
        $this->assertSame(['loaded' => true], $done->result);
        $this->assertNull($done->lock_token);
        $this->assertNotNull($done->finished_at);
        $this->assertSame(
            1,
            AuditLog::where('action', 'machine_command.completed')->where('entity_id', $done->id)->count(),
        );
    }

    public function test_done_requires_a_valid_outcome(): void
    {
        $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);

        $this->assertMachineCommandError(
            'invalid_outcome',
            fn () => $this->service->complete($claimed, 'worker-a', MachineCommand::STATUS_DONE),
            422,
        );
    }

    public function test_failed_requires_an_error(): void
    {
        $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);

        $this->assertMachineCommandError(
            'error_required',
            fn () => $this->service->complete($claimed, 'worker-a', MachineCommand::STATUS_FAILED),
            422,
        );

        $failed = $this->service->complete(
            $claimed,
            'worker-a',
            MachineCommand::STATUS_FAILED,
            error: 'NC file missing',
            errorClass: MachineCommand::ERROR_PERMANENT,
        );

        $this->assertSame(MachineCommand::STATUS_FAILED, $failed->status);
        $this->assertSame('NC file missing', $failed->last_error);
        $this->assertSame(MachineCommand::ERROR_PERMANENT, $failed->error_class);
        $this->assertNotNull($failed->finished_at);
    }

    public function test_complete_pending_requeues_with_backoff(): void
    {
        $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);

        $requeued = $this->service->complete(
            $claimed,
            'worker-a',
            MachineCommand::STATUS_PENDING,
            error: 'Machine busy',
            errorClass: MachineCommand::ERROR_TRANSIENT,
        );

        $this->assertSame(MachineCommand::STATUS_PENDING, $requeued->status);
        $this->assertNotNull($requeued->next_attempt_at);
        $this->assertSame(MachineCommand::ERROR_TRANSIENT, $requeued->error_class);
        $this->assertNull($requeued->lock_token);
        $this->assertSame(
            1,
            AuditLog::where('action', 'machine_command.requeued')->where('entity_id', $requeued->id)->count(),
        );
    }

    public function test_complete_with_a_wrong_lock_token_loses_the_lease(): void
    {
        $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);

        $this->assertMachineCommandError(
            'lease_lost',
            fn () => $this->service->complete(
                $claimed,
                'worker-b',
                MachineCommand::STATUS_DONE,
                MachineCommand::OUTCOME_OK,
            ),
            409,
        );
    }

    public function test_a_terminal_command_is_immutable(): void
    {
        $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);
        $done = $this->service->complete($claimed, 'worker-a', MachineCommand::STATUS_DONE, MachineCommand::OUTCOME_OK);

        $this->assertMachineCommandError(
            'invalid_transition',
            fn () => $this->service->complete($done, 'worker-a', MachineCommand::STATUS_FAILED, error: 'late'),
            409,
        );
    }

    public function test_a_delivery_id_is_only_allowed_for_manual_m30(): void
    {
        $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);

        $this->assertMachineCommandError(
            'delivery_not_allowed',
            fn () => $this->service->complete(
                $claimed,
                'worker-a',
                MachineCommand::STATUS_DONE,
                MachineCommand::OUTCOME_OK,
                deliveryId: (string) Str::uuid(),
            ),
            422,
        );
    }

    public function test_manual_m30_uses_the_stored_delivery_id_when_the_request_omits_it(): void
    {
        $command = $this->makeCommand(MachineCommand::TYPE_MANUAL_M30, reason: 'Zmiana narzędzia');
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);

        $done = $this->service->complete(
            $claimed,
            'worker-a',
            MachineCommand::STATUS_DONE,
            MachineCommand::OUTCOME_OK,
        );

        $this->assertSame($command->delivery_id, $done->delivery_id);
    }

    public function test_cancel_changes_status_only_for_pending_commands(): void
    {
        $command = $this->makeCommand();

        $cancelled = $this->service->cancel($command, $this->supervisor, 'Plan changed');

        $this->assertSame(MachineCommand::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame('Plan changed', $cancelled->cancel_reason);
        $this->assertSame($this->supervisor->id, $cancelled->cancelled_by_id);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame(
            1,
            AuditLog::where('action', 'machine_command.cancelled')->where('entity_id', $command->id)->count(),
        );

        $processing = $this->makeCommand();
        $this->service->claim(['NEST-01'], 'worker-a', 60);

        $this->assertMachineCommandError(
            'invalid_transition',
            fn () => $this->service->cancel($processing->fresh(), $this->supervisor, 'Too late'),
            409,
        );
    }

    public function test_expire_leases_requeues_unfinished_commands(): void
    {
        $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);
        $claimed->forceFill(['locked_until' => now()->subMinute()])->save();

        $this->assertSame(1, $this->service->expireLeases());

        $claimed->refresh();
        $this->assertSame(MachineCommand::STATUS_PENDING, $claimed->status);
        $this->assertNull($claimed->lock_token);
        $this->assertNotNull($claimed->next_attempt_at);
    }

    public function test_expire_leases_fails_a_command_that_exhausted_its_attempts(): void
    {
        $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);
        $claimed->forceFill([
            'attempt_count' => (int) config('machine_commands.max_attempts'),
            'locked_until' => now()->subMinute(),
        ])->save();

        $this->assertSame(1, $this->service->expireLeases());

        $claimed->refresh();
        $this->assertSame(MachineCommand::STATUS_FAILED, $claimed->status);
        $this->assertSame(MachineCommand::ERROR_TRANSIENT, $claimed->error_class);
        $this->assertNotNull($claimed->finished_at);
        $this->assertSame(
            1,
            AuditLog::where('action', 'machine_command.failed')->where('entity_id', $claimed->id)->count(),
        );
    }

    public function test_packing_step_named_packing_on_a_cnc_line_is_not_cnc(): void
    {
        // The step name contains "CNC" and the line is CNC, so the token match
        // would classify it as machinable. The packing kind must win.
        [, , $workOrder, , $step] = $this->makeStepOn(
            $this->cncLine,
            'PACK-1',
            'Pakowanie CNC',
            BatchStep::STATUS_IN_PROGRESS,
            'packing',
            'packing',
        );

        $this->assertMachineCommandError(
            'not_cnc_step',
            fn () => $this->makeCommand(actor: $this->supervisor, workOrder: $workOrder, step: $step),
            422,
        );
    }

    public function test_a_step_named_execution_is_not_classified_by_the_cut_token(): void
    {
        [, , $workOrder, , $step] = $this->makeStepOn(
            $this->cncLine,
            'WS-1',
            'Execution',
            BatchStep::STATUS_IN_PROGRESS,
            'router',
            'production',
        );

        $this->assertMachineCommandError(
            'not_cnc_step',
            fn () => $this->makeCommand(actor: $this->supervisor, workOrder: $workOrder, step: $step),
            422,
        );
    }

    public function test_manual_m30_gets_a_deterministic_and_stable_delivery_id(): void
    {
        $command = $this->makeCommand(MachineCommand::TYPE_MANUAL_M30, reason: 'Zmiana narzędzia');

        $expected = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'machine-command:'.$command->id);

        $this->assertSame($expected, $command->delivery_id);
        $this->assertSame($expected, $command->fresh()->delivery_id);

        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);
        $done = $this->service->complete($claimed, 'worker-a', MachineCommand::STATUS_DONE, MachineCommand::OUTCOME_OK);

        $this->assertSame($expected, $done->delivery_id);
    }

    public function test_load_nc_and_labels_have_no_delivery_id(): void
    {
        $load = $this->makeCommand();
        $labels = $this->makeCommand(MachineCommand::TYPE_GENERATE_LABELS);

        $this->assertNull($load->delivery_id);
        $this->assertNull($labels->delivery_id);
    }

    public function test_manual_m30_completion_rejects_a_different_delivery_id(): void
    {
        $command = $this->makeCommand(MachineCommand::TYPE_MANUAL_M30, reason: 'Zmiana narzędzia');
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);

        $this->assertMachineCommandError(
            'delivery_mismatch',
            fn () => $this->service->complete(
                $claimed,
                'worker-a',
                MachineCommand::STATUS_DONE,
                MachineCommand::OUTCOME_OK,
                deliveryId: (string) Str::uuid(),
            ),
            409,
        );
    }

    public function test_manual_m30_expired_lease_is_parked_as_post_effect(): void
    {
        $command = $this->makeCommand(MachineCommand::TYPE_MANUAL_M30, reason: 'Zmiana narzędzia');
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);
        $claimed->forceFill(['locked_until' => now()->subMinute()])->save();

        $this->assertSame(1, $this->service->expireLeases());

        $command->refresh();
        $this->assertSame(MachineCommand::STATUS_FAILED, $command->status);
        $this->assertSame(MachineCommand::ERROR_POST_EFFECT, $command->error_class);
        $this->assertNotNull($command->finished_at);
        $this->assertNull($command->next_attempt_at);
        $this->assertNull($this->service->claim(['NEST-01'], 'worker-b', 60));
    }

    public function test_manual_m30_pending_with_transient_requeues(): void
    {
        $command = $this->makeCommand(MachineCommand::TYPE_MANUAL_M30, reason: 'Zmiana narzędzia');
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);

        $requeued = $this->service->complete(
            $claimed,
            'worker-a',
            MachineCommand::STATUS_PENDING,
            error: 'M30 delivery in progress',
            errorClass: MachineCommand::ERROR_TRANSIENT,
        );

        $this->assertSame(MachineCommand::STATUS_PENDING, $requeued->status);
        $this->assertSame(MachineCommand::ERROR_TRANSIENT, $requeued->error_class);
        $this->assertNotNull($requeued->next_attempt_at);
        $this->assertNull($requeued->lock_token);
        $this->assertNull($requeued->locked_until);
        $this->assertNull($requeued->finished_at);
        $this->assertSame($command->delivery_id, $requeued->delivery_id);
        $this->assertSame(
            1,
            AuditLog::where('action', 'machine_command.requeued')->where('entity_id', $requeued->id)->count(),
        );
    }

    public function test_manual_m30_pending_without_transient_is_refused(): void
    {
        $this->makeCommand(MachineCommand::TYPE_MANUAL_M30, reason: 'Zmiana narzędzia');
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);

        $this->assertMachineCommandError(
            'requeue_not_allowed',
            fn () => $this->service->complete(
                $claimed,
                'worker-a',
                MachineCommand::STATUS_PENDING,
                error: 'Retry please',
                errorClass: MachineCommand::ERROR_PERMANENT,
            ),
            409,
        );

        $this->assertSame(
            MachineCommand::STATUS_PROCESSING,
            $claimed->fresh()->status,
        );
    }

    public function test_manual_m30_pending_without_an_error_class_is_refused(): void
    {
        $this->makeCommand(MachineCommand::TYPE_MANUAL_M30, reason: 'Zmiana narzędzia');
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);

        $this->assertMachineCommandError(
            'requeue_not_allowed',
            fn () => $this->service->complete(
                $claimed,
                'worker-a',
                MachineCommand::STATUS_PENDING,
                error: 'Retry please',
            ),
            409,
        );
    }

    public function test_manual_m30_transient_requeue_respects_the_max_attempts_budget(): void
    {
        $this->makeCommand(MachineCommand::TYPE_MANUAL_M30, reason: 'Zmiana narzędzia');
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);
        $claimed->forceFill(['attempt_count' => (int) config('machine_commands.max_attempts')])->save();

        $failed = $this->service->complete(
            $claimed->fresh(),
            'worker-a',
            MachineCommand::STATUS_PENDING,
            error: 'M30 delivery in progress',
            errorClass: MachineCommand::ERROR_TRANSIENT,
        );

        $this->assertSame(MachineCommand::STATUS_FAILED, $failed->status);
        $this->assertSame(MachineCommand::ERROR_TRANSIENT, $failed->error_class);
        $this->assertNotNull($failed->finished_at);
        $this->assertNull($failed->next_attempt_at);
    }

    public function test_repeating_a_terminal_report_returns_the_same_row(): void
    {
        $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);
        $done = $this->service->complete(
            $claimed,
            'worker-a',
            MachineCommand::STATUS_DONE,
            MachineCommand::OUTCOME_OK,
            ['loaded' => true],
        );

        $again = $this->service->complete(
            $done,
            'worker-a',
            MachineCommand::STATUS_DONE,
            MachineCommand::OUTCOME_OK,
            ['loaded' => true],
        );

        $this->assertSame($done->id, $again->id);
        $this->assertSame(MachineCommand::STATUS_DONE, $again->status);
        $this->assertSame(['loaded' => true], $again->result);
        $this->assertSame(
            1,
            AuditLog::where('action', 'machine_command.completed')->where('entity_id', $done->id)->count(),
        );
    }

    public function test_claim_reclaims_expired_leases_even_when_every_type_is_disabled(): void
    {
        $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);
        $claimed->forceFill(['locked_until' => now()->subMinute()])->save();

        config()->set('machine_commands.types', [
            MachineCommand::TYPE_LOAD_NC => false,
            MachineCommand::TYPE_MANUAL_M30 => false,
            MachineCommand::TYPE_GENERATE_LABELS => false,
        ]);

        $this->assertNull($this->service->claim(['NEST-01'], 'worker-b', 60));

        $claimed->refresh();
        $this->assertSame(MachineCommand::STATUS_PENDING, $claimed->status);
        $this->assertNull($claimed->lock_token);
    }

    public function test_replaying_a_done_command_with_a_different_outcome_is_a_conflict(): void
    {
        $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);
        $done = $this->service->complete(
            $claimed,
            'worker-a',
            MachineCommand::STATUS_DONE,
            MachineCommand::OUTCOME_OK,
        );

        $this->assertMachineCommandError(
            'invalid_transition',
            fn () => $this->service->complete(
                $done,
                'worker-a',
                MachineCommand::STATUS_DONE,
                MachineCommand::OUTCOME_ALREADY_LOADED,
            ),
            409,
        );
    }

    public function test_complete_after_the_lease_window_loses_the_lease(): void
    {
        $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);
        $claimed->forceFill(['locked_until' => now()->subMinute()])->save();

        $this->assertMachineCommandError(
            'lease_lost',
            fn () => $this->service->complete($claimed->fresh(), 'worker-a', MachineCommand::STATUS_DONE, MachineCommand::OUTCOME_OK),
            409,
        );
    }

    public function test_requeue_with_a_non_transient_class_is_refused(): void
    {
        $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);

        $this->assertMachineCommandError(
            'requeue_not_allowed',
            fn () => $this->service->complete(
                $claimed,
                'worker-a',
                MachineCommand::STATUS_PENDING,
                error: 'Broken NC file',
                errorClass: MachineCommand::ERROR_PERMANENT,
            ),
            409,
        );
    }

    public function test_requeue_respects_the_max_attempts_budget(): void
    {
        $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);
        $claimed->forceFill(['attempt_count' => (int) config('machine_commands.max_attempts')])->save();

        $failed = $this->service->complete(
            $claimed->fresh(),
            'worker-a',
            MachineCommand::STATUS_PENDING,
            error: 'Machine busy',
            errorClass: MachineCommand::ERROR_TRANSIENT,
        );

        $this->assertSame(MachineCommand::STATUS_FAILED, $failed->status);
        $this->assertSame(MachineCommand::ERROR_TRANSIENT, $failed->error_class);
        $this->assertNotNull($failed->finished_at);
        $this->assertNull($failed->next_attempt_at);
    }

    public function test_requeue_ignores_the_result_and_done_clears_the_error_fields(): void
    {
        $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);

        $requeued = $this->service->complete(
            $claimed,
            'worker-a',
            MachineCommand::STATUS_PENDING,
            result: ['partial' => true],
            error: 'Machine busy',
            errorClass: MachineCommand::ERROR_TRANSIENT,
        );

        $this->assertNull($requeued->result);
        $this->assertSame('Machine busy', $requeued->last_error);

        $this->travel(31)->seconds();

        $again = $this->service->claim(['NEST-01'], 'worker-b', 60);
        $this->assertNotNull($again);
        $this->assertNull($again->next_attempt_at);

        $done = $this->service->complete($again, 'worker-b', MachineCommand::STATUS_DONE, MachineCommand::OUTCOME_OK);

        $this->assertNull($done->last_error);
        $this->assertNull($done->error_class);
        $this->assertNull($done->next_attempt_at);
    }

    public function test_cancel_requires_a_reason_of_at_least_five_characters(): void
    {
        $command = $this->makeCommand();

        $this->assertMachineCommandError(
            'reason_required',
            fn () => $this->service->cancel($command, $this->supervisor, 'abc'),
            422,
        );
    }

    public function test_manual_m30_audit_includes_the_force_flag(): void
    {
        $command = $this->makeCommand(
            MachineCommand::TYPE_MANUAL_M30,
            payload: ['force' => true],
            reason: 'Świadome domknięcie cyklu',
        );

        $audit = AuditLog::where('action', 'machine_command.requested')
            ->where('entity_id', $command->id)
            ->firstOrFail();

        $this->assertTrue($audit->after_state['force']);
    }

    public function test_claim_audit_never_exposes_the_lock_token(): void
    {
        $command = $this->makeCommand();

        $this->service->claim(['NEST-01'], 'worker-a', 60, null, $this->supervisor->id);

        $audit = AuditLog::where('action', 'machine_command.claimed')
            ->where('entity_id', $command->id)
            ->firstOrFail();

        $this->assertSame($this->supervisor->id, $audit->user_id);
        $this->assertSame(1, $audit->after_state['attempt_count']);
        $this->assertArrayNotHasKey('lock_token', $audit->after_state);
    }

    public function test_find_for_step_returns_history_newest_first_and_can_filter_by_type(): void
    {
        $this->makeCommand();
        $labels = $this->makeCommand(MachineCommand::TYPE_GENERATE_LABELS);

        $all = $this->service->findForStep($this->step);
        $this->assertCount(2, $all);
        $this->assertSame($labels->id, $all->first()->id);

        $onlyLabels = $this->service->findForStep($this->step, MachineCommand::TYPE_GENERATE_LABELS);
        $this->assertCount(1, $onlyLabels);
        $this->assertSame($labels->id, $onlyLabels->first()->id);
    }
}
