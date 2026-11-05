<?php

namespace Tests\Feature\Packaging;

use App\Enums\PalletStatus;
use App\Models\PackagingBox;
use App\Models\PackagingScanLog;
use App\Models\Pallet;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use App\Support\WorkOrderPackingList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackagingDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');
    }

    public function test_box_label_pdf_streams_for_an_operator_on_the_line(): void
    {
        $wo = $this->workOrder();
        $box = $this->box($wo);
        $this->operator->lines()->attach($wo->line_id);

        $this->actingAs($this->operator)
            ->get(route('packaging.boxes.label.pdf', $box))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_box_payload_carries_the_label_urls(): void
    {
        $wo = $this->workOrder();
        $box = $this->box($wo);
        $this->operator->lines()->attach($wo->line_id);

        $this->actingAs($this->operator)
            ->getJson('/packaging/boxes/'.$box->id)
            ->assertOk()
            ->assertJsonPath('data.label_pdf', route('packaging.boxes.label.pdf', $box))
            ->assertJsonPath('data.label_zpl', route('packaging.boxes.label.zpl', $box));
    }

    public function test_box_label_is_forbidden_for_an_operator_on_another_line(): void
    {
        $wo = $this->workOrder();
        $box = $this->box($wo);
        $other = $this->workOrder();
        $this->operator->lines()->attach($other->line_id);

        $this->actingAs($this->operator)
            ->get(route('packaging.boxes.label.pdf', $box))
            ->assertForbidden();
    }

    public function test_box_label_qr_carries_the_box_position_parts_and_weight(): void
    {
        $wo = $this->workOrder();
        $pallet = Pallet::create([
            'work_order_id' => $wo->id,
            'qty' => 5,
            'kind' => Pallet::KIND_BOXES,
            'status' => PalletStatus::Open->value,
        ]);
        $box = $this->box($wo, 1, [
            'pallet_id' => $pallet->id,
            'part_count' => 2,
            'weight_kg' => 1.5,
            'status' => PackagingBox::STATUS_CLOSED,
        ]);
        $this->box($wo, 2, [
            'pallet_id' => $pallet->id,
            'part_count' => 3,
            'status' => PackagingBox::STATUS_CLOSED,
        ]);
        $this->operator->lines()->attach($wo->line_id);

        $response = $this->actingAs($this->operator)
            ->get(route('packaging.boxes.label.zpl', $box))
            ->assertOk();

        $zpl = $response->getContent();
        $this->assertStringContainsString($box->box_id, $zpl);
        $this->assertStringContainsString('Box 1 of 2', $zpl);
        $this->assertStringContainsString('"box":1', $zpl);
        $this->assertStringContainsString('"of":2', $zpl);
        $this->assertStringContainsString('"parts":2', $zpl);
        $this->assertStringContainsString('"weight_kg":1.5', $zpl);
    }

    public function test_work_order_packing_list_streams_for_a_boxes_pallet_and_a_loose_pallet(): void
    {
        $wo = $this->workOrder();
        $boxesPallet = Pallet::create([
            'work_order_id' => $wo->id,
            'qty' => 2,
            'kind' => Pallet::KIND_BOXES,
            'status' => PalletStatus::Open->value,
        ]);
        $box = $this->box($wo, 1, [
            'pallet_id' => $boxesPallet->id,
            'part_count' => 2,
            'weight_kg' => 2.4,
            'status' => PackagingBox::STATUS_CLOSED,
        ]);
        $this->part($wo, 'LBL-001', ['packaging_box_id' => $box->id, 'part_seq' => 1, 'part_name' => 'Side', 'material' => 'MDF', 'width_mm' => 100, 'height_mm' => 50, 'thickness_mm' => 18, 'sheet_name' => 'SHEET-A1']);
        $this->part($wo, 'LBL-002', ['packaging_box_id' => $box->id, 'part_seq' => 2]);

        $loosePallet = Pallet::create([
            'work_order_id' => $wo->id,
            'qty' => 1,
            'kind' => Pallet::KIND_LOOSE,
            'status' => PalletStatus::Open->value,
        ]);
        $loose = $this->part($wo, 'LBL-003', ['part_seq' => 3]);
        PackagingScanLog::create([
            'work_order_id' => $wo->id,
            'pallet_id' => $loosePallet->id,
            'ean' => $loose->ean,
            'scanned_at' => now(),
        ]);
        $this->operator->lines()->attach($wo->line_id);

        $data = WorkOrderPackingList::data($wo);
        $this->assertCount(2, $data['pallets']);
        $this->assertSame($box->box_id, $data['pallets'][0]['boxes'][0]['box_id']);
        $this->assertSame('LBL-001', $data['pallets'][0]['boxes'][0]['parts'][0]['label_id']);
        $this->assertSame('MDF', $data['pallets'][0]['boxes'][0]['parts'][0]['material']);
        $this->assertSame('Side', $data['pallets'][0]['boxes'][0]['parts'][0]['part_name']);
        $this->assertSame('SHEET-A1', $data['pallets'][0]['boxes'][0]['parts'][0]['sheet_name']);
        $this->assertSame('100 × 50 × 18 mm', $data['pallets'][0]['boxes'][0]['parts'][0]['dimensions']);
        $this->assertSame(['LBL-003'], array_column($data['pallets'][1]['loose_parts'], 'label_id'));
        $this->assertSame(3, $data['totals']['parts']);

        $this->actingAs($this->operator)
            ->get(route('packaging.work-orders.packing-list.pdf', $wo))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_packing_list_for_a_loose_pallet_without_boxes_still_renders(): void
    {
        $wo = $this->workOrder();
        $pallet = Pallet::create([
            'work_order_id' => $wo->id,
            'qty' => 1,
            'kind' => Pallet::KIND_LOOSE,
            'status' => PalletStatus::Open->value,
        ]);
        $part = $this->part($wo, 'LBL-LOOSE');
        PackagingScanLog::create([
            'work_order_id' => $wo->id,
            'pallet_id' => $pallet->id,
            'ean' => $part->ean,
            'scanned_at' => now(),
        ]);
        $this->operator->lines()->attach($wo->line_id);

        $this->assertSame(0, PackagingBox::where('work_order_id', $wo->id)->count());

        $this->actingAs($this->operator)
            ->get(route('packaging.work-orders.packing-list.pdf', $wo))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_packing_list_is_404_when_nothing_was_packed(): void
    {
        $wo = $this->workOrder();
        $this->operator->lines()->attach($wo->line_id);

        $this->actingAs($this->operator)
            ->get(route('packaging.work-orders.packing-list.pdf', $wo))
            ->assertNotFound();
    }

    public function test_packing_list_is_404_for_a_work_order_that_is_not_packable(): void
    {
        $wo = $this->workOrder(WorkOrder::STATUS_PENDING);
        $this->box($wo);
        $this->operator->lines()->attach($wo->line_id);

        $this->actingAs($this->operator)
            ->get(route('packaging.work-orders.packing-list.pdf', $wo))
            ->assertNotFound();
    }

    public function test_packing_list_is_forbidden_for_an_operator_on_another_line(): void
    {
        $wo = $this->workOrder();
        $this->box($wo);
        $other = $this->workOrder();
        $this->operator->lines()->attach($other->line_id);

        $this->actingAs($this->operator)
            ->get(route('packaging.work-orders.packing-list.pdf', $wo))
            ->assertForbidden();
    }

    private function workOrder(string $status = WorkOrder::STATUS_IN_PROGRESS): WorkOrder
    {
        return WorkOrder::factory()->create([
            'status' => $status,
            'customer_order_no' => 'PO-77',
        ]);
    }

    private function box(WorkOrder $wo, int $boxNo = 1, array $overrides = []): PackagingBox
    {
        return PackagingBox::create(array_merge([
            'work_order_id' => $wo->id,
            'box_no' => $boxNo,
            'box_id' => 'BOX-'.$wo->order_no.'-'.$boxNo,
            'part_count' => 0,
            'status' => PackagingBox::STATUS_OPEN,
        ], $overrides));
    }

    private function part(WorkOrder $wo, string $ean, array $overrides = []): WorkOrderEan
    {
        return WorkOrderEan::create(array_merge([
            'work_order_id' => $wo->id,
            'ean' => $ean,
            'code_type' => WorkOrderEan::CODE_TYPE_PART_LABEL,
            'state' => WorkOrderEan::STATE_SCANNED,
        ], $overrides));
    }
}
