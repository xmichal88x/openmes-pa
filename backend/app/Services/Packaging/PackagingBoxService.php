<?php

namespace App\Services\Packaging;

use App\Models\PackagingBox;
use App\Models\PackagingDispatch;
use App\Models\Pallet;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use Illuminate\Support\Facades\DB;

/**
 * Cartons at the packing station (R07 §4.2).
 *
 * A box is created ad hoc, holds scanned part labels, and is closed before it
 * goes onto a boxes-mode pallet. Membership lives on work_order_eans.
 * packaging_box_id; part_count is only a cache and is always recomputed from
 * that column. Every operation locks the box, the pallet (when relevant) and
 * the work order, and marks any active dispatch's documents stale.
 */
class PackagingBoxService
{
    public function create(WorkOrder $workOrder, ?float $weightKg = null): PackagingBox
    {
        return DB::transaction(function () use ($workOrder, $weightKg) {
            $workOrder = WorkOrder::whereKey($workOrder->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($workOrder->status, [WorkOrder::STATUS_DONE, WorkOrder::STATUS_IN_PROGRESS], true)) {
                abort(422, __('Work order not in a packable state (current: :status)', ['status' => $workOrder->status]));
            }

            $boxNo = ((int) PackagingBox::where('work_order_id', $workOrder->id)->max('box_no')) + 1;

            $box = PackagingBox::create([
                'work_order_id' => $workOrder->id,
                'box_no' => $boxNo,
                'box_id' => 'BOX-'.$workOrder->order_no.'-'.$boxNo,
                'part_count' => 0,
                'status' => PackagingBox::STATUS_OPEN,
                'weight_kg' => $weightKg,
            ]);

            $this->markDocsStale($workOrder->id);

            return $box->fresh();
        });
    }

    public function close(PackagingBox $box, ?float $weightKg = null): PackagingBox
    {
        return DB::transaction(function () use ($box, $weightKg) {
            $box = $this->lockBox($box);
            $this->lockWorkOrder($box->work_order_id);

            if (! $box->isOpen()) {
                abort(409, __('Box :id is not open.', ['id' => $box->box_id]));
            }

            $count = $this->recomputePartCount($box);
            if ($count < 1) {
                abort(422, __('Box :id is empty - add a part before closing it.', ['id' => $box->box_id]));
            }

            $box->forceFill([
                'status' => PackagingBox::STATUS_CLOSED,
                'closed_at' => now(),
                'weight_kg' => $weightKg ?? $box->weight_kg,
                'part_count' => $count,
            ])->save();

            $this->syncPalletForBox($box);
            $this->markDocsStale($box->work_order_id);

            return $box->fresh();
        });
    }

    /**
     * A closed box is reopened only before any dispatch was created for the
     * order. It may stay on its pallet: the next scan recomputes the pallet's
     * quantity instead of incrementing it again.
     */
    public function reopen(PackagingBox $box): PackagingBox
    {
        return DB::transaction(function () use ($box) {
            $box = $this->lockBox($box);
            $this->lockWorkOrder($box->work_order_id);

            if (! $box->isClosed()) {
                abort(409, __('Box :id is not closed.', ['id' => $box->box_id]));
            }

            $dispatched = PackagingDispatch::where('work_order_id', $box->work_order_id)
                ->where('status', '!=', PackagingDispatch::STATUS_CANCELLED)
                ->exists();

            if ($dispatched) {
                abort(409, __('Box :id cannot be reopened after a dispatch was created.', ['id' => $box->box_id]));
            }

            $box->forceFill([
                'status' => PackagingBox::STATUS_OPEN,
                'closed_at' => null,
                'weight_kg' => null,
            ])->save();

            $this->syncPalletForBox($box);
            $this->markDocsStale($box->work_order_id);

            return $box->fresh();
        });
    }

    /**
     * Move a scanned part label into this box. Assigning the same code again is
     * a no-op; assigning it from another box moves it and fixes both counts.
     */
    public function assignCode(PackagingBox $box, WorkOrderEan $code): PackagingBox
    {
        return DB::transaction(function () use ($box, $code) {
            $box = $this->lockBox($box);
            $code = WorkOrderEan::whereKey($code->getKey())->lockForUpdate()->firstOrFail();
            $this->lockWorkOrder($box->work_order_id);

            if (! $box->isOpen()) {
                abort(409, __('Box :id is closed.', ['id' => $box->box_id]));
            }

            if ((int) $code->work_order_id !== (int) $box->work_order_id) {
                abort(409, __('Part :code belongs to a different work order.', ['code' => $code->ean]));
            }

            if ($code->state !== WorkOrderEan::STATE_SCANNED) {
                abort(422, __('Only scanned parts can be assigned to a box.'));
            }

            if ((int) $code->packaging_box_id === (int) $box->id) {
                return $box->fresh();
            }

            $previousBoxId = $code->packaging_box_id;

            $code->forceFill(['packaging_box_id' => $box->id])->save();
            $this->recomputePartCount($box);

            if ($previousBoxId) {
                $previous = PackagingBox::whereKey($previousBoxId)->lockForUpdate()->first();
                if ($previous) {
                    $this->recomputePartCount($previous);
                    $this->syncPalletForBox($previous);
                }
            }

            $this->syncPalletForBox($box);
            $this->markDocsStale($box->work_order_id);

            return $box->fresh();
        });
    }

    /**
     * Put a closed box on a boxes-mode pallet. The pallet's qty is recomputed
     * as the sum of its boxes, so a repeated call cannot double-count.
     */
    public function assignPallet(PackagingBox $box, Pallet $pallet): PackagingBox
    {
        return DB::transaction(function () use ($box, $pallet) {
            $box = $this->lockBox($box);
            $pallet = Pallet::whereKey($pallet->getKey())->lockForUpdate()->firstOrFail();
            $this->lockWorkOrder($box->work_order_id);

            if ($box->pallet_id === $pallet->id) {
                return $box->fresh();
            }

            if (! $box->isClosed()) {
                abort(422, __('Only a closed box can go on a pallet.'));
            }

            if (! $pallet->isOpen()) {
                abort(422, __('Pallet :no is not open.', ['no' => $pallet->pallet_no]));
            }

            if ((int) $pallet->work_order_id !== (int) $box->work_order_id) {
                abort(409, __('Box :id belongs to a different work order than pallet :no.', [
                    'id' => $box->box_id,
                    'no' => $pallet->pallet_no,
                ]));
            }

            if (! $pallet->isBoxesMode()) {
                abort(422, __('Pallet :no does not accept boxes (loose mode).', ['no' => $pallet->pallet_no]));
            }

            if ($box->pallet_id !== null) {
                abort(409, __('Box :id is already on pallet :no - unassign it first.', [
                    'id' => $box->box_id,
                    'no' => $box->pallet?->pallet_no,
                ]));
            }

            if ($this->palletHasLooseParts($pallet)) {
                abort(409, __('Pallet :no already holds loose parts - boxes cannot be mixed in.', ['no' => $pallet->pallet_no]));
            }

            $box->forceFill(['pallet_id' => $pallet->id])->save();
            $this->syncPalletQty($pallet);
            $this->markDocsStale($box->work_order_id);

            return $box->fresh();
        });
    }

    public function unassignPallet(PackagingBox $box): PackagingBox
    {
        return DB::transaction(function () use ($box) {
            $box = $this->lockBox($box);

            if ($box->pallet_id === null) {
                return $box->fresh();
            }

            $pallet = Pallet::whereKey($box->pallet_id)->lockForUpdate()->first();
            $box->forceFill(['pallet_id' => null])->save();

            if ($pallet) {
                $this->syncPalletQty($pallet);
            }

            $this->markDocsStale($box->work_order_id);

            return $box->fresh();
        });
    }

    /** Only an open, empty box may be physically removed. */
    public function delete(PackagingBox $box): void
    {
        DB::transaction(function () use ($box) {
            $box = $this->lockBox($box);

            if (! $box->isOpen()) {
                abort(409, __('Only an open box can be deleted.', ['id' => $box->box_id]));
            }

            if ($box->eans()->exists()) {
                abort(422, __('Box :id is not empty - remove its parts first.', ['id' => $box->box_id]));
            }

            if ($box->pallet_id !== null) {
                abort(422, __('Take box :id off its pallet first.', ['id' => $box->box_id]));
            }

            $workOrderId = $box->work_order_id;
            $box->delete();

            $this->markDocsStale($workOrderId);
        });
    }

    /** Recompute the cached part count from the label rows. */
    public function recomputePartCount(PackagingBox $box): int
    {
        $count = (int) $box->eans()->count();

        if ((int) $box->part_count !== $count) {
            $box->forceFill(['part_count' => $count])->saveQuietly();
        }

        $box->part_count = $count;

        return $count;
    }

    /** A boxes pallet's qty is the sum of the part counts of its boxes. */
    public function syncPalletQty(Pallet $pallet): int
    {
        $qty = (int) PackagingBox::where('pallet_id', $pallet->id)->sum('part_count');

        if ((int) $pallet->qty !== $qty) {
            $pallet->forceFill(['qty' => $qty])->saveQuietly();
        }

        $pallet->qty = $qty;

        return $qty;
    }

    /** Flag the documents of an active dispatch as stale after any box move. */
    public function markDocsStale(int $workOrderId): void
    {
        PackagingDispatch::where('work_order_id', $workOrderId)
            ->whereIn('status', PackagingDispatch::ACTIVE_STATUSES)
            ->update(['docs_stale' => true]);
    }

    private function lockBox(PackagingBox $box): PackagingBox
    {
        return PackagingBox::whereKey($box->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockWorkOrder(int $workOrderId): WorkOrder
    {
        return WorkOrder::whereKey($workOrderId)->lockForUpdate()->firstOrFail();
    }

    private function syncPalletForBox(PackagingBox $box): void
    {
        if ($box->pallet_id === null) {
            return;
        }

        $pallet = Pallet::whereKey($box->pallet_id)->lockForUpdate()->first();

        if ($pallet) {
            $this->syncPalletQty($pallet);
        }
    }

    private function palletHasLooseParts(Pallet $pallet): bool
    {
        return $pallet->scanLogs()->whereNull('packaging_box_id')->exists();
    }
}
