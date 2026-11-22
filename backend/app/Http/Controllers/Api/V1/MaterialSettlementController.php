<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EnableMaterialSettlementRequest;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Material\MaterialSettlementScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administration of the pilot settlement scope on one work order (RQ-PA-265).
 *
 * Reading is available to whoever may view the order; changing it is an
 * administrative decision (Admin role on the write routes) because it changes
 * who is allowed to book the material. A refusal names the reason in `code`,
 * so a caller can tell an ineligible component from an order already in flight.
 */
class MaterialSettlementController extends Controller
{
    public function __construct(
        protected MaterialSettlementScope $scope,
    ) {}

    public function index(WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('view', $workOrder);

        return $this->state($workOrder);
    }

    public function store(EnableMaterialSettlementRequest $request, WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('view', $workOrder);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->scope->enable($workOrder, $request->validated('materials'), $actor);
        } catch (\DomainException $exception) {
            return $this->refused($exception);
        }

        return $this->state($workOrder, __('Material settlement scope enabled.'));
    }

    public function destroy(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('view', $workOrder);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->scope->disable($workOrder, $actor);
        } catch (\DomainException $exception) {
            return $this->refused($exception);
        }

        return $this->state($workOrder, __('Material settlement scope disabled.'));
    }

    /**
     * The whole picture the screen needs: what is on, what could be, and
     * whether switching it on would be accepted right now.
     */
    private function state(WorkOrder $workOrder, ?string $message = null): JsonResponse
    {
        $fresh = $workOrder->fresh() ?? $workOrder;

        $current = $this->scope->current($fresh);
        $candidates = $this->scope->candidates($fresh);

        $payload = [
            'current' => $current,
            'candidates' => $candidates,
            'can_enable' => ! $current['enabled'] && $this->hasEligible($candidates),
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        return response()->json($payload);
    }

    /**
     * Turn the service refusal into the documented 422, keeping the machine
     * readable code in front of the human sentence.
     */
    private function refused(\DomainException $exception): JsonResponse
    {
        $message = $exception->getMessage();
        $code = str_contains($message, ':') ? trim(strstr($message, ':', true)) : $message;

        return response()->json([
            'message' => $message,
            'code' => $code,
        ], 422);
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function hasEligible(array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (($candidate['eligible'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }
}
