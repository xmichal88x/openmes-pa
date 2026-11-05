<?php

namespace Modules\PlPackagingExtras\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;

/**
 * Start a work order from the packing station (PENDING/ACCEPTED → IN_PROGRESS).
 *
 * Idempotent: starting an order already IN_PROGRESS returns 200 with the same
 * payload, so a retried request is never an error.
 */
class WorkOrderStartController extends Controller
{
    public function start(WorkOrder $workOrder): JsonResponse
    {
        // The packing line is governed by the narrow packaging grant; every
        // other line keeps the legacy update policy (no CNC regression).
        if ($workOrder->line?->code === 'PACKING') {
            $this->authorize('managePacking', $workOrder);
        } else {
            $this->authorize('update', $workOrder);
        }

        if ($workOrder->status === WorkOrder::STATUS_IN_PROGRESS) {
            return response()->json([
                'message' => 'Work order already in progress',
                'data' => $workOrder->fresh(['line', 'productType']),
            ]);
        }

        if (! in_array($workOrder->status, [WorkOrder::STATUS_PENDING, WorkOrder::STATUS_ACCEPTED], true)) {
            return response()->json([
                'message' => 'Only PENDING or ACCEPTED work orders can be started.',
            ], 422);
        }

        $workOrder->update(['status' => WorkOrder::STATUS_IN_PROGRESS]);

        return response()->json([
            'message' => 'Work order status set to '.WorkOrder::STATUS_IN_PROGRESS,
            'data' => $workOrder->fresh(['line', 'productType']),
        ]);
    }
}
