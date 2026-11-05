<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\MachineCommandException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClaimMachineCommandRequest;
use App\Http\Requests\CompleteMachineCommandRequest;
use App\Http\Requests\StoreMachineCommandRequest;
use App\Models\BatchStep;
use App\Models\MachineCommand;
use App\Services\Machine\MachineCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class MachineCommandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', MachineCommand::class);

        $user = $request->user();
        $seesEveryLine = $user->hasAnyRole(['Admin', 'Supervisor'])
            || $user->can('process machine commands');

        $visibleTypes = Gate::getPolicyFor(MachineCommand::class)?->visibleTypes($user);

        $query = MachineCommand::query()
            ->when($request->filled('batch_step_id'), fn ($q) => $q->where('batch_step_id', $request->integer('batch_step_id')))
            ->when($request->filled('work_order_id'), fn ($q) => $q->where('work_order_id', $request->integer('work_order_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', (string) $request->query('type')))
            ->when($visibleTypes !== null, fn ($q) => $q->whereIn('type', $visibleTypes));

        if (! $seesEveryLine) {
            $query->whereIn('line_id', $user->lines()->pluck('lines.id')->all());
        }

        $page = $query->orderByDesc('id')->paginate(50);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function store(StoreMachineCommandRequest $request, MachineCommandService $service): JsonResponse
    {
        try {
            // Authorize against the requested type before resolving the step so
            // an unauthorized caller cannot probe for 404/422.
            $type = (string) $request->input('type');

            Gate::authorize('create', [MachineCommand::class, $type]);

            $step = BatchStep::with(['batch.workOrder', 'workstation.workstationType'])
                ->find($request->integer('batch_step_id'));

            $workOrder = $step?->batch?->workOrder;

            if (! $step || ! $workOrder) {
                throw MachineCommandException::invalidStep(__('The batch step does not belong to a work order.'));
            }

            if ($request->filled('work_order_id')
                && (int) $request->integer('work_order_id') !== (int) $workOrder->id) {
                throw MachineCommandException::invalidStep(__('The batch step does not belong to this work order.'));
            }

            $key = (string) $request->input('idempotency_key');
            // Tenant-scoped pre-check: only decides 200 vs 201, the service and
            // the unique index still settle races and cross-tenant keys.
            $existing = MachineCommand::query()
                ->where('idempotency_key', $key)
                ->exists();

            $payload = (array) $request->input('payload', []);

            if ($type === MachineCommand::TYPE_MANUAL_M30 && $request->has('force')) {
                $payload['force'] = $request->boolean('force');
            }

            $command = $service->create(
                $workOrder,
                $step,
                $type,
                $request->user(),
                $key,
                $payload,
                $request->input('reason'),
            );

            return response()->json(['data' => $command], $existing ? 200 : 201);
        } catch (MachineCommandException $e) {
            return $this->errorResponse($e);
        }
    }

    public function show(MachineCommand $machineCommand): JsonResponse
    {
        Gate::authorize('view', $machineCommand);

        return response()->json(['data' => $machineCommand]);
    }

    public function claim(ClaimMachineCommandRequest $request, MachineCommandService $service): JsonResponse|Response
    {
        Gate::authorize('process', MachineCommand::class);

        $lockToken = (string) Str::uuid();

        try {
            $command = $service->claim(
                $request->input('workstation_codes'),
                $lockToken,
                $request->filled('lease_seconds') ? $request->integer('lease_seconds') : null,
                $request->user()?->tenant_id,
                $request->user()?->id,
                $request->input('types'),
            );
        } catch (MachineCommandException $e) {
            return $this->errorResponse($e);
        }

        if (! $command) {
            return response()->noContent();
        }

        return response()->json([
            'data' => array_merge($command->toArray(), ['lock_token' => $command->lock_token]),
        ]);
    }

    public function update(
        CompleteMachineCommandRequest $request,
        MachineCommand $machineCommand,
        MachineCommandService $service,
    ): JsonResponse {
        Gate::authorize('process', MachineCommand::class);

        try {
            $command = $service->complete(
                $machineCommand,
                (string) $request->input('lock_token'),
                (string) $request->input('status'),
                $request->input('outcome'),
                $request->input('result'),
                $request->input('error'),
                $request->input('error_class'),
                $request->input('delivery_id'),
            );

            return response()->json(['data' => $command]);
        } catch (MachineCommandException $e) {
            return $this->errorResponse($e);
        }
    }

    public function cancel(
        Request $request,
        MachineCommand $machineCommand,
        MachineCommandService $service,
    ): JsonResponse {
        Gate::authorize('cancel', $machineCommand);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $command = $service->cancel($machineCommand, $request->user(), $data['reason']);

            return response()->json(['data' => $command]);
        } catch (MachineCommandException $e) {
            return $this->errorResponse($e);
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
