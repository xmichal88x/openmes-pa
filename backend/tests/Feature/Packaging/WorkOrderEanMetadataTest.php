<?php

namespace Tests\Feature\Packaging;

use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderEanMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_code_defaults_to_ean_type_and_expected_state(): void
    {
        $wo = WorkOrder::factory()->create();

        $record = WorkOrderEan::create([
            'work_order_id' => $wo->id,
            'ean' => 'TEST-1',
        ]);
        $record->refresh();

        $this->assertSame(WorkOrderEan::CODE_TYPE_EAN, $record->code_type);
        $this->assertSame(WorkOrderEan::STATE_EXPECTED, $record->state);
        $this->assertNull($record->part_seq);
        $this->assertDatabaseHas('work_order_eans', [
            'ean' => 'TEST-1',
            'code_type' => 'ean',
            'state' => 'expected',
            'part_seq' => null,
        ]);
    }

    public function test_part_label_fields_persist(): void
    {
        $wo = WorkOrder::factory()->create();

        WorkOrderEan::create([
            'work_order_id' => $wo->id,
            'ean' => 'LBL-B1-3',
            'code_type' => WorkOrderEan::CODE_TYPE_PART_LABEL,
            'label_batch_id' => 'B1',
            'part_seq' => 3,
            'state' => WorkOrderEan::STATE_SCANNED,
        ]);

        $this->assertDatabaseHas('work_order_eans', [
            'ean' => 'LBL-B1-3',
            'code_type' => 'part_label',
            'label_batch_id' => 'B1',
            'part_seq' => 3,
            'state' => 'scanned',
        ]);
    }

    public function test_ean_remains_globally_unique(): void
    {
        $wo = WorkOrder::factory()->create();
        WorkOrderEan::create(['work_order_id' => $wo->id, 'ean' => 'DUP-1']);

        $this->expectException(QueryException::class);

        WorkOrderEan::create(['work_order_id' => $wo->id, 'ean' => 'DUP-1']);
    }

    public function test_expected_scope_returns_only_expected_codes(): void
    {
        $wo = WorkOrder::factory()->create();

        WorkOrderEan::create(['work_order_id' => $wo->id, 'ean' => 'EXP-1']);
        WorkOrderEan::create(['work_order_id' => $wo->id, 'ean' => 'EXP-2']);
        WorkOrderEan::create([
            'work_order_id' => $wo->id,
            'ean' => 'SCAN-1',
            'state' => WorkOrderEan::STATE_SCANNED,
        ]);
        WorkOrderEan::create([
            'work_order_id' => $wo->id,
            'ean' => 'VOID-1',
            'state' => WorkOrderEan::STATE_VOID,
        ]);

        $expected = WorkOrderEan::expected()->orderBy('ean')->pluck('ean')->all();

        $this->assertSame(['EXP-1', 'EXP-2'], $expected);
    }
}
