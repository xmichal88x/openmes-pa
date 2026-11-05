<?php

namespace Tests\Feature\Packaging;

use App\Models\PackagingBox;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderEanPackagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_part_metadata_persists_and_is_cast(): void
    {
        $wo = WorkOrder::factory()->create();

        $ean = WorkOrderEan::create([
            'work_order_id' => $wo->id,
            'ean' => 'LBL-B1-7',
            'code_type' => WorkOrderEan::CODE_TYPE_PART_LABEL,
            'state' => WorkOrderEan::STATE_SCANNED,
            'part_name' => 'Front panel',
            'material' => 'MDF',
            'width_mm' => 1200.5,
            'height_mm' => 600,
            'thickness_mm' => 18,
            'sheet_name' => 'SHEET-1',
        ]);

        $ean->refresh();

        $this->assertSame('Front panel', $ean->part_name);
        $this->assertSame('MDF', $ean->material);
        $this->assertSame('1200.50', $ean->width_mm);
        $this->assertSame('600.00', $ean->height_mm);
        $this->assertSame('18.00', $ean->thickness_mm);
        $this->assertSame('SHEET-1', $ean->sheet_name);
    }

    public function test_part_label_can_be_assigned_to_a_box_and_is_detached_when_the_box_is_deleted(): void
    {
        $wo = WorkOrder::factory()->create();
        $box = PackagingBox::create([
            'work_order_id' => $wo->id,
            'box_no' => 1,
            'box_id' => 'BOX-879-1',
        ]);
        $ean = WorkOrderEan::create([
            'work_order_id' => $wo->id,
            'ean' => 'LBL-B1-7',
            'code_type' => WorkOrderEan::CODE_TYPE_PART_LABEL,
            'state' => WorkOrderEan::STATE_SCANNED,
            'packaging_box_id' => $box->id,
        ]);

        $this->assertSame($box->id, $ean->packagingBox->id);

        $box->delete();

        $this->assertNull($ean->refresh()->packaging_box_id);
    }

    public function test_scanned_and_part_label_scopes(): void
    {
        $wo = WorkOrder::factory()->create();

        WorkOrderEan::create([
            'work_order_id' => $wo->id,
            'ean' => 'LBL-SCAN',
            'code_type' => WorkOrderEan::CODE_TYPE_PART_LABEL,
            'state' => WorkOrderEan::STATE_SCANNED,
        ]);
        WorkOrderEan::create([
            'work_order_id' => $wo->id,
            'ean' => 'LBL-EXPECTED',
            'code_type' => WorkOrderEan::CODE_TYPE_PART_LABEL,
            'state' => WorkOrderEan::STATE_EXPECTED,
        ]);
        WorkOrderEan::create([
            'work_order_id' => $wo->id,
            'ean' => '5901234123457',
            'code_type' => WorkOrderEan::CODE_TYPE_EAN,
            'state' => WorkOrderEan::STATE_SCANNED,
        ]);

        $this->assertSame(
            ['5901234123457', 'LBL-SCAN'],
            WorkOrderEan::scanned()->orderBy('ean')->pluck('ean')->all(),
        );
        $this->assertSame(
            ['LBL-EXPECTED', 'LBL-SCAN'],
            WorkOrderEan::partLabels()->orderBy('ean')->pluck('ean')->all(),
        );
    }
}
