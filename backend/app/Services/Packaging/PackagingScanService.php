<?php

namespace App\Services\Packaging;

use App\Models\PackagingBox;
use App\Models\PackagingScanLog;
use App\Models\Pallet;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use App\Sync\CollectionBroadcaster;
use Illuminate\Support\Facades\DB;

/**
 * Single write-path for packaging scans.
 *
 * Both the operator station (web) and the mobile API share this service so the
 * void / duplicate / packable guards cannot drift apart. The whole scan is
 * serialised on the code and work-order rows: one code = one piece = one scan.
 */
class PackagingScanService
{
    /**
     * Register one scanned piece against its work order.
     *
     * With a box the part label joins that carton; a box already sitting on a
     * boxes-mode pallet recomputes the pallet's quantity from its boxes instead
     * of incrementing it a second time.
     *
     * @return array{work_order: WorkOrder, scan_log: PackagingScanLog, remaining: int, expected_total: int, box: ?PackagingBox}
     */
    public function scan(WorkOrderEan $code, WorkOrder $workOrder, ?Pallet $pallet = null, ?User $user = null, ?PackagingBox $box = null): array
    {
        return DB::transaction(function () use ($code, $workOrder, $pallet, $user, $box) {
            $code = WorkOrderEan::whereKey($code->getKey())->lockForUpdate()->firstOrFail();
            $workOrder = WorkOrder::whereKey($workOrder->getKey())->lockForUpdate()->firstOrFail();
            $box = $box ? PackagingBox::whereKey($box->getKey())->lockForUpdate()->firstOrFail() : null;
            $pallet = $pallet ? Pallet::whereKey($pallet->getKey())->lockForUpdate()->firstOrFail() : null;

            if ($code->state === WorkOrderEan::STATE_VOID) {
                abort(409, __('Code has been voided'));
            }

            if ($this->wasAlreadyScanned($code, $workOrder)) {
                abort(409, __('Code already scanned for this work order'));
            }

            if ($box) {
                if ((int) $box->work_order_id !== (int) $workOrder->id) {
                    abort(409, __('Box :id belongs to a different work order.', ['id' => $box->box_id]));
                }

                if (! $box->isOpen()) {
                    abort(409, __('Box :id is closed.', ['id' => $box->box_id]));
                }

                if ($box->pallet_id !== null) {
                    $boxPallet = Pallet::whereKey($box->pallet_id)->lockForUpdate()->first();

                    if (! $boxPallet || ! $boxPallet->isBoxesMode()) {
                        abort(409, __('Box :id is on a pallet that does not accept boxes.', ['id' => $box->box_id]));
                    }

                    if ($pallet && (int) $pallet->id !== (int) $boxPallet->id) {
                        abort(409, __('Box :id is on another pallet.', ['id' => $box->box_id]));
                    }

                    $pallet = $boxPallet;
                }
            }

            if ($pallet) {
                if (! $pallet->isOpen()) {
                    abort(422, __('Pallet :no is not open.', ['no' => $pallet->pallet_no]));
                }

                if ($pallet->work_order_id && (int) $pallet->work_order_id !== (int) $workOrder->id) {
                    abort(422, __("Piece does not belong to this pallet's work order"));
                }

                if (! $box && $pallet->isBoxesMode()) {
                    abort(422, __('Pallet :no accepts closed boxes only - scan into a box.', ['no' => $pallet->pallet_no]));
                }
            }

            if (! in_array($workOrder->status, [WorkOrder::STATUS_DONE, WorkOrder::STATUS_IN_PROGRESS], true)) {
                abort(422, __('Work order not in a packable state (current: :status)', ['status' => $workOrder->status]));
            }

            $planned = (int) $workOrder->planned_qty;
            if ($planned > 0 && $workOrder->packed_qty >= $planned) {
                abort(422, __('Work order fully packed'));
            }

            $workOrder->increment('packed_qty');
            CollectionBroadcaster::flush($workOrder); // increment() bypasses model events
            $workOrder->refresh();

            $code->update(['state' => WorkOrderEan::STATE_SCANNED]);

            if ($box) {
                // Recomputed from the label rows; a pallet under the box follows.
                $box = app(PackagingBoxService::class)->assignCode($box, $code);
            } elseif ($pallet) {
                $pallet->increment('qty');
                CollectionBroadcaster::flush($pallet); // increment() bypasses model events
                $pallet->refresh();
            }

            $scanLog = PackagingScanLog::create([
                'user_id' => $user?->id,
                'work_order_id' => $workOrder->id,
                'pallet_id' => $pallet?->id,
                'packaging_box_id' => $box?->id,
                'ean' => $code->ean,
                'product_name' => self::productLabel($workOrder),
                'scanned_at' => now(),
            ]);

            $remaining = WorkOrderEan::where('work_order_id', $workOrder->id)
                ->where('state', WorkOrderEan::STATE_EXPECTED)
                ->count();

            $expectedTotal = WorkOrderEan::where('work_order_id', $workOrder->id)
                ->where('state', '!=', WorkOrderEan::STATE_VOID)
                ->count();

            return [
                'work_order' => $workOrder,
                'scan_log' => $scanLog,
                'remaining' => $remaining,
                'expected_total' => $expectedTotal,
                'box' => $box,
            ];
        });
    }

    /**
     * Human readable product label used in scan logs and API messages.
     */
    public static function productLabel(WorkOrder $workOrder): string
    {
        $parts = array_filter([$workOrder->productType?->name, $workOrder->order_no]);

        return implode(' — ', $parts) ?: $workOrder->order_no;
    }

    private function wasAlreadyScanned(WorkOrderEan $code, WorkOrder $workOrder): bool
    {
        if ($code->state === WorkOrderEan::STATE_SCANNED) {
            return true;
        }

        return PackagingScanLog::where('work_order_id', $workOrder->id)
            ->where('ean', $code->ean)
            ->exists();
    }
}
