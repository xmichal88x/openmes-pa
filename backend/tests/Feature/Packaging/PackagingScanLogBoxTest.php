<?php

namespace Tests\Feature\Packaging;

use App\Models\PackagingBox;
use App\Models\PackagingScanLog;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackagingScanLogBoxTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_log_points_at_its_box_and_is_detached_when_the_box_is_deleted(): void
    {
        $wo = WorkOrder::factory()->create();
        $box = PackagingBox::create([
            'work_order_id' => $wo->id,
            'box_no' => 1,
            'box_id' => 'BOX-879-1',
        ]);
        $log = PackagingScanLog::create([
            'work_order_id' => $wo->id,
            'packaging_box_id' => $box->id,
            'ean' => 'LBL-B1-1',
            'scanned_at' => now(),
        ]);

        $this->assertSame($box->id, $log->packagingBox->id);
        $this->assertSame(1, $box->scanLogs()->count());

        $box->delete();

        $this->assertNull($log->refresh()->packaging_box_id);
    }
}
