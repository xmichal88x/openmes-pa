<?php

namespace Modules\PlPackagingExtras\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PackagingBox;
use App\Models\PackagingScanLog;
use App\Models\Pallet;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use App\Services\Packaging\PackagingScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\PlPackagingExtras\Services\PackagingApiService;

/**
 * P1 packaging API — EAN management, scan and dashboard reads.
 *
 * The scan write-path is the core PackagingScanService so the API and the
 * operator station share the same void/duplicate/packable guards.
 */
class PackagingApiController extends Controller
{
    public function __construct(
        protected PackagingScanService $scanService,
        protected PackagingApiService $api,
    ) {}

    // ── EAN management ──────────────────────────────────────────────────────

    public function listEans(Request $request): JsonResponse
    {
        $query = WorkOrderEan::query()->with('workOrder.productType');

        if ($woId = $request->query('work_order_id')) {
            $query->where('work_order_id', $woId);
        }
        if ($q = $request->query('q')) {
            $query->whereRaw('LOWER(ean) LIKE ?', ['%'.mb_strtolower((string) $q).'%']);
        }

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min($perPage, 100));

        $page = $query->orderBy('ean')->paginate($perPage);

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

    public function showEan(WorkOrderEan $workOrderEan): JsonResponse
    {
        $workOrderEan->load('workOrder.productType');

        return response()->json(['data' => $workOrderEan]);
    }

    public function storeEan(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['Admin', 'Supervisor'])) {
            return response()->json(['message' => __('Forbidden')], 403);
        }

        $validated = $request->validate([
            'work_order_id' => ['required', 'integer', 'exists:work_orders,id'],
            'ean' => ['required', 'string', 'max:100', 'unique:work_order_eans,ean'],
        ]);

        $ean = WorkOrderEan::create($validated);

        return response()->json([
            'message' => __('EAN added'),
            'data' => $ean->load('workOrder.productType'),
        ], 201);
    }

    public function destroyEan(Request $request, WorkOrderEan $workOrderEan): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['Admin', 'Supervisor'])) {
            return response()->json(['message' => __('Forbidden')], 403);
        }
        $workOrderEan->delete();

        return response()->json(['message' => __('EAN deleted')]);
    }

    // ── Scan ────────────────────────────────────────────────────────────────

    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ean' => ['required', 'string', 'max:100'],
            'work_order_id' => ['nullable', 'integer', 'exists:work_orders,id'],
            'pallet_id' => ['nullable', 'integer', 'exists:pallets,id'],
            'box_id' => ['nullable'],
        ]);

        $user = $request->user();

        $eanRecord = WorkOrderEan::where('ean', $validated['ean'])->first();
        if (! $eanRecord) {
            return response()->json(['message' => __('Unknown EAN')], 404);
        }

        $workOrder = WorkOrder::find($validated['work_order_id'] ?? $eanRecord->work_order_id);
        if (! $workOrder) {
            return response()->json(['message' => __('Work order not found')], 404);
        }

        if ((int) $eanRecord->work_order_id !== (int) $workOrder->id) {
            return response()->json(['message' => __('Code belongs to a different work order')], 409);
        }

        if ($user->hasAnyRole(['Operator', 'PackingWorker'])) {
            $hasAccess = $user->lines()->where('lines.id', $workOrder->line_id)->exists();
            if (! $hasAccess) {
                return response()->json(['message' => __('You do not have access to this line.')], 403);
            }
        }

        $box = null;
        if (! empty($validated['box_id'])) {
            $box = PackagingBox::when(
                is_numeric($validated['box_id']),
                fn ($query) => $query->whereKey((int) $validated['box_id']),
                fn ($query) => $query->where('box_id', (string) $validated['box_id']),
            )->first();

            if (! $box) {
                return response()->json(['message' => __('Unknown box')], 404);
            }

            if ((int) $box->work_order_id !== (int) $workOrder->id) {
                return response()->json(['message' => __('Box belongs to a different work order')], 409);
            }

            if (! $box->isOpen()) {
                return response()->json(['message' => __('Box :id is closed.', ['id' => $box->box_id])], 409);
            }
        }

        $pallet = null;
        if (! empty($validated['pallet_id'])) {
            $pallet = Pallet::find($validated['pallet_id']);

            if (! $pallet || ! $pallet->isOpen()) {
                return response()->json(['message' => __('Pallet is not open')], 422);
            }

            if ((int) $pallet->work_order_id !== (int) $workOrder->id) {
                return response()->json(['message' => __('Piece does not belong to this pallet\'s work order')], 422);
            }
        }

        $result = $this->scanService->scan($eanRecord, $workOrder, $pallet, $user, $box);
        $workOrder = $result['work_order'];
        $label = PackagingScanService::productLabel($workOrder);

        return response()->json([
            'message' => __('Packed: :name', ['name' => $label]),
            'data' => [
                'work_order' => [
                    'id' => $workOrder->id,
                    'order_no' => $workOrder->order_no,
                    'product' => $label,
                    'planned_qty' => (int) $workOrder->planned_qty,
                    'packed_qty' => (int) $workOrder->packed_qty,
                    'status' => $workOrder->status,
                ],
                'scan' => [
                    'id' => $result['scan_log']->id,
                    'ean' => $result['scan_log']->ean,
                    'box_id' => $result['box']?->box_id,
                    'scanned_at' => $result['scan_log']->scanned_at->toIso8601String(),
                ],
                'remaining' => $result['remaining'],
                'expected_total' => $result['expected_total'],
            ],
        ]);
    }

    // ── Scan logs ───────────────────────────────────────────────────────────

    public function scanLogs(Request $request): JsonResponse
    {
        $query = PackagingScanLog::query()->with('user', 'workOrder');

        if ($woId = $request->query('work_order_id')) {
            $query->where('work_order_id', $woId);
        }
        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }
        if ($from = $request->query('from')) {
            $query->where('scanned_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('scanned_at', '<=', $to);
        }

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min($perPage, 100));

        $page = $query->orderByDesc('scanned_at')->paginate($perPage);

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

    // ── Packaging dashboard data ────────────────────────────────────────────

    public function items(): JsonResponse
    {
        return response()->json(['data' => $this->api->items()]);
    }

    public function stats(): JsonResponse
    {
        return response()->json(['data' => $this->api->stats()]);
    }
}
