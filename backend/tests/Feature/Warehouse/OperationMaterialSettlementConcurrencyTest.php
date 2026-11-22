<?php

namespace Tests\Feature\Warehouse;

use App\Models\Batch;
use App\Models\BomItem;
use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\StockMovement;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Material\MaterialAllocationService;
use App\Services\Material\MaterialSettlementPolicy;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RQ-PA-265 A4 — two settlements of one allocation, at the same moment, on a
 * database that actually serialises them.
 *
 * SQLite cannot prove this: it has no `FOR UPDATE`, so a second process locks
 * nothing and the interleave would pass for the wrong reason. The class runs
 * only against PostgreSQL (`phpunit.backflush.pgsql.xml`) and skips elsewhere.
 *
 * The interleave is a real one: `pcntl_fork()` starts a second PHP process, both
 * are released by a file barrier, and each settles the same batch through its
 * own connection. The fixtures therefore have to be committed, which is why the
 * class uses DatabaseMigrations instead of RefreshDatabase — a surrounding
 * transaction would hide every row from the second process.
 */
class OperationMaterialSettlementConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    /** How long the two processes wait for each other before calling it a failure. */
    private const BARRIER_TIMEOUT_SECONDS = 5;

    /** How long the parent waits for the child before killing it. */
    private const CHILD_TIMEOUT_SECONDS = 30;

    private Material $material;

    private ProductType $product;

    private ProcessTemplate $template;

    private User $operator;

    private User $admin;

    private WorkOrder $workOrder;

    private Batch $batch;

    private MaterialAllocation $allocation;

    /**
     * The driver gate and the schema rebuild in one place, because the gate has
     * to run before anything is migrated: on SQLite there is no `FOR UPDATE` to
     * prove, so the class skips instead of pretending to test a lock.
     *
     * The rebuild is a `migrate:fresh` on its own rather than the trait's
     * fresh-then-rollback:
     *
     * The trait's rollback is skipped on purpose: this repository's migration
     * history cannot roll back cleanly (`2026_05_22_240200_add_personnel_class_id_to_workers`
     * drops a foreign key PostgreSQL names differently), and that failing `down()`
     * would turn a passing test into an error. The next class that needs the
     * schema either rebuilds it (DatabaseMigrations) or wraps its own work in a
     * transaction (RefreshDatabase), so leaving the tables in place is harmless.
     */
    public function runDatabaseMigrations(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SQLite does not implement FOR UPDATE: the settlement interleave is only proven on PostgreSQL.');
        }

        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->setArtisan(null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        Role::findOrCreate('Operator', 'web');

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->material = Material::factory()->create([
            'code' => 'FORMATKA-18',
            'name' => 'Formatka 18 mm',
            'unit_of_measure' => 'szt',
            'tracking_type' => 'none',
            'external_system' => null,
            'stock_quantity' => 100,
        ]);

        $this->product = ProductType::factory()->create([
            'code' => 'PROD-RQ265-CONC',
            'unit_of_measure' => 'szt',
        ]);

        $this->template = ProcessTemplate::factory()->create([
            'product_type_id' => $this->product->id,
            'name' => 'RQ265 concurrency routing',
            'is_active' => true,
        ]);

        $cutStep = TemplateStep::create([
            'process_template_id' => $this->template->id,
            'step_number' => 1,
            'name' => 'Cutting (CUT)',
            'kind' => TemplateStep::KIND_PRODUCTION,
        ]);

        BomItem::create([
            'process_template_id' => $this->template->id,
            'template_step_id' => $cutStep->id,
            'material_id' => $this->material->id,
            'quantity_per_unit' => 1,
            'scrap_percentage' => 0,
            'consumed_at' => 'start',
        ]);

        $this->workOrder = WorkOrder::factory()->create([
            'order_no' => 'WO-RQ265-CONC',
            'product_type_id' => $this->product->id,
            'process_snapshot' => $this->template->fresh()->load(['steps', 'bomItems'])->toSnapshot(),
            'planned_qty' => 10,
            'produced_qty' => 0,
            'status' => WorkOrder::STATUS_PENDING,
            'extra_data' => [
                MaterialSettlementPolicy::EXTRA_DATA_KEY => [
                    'mode' => MaterialSettlementPolicy::MODE_OPERATION,
                    'materials' => [$this->material->id],
                ],
            ],
        ]);

        $this->batch = app(WorkOrderService::class)->createBatch($this->workOrder, 10);
        $step = $this->batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($step, $this->operator);

        $this->allocation = MaterialAllocation::query()
            ->where('batch_id', $this->batch->id)
            ->where('material_id', $this->material->id)
            ->firstOrFail();

        // The operation declared 8 good + 2 scrapped out of the 10 it withdrew.
        app(MaterialAllocationService::class)->recordConsumption(
            $this->allocation,
            8.0,
            2.0,
            null,
            $this->operator,
        );

        // Pre-state, so a failure is read as "the interleave broke this" rather
        // than "the fixture never worked".
        $this->assertEqualsWithDelta(90.0, (float) $this->material->fresh()->stock_quantity, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $this->material->fresh()->reserved_quantity, 0.0001);
        $this->assertSame(1, StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_ALLOCATION)
            ->count());
    }

    // ── 1. the settlement event, twice, at once ──────────────────────────────

    public function test_two_settlement_events_on_the_same_allocation_book_one_withdrawal(): void
    {
        $workspace = $this->openWorkspace();
        $batchId = (int) $this->batch->id;

        $settle = function () use ($batchId): array {
            $startedAt = microtime(true);
            $result = app(MaterialAllocationService::class)->consumeForBatch(Batch::findOrFail($batchId));

            return $result + ['started_at' => $startedAt, 'finished_at' => microtime(true)];
        };

        $race = $this->race($workspace, $settle, $settle);

        $this->assertSame(0, $race['exit'], 'The second process failed: '.json_encode($race['child']));

        // The two settlements really were in flight together, not one after the
        // other: their windows in time overlap. Without a row lock both would
        // have read the row as `allocated` and both would have posted.
        $this->assertLessThan(
            $race['child']['finished_at'],
            $race['parent']['started_at'],
            'The two settlement windows do not overlap, so nothing was interleaved.',
        );
        $this->assertLessThan(
            $race['parent']['finished_at'],
            $race['child']['started_at'],
            'The two settlement windows do not overlap, so nothing was interleaved.',
        );

        // Whichever process got there first posted the settlement; the other found
        // the row already closed and posted nothing. Never both, never neither.
        $settled = array_map(fn (array $result) => (int) $result['settled'], [$race['parent'], $race['child']]);
        sort($settled);
        $this->assertSame([0, 1], $settled, 'Exactly one of the two settlement events posts.');
        $this->assertSame(0, count($race['parent']['pending']) + count($race['child']['pending']));

        $allocation = $this->allocation->fresh();
        $this->assertSame(MaterialAllocation::STATUS_CONSUMED, $allocation->status);
        $this->assertEqualsWithDelta(8.0, (float) $allocation->consumed_qty, 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $allocation->scrap_qty, 0.0001);

        // One withdrawal, one scrap-status row, and no second consumption of the
        // material: settling twice does not double-book the store.
        $withdrawals = StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_ALLOCATION)
            ->get();
        $this->assertCount(1, $withdrawals);
        $this->assertEqualsWithDelta(-10.0, (float) $withdrawals->sole()->quantity, 0.0001);

        $scrapRows = StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_SCRAP)
            ->get();
        $this->assertCount(1, $scrapRows);
        $this->assertEqualsWithDelta(0.0, (float) $scrapRows->sole()->quantity, 0.0001);

        $this->assertSame(0, StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_CONSUME)
            ->count());

        $this->assertEqualsWithDelta(90.0, (float) $this->material->fresh()->stock_quantity, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $this->material->fresh()->reserved_quantity, 0.0001);
    }

    // ── 2. the same interleave through the settlement endpoint ───────────────

    public function test_the_settle_endpoint_is_idempotent_across_two_sessions(): void
    {
        $token = $this->admin->createToken('rq265-concurrency')->plainTextToken;
        $workspace = $this->openWorkspace();
        $allocationId = (int) $this->allocation->id;

        $settle = fn (): array => $this->settleOverHttp($token, $allocationId);

        $race = $this->race($workspace, $settle, $settle);

        $this->assertSame(0, $race['exit'], 'The second session failed: '.json_encode($race['child']));

        // Both requests are answered, but only one of them finds anything to post.
        $this->assertSame(200, $race['parent']['status'], 'The parent request was not answered.');
        $this->assertSame(200, $race['child']['status'], 'The second session was not answered: '.json_encode($race['child']));

        $settled = [(int) $race['parent']['settled'], (int) $race['child']['settled']];
        sort($settled);
        $this->assertSame([0, 1], $settled, 'The endpoint posts the settlement once across two sessions.');

        $this->assertSame(1, StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_ALLOCATION)
            ->count());
        $this->assertSame(0, StockMovement::query()
            ->where('material_id', $this->material->id)
            ->where('movement_type', StockMovement::TYPE_CONSUME)
            ->count());
        $this->assertEqualsWithDelta(90.0, (float) $this->material->fresh()->stock_quantity, 0.0001);
        $this->assertSame(MaterialAllocation::STATUS_CONSUMED, $this->allocation->fresh()->status);
    }

    // ── the two-process harness ──────────────────────────────────────────────

    /**
     * Release a parent and a child process through a file barrier, run one
     * settlement in each, and hand both results back.
     *
     * @param  callable(): array<string, mixed>  $parentWork
     * @param  callable(): array<string, mixed>  $childWork
     * @return array{parent: array<string, mixed>, child: array<string, mixed>, exit: int}
     */
    private function race(string $workspace, callable $parentWork, callable $childWork): array
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Could not fork the second settlement process.');
        }

        if ($pid === 0) {
            exit($this->runChild($workspace, $childWork));
        }

        // The parent announces itself and waits for the child, so both start from
        // the same committed fixture at (as near as files allow) the same moment.
        file_put_contents($workspace.'/p.ready', (string) getmypid());
        if (! $this->awaitBarrier($workspace)) {
            posix_kill($pid, SIGKILL);
            $this->fail('The barrier timed out: the two processes never overlapped.');
        }

        $parentResult = $parentWork();

        $status = 0;
        $deadline = microtime(true) + self::CHILD_TIMEOUT_SECONDS;

        while (pcntl_waitpid($pid, $status, WNOHANG) !== $pid) {
            if (microtime(true) > $deadline) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
                $this->fail('The second settlement process did not finish in time.');
            }
            usleep(10000);
        }

        $child = json_decode((string) @file_get_contents($workspace.'/child.json'), true);
        $this->assertIsArray($child, 'The second process left no readable result.');

        return [
            'parent' => $parentResult,
            'child' => $child,
            'exit' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1,
        ];
    }

    /**
     * The child's whole life: take a connection of its own, wait for the parent,
     * settle, report through a file, exit with a code the parent can read.
     *
     * @param  callable(): array<string, mixed>  $work
     */
    private function runChild(string $workspace, callable $work): int
    {
        try {
            // A forked process inherits the parent's PDO socket. Two processes on
            // one socket corrupt each other's protocol, so the child opens its own.
            DB::purge();
            DB::reconnect();

            file_put_contents($workspace.'/c.ready', (string) getmypid());
            if (! $this->awaitBarrier($workspace)) {
                throw new \RuntimeException('Barrier timed out before the settlement started.');
            }

            file_put_contents($workspace.'/child.json', json_encode($work()));

            return 0;
        } catch (\Throwable $e) {
            file_put_contents($workspace.'/child.json', json_encode([
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]));

            return 1;
        }
    }

    /** Both ready files present, or the barrier has timed out. */
    private function awaitBarrier(string $workspace): bool
    {
        $deadline = microtime(true) + self::BARRIER_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            if (is_file($workspace.'/p.ready') && is_file($workspace.'/c.ready')) {
                return true;
            }
            usleep(10000);
        }

        return false;
    }

    /** A clean scratch directory for this test's barrier and results. */
    private function openWorkspace(): string
    {
        $workspace = sys_get_temp_dir().'/rq265-conc-'.getmypid();

        if (! is_dir($workspace)) {
            mkdir($workspace, 0700, true);
        }

        foreach (glob($workspace.'/*') ?: [] as $file) {
            unlink($file);
        }

        return $workspace;
    }

    /**
     * One settlement request as a session of its own.
     *
     * @return array{status: int, settled: ?int, code: ?string}
     */
    private function settleOverHttp(string $token, int $allocationId): array
    {
        $response = $this->withToken($token)
            ->postJson("/api/v1/material-allocations/{$allocationId}/settle");

        return [
            'status' => $response->status(),
            'settled' => $response->json('summary.settled'),
            'code' => $response->json('code'),
        ];
    }
}
