<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordPalletQualityRequest;
use App\Models\Pallet;
use App\Services\Packaging\PalletQualityService;
use Illuminate\Http\JsonResponse;

class PalletQualityController extends Controller
{
    public function store(
        RecordPalletQualityRequest $request,
        Pallet $pallet,
        PalletQualityService $service,
    ): JsonResponse {
        $this->authorize('dispatch', $pallet->workOrder);

        $pallet = $service->record(
            $pallet,
            (string) $request->validated('status'),
            $request->validated('reason'),
            $request->user(),
        );

        $decision = $pallet->qualityDecisions()
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'data' => [
                'id' => $pallet->id,
                'pallet_no' => $pallet->pallet_no,
                'work_order_id' => $pallet->work_order_id,
                'status' => $pallet->status?->value,
                'quality_status' => $pallet->quality_status,
                'quality_reason' => $decision?->reason,
                'quality_decided_at' => $decision?->decided_at?->toIso8601String(),
            ],
        ]);
    }
}
