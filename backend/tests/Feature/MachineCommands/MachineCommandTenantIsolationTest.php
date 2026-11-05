<?php

namespace Tests\Feature\MachineCommands;

use App\Models\MachineCommand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

class MachineCommandTenantIsolationTest extends MachineCommandsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('machine_commands.line_codes', ['CNC-A', 'CNC-B']);
    }

    public function test_an_idempotency_key_cannot_be_replayed_across_tenants(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A']);
        $tenantB = Tenant::create(['name' => 'Tenant B']);

        [$workOrderA, $stepA, $actorA] = $this->makeTenantWorld($tenantA, 'CNC-A', 'NEST-A');
        [$workOrderB, $stepB, $actorB] = $this->makeTenantWorld($tenantB, 'CNC-B', 'NEST-B');

        $key = (string) Str::uuid();

        $this->service->create($workOrderA, $stepA, MachineCommand::TYPE_LOAD_NC, $actorA, $key, ['nc_path' => '/nc/a.anc']);

        $this->assertMachineCommandError(
            'idempotency_conflict',
            fn () => $this->service->create(
                $workOrderB,
                $stepB,
                MachineCommand::TYPE_LOAD_NC,
                $actorB,
                $key,
                ['nc_path' => '/nc/a.anc'],
            ),
            409,
        );

        $this->assertSame(
            1,
            MachineCommand::withoutGlobalScopes()->where('idempotency_key', $key)->count(),
        );
    }

    public function test_a_tenantless_key_still_blocks_a_tenant_actor_without_a_db_error(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        [$workOrder, $step, $actor] = $this->makeTenantWorld($tenant, 'CNC-A', 'NEST-A');

        $key = (string) Str::uuid();

        $this->service->create(
            $workOrder,
            $step,
            MachineCommand::TYPE_LOAD_NC,
            $this->makeUser('Admin'),
            $key,
            ['nc_path' => '/nc/a.anc'],
        );

        $this->assertMachineCommandError(
            'idempotency_conflict',
            fn () => $this->service->create(
                $workOrder,
                $step,
                MachineCommand::TYPE_LOAD_NC,
                $actor,
                $key,
                ['nc_path' => '/nc/a.anc'],
            ),
            409,
        );
    }

    public function test_claim_only_sees_the_requested_tenant_even_with_the_same_workstation_code(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A']);
        $tenantB = Tenant::create(['name' => 'Tenant B']);

        [$workOrderA, $stepA, $actorA] = $this->makeTenantWorld($tenantA, 'CNC-A', 'NEST-A');
        [$workOrderB, $stepB, $actorB] = $this->makeTenantWorld($tenantB, 'CNC-B', 'NEST-B');

        $commandA = $this->service->create($workOrderA, $stepA, MachineCommand::TYPE_LOAD_NC, $actorA, (string) Str::uuid());
        $commandB = $this->service->create($workOrderB, $stepB, MachineCommand::TYPE_LOAD_NC, $actorB, (string) Str::uuid());

        $commandA->forceFill(['workstation_code' => 'SHARED'])->save();
        $commandB->forceFill(['workstation_code' => 'SHARED'])->save();

        $claimedA = $this->service->claim(['SHARED'], 'worker-a', 60, $tenantA->id, $actorA->id);
        $this->assertNotNull($claimedA);
        $this->assertSame($commandA->id, $claimedA->id);

        $this->assertNull($this->service->claim(['SHARED'], 'worker-a', 60, $tenantA->id, $actorA->id));

        $claimedB = $this->service->claim([' SHARED ', 'SHARED', ''], 'worker-b', 60, $tenantB->id, $actorB->id);
        $this->assertNotNull($claimedB);
        $this->assertSame($commandB->id, $claimedB->id);
    }

    public function test_complete_and_cancel_do_not_touch_the_other_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A']);
        $tenantB = Tenant::create(['name' => 'Tenant B']);

        [$workOrderA, $stepA, $actorA] = $this->makeTenantWorld($tenantA, 'CNC-A', 'NEST-A');
        [$workOrderB, $stepB, $actorB] = $this->makeTenantWorld($tenantB, 'CNC-B', 'NEST-B');

        $loadA = $this->service->create($workOrderA, $stepA, MachineCommand::TYPE_LOAD_NC, $actorA, (string) Str::uuid());
        $loadB = $this->service->create($workOrderB, $stepB, MachineCommand::TYPE_LOAD_NC, $actorB, (string) Str::uuid());

        $loadA->forceFill(['workstation_code' => 'SHARED'])->save();
        $loadB->forceFill(['workstation_code' => 'SHARED'])->save();

        $claimedA = $this->service->claim(['SHARED'], 'worker-a', 60, $tenantA->id, $actorA->id);
        $this->service->complete($claimedA, 'worker-a', MachineCommand::STATUS_DONE, MachineCommand::OUTCOME_OK);

        $this->assertSame(MachineCommand::STATUS_PENDING, $loadB->fresh()->status);

        $labelsA = $this->service->create($workOrderA, $stepA, MachineCommand::TYPE_GENERATE_LABELS, $actorA, (string) Str::uuid());
        $labelsB = $this->service->create($workOrderB, $stepB, MachineCommand::TYPE_GENERATE_LABELS, $actorB, (string) Str::uuid());

        $this->service->cancel($labelsA, $actorA, 'Nie jest już potrzebne');

        $this->assertSame(MachineCommand::STATUS_CANCELLED, $labelsA->fresh()->status);
        $this->assertSame(MachineCommand::STATUS_PENDING, $labelsB->fresh()->status);
    }

    public function test_expire_leases_is_scoped_to_the_requested_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A']);
        $tenantB = Tenant::create(['name' => 'Tenant B']);

        [$workOrderA, $stepA, $actorA] = $this->makeTenantWorld($tenantA, 'CNC-A', 'NEST-A');
        [$workOrderB, $stepB, $actorB] = $this->makeTenantWorld($tenantB, 'CNC-B', 'NEST-B');

        $labelsA = $this->service->create($workOrderA, $stepA, MachineCommand::TYPE_GENERATE_LABELS, $actorA, (string) Str::uuid());
        $labelsB = $this->service->create($workOrderB, $stepB, MachineCommand::TYPE_GENERATE_LABELS, $actorB, (string) Str::uuid());

        $labelsA->forceFill(['workstation_code' => 'SHARED-EXP'])->save();
        $labelsB->forceFill(['workstation_code' => 'SHARED-EXP'])->save();

        $claimedA = $this->service->claim(['SHARED-EXP'], 'worker-a', 60, $tenantA->id, $actorA->id);
        $claimedB = $this->service->claim(['SHARED-EXP'], 'worker-b', 60, $tenantB->id, $actorB->id);

        $claimedA->forceFill(['locked_until' => now()->subMinute()])->save();
        $claimedB->forceFill(['locked_until' => now()->subMinute()])->save();

        $this->assertSame(1, $this->service->expireLeases($tenantA->id));

        $this->assertSame(MachineCommand::STATUS_PENDING, $labelsA->fresh()->status);
        $this->assertSame(MachineCommand::STATUS_PROCESSING, $labelsB->fresh()->status);

        $this->assertSame(1, $this->service->expireLeases($tenantB->id));
    }

    /**
     * @return array{0: \App\Models\WorkOrder, 1: \App\Models\BatchStep, 2: User}
     */
    private function makeTenantWorld(Tenant $tenant, string $lineCode, string $workstationCode): array
    {
        [$line, , , $workOrder, , $step] = $this->makeMachineStep(
            $lineCode,
            'nesting_router_'.$lineCode,
            $workstationCode,
            'Cięcie CNC',
        );

        $line->forceFill(['tenant_id' => $tenant->id])->save();
        $workOrder->forceFill(['tenant_id' => $tenant->id])->save();

        $actor = User::factory()->create(['tenant_id' => $tenant->id]);
        $actor->assignRole('Admin');

        return [$workOrder, $step, $actor];
    }
}
