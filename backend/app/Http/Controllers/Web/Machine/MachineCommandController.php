<?php

namespace App\Http\Controllers\Web\Machine;

use App\Exceptions\MachineCommandException;
use App\Http\Controllers\Concerns\PackagingLineAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMachineCommandRequest;
use App\Models\BatchStep;
use App\Models\MachineCommand;
use App\Services\Machine\MachineCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Operator-station endpoints for the machine-command outbox (P1b).
 *
 * The operator queue renders HTML, so store/cancel answer with a redirect and
 * a flash for form posts and with JSON for the polling station (P2).
 */
class MachineCommandController extends Controller
{
    use PackagingLineAccess;

    public function index(Request $request, BatchStep $batchStep, MachineCommandService $service): JsonResponse
    {
        Gate::authorize('viewAny', MachineCommand::class);

        $batchStep->loadMissing(['batch.workOrder', 'workstation.workstationType']);
        $workOrder = $batchStep->batch?->workOrder;

        abort_unless($workOrder, 422, __('The batch step does not belong to a work order.'));

        $this->assertWorkOrderLineAccess($request, $workOrder);

        $visibleTypes = Gate::getPolicyFor(MachineCommand::class)?->visibleTypes($request->user());

        return response()->json([
            'data' => $service->findForStep($batchStep, null, $visibleTypes),
            'capabilities' => $service->capabilitiesFor(
                $request->user(),
                $batchStep,
                null,
                $workOrder,
                $this->operatorLineIds($request),
            ),
        ]);
    }

    public function store(
        StoreMachineCommandRequest $request,
        BatchStep $batchStep,
        MachineCommandService $service,
    ): JsonResponse|RedirectResponse {
        try {
            // Same order as the API: type authorization first, lookup second.
            $type = (string) $request->input('type');

            Gate::authorize('create', [MachineCommand::class, $type]);

            $batchStep->loadMissing('batch.workOrder');
            $workOrder = $batchStep->batch?->workOrder;

            if (! $workOrder) {
                throw MachineCommandException::invalidStep(__('The batch step does not belong to a work order.'));
            }

            // The stepped route already names the step; an optional body field
            // must not silently point at a different one.
            if ($request->filled('batch_step_id')
                && (int) $request->integer('batch_step_id') !== (int) $batchStep->id) {
                throw MachineCommandException::stepMismatch();
            }

            if ($request->filled('work_order_id')
                && (int) $request->integer('work_order_id') !== (int) $workOrder->id) {
                throw MachineCommandException::invalidStep(__('The batch step does not belong to this work order.'));
            }

            $key = (string) $request->input('idempotency_key');
            // Tenant-scoped pre-check: only decides 200 vs 201.
            $existing = MachineCommand::query()
                ->where('idempotency_key', $key)
                ->exists();

            $payload = (array) $request->input('payload', []);

            if ($type === MachineCommand::TYPE_MANUAL_M30 && $request->has('force')) {
                $payload['force'] = $request->boolean('force');
            }

            $command = $service->create(
                $workOrder,
                $batchStep,
                $type,
                $request->user(),
                $key,
                $payload,
                $request->input('reason'),
            );

            if ($request->expectsJson()) {
                return response()->json(['data' => $command], $existing ? 200 : 201);
            }

            return back()->with('success', __('Machine command queued.'));
        } catch (MachineCommandException $e) {
            if ($request->expectsJson()) {
                return $this->errorResponse($e);
            }

            return back()->withErrors(['machine_command' => $e->getMessage()]);
        }
    }

    public function show(MachineCommand $machineCommand): JsonResponse
    {
        Gate::authorize('view', $machineCommand);

        return response()->json(['data' => $machineCommand]);
    }

    public function cancel(
        Request $request,
        MachineCommand $machineCommand,
        MachineCommandService $service,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('cancel', $machineCommand);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $command = $service->cancel($machineCommand, $request->user(), $data['reason']);

            if ($request->expectsJson()) {
                return response()->json(['data' => $command]);
            }

            return back()->with('success', __('Machine command cancelled.'));
        } catch (MachineCommandException $e) {
            if ($request->expectsJson()) {
                return $this->errorResponse($e);
            }

            return back()->withErrors(['machine_command' => $e->getMessage()]);
        }
    }

    private function errorResponse(MachineCommandException $e): JsonResponse
    {
        return response()->json([
            'error' => $e->getMessage(),
            'reason' => $e->reason,
            'context' => $e->context,
        ], $e->httpStatus);
    }
}
