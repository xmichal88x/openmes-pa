<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecutBatchesRequest;
use App\Http\Requests\Api\V1\ReleaseBatchRequest;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\SerialUnit;
use App\Models\WorkOrder;
use App\Services\Lot\BatchReleaseService;
use App\Services\Material\MaterialAllocationService;
use App\Services\Settings\RuntimeSettings;
use App\Services\WorkOrder\WorkOrderService;
use App\Support\ApiValidationError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BatchController extends Controller
{
    /** Plan comparison tolerance: quantities are decimal, so exact equality is not a test. */
    private const PLANNED_QTY_TOLERANCE = 0.001;

    public function __construct(
        protected WorkOrderService $workOrderService,
        protected BatchReleaseService $releaseService,
        protected MaterialAllocationService $allocationService,
        protected RuntimeSettings $runtimeSettings,
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

        // Check if adding this batch would exceed planned qty (see
        // projectedPlannedBatchTotal for which batches count).
        $totalTargetQty = $this->projectedPlannedBatchTotal($workOrder, (float) $validated['target_qty']);

        if ($this->exceedsPlannedQty($workOrder, $totalTargetQty)) {
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
     *
     * A PENDING batch is still part of the order's plan, so raising it is the
     * same act as creating one: it goes through the same planned-qty projection
     * as `store()`. Without that, PATCH was a door around the plan guard.
     * Every accepted change is audited with who/from/to/why.
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
            // Optional, and bounded like recut's reason: the audit keeps the
            // operator's own words rather than an inferred motive.
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $workOrder = $batch->workOrder;
        $newQty = (float) $validated['target_qty'];

        // A replacement (re-cut) lives outside the plan: its size never counts
        // against `planned_qty`, exactly as in recut(). Legacy NULL reads as
        // "not a replacement", so it is guarded like a planned batch.
        if (! $batch->is_replacement) {
            // The edited batch already sits in the sum, so it is projected with
            // its new value instead of being counted twice.
            $projectedTotal = $this->projectedPlannedBatchTotal($workOrder, $newQty, $batch);

            if ($this->exceedsPlannedQty($workOrder, $projectedTotal)) {
                return response()->json([
                    'message' => sprintf(
                        'Total batch quantity would exceed planned quantity (%s of %s)',
                        self::formatQty($projectedTotal),
                        self::formatQty((float) $workOrder->planned_qty),
                    ),
                ], 422);
            }
        }

        $previousQty = (float) $batch->target_qty;

        // An unchanged quantity is not a change: no write and no audit entry,
        // the same rule the step-quantity correction follows.
        if (abs($previousQty - $newQty) >= self::PLANNED_QTY_TOLERANCE) {
            $reason = trim((string) ($validated['reason'] ?? ''));

            DB::transaction(function () use ($request, $batch, $newQty, $previousQty, $reason) {
                $batch->update(['target_qty' => $newQty]);

                // `reason` is optional, so a caller that gave none is recorded
                // as null: the audit states what was asked, not a guessed motive.
                AuditLog::create([
                    'user_id' => $request->user()?->id,
                    'entity_type' => Batch::class,
                    'entity_id' => $batch->id,
                    'action' => 'batch_target_qty_updated',
                    'before_state' => ['target_qty' => $previousQty],
                    'after_state' => ['target_qty' => $newQty, 'reason' => $reason === '' ? null : $reason],
                ]);
            });
        }

        return response()->json([
            'message' => 'Batch updated',
            'data' => $batch->fresh(['steps']),
        ]);
    }

    /**
     * The planned total a work order would carry once `$additionalQty` is added.
     *
     * Replacement batches (re-cuts, RQ-PA-214) live outside the plan and
     * cancelled batches no longer consume capacity, so neither counts toward the
     * sum. Legacy rows may have a NULL is_replacement, which must still count.
     * When a batch itself is being edited it is already inside the sum, so it is
     * excluded and re-added as `$additionalQty` rather than counted twice.
     */
    private function projectedPlannedBatchTotal(WorkOrder $workOrder, float $additionalQty, ?Batch $exclude = null): float
    {
        $sum = $workOrder->batches()
            ->where(fn ($query) => $query->whereNull('is_replacement')->orWhere('is_replacement', false))
            ->where('status', '!=', Batch::STATUS_CANCELLED)
            ->when($exclude !== null, fn ($query) => $query->where('id', '!=', $exclude->getKey()))
            ->sum('target_qty');

        return (float) $sum + $additionalQty;
    }

    /**
     * Whether the projected total overshoots the plan by more than the qty
     * tolerance. The stored switch decides (Settings -> System), with the env var
     * only as the declared default when nothing is stored - see RuntimeSettings.
     */
    private function exceedsPlannedQty(WorkOrder $workOrder, float $projectedTotal): bool
    {
        if ($this->runtimeSettings->allowOverproduction()) {
            return false;
        }

        return ($projectedTotal - (float) $workOrder->planned_qty) > self::PLANNED_QTY_TOLERANCE;
    }

    private static function formatQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
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
