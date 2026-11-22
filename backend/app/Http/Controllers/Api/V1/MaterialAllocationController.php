<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\MaterialCorrectionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CorrectMaterialConsumptionRequest;
use App\Http\Requests\Api\V1\RecordConsumptionRequest;
use App\Http\Requests\Api\V1\ReturnAllocationRequest;
use App\Http\Requests\Api\V1\SettleMaterialAllocationRequest;
use App\Models\Batch;
use App\Models\MaterialAllocation;
use App\Services\Material\MaterialAllocationService;
use App\Services\Material\MaterialSettlementPolicy;
use Illuminate\Http\JsonResponse;

/**
 * Work-order material reconciliation (#99): declare actual (partial) consumption
 * and return unused quantity to stock. Both funnel through
 * MaterialAllocationService so stock, reservation and lot accounting stay in sync.
 */
class MaterialAllocationController extends Controller
{
    public function __construct(
        protected MaterialAllocationService $allocations,
    ) {}

    public function consume(RecordConsumptionRequest $request, MaterialAllocation $allocation): JsonResponse
    {
        try {
            $updated = $this->allocations->recordConsumption(
                $allocation,
                (float) $request->validated('consumed_qty'),
                (float) ($request->validated('scrap_qty') ?? 0),
                $request->validated('notes'),
                $request->user(),
                MaterialAllocationService::SOURCE_OPERATOR,
                $request->validated('idempotency_key'),
            );

            return response()->json([
                'message' => 'Consumption recorded',
                'data' => $updated,
            ]);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['consumed_qty' => [$e->getMessage()]],
            ], 422);
        }
    }

    public function return(ReturnAllocationRequest $request, MaterialAllocation $allocation): JsonResponse
    {
        try {
            $updated = $this->allocations->returnQuantity(
                $allocation,
                (float) $request->validated('qty'),
                $request->user(),
                $request->validated('reason'),
            );

            return response()->json([
                'message' => 'Material returned to stock',
                'data' => $updated,
            ]);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['qty' => [$e->getMessage()]],
            ], 422);
        }
    }

    /**
     * Explicit settlement of a pilot line (RQ-PA-265 A3).
     *
     * Only the pilot scope has anything to settle, and only once the operation
     * has confirmed its retrieval. A line still waiting is refused with the
     * reason and books nothing — the queue stays visible instead of being
     * resolved by guesswork. A repeated request finds nothing left to settle and
     * posts no second movement.
     */
    public function settle(SettleMaterialAllocationRequest $request, MaterialAllocation $allocation): JsonResponse
    {
        $batch = $allocation->batch;

        if (! $batch instanceof Batch) {
            return response()->json([
                'message' => __('This allocation has no batch to settle.'),
                'code' => 'material_settlement_not_applicable',
            ], 422);
        }

        $policy = app(MaterialSettlementPolicy::class);

        if ($policy->modeFor($batch->workOrder, (int) $allocation->material_id) !== MaterialSettlementPolicy::MODE_OPERATION) {
            return response()->json([
                'message' => __('This allocation is not settled by its operation — there is nothing to settle.'),
                'code' => 'material_settlement_not_applicable',
            ], 422);
        }

        $reason = $policy->pendingReason($allocation);

        if ($reason !== null) {
            return response()->json([
                'message' => __('The settlement cannot be posted yet: :reason', ['reason' => $reason]),
                'code' => 'material_settlement_pending',
                'reason' => $reason,
            ], 422);
        }

        $summary = $this->allocations->consumeForBatch($batch);

        return response()->json([
            'message' => __('Settlement posted'),
            'data' => $allocation->fresh(),
            'summary' => $summary,
        ]);
    }

    /**
     * Correct a settled pilot consumption (RQ-PA-265 A5).
     *
     * Four eyes: the reviewer who posts the correction cannot be the person who
     * declared the confirmation, and only a holder of `approve work order changes`
     * reaches this endpoint at all (route middleware). The refusal carries a code
     * so the screen can say which rule stopped it; nothing is booked when it does.
     */
    public function correct(CorrectMaterialConsumptionRequest $request, MaterialAllocation $allocation): JsonResponse
    {
        try {
            $updated = $this->allocations->correctConsumption(
                $allocation,
                (float) $request->validated('consumed_qty'),
                (float) ($request->validated('scrap_qty') ?? 0),
                (string) $request->validated('reason'),
                (string) $request->validated('reference'),
                $request->user(),
                $request->validated('idempotency_key'),
            );

            return response()->json([
                'message' => __('Material consumption corrected'),
                'data' => $updated,
            ]);
        } catch (MaterialCorrectionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->reasonCode,
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'material_correction_invalid_input',
                'errors' => ['consumed_qty' => [$e->getMessage()]],
            ], 422);
        } catch (\DomainException $e) {
            // A refusal further down (a location that cannot be credited back, for
            // one) still has to reach the screen as a refusal, not a 500.
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'material_correction_refused',
            ], 422);
        }
    }
}
