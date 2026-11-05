<?php

namespace Modules\PlPackagingExtras\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\Line;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\PlPackagingExtras\Services\PackagingApiService;
use Modules\PlPackagingExtras\Support\PackagingPayload;

/**
 * R06 endpoints: expected codes, atomic PACK work-order creation and completion.
 */
class PackagingWorkOrderController extends Controller
{
    public function __construct(
        protected PackagingApiService $api,
        protected WorkOrderService $workOrderService,
    ) {}

    /**
     * Replace the full expected-code list for a work order (idempotent).
     */
    public function expectedCodes(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('managePacking', $workOrder);

        $validated = $request->validate([
            'codes' => ['required', 'array', 'min:1'],
            'codes.*.code' => ['required', 'string', 'max:100'],
            'codes.*.label_batch_id' => ['nullable', 'string', 'max:100'],
            'codes.*.part_seq' => ['nullable', 'integer', 'min:1'],
            ...$this->codeMetadataRules(),
        ]);

        $this->api->assertNoDuplicateCodes($validated['codes']);

        if (! in_array($workOrder->status, [WorkOrder::STATUS_PENDING, WorkOrder::STATUS_ACCEPTED, WorkOrder::STATUS_IN_PROGRESS], true)) {
            abort(422, __('Work order cannot be prepared for packing in its current state (:status)', ['status' => $workOrder->status]));
        }

        $result = DB::transaction(fn () => $this->api->upsertExpectedCodes($workOrder, $validated['codes']));

        $result['codes'] = $result['codes']->map(fn (WorkOrderEan $code) => [
            'ean' => $code->ean,
            'code_type' => $code->code_type,
            'part_seq' => $code->part_seq,
            'label_batch_id' => $code->label_batch_id,
            'state' => $code->state,
            ...PackagingPayload::partMetadata($code),
        ])->values();

        return response()->json([
            'message' => __('Expected codes updated'),
            'data' => array_merge(['work_order_id' => $workOrder->id], $result),
        ]);
    }

    /**
     * Atomically create a PACK work order already IN_PROGRESS with its expected
     * part-label codes. Idempotent for IN_PROGRESS/DONE work orders with the
     * same order_no and customer_order_no.
     */
    public function createWorkOrder(Request $request): JsonResponse
    {
        $this->authorize('createPacking', WorkOrder::class);

        $validated = $request->validate([
            'order_no' => ['required', 'string', 'max:100'],
            'customer_order_no' => ['required', 'string', 'max:100'],
            'planned_qty' => ['nullable', 'integer', 'min:1'],
            'codes' => ['required', 'array', 'min:1'],
            'codes.*.code' => ['required', 'string', 'max:100'],
            'codes.*.label_batch_id' => ['nullable', 'string', 'max:100'],
            'codes.*.part_seq' => ['nullable', 'integer', 'min:1'],
            'extra_data' => ['nullable', 'array'],
            ...$this->codeMetadataRules(),
        ]);

        $this->api->assertNoDuplicateCodes($validated['codes']);

        $packingLine = Line::where('code', 'PACKING')->where('is_active', true)->first();
        if (! $packingLine) {
            abort(422, __('Packing line (PACKING) is not configured'));
        }

        $codeCount = count($validated['codes']);
        $plannedQty = $validated['planned_qty'] ?? $codeCount;
        if ((int) $plannedQty !== $codeCount) {
            abort(422, __('planned_qty (:planned) must match the number of codes (:codes)', [
                'planned' => $plannedQty,
                'codes' => $codeCount,
            ]));
        }

        $existing = WorkOrder::where('order_no', $validated['order_no'])->first();
        if ($existing) {
            if ((string) $existing->customer_order_no !== (string) $validated['customer_order_no']) {
                abort(409, __('Work order :order_no already belongs to customer order :customer_order_no', [
                    'order_no' => $existing->order_no,
                    'customer_order_no' => $existing->customer_order_no,
                ]));
            }

            if (in_array($existing->status, [WorkOrder::STATUS_IN_PROGRESS, WorkOrder::STATUS_DONE], true)) {
                $existing->loadMissing('line');

                return response()->json([
                    'message' => __('Packing work order already exists'),
                    'data' => [
                        'work_order' => PackagingPayload::workOrder($existing, $existing->line?->code ?? $packingLine->code),
                        'codes' => PackagingPayload::codes($this->api->packingCodes($existing)),
                    ],
                ]);
            }

            abort(409, __('Work order :order_no already exists in state :status', [
                'order_no' => $existing->order_no,
                'status' => $existing->status,
            ]));
        }

        // order_no is unique at the database level regardless of soft deletes,
        // so a deleted row must produce a clear 409 instead of a 500.
        if (WorkOrder::withTrashed()->where('order_no', $validated['order_no'])->exists()) {
            abort(409, __('Work order :order_no already exists (deleted)', ['order_no' => $validated['order_no']]));
        }

        $result = DB::transaction(function () use ($validated, $packingLine, $plannedQty) {
            $workOrder = $this->workOrderService->createWorkOrder([
                'order_no' => $validated['order_no'],
                'customer_order_no' => $validated['customer_order_no'],
                'line_id' => $packingLine->id,
                'product_type_id' => null,
                'planned_qty' => $plannedQty,
                'status' => WorkOrder::STATUS_IN_PROGRESS,
                'extra_data' => array_merge($validated['extra_data'] ?? [], ['packing_workflow' => true]),
            ]);

            // Invariant: a PACK work order must never be visible to the nesting
            // queue as PENDING (queue picks up `?status=PENDING` only).
            if ($workOrder->status === WorkOrder::STATUS_PENDING) {
                abort(500, __('Packing work order cannot be created in PENDING state'));
            }

            $upsert = $this->api->upsertExpectedCodes($workOrder, $validated['codes'], false);

            return ['work_order' => $workOrder, 'codes' => $upsert['codes']];
        });

        return response()->json([
            'message' => __('Packing work order created'),
            'data' => [
                'work_order' => PackagingPayload::workOrder($result['work_order'], $packingLine->code),
                'codes' => PackagingPayload::codes($result['codes']),
            ],
        ], 201);
    }

    /**
     * Close a fully packed work order (packed_qty == planned_qty, no open issues).
     */
    public function complete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'work_order_id' => ['required', 'integer', 'exists:work_orders,id'],
        ]);

        $workOrder = WorkOrder::findOrFail($validated['work_order_id']);
        $this->authorize('managePacking', $workOrder);

        if ($workOrder->status !== WorkOrder::STATUS_IN_PROGRESS) {
            abort(422, __('Only IN_PROGRESS work orders can be completed.'));
        }

        $planned = (int) $workOrder->planned_qty;
        $packed = (int) $workOrder->packed_qty;
        if ($planned <= 0 || $packed < $planned) {
            abort(422, __('Work order is not fully packed (:remaining remaining)', [
                'remaining' => max(0, $planned - $packed),
            ]));
        }

        $hasBlockingIssue = Issue::where('work_order_id', $workOrder->id)
            ->whereIn('status', [Issue::STATUS_OPEN, Issue::STATUS_ACKNOWLEDGED])
            ->exists();

        if ($hasBlockingIssue) {
            abort(422, __('Work order has unresolved blocking issues'));
        }

        $workOrder->update([
            'status' => WorkOrder::STATUS_DONE,
            'completed_at' => now(),
        ]);

        return response()->json([
            'message' => __('Work order packed and completed'),
            'data' => $workOrder->fresh(['line', 'productType']),
        ]);
    }

    /**
     * Optional R07 part metadata (M4). Every field is nullable so an older PA
     * that only sends code/part_seq keeps working.
     *
     * @return array<string, array<int, string>>
     */
    private function codeMetadataRules(): array
    {
        return [
            'codes.*.part_name' => ['nullable', 'string', 'max:150'],
            'codes.*.material' => ['nullable', 'string', 'max:100'],
            'codes.*.width_mm' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'codes.*.height_mm' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'codes.*.thickness_mm' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'codes.*.sheet_name' => ['nullable', 'string', 'max:150'],
        ];
    }
}
