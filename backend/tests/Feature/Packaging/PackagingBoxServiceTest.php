<?php

namespace Tests\Feature\Packaging;

use App\Models\PackagingBox;
use App\Models\PackagingDispatch;
use App\Models\PackagingScanLog;
use App\Models\Pallet;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use App\Services\Packaging\PackagingBoxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PackagingBoxServiceTest extends TestCase
{
    use RefreshDatabase;

    private PackagingBoxService $boxes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boxes = app(PackagingBoxService::class);
    }

    public function test_create_numbers_boxes_with_the_work_order_number(): void
    {
        $wo = $this->workOrder();

        $first = $this->boxes->create($wo);
        $second = $this->boxes->create($wo);

        $this->assertSame(1, $first->box_no);
        $this->assertSame('BOX-'.$wo->order_no.'-1', $first->box_id);
        $this->assertSame(2, $second->box_no);
        $this->assertSame('BOX-'.$wo->order_no.'-2', $second->box_id);
        $this->assertSame(PackagingBox::STATUS_OPEN, $first->status);
        $this->assertSame(0, $first->part_count);
    }

    public function test_create_rejects_a_work_order_that_is_not_packable(): void
    {
        $wo = WorkOrder::factory()->create(['status' => WorkOrder::STATUS_PENDING]);

        $this->assertAborts(422, fn () => $this->boxes->create($wo));
    }

    public function test_an_empty_box_cannot_be_closed(): void
    {
        $box = $this->boxes->create($this->workOrder());

        $this->assertAborts(422, fn () => $this->boxes->close($box));
    }

    public function test_close_stores_weight_and_part_count(): void
    {
        $wo = $this->workOrder();
        $box = $this->boxes->create($wo);
        $code = $this->code($wo, WorkOrderEan::STATE_SCANNED);
        $this->boxes->assignCode($box, $code);

        $box = $this->boxes->close($box, 4.25);

        $this->assertSame(PackagingBox::STATUS_CLOSED, $box->status);
        $this->assertSame(1, $box->part_count);
        $this->assertSame('4.250', (string) $box->weight_kg);
        $this->assertNotNull($box->closed_at);
    }

    public function test_reopen_is_refused_after_a_dispatch_exists(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);

        $this->dispatch($wo);

        $this->assertAborts(409, fn () => $this->boxes->reopen($box));
    }

    public function test_reopen_restores_the_open_state_and_clears_the_weight(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo, weight: 2.0);

        $box = $this->boxes->reopen($box);

        $this->assertTrue($box->isOpen());
        $this->assertNull($box->closed_at);
        $this->assertNull($box->weight_kg);
    }

    public function test_assigning_a_scanned_code_to_another_box_moves_it_and_fixes_both_counts(): void
    {
        $wo = $this->workOrder();
        $one = $this->boxes->create($wo);
        $two = $this->boxes->create($wo);
        $code = $this->code($wo, WorkOrderEan::STATE_SCANNED);

        $this->boxes->assignCode($one, $code);
        $this->assertSame(1, $one->fresh()->part_count);

        $this->boxes->assignCode($two, $code);

        $this->assertSame(0, $one->fresh()->part_count);
        $this->assertSame(1, $two->fresh()->part_count);
        $this->assertSame($two->id, $code->fresh()->packaging_box_id);
    }

    public function test_assigning_a_code_of_another_work_order_is_refused(): void
    {
        $box = $this->boxes->create($this->workOrder());
        $foreign = $this->code($this->workOrder(), WorkOrderEan::STATE_SCANNED);

        $this->assertAborts(409, fn () => $this->boxes->assignCode($box, $foreign));
    }

    public function test_only_scanned_codes_can_be_assigned(): void
    {
        $wo = $this->workOrder();
        $box = $this->boxes->create($wo);
        $code = $this->code($wo, WorkOrderEan::STATE_EXPECTED);

        $this->assertAborts(422, fn () => $this->boxes->assignCode($box, $code));
    }

    public function test_assigning_to_a_closed_box_is_refused(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);
        $second = $this->code($wo, WorkOrderEan::STATE_SCANNED, 'SECOND');

        $this->assertAborts(409, fn () => $this->boxes->assignCode($box, $second));
    }

    public function test_only_an_empty_open_box_can_be_deleted(): void
    {
        $wo = $this->workOrder();
        $empty = $this->boxes->create($wo);
        $full = $this->packedBox($wo);

        $this->boxes->delete($empty);
        $this->assertDatabaseMissing('packaging_boxes', ['id' => $empty->id]);

        $this->assertAborts(409, fn () => $this->boxes->delete($full));
    }

    public function test_box_on_a_boxes_pallet_updates_the_pallet_qty_once(): void
    {
        $wo = $this->workOrder();
        $pallet = $this->pallet($wo);
        $one = $this->packedBox($wo, parts: 2);
        $two = $this->packedBox($wo, parts: 3);

        $this->boxes->assignPallet($one, $pallet);
        $this->assertSame(2, $pallet->fresh()->qty);

        $this->boxes->assignPallet($two, $pallet);
        $this->assertSame(5, $pallet->fresh()->qty);

        // Repeating the same assignment must not count the box twice.
        $this->boxes->assignPallet($one, $pallet);
        $this->assertSame(5, $pallet->fresh()->qty);

        $this->boxes->unassignPallet($one);
        $this->assertSame(3, $pallet->fresh()->qty);
    }

    public function test_an_open_box_cannot_go_on_a_pallet(): void
    {
        $wo = $this->workOrder();
        $box = $this->boxes->create($wo);
        $pallet = $this->pallet($wo);

        $this->assertAborts(422, fn () => $this->boxes->assignPallet($box, $pallet));
    }

    public function test_a_loose_pallet_cannot_accept_boxes(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);
        $pallet = Pallet::factory()->create(['work_order_id' => $wo->id, 'kind' => Pallet::KIND_LOOSE]);

        $this->assertAborts(422, fn () => $this->boxes->assignPallet($box, $pallet));
    }

    public function test_a_pallet_that_holds_loose_scans_cannot_accept_boxes(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);
        $pallet = $this->pallet($wo);

        PackagingScanLog::create([
            'work_order_id' => $wo->id,
            'pallet_id' => $pallet->id,
            'ean' => 'LOOSE-1',
            'scanned_at' => now(),
        ]);

        $this->assertAborts(409, fn () => $this->boxes->assignPallet($box, $pallet));
    }

    public function test_a_pallet_of_another_work_order_cannot_accept_the_box(): void
    {
        $box = $this->packedBox($this->workOrder());
        $foreign = $this->pallet($this->workOrder());

        $this->assertAborts(409, fn () => $this->boxes->assignPallet($box, $foreign));
    }

    public function test_box_changes_mark_an_active_dispatch_stale(): void
    {
        $wo = $this->workOrder();
        $box = $this->boxes->create($wo);
        $dispatch = $this->dispatch($wo, ['docs_stale' => false]);

        $code = $this->code($wo, WorkOrderEan::STATE_SCANNED);
        $this->boxes->assignCode($box, $code);

        $this->assertTrue($dispatch->fresh()->docs_stale);
    }

    private function workOrder(): WorkOrder
    {
        return WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 50,
        ]);
    }

    private function code(WorkOrder $wo, string $state, ?string $ean = null): WorkOrderEan
    {
        return WorkOrderEan::create([
            'work_order_id' => $wo->id,
            'ean' => $ean ?? 'LBL-'.Str::random(8),
            'code_type' => WorkOrderEan::CODE_TYPE_PART_LABEL,
            'state' => $state,
        ]);
    }

    private function packedBox(WorkOrder $wo, int $parts = 1, ?float $weight = null): PackagingBox
    {
        $box = $this->boxes->create($wo);

        for ($i = 0; $i < $parts; $i++) {
            $this->boxes->assignCode($box, $this->code($wo, WorkOrderEan::STATE_SCANNED));
        }

        return $this->boxes->close($box, $weight);
    }

    private function pallet(WorkOrder $wo): Pallet
    {
        return Pallet::factory()->create([
            'work_order_id' => $wo->id,
            'kind' => Pallet::KIND_BOXES,
            'quality_status' => Pallet::QUALITY_PASS,
        ]);
    }

    private function dispatch(WorkOrder $wo, array $overrides = []): PackagingDispatch
    {
        return PackagingDispatch::create(array_merge([
            'work_order_id' => $wo->id,
            'order_no' => $wo->order_no,
            'idempotency_key' => (string) Str::uuid(),
            'status' => PackagingDispatch::STATUS_PENDING,
        ], $overrides));
    }

    private function assertAborts(int $status, callable $callback): void
    {
        try {
            $callback();
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode(), $e->getMessage());

            return;
        }

        $this->fail('Expected an HTTP '.$status.' abort.');
    }
}
