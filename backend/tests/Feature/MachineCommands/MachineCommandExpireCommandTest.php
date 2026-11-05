<?php

namespace Tests\Feature\MachineCommands;

use App\Models\MachineCommand;

class MachineCommandExpireCommandTest extends MachineCommandsTestCase
{
    public function test_expired_load_nc_lease_is_requeued(): void
    {
        $command = $this->makeCommand();
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);
        $claimed->forceFill(['locked_until' => now()->subMinute()])->save();

        $this->artisan('machine-commands:expire')
            ->expectsOutputToContain('Reclaimed 1')
            ->assertExitCode(0);

        $command->refresh();
        $this->assertSame(MachineCommand::STATUS_PENDING, $command->status);
        $this->assertNull($command->lock_token);
        $this->assertNotNull($command->next_attempt_at);
    }

    public function test_expired_manual_m30_lease_is_parked_as_post_effect(): void
    {
        $command = $this->makeCommand(MachineCommand::TYPE_MANUAL_M30, reason: 'Zmiana narzędzia');
        $claimed = $this->service->claim(['NEST-01'], 'worker-a', 60);
        $claimed->forceFill(['locked_until' => now()->subMinute()])->save();

        $this->artisan('machine-commands:expire')->assertExitCode(0);

        $command->refresh();
        $this->assertSame(MachineCommand::STATUS_FAILED, $command->status);
        $this->assertSame(MachineCommand::ERROR_POST_EFFECT, $command->error_class);
        $this->assertNotNull($command->finished_at);
    }

    public function test_fresh_commands_are_left_untouched(): void
    {
        $first = $this->makeCommand();
        $second = $this->makeCommand(MachineCommand::TYPE_GENERATE_LABELS);
        $claimed = $this->service->claim([$this->nest->code], 'worker-a', 60);

        $this->assertSame($first->id, $claimed?->id);

        $this->artisan('machine-commands:expire')
            ->expectsOutputToContain('Reclaimed 0')
            ->assertExitCode(0);

        $this->assertSame(MachineCommand::STATUS_PROCESSING, $first->fresh()->status);
        $this->assertSame(MachineCommand::STATUS_PENDING, $second->fresh()->status);
    }
}
