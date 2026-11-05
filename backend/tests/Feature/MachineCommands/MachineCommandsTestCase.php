<?php

namespace Tests\Feature\MachineCommands;

use App\Exceptions\MachineCommandException;
use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\Line;
use App\Models\MachineCommand;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Models\WorkstationType;
use App\Services\Machine\MachineCommandService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared fixtures for the machine_commands outbox (P1a).
 *
 * The default world is a CNC work order on a single workstation, with one
 * operator bound to that line and one operator on a foreign line.
 */
abstract class MachineCommandsTestCase extends TestCase
{
    use RefreshDatabase;

    protected MachineCommandService $service;

    protected Line $cncLine;

    protected WorkstationType $cncType;

    protected Workstation $nest;

    protected WorkOrder $workOrder;

    protected Batch $batch;

    protected BatchStep $step;

    protected User $operator;

    protected User $foreignOperator;

    protected User $supervisor;

    protected User $admin;

    protected bool $seedRoles = true;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->seedRoles) {
            $this->seed(RolesAndPermissionsSeeder::class);
        }

        config()->set('machine_commands.enabled', true);
        config()->set('machine_commands.types', [
            MachineCommand::TYPE_LOAD_NC => true,
            MachineCommand::TYPE_MANUAL_M30 => true,
            MachineCommand::TYPE_GENERATE_LABELS => true,
        ]);
        config()->set('machine_commands.line_codes', ['CNC']);
        config()->set('machine_commands.step_match', ['cnc', 'ciecie', 'cięcie', 'nest', 'frez']);
        config()->set('machine_commands.workstation_type_codes', []);
        config()->set('machine_commands.lease_seconds', 300);
        config()->set('machine_commands.max_attempts', 5);

        $this->service = app(MachineCommandService::class);

        [$this->cncLine, $this->cncType, $this->nest, $this->workOrder, $this->batch, $this->step]
            = $this->makeMachineStep('CNC', 'nesting_router', 'NEST-01', 'Cięcie CNC');

        $this->operator = $this->makeUser('Operator', [$this->cncLine]);
        $this->foreignOperator = $this->makeUser('Operator', [Line::factory()->create(['code' => 'LINIA-B'])]);
        $this->supervisor = $this->makeUser('Supervisor');
        $this->admin = $this->makeUser('Admin');
    }

    /**
     * @return array{0: Line, 1: WorkstationType, 2: Workstation, 3: WorkOrder, 4: Batch, 5: BatchStep}
     */
    protected function makeMachineStep(
        string $lineCode,
        string $typeCode,
        string $workstationCode,
        string $stepName,
        string $status = BatchStep::STATUS_IN_PROGRESS,
        ?string $kind = null,
    ): array {
        $line = Line::factory()->create(['code' => $lineCode]);
        $type = WorkstationType::factory()->create(['code' => $typeCode]);
        $workstation = Workstation::factory()->create([
            'line_id' => $line->id,
            'workstation_type_id' => $type->id,
            'code' => $workstationCode,
        ]);
        $workOrder = WorkOrder::factory()->create([
            'line_id' => $line->id,
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
            'name' => $stepName,
            'kind' => $kind ?? ($typeCode === 'packing' ? 'packing' : 'production'),
            'workstation_id' => $workstation->id,
            'workstation_type_id' => $type->id,
            'status' => $status,
        ]);

        return [$line, $type, $workstation, $workOrder, $batch, $step];
    }

    /**
     * Add another machine step on an existing line (unique line/workstation
     * codes are left to the caller).
     *
     * @return array{0: WorkstationType, 1: Workstation, 2: WorkOrder, 3: Batch, 4: BatchStep}
     */
    protected function makeStepOn(
        Line $line,
        string $workstationCode,
        string $stepName = 'Cięcie CNC',
        string $status = BatchStep::STATUS_IN_PROGRESS,
        ?string $typeCode = null,
        ?string $kind = null,
    ): array {
        $type = WorkstationType::factory()->create(
            $typeCode !== null ? ['code' => $typeCode] : [],
        );
        $workstation = Workstation::factory()->create([
            'line_id' => $line->id,
            'workstation_type_id' => $type->id,
            'code' => $workstationCode,
        ]);
        $workOrder = WorkOrder::factory()->create([
            'line_id' => $line->id,
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
            'name' => $stepName,
            'kind' => $kind ?? ($typeCode === 'packing' ? 'packing' : 'production'),
            'workstation_id' => $workstation->id,
            'workstation_type_id' => $type->id,
            'status' => $status,
        ]);

        return [$type, $workstation, $workOrder, $batch, $step];
    }

    /**
     * @param  array<int, Line>  $lines
     */
    protected function makeUser(string $role, array $lines = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        if ($lines !== []) {
            $user->lines()->sync(array_map(fn (Line $line) => $line->id, $lines));
        }

        return $user;
    }

    protected function makeCommand(
        string $type = MachineCommand::TYPE_LOAD_NC,
        ?User $actor = null,
        ?array $payload = null,
        ?string $reason = null,
        ?string $key = null,
        ?BatchStep $step = null,
        ?WorkOrder $workOrder = null,
    ): MachineCommand {
        // manual_m30 is restricted to Admin/Supervisor at the service level, so
        // the shared helper mirrors that instead of forcing every test to pass
        // an actor explicitly.
        $actor ??= $type === MachineCommand::TYPE_MANUAL_M30 ? $this->supervisor : $this->operator;

        return $this->service->create(
            $workOrder ?? $this->workOrder,
            $step ?? $this->step,
            $type,
            $actor,
            $key ?? (string) Str::uuid(),
            $payload,
            $reason,
        );
    }

    protected function assertMachineCommandError(string $reason, callable $callback, ?int $httpStatus = null): MachineCommandException
    {
        try {
            $callback();
        } catch (MachineCommandException $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());

            if ($httpStatus !== null) {
                $this->assertSame($httpStatus, $e->httpStatus, $e->getMessage());
            }

            return $e;
        }

        $this->fail('Expected a MachineCommandException with reason "'.$reason.'".');
    }
}
