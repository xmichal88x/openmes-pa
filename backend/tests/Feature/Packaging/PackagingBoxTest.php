<?php

namespace Tests\Feature\Packaging;

use App\Models\PackagingBox;
use App\Models\PackagingScanLog;
use App\Models\Pallet;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackagingBoxTest extends TestCase
{
    use RefreshDatabase;

    public function test_carton_defaults_and_relations(): void
    {
        $wo = WorkOrder::factory()->create();

        $box = PackagingBox::create([
            'work_order_id' => $wo->id,
            'box_no' => 1,
            'box_id' => 'BOX-879-1',
        ]);

        $box->refresh();

        $this->assertSame(PackagingBox::STATUS_OPEN, $box->status);
        $this->assertSame(0, $box->part_count);
        $this->assertTrue($box->isOpen());
        $this->assertNull($box->pallet_id);
        $this->assertSame($wo->id, $box->workOrder->id);
        $this->assertSame(1, $wo->packagingBoxes()->count());
    }

    public function test_box_id_is_globally_unique(): void
    {
        $wo = WorkOrder::factory()->create();

        PackagingBox::create([
            'work_order_id' => $wo->id,
            'box_no' => 1,
            'box_id' => 'BOX-879-1',
        ]);

        $this->expectException(QueryException::class);

        PackagingBox::create([
            'work_order_id' => $wo->id,
            'box_no' => 2,
            'box_id' => 'BOX-879-1',
        ]);
    }

    public function test_box_no_is_unique_within_a_work_order(): void
    {
        $wo = WorkOrder::factory()->create();

        PackagingBox::create([
            'work_order_id' => $wo->id,
            'box_no' => 1,
            'box_id' => 'BOX-879-1',
        ]);

        $this->expectException(QueryException::class);

        PackagingBox::create([
            'work_order_id' => $wo->id,
            'box_no' => 1,
            'box_id' => 'BOX-879-2',
        ]);
    }

    public function test_pallet_kind_defaults_to_loose_and_boxes_mode_helper(): void
    {
        $pallet = Pallet::factory()->create();

        $pallet->refresh();
        $this->assertSame(Pallet::KIND_LOOSE, $pallet->kind);
        $this->assertFalse($pallet->isBoxesMode());

        $pallet->update(['kind' => Pallet::KIND_BOXES]);

        $this->assertTrue($pallet->refresh()->isBoxesMode());
        $this->assertSame(0, $pallet->boxes()->count());
    }

    public function test_carton_belongs_to_boxes_pallet_and_is_detached_when_pallet_is_hard_deleted(): void
    {
        $wo = WorkOrder::factory()->create();
        $pallet = Pallet::factory()->create([
            'work_order_id' => $wo->id,
            'kind' => Pallet::KIND_BOXES,
        ]);
        $box = PackagingBox::create([
            'work_order_id' => $wo->id,
            'pallet_id' => $pallet->id,
            'box_no' => 1,
            'box_id' => 'BOX-879-1',
            'status' => PackagingBox::STATUS_CLOSED,
        ]);

        $this->assertSame($pallet->id, $box->pallet->id);
        $this->assertSame(1, $pallet->boxes()->count());

        $pallet->forceDelete();

        $this->assertNull($box->refresh()->pallet_id);
    }

    public function test_box_groups_parts_and_scan_logs(): void
    {
        $wo = WorkOrder::factory()->create();
        $box = PackagingBox::create([
            'work_order_id' => $wo->id,
            'box_no' => 1,
            'box_id' => 'BOX-879-1',
        ]);

        $ean = WorkOrderEan::create([
            'work_order_id' => $wo->id,
            'ean' => 'LBL-B1-1',
            'code_type' => WorkOrderEan::CODE_TYPE_PART_LABEL,
            'state' => WorkOrderEan::STATE_SCANNED,
            'packaging_box_id' => $box->id,
        ]);
        $log = PackagingScanLog::create([
            'work_order_id' => $wo->id,
            'packaging_box_id' => $box->id,
            'ean' => 'LBL-B1-1',
            'scanned_at' => now(),
        ]);

        $this->assertSame($box->id, $ean->packagingBox->id);
        $this->assertSame($box->id, $log->packagingBox->id);
        $this->assertSame(1, $box->eans()->count());
        $this->assertSame(1, $box->scanLogs()->count());
    }
}
