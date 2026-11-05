<?php

namespace Tests\Feature\Packaging;

use App\Models\PackagingBox;
use App\Models\PackagingScanLog;
use App\Models\Pallet;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use App\Support\WorkOrderPackingList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** R07-15 per-pallet packing list: boxes -> parts, or loose parts directly. */
class PalletPackagingListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    private function workOrder(): WorkOrder
    {
        return WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'customer_order_no' => 'PO-77',
        ]);
    }

    private function box(WorkOrder $wo, Pallet $pallet, int $boxNo = 1): PackagingBox
    {
        return PackagingBox::create([
            'work_order_id' => $wo->id,
            'pallet_id' => $pallet->id,
            'box_no' => $boxNo,
            'box_id' => 'BOX-'.$wo->order_no.'-'.$boxNo,
            'part_count' => 0,
            'status' => PackagingBox::STATUS_CLOSED,
            'closed_at' => now(),
        ]);
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

    public function test_a_boxes_pallet_lists_its_boxes_and_parts_without_loose_parts(): void
    {
        $wo = $this->workOrder();
        $pallet = Pallet::create([
            'work_order_id' => $wo->id,
            'kind' => Pallet::KIND_BOXES,
            'status' => 'closed',
            'qty' => 2,
        ]);
        $box = $this->box($wo, $pallet);
        $this->part($wo, 'LBL-001', ['packaging_box_id' => $box->id, 'part_seq' => 1, 'part_name' => 'Side']);
        $this->part($wo, 'LBL-002', ['packaging_box_id' => $box->id, 'part_seq' => 2, 'part_name' => 'Shelf']);

        $list = WorkOrderPackingList::palletData($pallet->fresh());

        $this->assertTrue($list['has_packing']);
        $this->assertSame($pallet->pallet_no, $list['pallet']['pallet_no']);
        $this->assertSame(Pallet::KIND_BOXES, $list['pallet']['kind']);
        $this->assertCount(1, $list['boxes']);
        $this->assertSame($box->box_id, $list['boxes'][0]['box_id']);
        $this->assertSame(['LBL-001', 'LBL-002'], array_column($list['boxes'][0]['parts'], 'label_id'));
        $this->assertSame([], $list['loose_parts']);
        $this->assertSame(['boxes' => 1, 'parts' => 2, 'weight_kg' => '0'], $list['totals']);
    }

    public function test_a_loose_pallet_lists_its_scanned_parts_without_boxes(): void
    {
        $wo = $this->workOrder();
        $pallet = Pallet::create([
            'work_order_id' => $wo->id,
            'kind' => Pallet::KIND_LOOSE,
            'status' => 'closed',
            'qty' => 1,
        ]);
        $part = $this->part($wo, 'LBL-LOOSE', ['part_seq' => 3, 'material' => 'MDF']);
        PackagingScanLog::create([
            'work_order_id' => $wo->id,
            'pallet_id' => $pallet->id,
            'ean' => $part->ean,
            'scanned_at' => now(),
        ]);

        $list = WorkOrderPackingList::palletData($pallet->fresh());

        $this->assertTrue($list['has_packing']);
        $this->assertSame([], $list['boxes']);
        $this->assertSame(['LBL-LOOSE'], array_column($list['loose_parts'], 'label_id'));
        $this->assertSame('MDF', $list['loose_parts'][0]['material']);
        $this->assertSame(1, $list['totals']['parts']);
    }

    public function test_an_empty_pallet_has_no_packing_list(): void
    {
        $wo = $this->workOrder();
        $pallet = Pallet::create([
            'work_order_id' => $wo->id,
            'kind' => Pallet::KIND_LOOSE,
            'status' => 'open',
            'qty' => 0,
        ]);

        $this->assertFalse(WorkOrderPackingList::palletData($pallet->fresh())['has_packing']);

        $this->actingAs($this->admin)
            ->get(route('packaging.pallets.packing-list', $pallet))
            ->assertNotFound();
    }

    public function test_the_pallet_packing_list_streams_a_pdf_for_boxes_and_for_loose(): void
    {
        $wo = $this->workOrder();

        $boxesPallet = Pallet::create([
            'work_order_id' => $wo->id,
            'kind' => Pallet::KIND_BOXES,
            'status' => 'closed',
            'qty' => 1,
        ]);
        $box = $this->box($wo, $boxesPallet);
        $this->part($wo, 'LBL-001', ['packaging_box_id' => $box->id]);

        $loosePallet = Pallet::create([
            'work_order_id' => $wo->id,
            'kind' => Pallet::KIND_LOOSE,
            'status' => 'closed',
            'qty' => 1,
        ]);
        $loose = $this->part($wo, 'LBL-002');
        PackagingScanLog::create([
            'work_order_id' => $wo->id,
            'pallet_id' => $loosePallet->id,
            'ean' => $loose->ean,
            'scanned_at' => now(),
        ]);

        $this->get(route('packaging.pallets.packing-list', $boxesPallet))->assertRedirect('/login');

        foreach ([$boxesPallet, $loosePallet] as $pallet) {
            $this->actingAs($this->admin)
                ->get(route('packaging.pallets.packing-list', $pallet))
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf');
        }
    }
}
