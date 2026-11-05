<?php

namespace Tests\Feature\Packaging;

use App\Enums\PalletStatus;
use App\Models\PackagingBox;
use App\Models\PackagingDispatch;
use App\Models\PackagingScanLog;
use App\Models\Pallet;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use App\Services\Packaging\PackagingBoxService;
use App\Services\Packaging\PackagingDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PackagingDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    private PackagingBoxService $boxes;

    private PackagingDispatchService $dispatches;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boxes = app(PackagingBoxService::class);
        $this->dispatches = app(PackagingDispatchService::class);
        config()->set('packaging.dispatch_weight_tolerance_pct', 2.0);
    }

    public function test_boxes_dispatch_snapshots_the_order_and_manifests(): void
    {
        $wo = $this->workOrder(['customer_order_no' => 'WOO-555']);
        $one = $this->packedBox($wo, parts: 2, weight: 2.5);
        $two = $this->packedBox($wo, parts: 3, weight: 3.5);

        $dispatch = $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$one->box_id, $two->box_id],
            'qc_override' => true,
            'reason' => 'test override',
            'weight_kg' => 6.05,
            'carrier' => 'DHL',
        ]);

        $this->assertSame(PackagingDispatch::STATUS_PENDING, $dispatch->status);
        $this->assertSame('WOO-555', $dispatch->customer_order_no);
        $this->assertSame([$one->box_id, $two->box_id], $dispatch->scanned_boxes);
        $this->assertSame([], $dispatch->scanned_pallets);
        $this->assertCount(2, $dispatch->box_manifest);
        $this->assertSame(5, array_sum(array_column($dispatch->box_manifest, 'part_count')));
        $this->assertSame('6.000', (string) $dispatch->expected_weight_kg);
        $this->assertSame('6.050', (string) $dispatch->weight_kg);
        $this->assertSame(0.833, (float) $dispatch->weight_deviation_pct);
        $this->assertSame('DHL', $dispatch->carrier);
        $this->assertTrue($dispatch->docs_stale);
    }

    public function test_exactly_one_of_boxes_or_pallets_is_required(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);

        $this->assertAborts(422, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => 'test override',
            'scanned_pallets' => ['PAL-000001'],
        ]));

        $this->assertAborts(422, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [],
        ]));
    }

    public function test_an_open_box_blocks_dispatch(): void
    {
        $wo = $this->workOrder();
        $box = $this->boxes->create($wo);
        $this->boxes->assignCode($box, $this->code($wo, WorkOrderEan::STATE_SCANNED));

        $this->assertAborts(422, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$box->id],
            'qc_override' => true,
            'reason' => 'test override',
        ]));
    }

    public function test_every_closed_box_must_be_covered(): void
    {
        $wo = $this->workOrder();
        $one = $this->packedBox($wo);
        $this->packedBox($wo);

        $this->assertAborts(422, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$one->box_id],
            'qc_override' => true,
            'reason' => 'test override',
        ]));
    }

    public function test_every_part_must_be_in_a_covered_closed_box(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);
        $this->code($wo, WorkOrderEan::STATE_EXPECTED);

        $this->assertAborts(422, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => 'test override',
        ]));
    }

    public function test_direct_box_dispatch_without_qc_override_is_refused(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);

        $this->assertAborts(409, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$box->box_id],
        ]));
    }

    public function test_direct_box_dispatch_requires_a_reason_with_the_override(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);

        $this->assertAborts(409, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => '   ',
        ]));
    }

    public function test_direct_box_dispatch_with_qc_override_and_reason_is_recorded(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);

        $dispatch = $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => ' customer collects cartons without a pallet ',
        ]);

        $this->assertSame(PackagingDispatch::STATUS_PENDING, $dispatch->status);
        $this->assertSame('customer collects cartons without a pallet', $dispatch->reason);
        $this->assertSame([$box->box_id], $dispatch->scanned_boxes);
    }

    public function test_the_same_idempotency_key_returns_the_same_dispatch(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);
        $key = (string) Str::uuid();

        $first = $this->dispatches->create($wo, [
            'idempotency_key' => $key,
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => 'test override',
        ]);
        $second = $this->dispatches->create($wo, [
            'idempotency_key' => $key,
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => 'test override',
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PackagingDispatch::where('work_order_id', $wo->id)->count());
    }

    public function test_a_second_active_dispatch_for_the_same_order_is_refused(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);

        $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => 'test override',
        ]);

        $this->assertAborts(409, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => 'test override',
        ]));
    }

    public function test_weight_outside_tolerance_is_refused(): void
    {
        $wo = $this->workOrder();
        $one = $this->packedBox($wo, weight: 2.0);
        $two = $this->packedBox($wo, weight: 3.0);

        $this->assertAborts(422, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$one->box_id, $two->box_id],
            'qc_override' => true,
            'reason' => 'test override',
            'weight_kg' => 7.0,
        ]));
    }

    public function test_weight_is_required_when_box_weights_are_known(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo, weight: 2.0);

        $this->assertAborts(422, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => 'test override',
        ]));
    }

    public function test_pallet_dispatch_requires_closed_boxes_pallets_with_passed_quality(): void
    {
        $wo = $this->workOrder();
        $pallet = $this->boxesPallet($wo);
        $this->boxes->assignPallet($this->packedBox($wo, parts: 2, weight: 1.0), $pallet);
        $this->closePallet($pallet);

        $dispatch = $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_pallets' => [$pallet->pallet_no],
            'weight_kg' => 1.0,
        ]);

        $this->assertSame([$pallet->pallet_no], $dispatch->scanned_pallets);
        $this->assertSame([], $dispatch->scanned_boxes);
        $this->assertCount(1, $dispatch->pallet_manifest);
        $this->assertSame(2, $dispatch->pallet_manifest[0]['boxes'][0]['part_count']);
    }

    public function test_a_loose_pallet_with_all_scanned_parts_can_be_dispatched(): void
    {
        $wo = $this->workOrder();
        $pallet = $this->loosePallet($wo, parts: 3);

        $dispatch = $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_pallets' => [$pallet->pallet_no],
        ]);

        $this->assertSame([$pallet->pallet_no], $dispatch->scanned_pallets);
        $this->assertSame([], $dispatch->scanned_boxes);
        $this->assertCount(1, $dispatch->pallet_manifest);
        $this->assertSame(Pallet::KIND_LOOSE, $dispatch->pallet_manifest[0]['kind']);
        $this->assertSame(3, $dispatch->pallet_manifest[0]['qty']);
        $this->assertSame([], $dispatch->pallet_manifest[0]['boxes']);
    }

    public function test_a_loose_pallet_missing_a_scanned_part_cannot_be_dispatched(): void
    {
        $wo = $this->workOrder();
        $pallet = $this->loosePallet($wo, parts: 2);
        $this->code($wo, WorkOrderEan::STATE_SCANNED);

        $this->assertAborts(422, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_pallets' => [$pallet->pallet_no],
        ]));
    }

    public function test_a_loose_pallet_that_did_not_pass_quality_cannot_be_dispatched(): void
    {
        $wo = $this->workOrder();
        $pallet = $this->loosePallet($wo, parts: 2, quality: Pallet::QUALITY_FAIL);

        $this->assertAborts(422, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_pallets' => [$pallet->pallet_no],
        ]));
    }

    public function test_a_loose_pallet_with_qty_out_of_sync_with_its_scan_logs_cannot_be_dispatched(): void
    {
        $wo = $this->workOrder();
        $pallet = $this->loosePallet($wo, parts: 2, qty: 3);

        $this->assertAborts(422, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_pallets' => [$pallet->pallet_no],
        ]));
    }

    public function test_a_loose_pallet_holding_boxes_cannot_be_dispatched(): void
    {
        $wo = $this->workOrder();
        $pallet = $this->loosePallet($wo, parts: 1);
        PackagingBox::create([
            'work_order_id' => $wo->id,
            'pallet_id' => $pallet->id,
            'box_no' => 1,
            'box_id' => 'BOX-'.$wo->order_no.'-1',
            'part_count' => 0,
            'status' => PackagingBox::STATUS_CLOSED,
        ]);

        $this->assertAborts(422, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_pallets' => [$pallet->pallet_no],
        ]));
    }

    public function test_an_open_loose_pallet_cannot_be_dispatched(): void
    {
        $wo = $this->workOrder();
        $pallet = $this->loosePallet($wo, parts: 2, status: PalletStatus::Open);

        $this->assertAborts(422, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_pallets' => [$pallet->pallet_no],
        ]));
    }

    public function test_boxes_and_loose_pallets_cannot_be_mixed_in_one_dispatch(): void
    {
        $wo = $this->workOrder();
        $boxesPallet = $this->boxesPallet($wo);
        $this->boxes->assignPallet($this->packedBox($wo), $boxesPallet);
        $this->closePallet($boxesPallet);
        $loose = $this->loosePallet($wo, parts: 1);

        $this->assertAborts(422, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_pallets' => [$boxesPallet->pallet_no, $loose->pallet_no],
        ]));
    }

    public function test_a_pallet_without_passed_quality_cannot_be_dispatched(): void
    {
        $wo = $this->workOrder();
        $pallet = $this->boxesPallet($wo, quality: Pallet::QUALITY_PENDING);
        $this->boxes->assignPallet($this->packedBox($wo), $pallet);
        $this->closePallet($pallet);

        $this->assertAborts(422, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_pallets' => [$pallet->pallet_no],
        ]));
    }

    public function test_an_open_pallet_cannot_be_dispatched(): void
    {
        $wo = $this->workOrder();
        $pallet = $this->boxesPallet($wo);
        $this->boxes->assignPallet($this->packedBox($wo), $pallet);

        $this->assertAborts(422, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_pallets' => [$pallet->pallet_no],
        ]));
    }

    public function test_a_box_off_the_dispatched_pallet_blocks_it(): void
    {
        $wo = $this->workOrder();
        $pallet = $this->boxesPallet($wo);
        $this->boxes->assignPallet($this->packedBox($wo), $pallet);
        $this->packedBox($wo);
        $this->closePallet($pallet);

        $this->assertAborts(422, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_pallets' => [$pallet->pallet_no],
        ]));
    }

    public function test_a_pallet_with_loose_scans_cannot_be_dispatched(): void
    {
        $wo = $this->workOrder();
        $pallet = $this->boxesPallet($wo);
        $this->boxes->assignPallet($this->packedBox($wo), $pallet);
        $this->closePallet($pallet);

        PackagingScanLog::create([
            'work_order_id' => $wo->id,
            'pallet_id' => $pallet->id,
            'ean' => 'LOOSE-1',
            'scanned_at' => now(),
        ]);

        $this->assertAborts(422, fn () => $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_pallets' => [$pallet->pallet_no],
        ]));
    }

    public function test_only_a_pending_dispatch_can_be_cancelled(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);
        $dispatch = $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => 'test override',
        ]);

        $dispatch = $this->dispatches->cancel($dispatch, null, 'Changed plan');

        $this->assertSame(PackagingDispatch::STATUS_CANCELLED, $dispatch->status);
        $this->assertSame('Changed plan', $dispatch->cancel_reason);
        $this->assertNotNull($dispatch->cancelled_at);
        $this->assertNotNull($dispatch->resolved_at);

        $this->assertAborts(409, fn () => $this->dispatches->cancel($dispatch));
    }

    public function test_a_cancelled_dispatch_does_not_block_a_new_intent(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);
        $first = $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => 'test override',
        ]);
        $this->dispatches->cancel($first);

        $second = $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => 'test override',
        ]);

        $this->assertSame(PackagingDispatch::STATUS_PENDING, $second->status);
        $this->assertSame(2, PackagingDispatch::where('work_order_id', $wo->id)->count());
    }

    public function test_patch_claims_with_a_lease_and_guards_the_result_with_the_token(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);
        $dispatch = $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => 'test override',
        ]);

        $claimed = $this->dispatches->patch($dispatch, [
            'status' => PackagingDispatch::STATUS_PROCESSING,
            'lock_token' => 'worker-a',
            'lease_seconds' => 120,
        ]);

        $this->assertSame(PackagingDispatch::STATUS_PROCESSING, $claimed->status);
        $this->assertSame('worker-a', $claimed->lock_token);
        $this->assertSame(1, $claimed->attempt_count);
        $this->assertNotNull($claimed->locked_until);

        $this->assertAborts(409, fn () => $this->dispatches->patch($dispatch, [
            'status' => PackagingDispatch::STATUS_PROCESSING,
            'lock_token' => 'worker-b',
        ]));

        $this->assertAborts(409, fn () => $this->dispatches->patch($dispatch, [
            'status' => PackagingDispatch::STATUS_DONE,
            'lock_token' => 'worker-b',
        ]));

        $done = $this->dispatches->patch($dispatch, [
            'status' => PackagingDispatch::STATUS_DONE,
            'lock_token' => 'worker-a',
            'shipment_id' => 987,
            'shipment_status' => 'validated',
            'boxes_hash' => str_repeat('a', 64),
        ]);

        $this->assertSame(PackagingDispatch::STATUS_DONE, $done->status);
        $this->assertSame(987, $done->shipment_id);
        $this->assertSame('validated', $done->shipment_status);
        $this->assertNull($done->lock_token);
        $this->assertNotNull($done->resolved_at);
        $this->assertFalse($done->docs_stale);
    }

    public function test_list_reclaims_a_processing_row_with_an_expired_lease(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);
        $dispatch = $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => 'test override',
        ]);

        $this->dispatches->patch($dispatch, [
            'status' => PackagingDispatch::STATUS_PROCESSING,
            'lock_token' => 'worker-a',
        ]);

        DB::table('packaging_dispatches')->where('id', $dispatch->id)->update([
            'locked_until' => now()->subMinute(),
        ]);

        $page = $this->dispatches->list(['work_order_id' => $wo->id]);

        $this->assertSame(PackagingDispatch::STATUS_PENDING, $page->items()[0]->status);
        $this->assertNull($page->items()[0]->lock_token);
    }

    public function test_external_close_is_idempotent(): void
    {
        $wo = $this->workOrder();
        $payload = [
            'work_order_id' => $wo->id,
            'idempotency_key' => (string) Str::uuid(),
            'shipment_id' => 321,
            'shipment_status' => 'closed',
        ];

        $first = $this->dispatches->externalClose($payload);
        $second = $this->dispatches->externalClose($payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(PackagingDispatch::STATUS_DONE, $first->status);
        $this->assertSame(PackagingDispatch::SOURCE_EXTERNAL, $first->source);
        $this->assertSame(1, PackagingDispatch::where('work_order_id', $wo->id)->count());
    }

    public function test_external_close_is_refused_while_an_operator_dispatch_is_active(): void
    {
        $wo = $this->workOrder();
        $box = $this->packedBox($wo);
        $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_boxes' => [$box->box_id],
            'qc_override' => true,
            'reason' => 'test override',
        ]);

        $this->assertAborts(409, fn () => $this->dispatches->externalClose([
            'work_order_id' => $wo->id,
            'idempotency_key' => (string) Str::uuid(),
        ]));
    }

    public function test_done_dispatch_marks_its_pallets_shipped(): void
    {
        $wo = $this->workOrder();
        $pallet = $this->boxesPallet($wo);
        $this->boxes->assignPallet($this->packedBox($wo, parts: 2, weight: 1.0), $pallet);
        $this->closePallet($pallet);
        $other = $this->boxesPallet($wo);
        $this->closePallet($other);

        $dispatch = $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_pallets' => [$pallet->pallet_no],
            'weight_kg' => 1.0,
        ]);

        $this->resolve($dispatch, PackagingDispatch::STATUS_DONE);

        $shipped = $pallet->fresh();
        $this->assertSame(PalletStatus::Shipped, $shipped->status);
        $this->assertNotNull($shipped->shipped_at);

        $untouched = $other->fresh();
        $this->assertSame(PalletStatus::Closed, $untouched->status);
        $this->assertNull($untouched->shipped_at);
    }

    public function test_done_dispatch_marks_a_loose_pallet_shipped(): void
    {
        $wo = $this->workOrder();
        $pallet = $this->loosePallet($wo, parts: 2);

        $dispatch = $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_pallets' => [$pallet->pallet_no],
        ]);

        $this->resolve($dispatch, PackagingDispatch::STATUS_DONE);

        $shipped = $pallet->fresh();
        $this->assertSame(Pallet::KIND_LOOSE, $shipped->kind);
        $this->assertSame(PalletStatus::Shipped, $shipped->status);
        $this->assertNotNull($shipped->shipped_at);
    }

    public function test_shipped_at_is_not_rewritten_on_a_second_save(): void
    {
        $wo = $this->workOrder();
        $pallet = $this->boxesPallet($wo);
        $this->boxes->assignPallet($this->packedBox($wo, parts: 1, weight: 1.0), $pallet);
        $this->closePallet($pallet);

        $dispatch = $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_pallets' => [$pallet->pallet_no],
            'weight_kg' => 1.0,
        ]);

        $this->resolve($dispatch, PackagingDispatch::STATUS_DONE);

        $first = $pallet->fresh()->shipped_at;
        $this->assertNotNull($first);

        $pallet->fresh()->update(['destination' => 'ramp']);
        $this->assertTrue($first->equalTo($pallet->fresh()->shipped_at));
    }

    public function test_skipped_dispatch_marks_its_pallets_shipped(): void
    {
        $wo = $this->workOrder();
        $pallet = $this->boxesPallet($wo);
        $this->boxes->assignPallet($this->packedBox($wo, parts: 2, weight: 1.0), $pallet);
        $this->closePallet($pallet);

        $dispatch = $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_pallets' => [$pallet->pallet_no],
            'weight_kg' => 1.0,
        ]);

        $this->resolve($dispatch, PackagingDispatch::STATUS_SKIPPED);

        $shipped = $pallet->fresh();
        $this->assertSame(PalletStatus::Shipped, $shipped->status);
        $this->assertNotNull($shipped->shipped_at);
    }

    public function test_external_close_marks_closed_passed_pallets_shipped(): void
    {
        $wo = $this->workOrder();
        $closed = Pallet::factory()->closed()->create([
            'work_order_id' => $wo->id,
            'kind' => Pallet::KIND_BOXES,
            'quality_status' => Pallet::QUALITY_PASS,
        ]);
        $open = Pallet::factory()->create([
            'work_order_id' => $wo->id,
            'kind' => Pallet::KIND_BOXES,
            'quality_status' => Pallet::QUALITY_PASS,
        ]);

        $this->dispatches->externalClose([
            'work_order_id' => $wo->id,
            'idempotency_key' => (string) Str::uuid(),
            'shipment_id' => 555,
            'shipment_status' => 'closed',
        ]);

        $this->assertSame(PalletStatus::Shipped, $closed->fresh()->status);
        $this->assertNotNull($closed->fresh()->shipped_at);
        $this->assertSame(PalletStatus::Open, $open->fresh()->status);
        $this->assertNull($open->fresh()->shipped_at);
    }

    public function test_done_dispatch_does_not_touch_other_work_order_pallets(): void
    {
        $wo = $this->workOrder();
        $pallet = $this->boxesPallet($wo);
        $this->boxes->assignPallet($this->packedBox($wo, parts: 1, weight: 1.0), $pallet);
        $this->closePallet($pallet);

        $otherWo = $this->workOrder();
        $otherPallet = $this->boxesPallet($otherWo);
        $this->closePallet($otherPallet);

        $dispatch = $this->dispatches->create($wo, [
            'idempotency_key' => (string) Str::uuid(),
            'scanned_pallets' => [$pallet->pallet_no],
            'weight_kg' => 1.0,
        ]);

        $this->resolve($dispatch, PackagingDispatch::STATUS_DONE);

        $this->assertSame(PalletStatus::Shipped, $pallet->fresh()->status);
        $this->assertSame(PalletStatus::Closed, $otherPallet->fresh()->status);
        $this->assertNull($otherPallet->fresh()->shipped_at);
    }

    private function resolve(PackagingDispatch $dispatch, string $status): PackagingDispatch
    {
        $this->dispatches->patch($dispatch, [
            'status' => PackagingDispatch::STATUS_PROCESSING,
            'lock_token' => 'worker-a',
        ]);

        return $this->dispatches->patch($dispatch, [
            'status' => $status,
            'lock_token' => 'worker-a',
        ]);
    }

    private function workOrder(array $overrides = []): WorkOrder
    {
        return WorkOrder::factory()->create(array_merge([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 50,
        ], $overrides));
    }

    private function code(WorkOrder $wo, string $state): WorkOrderEan
    {
        return WorkOrderEan::create([
            'work_order_id' => $wo->id,
            'ean' => 'LBL-'.Str::random(10),
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

    private function boxesPallet(WorkOrder $wo, string $quality = Pallet::QUALITY_PASS): Pallet
    {
        return Pallet::factory()->create([
            'work_order_id' => $wo->id,
            'kind' => Pallet::KIND_BOXES,
            'quality_status' => $quality,
        ]);
    }

    /** A loose pallet whose qty is backed by real scanned-part logs (and matching EAN states). */
    private function loosePallet(
        WorkOrder $wo,
        int $parts = 1,
        string $quality = Pallet::QUALITY_PASS,
        PalletStatus $status = PalletStatus::Closed,
        ?int $qty = null,
    ): Pallet {
        $pallet = Pallet::factory()->create([
            'work_order_id' => $wo->id,
            'kind' => Pallet::KIND_LOOSE,
            'status' => $status->value,
            'quality_status' => $quality,
            'qty' => $qty ?? $parts,
        ]);

        for ($i = 0; $i < $parts; $i++) {
            $code = $this->code($wo, WorkOrderEan::STATE_SCANNED);
            PackagingScanLog::create([
                'work_order_id' => $wo->id,
                'pallet_id' => $pallet->id,
                'ean' => $code->ean,
                'scanned_at' => now(),
            ]);
        }

        return $pallet;
    }

    private function closePallet(Pallet $pallet): void
    {
        $pallet->update(['status' => PalletStatus::Closed->value]);
        $pallet->refresh();
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
