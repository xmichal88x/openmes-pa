<?php

namespace Tests\Unit\Services;

use App\Models\Batch;
use App\Models\ScrapEntry;
use App\Models\ScrapReason;
use App\Models\SerialUnit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Quality\QualityFacts;
use App\Services\Traceability\SerialTraceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P2.7 - one yield/quality truth, chosen from evidence.
 *
 *  - `units`    the record carries packed units, so `produced_qty` is already
 *               good units and scrap must not be subtracted a second time;
 *  - `declared` no packed unit at all (operator/ledger flow), scrap is separate.
 */
class QualityFactsTest extends TestCase
{
    use RefreshDatabase;

    private QualityFacts $facts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facts = app(QualityFacts::class);
    }

    private function packedUnit(WorkOrder $workOrder, ?Batch $batch = null): SerialUnit
    {
        return SerialUnit::factory()->create([
            'work_order_id' => $workOrder->id,
            'batch_id' => $batch?->id,
            'status' => SerialUnit::STATUS_COMPLETED,
            'packed_at' => now(),
        ]);
    }

    private function scrappedUnit(WorkOrder $workOrder, ?Batch $batch = null, bool $wasPacked = false): SerialUnit
    {
        return SerialUnit::factory()->create([
            'work_order_id' => $workOrder->id,
            'batch_id' => $batch?->id,
            'status' => SerialUnit::STATUS_SCRAPPED,
            'packed_at' => $wasPacked ? now() : null,
        ]);
    }

    private function scrapEntry(WorkOrder $workOrder, float $quantity): ScrapEntry
    {
        return ScrapEntry::factory()->create([
            'work_order_id' => $workOrder->id,
            'scrap_reason_id' => ScrapReason::factory()->create()->id,
            'quantity' => $quantity,
        ]);
    }

    public function test_units_basis_reads_packed_units_and_scrapped_units(): void
    {
        $workOrder = WorkOrder::factory()->create(['produced_qty' => 3]);

        $this->packedUnit($workOrder);
        $this->packedUnit($workOrder);
        $this->packedUnit($workOrder);
        $this->scrappedUnit($workOrder);

        $facts = $this->facts->forWorkOrder($workOrder);

        $this->assertSame(QualityFacts::BASIS_UNITS, $facts['basis']);
        $this->assertEqualsWithDelta(3.0, $facts['good_qty'], 0.001);
        $this->assertEqualsWithDelta(1.0, $facts['scrap_qty'], 0.001);
        $this->assertEqualsWithDelta(0.0, $facts['scrap_entries_qty'], 0.001);
        $this->assertEqualsWithDelta(1.0, $facts['scrapped_units_qty'], 0.001);
        $this->assertEqualsWithDelta(4.0, $facts['total_qty'], 0.001);
        $this->assertSame(75.0, $facts['quality_pct']);
    }

    public function test_units_basis_adds_reported_scrap_to_scrapped_units_once(): void
    {
        $workOrder = WorkOrder::factory()->create(['produced_qty' => 3]);

        $this->packedUnit($workOrder);
        $this->packedUnit($workOrder);
        $this->packedUnit($workOrder);
        $this->scrappedUnit($workOrder);
        // Two separate evidences: the unit (status) and the reported entry.
        $this->scrapEntry($workOrder, 2);

        $facts = $this->facts->forWorkOrder($workOrder);

        $this->assertSame(QualityFacts::BASIS_UNITS, $facts['basis']);
        $this->assertEqualsWithDelta(3.0, $facts['good_qty'], 0.001);
        // 1 scrapped unit + 2 reported pieces - the unit never creates an entry.
        $this->assertEqualsWithDelta(3.0, $facts['scrap_qty'], 0.001);
        $this->assertEqualsWithDelta(6.0, $facts['total_qty'], 0.001);
        $this->assertSame(50.0, $facts['quality_pct']);
    }

    public function test_declared_basis_keeps_the_operator_flow_result(): void
    {
        $workOrder = WorkOrder::factory()->create(['produced_qty' => 100]);
        $this->scrapEntry($workOrder, 25);

        $facts = $this->facts->forWorkOrder($workOrder);

        $this->assertSame(QualityFacts::BASIS_DECLARED, $facts['basis']);
        $this->assertEqualsWithDelta(75.0, $facts['good_qty'], 0.001);
        $this->assertEqualsWithDelta(100.0, $facts['total_qty'], 0.001);
        // Parity with the pre-P2.7 formula: (100 - 25) / 100.
        $this->assertSame(75.0, $facts['quality_pct']);
        $this->assertSame(75.0, $workOrder->qualityPct());
    }

    public function test_no_produced_quantity_is_null_never_zero(): void
    {
        $workOrder = WorkOrder::factory()->create(['produced_qty' => 0]);
        $this->scrapEntry($workOrder, 5);

        $facts = $this->facts->forWorkOrder($workOrder);

        $this->assertSame(QualityFacts::BASIS_DECLARED, $facts['basis']);
        $this->assertEqualsWithDelta(0.0, $facts['total_qty'], 0.001);
        $this->assertNull($facts['quality_pct'], 'no basis is null, not 0% and not 100%');
        $this->assertNull($workOrder->qualityPct());
    }

    public function test_everything_scrapped_is_zero_percent(): void
    {
        $workOrder = WorkOrder::factory()->create(['produced_qty' => 0]);
        $units = [
            $this->packedUnit($workOrder),
            $this->packedUnit($workOrder),
            $this->packedUnit($workOrder),
        ];

        // Packed and then scrapped: `packed_at` stays, so the order is still a
        // unit-flow order - and its good count is now zero, a fact.
        foreach ($units as $unit) {
            $unit->update(['status' => SerialUnit::STATUS_SCRAPPED]);
        }

        $facts = $this->facts->forWorkOrder($workOrder);

        $this->assertSame(QualityFacts::BASIS_UNITS, $facts['basis']);
        $this->assertEqualsWithDelta(0.0, $facts['good_qty'], 0.001);
        $this->assertEqualsWithDelta(3.0, $facts['scrap_qty'], 0.001);
        $this->assertEqualsWithDelta(3.0, $facts['total_qty'], 0.001);
        $this->assertSame(0.0, $facts['quality_pct']);
    }

    public function test_scrapping_a_unit_records_no_scrap_entry_so_it_is_counted_once(): void
    {
        $workOrder = WorkOrder::factory()->create(['produced_qty' => 1]);
        $unit = $this->packedUnit($workOrder);
        $operator = User::factory()->create();

        app(SerialTraceService::class)->scrapUnit($unit, $operator, 'Cracked edge');

        $this->assertSame(0, ScrapEntry::query()->count(), 'a scrapped unit is not a scrap entry');

        $facts = $this->facts->forWorkOrder($workOrder);

        $this->assertEqualsWithDelta(0.0, $facts['scrap_entries_qty'], 0.001);
        $this->assertEqualsWithDelta(1.0, $facts['scrapped_units_qty'], 0.001);
        $this->assertEqualsWithDelta(1.0, $facts['scrap_qty'], 0.001, 'the unit is counted exactly once');
        $this->assertSame(0.0, $facts['quality_pct']);
    }

    public function test_query_count_does_not_follow_the_batch_count(): void
    {
        $measure = function (int $batchCount): int {
            $workOrder = WorkOrder::factory()->create(['produced_qty' => 1]);
            Batch::factory()->count($batchCount)->create(['work_order_id' => $workOrder->id]);
            $this->packedUnit($workOrder);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->facts->forWorkOrder($workOrder);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $one = $measure(1);
        $five = $measure(5);

        $this->assertSame($one, $five, "forWorkOrder must not scale with batches ({$one} vs {$five})");
    }

    public function test_batches_of_one_window_share_the_basis_rule(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $batch = Batch::factory()->create([
            'work_order_id' => $workOrder->id,
            'status' => Batch::STATUS_DONE,
            'produced_qty' => 3,
            'scrap_qty' => 1,
        ]);
        $batch->setProducedQtySource(Batch::PRODUCED_QTY_SOURCE_UNITS);
        $batch->save();

        foreach (range(1, 3) as $_) {
            $this->packedUnit($workOrder, $batch);
        }

        $facts = $this->facts->forBatches($batch->newQuery()->get());

        $this->assertSame(QualityFacts::BASIS_UNITS, $facts['basis']);
        $this->assertSame(75.0, $facts['quality_pct']);
        $this->assertSame(0, $facts['unknown_source_batches']);
    }

    public function test_batches_without_a_source_are_reported_not_guessed(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $batch = Batch::factory()->create([
            'work_order_id' => $workOrder->id,
            'status' => Batch::STATUS_DONE,
            'produced_qty' => 100,
            'scrap_qty' => 10,
        ]);

        $facts = $this->facts->forBatches($batch->newQuery()->get());

        $this->assertNull($facts['quality_pct'], 'no basis: null, never a guessed number');
        $this->assertNull($facts['basis']);
        $this->assertNull($facts['good_qty']);
        $this->assertNull($facts['total_qty']);
        $this->assertSame(1, $facts['unknown_source_batches']);
    }

    // ── P1.2: per-batch facts ──────────────────────────────────────────

    /** Each batch of a window keeps its own basis instead of one pooled ratio. */
    public function test_per_batch_keeps_one_basis_per_row(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $withUnits = Batch::factory()->create([
            'work_order_id' => $workOrder->id,
            'status' => Batch::STATUS_DONE,
            'produced_qty' => 2,
            'scrap_qty' => 0,
        ]);
        $withUnits->setProducedQtySource(Batch::PRODUCED_QTY_SOURCE_UNITS);
        $withUnits->save();
        $this->packedUnit($workOrder, $withUnits);
        $this->packedUnit($workOrder, $withUnits);

        $declared = Batch::factory()->create([
            'work_order_id' => $workOrder->id,
            'status' => Batch::STATUS_DONE,
            'produced_qty' => 10,
            'scrap_qty' => 2,
        ]);
        $declared->setProducedQtySource(Batch::PRODUCED_QTY_SOURCE_MANUAL);
        $declared->save();

        $rows = $this->facts->perBatch(Batch::query()->whereKey([$withUnits->id, $declared->id])->get());

        $this->assertCount(2, $rows);

        $this->assertSame(QualityFacts::BASIS_UNITS, $rows[$withUnits->id]['basis']);
        $this->assertEqualsWithDelta(2.0, $rows[$withUnits->id]['good_qty'], 0.001);
        $this->assertEqualsWithDelta(2.0, $rows[$withUnits->id]['total_qty'], 0.001);
        $this->assertFalse($rows[$withUnits->id]['unknown']);

        $this->assertSame(QualityFacts::BASIS_DECLARED, $rows[$declared->id]['basis']);
        $this->assertEqualsWithDelta(8.0, $rows[$declared->id]['good_qty'], 0.001);
        $this->assertEqualsWithDelta(10.0, $rows[$declared->id]['total_qty'], 0.001);
        $this->assertSame(80.0, $rows[$declared->id]['quality_pct']);

        // A set of both evidences has no single basis and says so.
        $this->assertSame(QualityFacts::BASIS_MIXED, QualityFacts::combinedBasis($rows));
    }

    /** A batch without a declared source is flagged, never counted or guessed. */
    public function test_per_batch_flags_an_unknown_source(): void
    {
        $batch = Batch::factory()->create([
            'work_order_id' => WorkOrder::factory()->create()->id,
            'status' => Batch::STATUS_DONE,
            'produced_qty' => 100,
        ]);

        $rows = $this->facts->perBatch(Batch::query()->whereKey($batch->id)->get());

        $this->assertTrue($rows[$batch->id]['unknown']);
        $this->assertNull($rows[$batch->id]['basis']);
        $this->assertNull($rows[$batch->id]['good_qty']);
        $this->assertNull($rows[$batch->id]['total_qty']);
        $this->assertNull($rows[$batch->id]['quality_pct']);
        $this->assertNull(QualityFacts::combinedBasis($rows));
    }

    /**
     * The per-batch facts cost the same for 1 and for 10 batches: one grouped
     * unit aggregate (plus the single-batch order lookup when units carry no
     * batch), never a query per batch.
     */
    public function test_per_batch_query_count_does_not_follow_the_batch_count(): void
    {
        $measure = function (int $batchCount): int {
            $workOrder = WorkOrder::factory()->create();
            $batches = Batch::factory()->count($batchCount)->create([
                'work_order_id' => $workOrder->id,
                'status' => Batch::STATUS_DONE,
                'produced_qty' => 1,
            ]);

            foreach ($batches as $batch) {
                $batch->setProducedQtySource(Batch::PRODUCED_QTY_SOURCE_UNITS);
                $batch->save();
                $this->packedUnit($workOrder, $batch);
            }

            $loaded = Batch::query()->whereIn('id', $batches->pluck('id'))->get();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->facts->perBatch($loaded);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $one = $measure(1);
        $ten = $measure(10);

        $this->assertSame($one, $ten, "perBatch must not scale with the batch count ({$one} vs {$ten})");
        $this->assertLessThanOrEqual(2, $one, 'at most two queries per call');
    }
}
