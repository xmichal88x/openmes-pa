<?php

namespace App\Services\Packaging;

use App\Enums\PalletStatus;
use App\Models\AuditLog;
use App\Models\PackagingDispatch;
use App\Models\Pallet;
use App\Models\PalletQualityDecision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Manual pallet quality decision made at the packing station before dispatch.
 *
 * The decision is append-only and becomes the authoritative source for the
 * pallet's quality_status until a newer decision is recorded.
 */
class PalletQualityService
{
    public function record(Pallet $pallet, string $status, ?string $reason, ?User $user): Pallet
    {
        return DB::transaction(function () use ($pallet, $status, $reason, $user) {
            $pallet = Pallet::whereKey($pallet->getKey())->lockForUpdate()->firstOrFail();

            $this->assertBeforeDispatch($pallet);

            $before = $pallet->quality_status;
            $reason = $reason !== null ? trim($reason) : null;
            if ($reason === '') {
                $reason = null;
            }

            PalletQualityDecision::create([
                'pallet_id' => $pallet->id,
                'status' => $status,
                'reason' => $reason,
                'decided_by_id' => $user?->id,
                'decided_at' => now(),
            ]);

            $pallet->forceFill(['quality_status' => $status])->save();

            AuditLog::create([
                'user_id' => $user?->id,
                'entity_type' => Pallet::class,
                'entity_id' => $pallet->id,
                'action' => 'quality_status_changed',
                'before_state' => ['quality_status' => $before],
                'after_state' => [
                    'quality_status' => $status,
                    'reason' => $reason,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return $pallet->fresh();
        });
    }

    private function assertBeforeDispatch(Pallet $pallet): void
    {
        if ($pallet->status === PalletStatus::Shipped) {
            abort(409, __('Pallet :no has already been dispatched.', ['no' => $pallet->pallet_no]));
        }

        $dispatched = PackagingDispatch::where('work_order_id', $pallet->work_order_id)
            ->whereIn('status', [
                PackagingDispatch::STATUS_PENDING,
                PackagingDispatch::STATUS_PROCESSING,
                PackagingDispatch::STATUS_DONE,
                PackagingDispatch::STATUS_SKIPPED,
            ])
            ->get(['id', 'status', 'scanned_pallets']);

        foreach ($dispatched as $dispatch) {
            if (in_array($pallet->pallet_no, (array) $dispatch->scanned_pallets, true)) {
                abort(409, __('Pallet :no is already part of dispatch #:id.', [
                    'no' => $pallet->pallet_no,
                    'id' => $dispatch->id,
                ]));
            }
        }
    }
}
