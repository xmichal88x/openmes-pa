<?php

namespace Tests\Feature\Traceability;

use App\Models\Batch;
use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\ScrapReason;
use App\Models\SerialUnit;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Traceability\SerialTraceService;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\UnitProducedQtySync;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RQ-PA-272: re-deriving the order's good units after a unit changed status is a
 * read-modify-write over the whole order, so it must serialise on the order row.
 *
 * The defect it closes: two scrap/re-cut events on units of different batches of
 * one order read the same rollup and wrote it back twice, so the second write
 * dropped the first (lost update). `UnitProducedQtySync::afterStatusChange()` now
 * reads the order under `lockForUpdate()` before it counts anything.
 *
 * The test database is SQLite (`phpunit.xml`: `DB_CONNECTION=sqlite`), where
 * `lockForUpdate()` compiles to nothing, so a real concurrency test is not
 * possible here and is not pretended. Covered instead: the order row is read
 * before the first batch row of the rollup (the read that serialises is the one
 * that precedes every input), the read happens inside a transaction of its own
 * (a lock outside one locks nothing), the caller's copy of the order has no say
 * in the outcome, and a unit without an order is left alone.
 */
class UnitProducedQtyLockTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private ScrapReason $reason;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Admin', 'Operator', 'Supervisor', 'PackingWorker'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');

        $this->reason = ScrapReason::factory()->create([
            'code' => 'NC-02',
            'name' => 'Broken beyond repair',
            'is_active' => true,
        ]);
    }

    /** An order whose only routing step is packing (the batch closes on it). */
    private function makeWorkOrder(float $plannedQty, array $overrides = []): WorkOrder
    {
        $product = ProductType::factory()->create();
        $template = ProcessTemplate::factory()->create(['product_type_id' => $product->id]);
        TemplateStep::create([
            'process_template_id' => $template->id, 'step_number' => 1, 'name' => 'Pack',
            'kind' => TemplateStep::KIND_PACKING, 'config' => ['unit' => 'carton', 'carton_capacity' => 5],
        ]);

        return WorkOrder::factory()->create($overrides + [
            'product_type_id' => $product->id,
            'process_snapshot' => $template->fresh()->toSnapshot(),
            'planned_qty' => $plannedQty,
            'status' => WorkOrder::STATUS_IN_PROGRESS,
        ]);
    }

    /** A packed unit: only a packed unit is a good unit (P2.12), so it counts. */
    private function unit(WorkOrder $workOrder, ?Batch $batch, string $no): SerialUnit
    {
        return SerialUnit::create([
            'serial_no' => $no,
            'psn' => $no,
            'work_order_id' => $workOrder->id,
            'batch_id' => $batch?->id,
            'status' => SerialUnit::STATUS_COMPLETED,
            'packed_at' => now(),
        ]);
    }

    private function pack(Batch $batch): void
    {
        $step = $batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($step, $this->operator);
        app(BatchService::class)->completeStep($step->fresh(), $this->operator);
    }

    /**
     * The lock itself cannot be observed on SQLite, but its precondition can: the
     * order row is read (and therefore locked on PostgreSQL) before the rollup
     * reads its first batch, so every recount of any batch of this order queues on
     * it. Before RQ-PA-272 the order was only read after the batch recount, i.e.
     * after the number the recount depends on.
     */
    public function test_the_order_is_read_before_the_first_batch_of_the_rollup(): void
    {
        $wo = $this->makeWorkOrder(1);
        $batch = app(WorkOrderService::class)->createBatch($wo, 1);
        $unit = $this->unit($wo, $batch, 'SN-1');
        $this->pack($batch);

        $statements = [];
        $collecting = true;
        DB::listen(function ($query) use (&$statements, &$collecting): void {
            if ($collecting) {
                $statements[] = strtolower($query->sql);
            }
        });

        try {
            app(SerialTraceService::class)->blockUnit($unit->fresh(), $this->operator, $this->reason);
        } finally {
            $collecting = false;
        }

        $firstUnitRead = $this->firstIndex($statements, 'serial_units', 'select');
        $firstOrderRead = $this->firstIndex($statements, 'work_orders', 'select');
        $firstBatchRead = $this->firstIndex($statements, 'batches', 'select');
        $firstBatchWrite = $this->firstIndex($statements, 'batches', 'update');

        $this->assertNotNull($firstUnitRead, 'the unit row is locked first (serial_units -> work_orders)');
        $this->assertNotNull($firstOrderRead, 'the recount must read the order row it serialises on');
        $this->assertNotNull($firstBatchRead);
        $this->assertLessThan($firstOrderRead, $firstUnitRead);
        $this->assertLessThan($firstBatchRead, $firstOrderRead);
        $this->assertLessThan($firstBatchWrite, $firstOrderRead, 'no batch may be rewritten before the order lock is taken');
    }

    /**
     * A lock taken outside a transaction locks nothing at all: the row is released
     * as soon as the statement autocommits. Every caller in `SerialTraceService`
     * already opens a transaction, so the wrapper inside `afterStatusChange()` is a
     * savepoint there - and this is the guarantee a future caller gets.
     */
    public function test_the_order_is_read_inside_a_transaction_of_its_own(): void
    {
        $wo = $this->makeWorkOrder(1);
        $batch = app(WorkOrderService::class)->createBatch($wo, 1);
        $unit = $this->unit($wo, $batch, 'SN-1');
        $this->pack($batch);

        $levels = [];
        DB::listen(function ($query) use (&$levels): void {
            $sql = strtolower($query->sql);
            if (str_starts_with($sql, 'select') && str_contains($sql, 'from "work_orders"')) {
                $levels[] = DB::transactionLevel();
            }
        });

        app(UnitProducedQtySync::class)->afterStatusChange($unit->fresh(), 'scrapped', $this->operator);

        $this->assertNotEmpty($levels, 'the recount must read the order row it serialises on');
        $this->assertGreaterThan(DB::transactionLevel(), min($levels), 'the lock has to live inside a transaction');
    }

    /**
     * The order the caller passes in may be a copy read before somebody else
     * changed it - the very shape of the lost update. It therefore decides
     * neither the counting policy nor the figure: the locked row does.
     */
    public function test_the_locked_row_decides_not_the_callers_copy(): void
    {
        $wo = $this->makeWorkOrder(1, ['counting_source' => WorkOrder::COUNTING_MACHINE, 'produced_qty' => 1]);
        $batch = app(WorkOrderService::class)->createBatch($wo, 1);
        $unit = $this->unit($wo, $batch, 'SN-1');
        $this->pack($batch);

        // A copy taken while the order was machine counted, as a request that has
        // been running for a while would hold it.
        $stale = SerialUnit::with(['workOrder', 'batch'])->findOrFail($unit->id);
        $this->assertSame(WorkOrder::COUNTING_MACHINE, $stale->workOrder->counting_source);

        // The operator takes the counter off the order and the piece is scrapped.
        $wo->update(['counting_source' => WorkOrder::COUNTING_OPERATOR]);
        $unit->update(['status' => SerialUnit::STATUS_SCRAPPED]);

        $warnings = app(UnitProducedQtySync::class)->afterStatusChange($stale, 'scrapped', $this->operator);

        $this->assertSame([], $warnings, 'the counter policy of the stale copy must not be applied');
        $this->assertSame(0.0, (float) $batch->fresh()->produced_qty);
        $this->assertSame(0.0, (float) $wo->fresh()->produced_qty);
    }

    /** A unit that belongs to no order carries no order quantity - and no lock. */
    public function test_a_unit_without_an_order_changes_nothing(): void
    {
        $unit = SerialUnit::create([
            'serial_no' => 'SN-FREE',
            'psn' => 'SN-FREE',
            'work_order_id' => null,
            'batch_id' => null,
            'status' => SerialUnit::STATUS_IN_PRODUCTION,
        ]);

        $ordersBefore = DB::table('work_orders')->orderBy('id')->get()->toArray();

        $warnings = app(UnitProducedQtySync::class)->afterStatusChange($unit, 'blocked', $this->operator);

        $this->assertSame([], $warnings);
        $this->assertSame($ordersBefore, DB::table('work_orders')->orderBy('id')->get()->toArray());
    }

    /** @param  array<int, string>  $statements */
    private function firstIndex(array $statements, string $table, string $verb): ?int
    {
        foreach ($statements as $index => $sql) {
            if (str_starts_with($sql, $verb) && str_contains($sql, '"'.$table.'"')) {
                return $index;
            }
        }

        return null;
    }
}
