<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecutBatchesRequest;
use App\Http\Requests\Api\V1\ReleaseBatchRequest;
use App\Models\Batch;
use App\Models\SerialUnit;
use App\Models\WorkOrder;
use App\Services\Lot\BatchReleaseService;
use App\Services\Material\MaterialAllocationService;
use App\Services\WorkOrder\WorkOrderService;
use App\Support\ApiValidationError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BatchController extends Controller
{
    public function __construct(
        protected WorkOrderService $workOrderService,
        protected BatchReleaseService $releaseService,
        protected MaterialAllocationService $allocationService,
    ) {}

    /**
     * Get batches for a work order.
     */
    public function index(WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('view', $workOrder);

        $batches = $workOrder->batches()
            ->with(['steps'])
            ->orderBy('batch_number')
            ->get();

        return response()->json([
            'data' => $batches,
        ]);
    }

    /**
     * Get a specific batch with steps.
     */
    public function show(Batch $batch): JsonResponse
    {
        $this->authorize('view', $batch->workOrder);

        $batch->load([
            'workOrder.line',
            'workOrder.productType',
            'steps.startedBy',
            'steps.completedBy',
        ]);

        return response()->json([
            'data' => $batch,
        ]);
    }

    /**
     * Create a new batch for a work order.
     */
    public function store(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('createBatch', $workOrder);

        $validated = $request->validate([
            'target_qty' => 'required|numeric|min:0.01',
            'workstation_id' => 'nullable|exists:workstations,id',
            'lot_number' => 'nullable|string|max:50',
        ]);

        // Check if adding this batch would exceed planned qty. Replacement batches
        // (re-cuts, RQ-PA-214) live outside the plan and cancelled batches no
        // longer consume capacity, so neither counts toward the sum. Legacy rows
        // may have a NULL is_replacement, which must still count.
        $totalTargetQty = $workOrder->batches()
            ->where(fn ($query) => $query->whereNull('is_replacement')->orWhere('is_replacement', false))
            ->where('status', '!=', Batch::STATUS_CANCELLED)
            ->sum('target_qty') + $validated['target_qty'];
        $allowOverproduction = config('openmmes.allow_overproduction', false);

        if (! $allowOverproduction && ($totalTargetQty - $workOrder->planned_qty) > 0.001) {
            return response()->json([
                'message' => 'Total batch quantity would exceed planned quantity',
            ], 422);
        }

        // Check workstation conflicts (soft warning in response)
        $conflicts = [];
        if (! empty($validated['workstation_id'])) {
            $conflicts = $this->releaseService->checkWorkstationConflicts($validated['workstation_id']);
        }

        $batch = $this->workOrderService->createBatch(
            $workOrder,
            $validated['target_qty'],
            $validated['workstation_id'] ?? null,
            $validated['lot_number'] ?? null,
        );

        $response = [
            'message' => 'Batch created successfully',
            'data' => $batch->load(['steps', 'workstation']),
        ];

        if (! empty($conflicts)) {
            $response['warnings'] = ['workstation_conflict' => $conflicts];
        }

        return response()->json($response, 201);
    }

    /**
     * Recut a defective batch (RQ-PA-214 / P2-T2).
     *
     * This is a low-level capacity operation, not a new work order or job type:
     * it opens a replacement batch on the same order, outside `planned_qty`,
     * and reopens a DONE order to IN_PROGRESS. PENDING/IN_PROGRESS keep their
     * status. Repeating the same `idempotency_key` returns the batch created by
     * the first call instead of cutting a second one.
     *
     * A replacement must prove the substitution: it names the batch it replaces,
     * and a replaced batch that carries units must already have one scrapped
     * (`recut_original_not_scrapped`, 422) - see assertReplacementEvidence().
     */
    public function recut(RecutBatchesRequest $request, WorkOrder $workOrder): JsonResponse
    {
        $validated = $request->validated();

        try {
            [$batch, $created] = DB::transaction(function () use ($workOrder, $validated) {
                $workOrder = WorkOrder::whereKey($workOrder->getKey())->lockForUpdate()->firstOrFail();

                $existing = Batch::query()
                    ->where('work_order_id', $workOrder->id)
                    ->where('is_replacement', true)
                    ->where('extra_data->idempotency_key', $validated['idempotency_key'])
                    ->first();

                if ($existing) {
                    // An idempotent replay answers the first call's outcome; the
                    // original's unit states may legitimately have moved since.
                    return [$existing->load(['steps', 'workstation']), false];
                }

                // The substitution has to be proven before the replacement exists
                // (RQ-PA-263 / lesson RC-32): the replaced batch must already have
                // a scrapped unit whenever it carries units at all.
                $replaced = Batch::query()->whereKey($validated['replaces_batch_id'])->firstOrFail();
                $this->assertReplacementEvidence($replaced, $workOrder);

                if ($workOrder->status === WorkOrder::STATUS_DONE) {
                    $reopened = WorkOrderService::applyTransition($workOrder, 'reopen');

                    if (! $reopened['ok']) {
                        abort(422, $reopened['message']);
                    }

                    $workOrder = $workOrder->fresh();
                }

                // Reuse the normal batch creation path (numbering, status, steps from
                // the process snapshot) but skip BatchController::store's planned-qty
                // guard: a replacement lives outside the order's planned quantity.
                $batch = $this->workOrderService->createBatch($workOrder, (float) $validated['target_qty']);

                // Replacement flags are intentionally not mass-assignable.
                $batch->forceFill([
                    'is_replacement' => true,
                    'replaces_batch_id' => (int) $validated['replaces_batch_id'],
                    'extra_data' => [
                        'idempotency_key' => $validated['idempotency_key'],
                        'reason' => $validated['reason'],
                    ],
                ])->save();

                return [$batch->fresh(['steps', 'workstation']), true];
            });
        } catch (\DomainException $e) {
            // A refused substitution is an outcome the caller can act on, not a
            // server fault: the transaction has rolled back.
            return ApiValidationError::response('recut', $e->getMessage());
        }

        return response()->json([
            'message' => $created ? 'Replacement batch created successfully' : 'Replacement batch already exists',
            'data' => $batch,
        ], $created ? 201 : 200);
    }

    /**
     * A replacement has to prove the substitution (RQ-PA-263 / lesson RC-32).
     *
     * The replaced batch must carry at least one `scrapped` unit: that is the
     * physical proof the piece it replaces is gone. Without it a replacement
     * sits next to the original and both get counted (the manual batch 2301
     * beside 2304).
     *
     * Polityka: brak jednostek = brak dowodu substytucji do zebrania; batch
     * planowy (korekta planu przed produkcją) nie ma czego złomować. A CANCELLED
     * batch is read the same way — cancelling does not remove its units, so a
     * cancelled batch that still carries units must still show a scrapped one.
     *
     * @throws \DomainException with the refusal the caller can act on
     */
    private function assertReplacementEvidence(Batch $replaced, WorkOrder $workOrder): void
    {
        $units = SerialUnit::query()
            ->where('batch_id', $replaced->id)
            ->get(['id', 'status']);

        // Mirrors the packing gate (I15): on a single-batch order a unit carrying
        // no batch is that batch's unit, so it is part of the proof too - the
        // rollup counts it, and a gate that ignored it would let the same double
        // count back in through the loose-unit door.
        if ($workOrder->batches()->count() === 1) {
            $units = $units->concat(
                SerialUnit::query()
                    ->where('work_order_id', $workOrder->id)
                    ->whereNull('batch_id')
                    ->get(['id', 'status']),
            );
        }

        if ($units->isEmpty()) {
            return;
        }

        if ($units->contains(fn (SerialUnit $unit) => $unit->status === SerialUnit::STATUS_SCRAPPED)) {
            return;
        }

        $statuses = $units->countBy('status')
            ->map(fn (int $count, string $status) => "{$status} x{$count}")
            ->implode(', ');

        throw new \DomainException(__(
            'recut_original_not_scrapped: batch :batch still carries :count unit(s) (:statuses) and none is scrapped, so a replacement would sit next to the original instead of substituting it. Scrap the original unit first (POST /serial-units/{id}/recut), or use the top-up path when nothing was cut.',
            [
                'batch' => $replaced->id,
                'count' => $units->count(),
                'statuses' => $statuses,
            ],
        ));
    }

    /**
     * Update a batch (only target_qty, only when PENDING).
     */
    public function update(Request $request, Batch $batch): JsonResponse
    {
        $this->authorize('update', $batch->workOrder);

        if ($batch->status !== Batch::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only PENDING batches can be updated.',
            ], 422);
        }

        $validated = $request->validate([
            'target_qty' => ['required', 'numeric', 'min:0.01'],
        ]);

        $batch->update(['target_qty' => $validated['target_qty']]);

        return response()->json([
            'message' => 'Batch updated',
            'data' => $batch->fresh(['steps']),
        ]);
    }

    /**
     * Cancel a batch.
     */
    public function cancel(Batch $batch): JsonResponse
    {
        $this->authorize('update', $batch->workOrder);

        if (in_array($batch->status, [Batch::STATUS_DONE, Batch::STATUS_CANCELLED], true)) {
            return response()->json([
                'message' => 'Batch is already in a terminal state.',
            ], 422);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($batch) {
            $batch->update(['status' => Batch::STATUS_CANCELLED]);
            // Restore allocated material stock; no-op if nothing was allocated.
            $this->allocationService->returnForBatch($batch);
        });

        return response()->json([
            'message' => 'Batch cancelled',
            'data' => $batch->fresh(['steps']),
        ]);
    }

    /**
     * Delete a batch (only when PENDING and no started steps).
     */
    public function destroy(Batch $batch): JsonResponse
    {
        $this->authorize('delete', $batch->workOrder);

        if ($batch->status !== Batch::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only PENDING batches can be deleted.',
            ], 422);
        }

        $hasStarted = $batch->steps()
            ->whereNotIn('status', [\App\Models\BatchStep::STATUS_PENDING])
            ->exists();
        if ($hasStarted) {
            return response()->json([
                'message' => 'Cannot delete batch with started steps.',
            ], 422);
        }

        $batch->delete();

        return response()->json(['message' => 'Batch deleted']);
    }

    /**
     * Preview material allocation for a batch (before starting).
     */
    public function allocationPreview(Batch $batch): JsonResponse
    {
        $this->authorize('view', $batch->workOrder);

        $preview = $this->allocationService->previewForBatch($batch);

        $allSufficient = collect($preview)->every(fn ($item) => $item['sufficient']);

        return response()->json([
            'data' => $preview,
            'all_sufficient' => $allSufficient,
            'batch_status' => $batch->status,
        ]);
    }

    /**
     * Release a completed batch for production or sale.
     */
    public function release(ReleaseBatchRequest $request, Batch $batch): JsonResponse
    {
        try {
            $batch = $this->releaseService->release(
                $batch,
                $request->user(),
                $request->validated('release_type'),
            );

            return response()->json([
                'message' => 'Batch released successfully',
                'data' => $batch,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
