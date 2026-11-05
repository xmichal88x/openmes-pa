<?php

namespace Tests\Feature\MachineCommands;

use App\Models\MachineCommand;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class MachineCommandModelTest extends MachineCommandsTestCase
{
    public function test_status_type_and_outcome_constants_are_consistent(): void
    {
        $this->assertSame(['load_nc', 'manual_m30', 'generate_labels'], MachineCommand::TYPES);
        $this->assertSame(['pending', 'processing'], MachineCommand::ACTIVE_STATUSES);
        $this->assertSame(['done', 'failed', 'cancelled'], MachineCommand::TERMINAL_STATUSES);
        $this->assertSame(
            ['pending', 'processing', 'done', 'failed', 'cancelled'],
            MachineCommand::STATUSES,
        );
        $this->assertContains('already_loaded', MachineCommand::OUTCOMES);
        $this->assertContains('post_effect', MachineCommand::ERROR_CLASSES);
    }

    public function test_only_intent_columns_are_mass_assignable(): void
    {
        $fillable = (new MachineCommand)->getFillable();

        foreach (['tenant_id', 'work_order_id', 'batch_step_id', 'type', 'idempotency_key', 'payload'] as $column) {
            $this->assertContains($column, $fillable);
        }

        foreach ([
            'status', 'outcome', 'result', 'lock_token', 'locked_until', 'attempt_count',
            'delivery_id', 'cancelled_at', 'cancelled_by_id', 'cancel_reason',
        ] as $column) {
            $this->assertNotContains($column, $fillable);
        }
    }

    public function test_casts_and_relations(): void
    {
        $command = $this->makeCommand(payload: ['nc_path' => '/nc/a.anc'], reason: null);

        $this->assertIsArray($command->payload);
        $this->assertSame('/nc/a.anc', $command->payload['nc_path']);
        $this->assertSame(0, $command->attempt_count);
        $this->assertNull($command->locked_until);

        $this->assertSame($this->workOrder->id, $command->workOrder->id);
        $this->assertSame($this->step->id, $command->batchStep->id);
        $this->assertSame($this->cncLine->id, $command->line->id);
        $this->assertSame($this->nest->id, $command->workstation->id);
        $this->assertSame($this->operator->id, $command->requestedBy->id);

        $this->assertTrue($command->isActive());
        $this->assertTrue($command->isPending());
        $this->assertFalse($command->isTerminal());

        $command->forceFill(['status' => MachineCommand::STATUS_DONE])->save();
        $command->refresh();

        $this->assertTrue($command->isTerminal());
        $this->assertFalse($command->isActive());
    }

    public function test_scopes_active_pending_and_for_workstation(): void
    {
        $pending = $this->makeCommand();
        $done = $this->makeCommand(key: (string) Str::uuid(), type: MachineCommand::TYPE_GENERATE_LABELS);
        $done->forceFill(['status' => MachineCommand::STATUS_DONE])->save();

        $this->assertSame([$pending->id], MachineCommand::query()->active()->pluck('id')->all());
        $this->assertSame([$pending->id], MachineCommand::query()->pending()->pluck('id')->all());
        $this->assertSame(
            [$pending->id, $done->id],
            MachineCommand::query()->forWorkstation('NEST-01')->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame([], MachineCommand::query()->forWorkstation('OTHER')->pluck('id')->all());
    }

    public function test_sqlite_partial_unique_blocks_two_active_commands_for_the_same_step_and_type(): void
    {
        $this->makeCommand();

        $this->expectException(QueryException::class);

        MachineCommand::create([
            'work_order_id' => $this->workOrder->id,
            'batch_step_id' => $this->step->id,
            'line_id' => $this->cncLine->id,
            'workstation_id' => $this->nest->id,
            'workstation_code' => $this->nest->code,
            'type' => MachineCommand::TYPE_LOAD_NC,
            'status' => MachineCommand::STATUS_PENDING,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function test_sqlite_partial_unique_ignores_terminal_commands(): void
    {
        $first = $this->makeCommand();
        $first->forceFill(['status' => MachineCommand::STATUS_DONE])->save();

        $second = $this->makeCommand();

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, MachineCommand::query()->where('batch_step_id', $this->step->id)->count());
    }
}
