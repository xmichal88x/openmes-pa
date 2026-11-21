<?php

namespace Tests\Unit\Models;

use App\Models\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RQ-PA-263: a batch's produced quantity carries an explicit source. A missing
 * or unrecognised value reads as `unknown` (the caller fails closed), never as
 * a guessed source, and marking a source never drops the batch's other
 * `extra_data` keys (the re-cut path keeps its idempotency_key/reason).
 */
class BatchProducedQtySourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_batch_without_a_marker_reads_as_unknown(): void
    {
        $batch = Batch::factory()->create();

        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_UNKNOWN, $batch->producedQtySource());
    }

    public function test_an_unrecognised_stored_value_reads_as_unknown(): void
    {
        $batch = Batch::factory()->create(['extra_data' => ['produced_qty_source' => 'plc']]);

        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_UNKNOWN, $batch->fresh()->producedQtySource());
    }

    public function test_every_known_source_is_read_back(): void
    {
        foreach (Batch::PRODUCED_QTY_SOURCES as $source) {
            $batch = Batch::factory()->create(['extra_data' => ['produced_qty_source' => $source]]);

            $this->assertSame($source, $batch->fresh()->producedQtySource());
        }
    }

    public function test_setting_a_source_keeps_the_other_extra_data_keys(): void
    {
        $batch = Batch::factory()->create([
            'extra_data' => ['idempotency_key' => 'recut-1', 'reason' => 'defective panel'],
        ]);

        $batch->setProducedQtySource(Batch::PRODUCED_QTY_SOURCE_MACHINE);
        $batch->update(['extra_data' => $batch->extra_data]);

        $saved = $batch->fresh();
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_MACHINE, $saved->producedQtySource());
        $this->assertSame('recut-1', $saved->extra_data['idempotency_key']);
        $this->assertSame('defective panel', $saved->extra_data['reason']);
    }

    public function test_a_replacement_batch_keeps_its_flags_and_extra_data_when_marked(): void
    {
        $origin = Batch::factory()->create();
        $replacement = Batch::factory()->create(['extra_data' => ['idempotency_key' => 'recut-2']]);
        $replacement->forceFill([
            'is_replacement' => true,
            'replaces_batch_id' => $origin->id,
        ])->save();

        $replacement->setProducedQtySource(Batch::PRODUCED_QTY_SOURCE_MANUAL);
        $replacement->update(['extra_data' => $replacement->extra_data]);

        $saved = $replacement->fresh();
        $this->assertTrue($saved->is_replacement);
        $this->assertSame($origin->id, (int) $saved->replaces_batch_id);
        $this->assertSame('recut-2', $saved->extra_data['idempotency_key']);
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_MANUAL, $saved->producedQtySource());
    }

    public function test_an_unknown_source_is_refused_and_changes_nothing(): void
    {
        $batch = Batch::factory()->create(['extra_data' => ['idempotency_key' => 'recut-3']]);

        try {
            $batch->setProducedQtySource('plc');
            $this->fail('A source outside PRODUCED_QTY_SOURCES must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('produced_qty_source', $e->getMessage());
        }

        $this->assertSame(['idempotency_key' => 'recut-3'], $batch->extra_data);
        $this->assertSame(Batch::PRODUCED_QTY_SOURCE_UNKNOWN, $batch->fresh()->producedQtySource());
    }
}
