<?php

namespace Tests\Feature\Packaging;

use App\Models\PackagingDispatch;
use App\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PackagingDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_defaults_and_json_casts(): void
    {
        $wo = WorkOrder::factory()->create();

        $dispatch = $this->createDispatch($wo);

        $this->assertSame(PackagingDispatch::STATUS_PENDING, $dispatch->status);
        $this->assertSame(PackagingDispatch::SOURCE_OPERATOR, $dispatch->source);
        $this->assertSame(0, $dispatch->attempt_count);
        $this->assertTrue($dispatch->docs_stale);
        $this->assertSame([], $dispatch->scanned_boxes);
        $this->assertSame([], $dispatch->scanned_pallets);
        $this->assertSame([], $dispatch->box_manifest);
        $this->assertSame([], $dispatch->pallet_manifest);
        $this->assertTrue($dispatch->isPending());
        $this->assertTrue($dispatch->isActive());
    }

    public function test_dispatch_relations_and_manifest_roundtrip(): void
    {
        $wo = WorkOrder::factory()->create();
        $manifest = [['box_id' => 'BOX-879-1', 'part_count' => 12, 'weight_kg' => '6.200']];

        $dispatch = $this->createDispatch($wo, [
            'scanned_boxes' => ['BOX-879-1'],
            'box_manifest' => $manifest,
            'weight_kg' => '6.200',
            'expected_weight_kg' => '6.200',
        ]);

        $this->assertSame($wo->id, $dispatch->workOrder->id);
        $this->assertSame(['BOX-879-1'], $dispatch->refresh()->scanned_boxes);
        $this->assertSame($manifest, $dispatch->box_manifest);
        $this->assertSame('6.200', $dispatch->weight_kg);
        $this->assertSame(1, $wo->packagingDispatches()->count());
    }

    public function test_idempotency_key_is_unique(): void
    {
        $wo = WorkOrder::factory()->create();
        $key = (string) Str::uuid();

        $this->createDispatch($wo, ['idempotency_key' => $key, 'status' => PackagingDispatch::STATUS_DONE]);

        $this->expectException(QueryException::class);

        $this->createDispatch($wo, ['idempotency_key' => $key, 'status' => PackagingDispatch::STATUS_DONE]);
    }

    public function test_active_scope_returns_pending_and_processing_only(): void
    {
        $wo = WorkOrder::factory()->create();
        $this->createDispatch($wo, ['status' => PackagingDispatch::STATUS_DONE]);

        $other = WorkOrder::factory()->create();
        $pending = $this->createDispatch($other);

        $third = WorkOrder::factory()->create();
        $processing = $this->createDispatch($third, ['status' => PackagingDispatch::STATUS_PROCESSING]);

        $cancelledWo = WorkOrder::factory()->create();
        $this->createDispatch($cancelledWo, ['status' => PackagingDispatch::STATUS_CANCELLED]);

        $ids = PackagingDispatch::active()->orderBy('id')->pluck('id')->all();

        $this->assertSame([$pending->id, $processing->id], $ids);
    }

    public function test_second_active_dispatch_for_the_same_work_order_is_rejected_on_sqlite(): void
    {
        $wo = WorkOrder::factory()->create();
        $this->createDispatch($wo);

        $this->expectException(\DomainException::class);

        $this->createDispatch($wo);
    }

    public function test_promoting_pending_to_processing_does_not_block_itself(): void
    {
        $wo = WorkOrder::factory()->create();
        $dispatch = $this->createDispatch($wo);

        $dispatch->update(['status' => PackagingDispatch::STATUS_PROCESSING]);

        $this->assertSame(PackagingDispatch::STATUS_PROCESSING, $dispatch->refresh()->status);
    }

    public function test_cancelled_dispatch_does_not_block_a_new_intent(): void
    {
        $wo = WorkOrder::factory()->create();
        $this->createDispatch($wo, ['status' => PackagingDispatch::STATUS_CANCELLED]);

        $pending = $this->createDispatch($wo);

        $this->assertTrue($pending->isPending());
        $this->assertSame(2, PackagingDispatch::where('work_order_id', $wo->id)->count());
    }

    public function test_sqlite_has_no_partial_unique_index_because_the_model_enforces_it(): void
    {
        $indexes = collect(DB::select("PRAGMA index_list('packaging_dispatches')"))
            ->pluck('name')
            ->all();

        $this->assertNotContains('packaging_dispatches_active_unique', $indexes);
    }

    private function createDispatch(WorkOrder $wo, array $overrides = []): PackagingDispatch
    {
        return PackagingDispatch::create(array_merge([
            'work_order_id' => $wo->id,
            'order_no' => $wo->order_no,
            'idempotency_key' => (string) Str::uuid(),
        ], $overrides));
    }
}
