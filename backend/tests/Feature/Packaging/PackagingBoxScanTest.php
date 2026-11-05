<?php

namespace Tests\Feature\Packaging;

use App\Models\Pallet;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use App\Services\Packaging\PackagingBoxService;
use App\Services\Packaging\PackagingScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PackagingBoxScanTest extends TestCase
{
    use RefreshDatabase;

    private PackagingBoxService $boxes;

    private PackagingScanService $scans;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boxes = app(PackagingBoxService::class);
        $this->scans = app(PackagingScanService::class);
    }

    public function test_a_scan_into_an_open_box_assigns_the_part_and_writes_the_box_on_the_log(): void
    {
        $wo = $this->workOrder();
        $box = $this->boxes->create($wo);
        $code = $this->code($wo);

        $result = $this->scans->scan($code, $wo, null, null, $box);

        $this->assertSame($box->id, $code->fresh()->packaging_box_id);
        $this->assertSame(1, $result['box']->part_count);
        $this->assertSame(1, (int) $wo->fresh()->packed_qty);
        $this->assertSame($box->id, $result['scan_log']->packaging_box_id);
        $this->assertDatabaseHas('packaging_scan_logs', [
            'ean' => $code->ean,
            'packaging_box_id' => $box->id,
        ]);
    }

    public function test_scanning_into_a_reopened_box_on_a_pallet_recomputes_instead_of_double_counting(): void
    {
        $wo = $this->workOrder();
        $box = $this->boxes->create($wo);
        $pallet = Pallet::factory()->create([
            'work_order_id' => $wo->id,
            'kind' => Pallet::KIND_BOXES,
        ]);

        $first = $this->code($wo);
        $this->scans->scan($first, $wo, null, null, $box);
        $box = $this->boxes->close($box);
        $this->boxes->assignPallet($box, $pallet);

        $this->assertSame(1, (int) $pallet->fresh()->qty);

        $box = $this->boxes->reopen($box);
        $second = $this->code($wo);
        $result = $this->scans->scan($second, $wo, null, null, $box);

        $this->assertSame(2, $result['box']->part_count);
        $this->assertSame(2, (int) $pallet->fresh()->qty);
        $this->assertSame($pallet->id, $result['scan_log']->pallet_id);
    }

    public function test_a_scan_into_a_closed_box_is_refused(): void
    {
        $wo = $this->workOrder();
        $box = $this->boxes->create($wo);
        $this->scans->scan($this->code($wo), $wo, null, null, $box);
        $box = $this->boxes->close($box);

        $this->assertAborts(409, fn () => $this->scans->scan($this->code($wo), $wo, null, null, $box));
    }

    public function test_a_box_of_another_work_order_is_refused(): void
    {
        $wo = $this->workOrder();
        $foreign = $this->boxes->create($this->workOrder());

        $this->assertAborts(409, fn () => $this->scans->scan($this->code($wo), $wo, null, null, $foreign));
    }

    public function test_a_boxes_pallet_refuses_a_loose_scan(): void
    {
        $wo = $this->workOrder();
        $pallet = Pallet::factory()->create([
            'work_order_id' => $wo->id,
            'kind' => Pallet::KIND_BOXES,
        ]);

        $this->assertAborts(422, fn () => $this->scans->scan($this->code($wo), $wo, $pallet, null, null));
    }

    public function test_a_box_on_another_pallet_cannot_be_scanned_with_a_different_pallet(): void
    {
        $wo = $this->workOrder();
        $box = $this->boxes->create($wo);
        $this->scans->scan($this->code($wo), $wo, null, null, $box);
        $box = $this->boxes->close($box);

        $one = Pallet::factory()->create(['work_order_id' => $wo->id, 'kind' => Pallet::KIND_BOXES]);
        $two = Pallet::factory()->create(['work_order_id' => $wo->id, 'kind' => Pallet::KIND_BOXES]);
        $this->boxes->assignPallet($box, $one);
        $box = $this->boxes->reopen($box);

        $this->assertAborts(409, fn () => $this->scans->scan($this->code($wo), $wo, $two, null, $box));
    }

    private function workOrder(): WorkOrder
    {
        return WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 50,
        ]);
    }

    private function code(WorkOrder $wo): WorkOrderEan
    {
        return WorkOrderEan::create([
            'work_order_id' => $wo->id,
            'ean' => 'LBL-'.Str::random(10),
            'code_type' => WorkOrderEan::CODE_TYPE_PART_LABEL,
            'state' => WorkOrderEan::STATE_EXPECTED,
        ]);
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
