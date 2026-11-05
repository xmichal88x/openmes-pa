<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PackagingLineAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelPackagingDispatchRequest;
use App\Http\Requests\CreatePackagingDispatchRequest;
use App\Http\Requests\ExternalClosePackagingDispatchRequest;
use App\Http\Requests\UpdatePackagingDispatchRequest;
use App\Models\PackagingDispatch;
use App\Models\WorkOrder;
use App\Services\Packaging\PackagingDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackagingDispatchController extends Controller
{
    use PackagingLineAccess;

    public function index(Request $request, PackagingDispatchService $service): JsonResponse
    {
        if ($request->integer('work_order_id')) {
            $this->authorize('view', WorkOrder::findOrFail($request->integer('work_order_id')));
        }

        $filters = [
            'work_order_id' => $request->query('work_order_id'),
            'status' => $request->query('status'),
            'order_no' => $request->query('order_no'),
            'per_page' => $request->query('per_page', 50),
            'line_ids' => $this->operatorLineIds($request),
        ];

        $page = $service->list($filters);

        return response()->json([
            'data' => collect($page->items())->map(fn (PackagingDispatch $dispatch) => $this->present($dispatch))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function store(CreatePackagingDispatchRequest $request, PackagingDispatchService $service): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($request->integer('work_order_id'));
        $this->authorize('dispatch', $workOrder);
        $this->assertWorkOrderLineAccess($request, $workOrder);

        $dispatch = $service->create($workOrder, $request->validated(), $request->user());

        return response()->json(['data' => $this->present($dispatch)], 201);
    }

    public function cancel(CancelPackagingDispatchRequest $request, PackagingDispatch $dispatch, PackagingDispatchService $service): JsonResponse
    {
        $this->authorize('dispatch', $dispatch->workOrder);
        $this->assertDispatchLineAccess($request, $dispatch);

        $dispatch = $service->cancel($dispatch, $request->user(), $request->input('reason'));

        return response()->json(['data' => $this->present($dispatch)]);
    }

    public function update(UpdatePackagingDispatchRequest $request, PackagingDispatch $dispatch, PackagingDispatchService $service): JsonResponse
    {
        $this->authorize('processDispatch', WorkOrder::class);

        $dispatch = $service->patch($dispatch, $request->validated(), $request->user());

        return response()->json(['data' => $this->present($dispatch)]);
    }

    public function externalClose(ExternalClosePackagingDispatchRequest $request, PackagingDispatchService $service): JsonResponse
    {
        $this->authorize('processDispatch', WorkOrder::class);
        WorkOrder::findOrFail($request->integer('work_order_id'));

        $dispatch = $service->externalClose($request->validated(), $request->user());

        return response()->json(['data' => $this->present($dispatch)]);
    }

    private function present(PackagingDispatch $dispatch): array
    {
        $dispatch->loadMissing('workOrder:id,order_no,line_id');

        return [
            'id' => $dispatch->id,
            'work_order_id' => $dispatch->work_order_id,
            'order_no' => $dispatch->order_no,
            'customer_order_no' => $dispatch->customer_order_no,
            'status' => $dispatch->status,
            'source' => $dispatch->source,
            'idempotency_key' => $dispatch->idempotency_key,
            'scanned_boxes' => $dispatch->scanned_boxes,
            'scanned_pallets' => $dispatch->scanned_pallets,
            'box_manifest' => $dispatch->box_manifest,
            'pallet_manifest' => $dispatch->pallet_manifest,
            'weight_kg' => $dispatch->weight_kg,
            'expected_weight_kg' => $dispatch->expected_weight_kg,
            'weight_deviation_pct' => $dispatch->weight_deviation_pct,
            'carrier' => $dispatch->carrier,
            'reason' => $dispatch->reason,
            'attempt_count' => (int) $dispatch->attempt_count,
            'lock_token' => $dispatch->lock_token,
            'locked_until' => $dispatch->locked_until?->toIso8601String(),
            'shipment_id' => $dispatch->shipment_id,
            'shipment_status' => $dispatch->shipment_status,
            'last_error' => $dispatch->last_error,
            'packing_list_path' => $dispatch->packing_list_path,
            'boxes_hash' => $dispatch->boxes_hash,
            'docs_generated_at' => $dispatch->docs_generated_at?->toIso8601String(),
            'docs_stale' => (bool) $dispatch->docs_stale,
            'cancelled_at' => $dispatch->cancelled_at?->toIso8601String(),
            'cancel_reason' => $dispatch->cancel_reason,
            'resolved_at' => $dispatch->resolved_at?->toIso8601String(),
            'created_at' => $dispatch->created_at?->toIso8601String(),
        ];
    }

    private function assertDispatchLineAccess(Request $request, PackagingDispatch $dispatch): void
    {
        $workOrder = $dispatch->workOrder()->first();

        if (! $workOrder) {
            abort(404, __('Work order not found'));
        }

        $this->assertWorkOrderLineAccess($request, $workOrder);
    }
}
