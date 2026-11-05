<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PackagingScanRequest;
use App\Models\PackagingBox;
use App\Models\PackagingScanLog;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use App\Services\Packaging\PackagingScanService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackagingApiController extends Controller
{
    // ── EAN management ──────────────────────────────────────────────────────

    public function listEans(Request $request): JsonResponse
    {
        $query = WorkOrderEan::query()->with('workOrder.productType');

        if ($woId = $request->query('work_order_id')) {
            $query->where('work_order_id', $woId);
        }
        if ($q = $request->query('q')) {
            $query->where('ean', 'ilike', '%'.$q.'%');
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

    public function scan(PackagingScanRequest $request, PackagingScanService $scanService): JsonResponse
    {
        $validated = $request->validated();

        $eanRecord = WorkOrderEan::where('ean', $validated['ean'])->first();
        if (! $eanRecord) {
            return response()->json(['message' => __('Unknown EAN')], 404);
        }

        $workOrder = WorkOrder::find($eanRecord->work_order_id);
        if (! $workOrder) {
            return response()->json(['message' => __('Work order not found')], 404);
        }

        $user = $request->user();
        if ($user->hasAnyRole(['Operator', 'PackingWorker'])) {
            $hasAccess = $user->lines()->where('lines.id', $workOrder->line_id)->exists();
            if (! $hasAccess) {
                return response()->json(['message' => __('You do not have access to this line.')], 403);
            }
        }

        if (! empty($validated['work_order_id']) && (int) $eanRecord->work_order_id !== (int) $validated['work_order_id']) {
            return response()->json(['message' => __('Code belongs to a different work order')], 409);
        }

        if (! WorkOrder::whereKey($workOrder->id)->packable()->exists()) {
            return response()->json([
                'message' => __('Work order not in a packable state (current: :status)', ['status' => $workOrder->status]),
            ], 422);
        }

        $box = null;
        if (! empty($validated['box_id'])) {
            $box = PackagingBox::when(
                is_numeric($validated['box_id']),
                fn ($q) => $q->whereKey((int) $validated['box_id']),
                fn ($q) => $q->where('box_id', (string) $validated['box_id']),
            )->first();

            if (! $box) {
                return response()->json(['message' => __('Unknown box')], 404);
            }

            if ((int) $box->work_order_id !== (int) $workOrder->id) {
                return response()->json(['message' => __('Box belongs to a different work order')], 409);
            }
        }

        $pallet = null;
        if (! empty($validated['pallet_id'])) {
            $pallet = \App\Models\Pallet::find($validated['pallet_id']);
        }

        $result = $scanService->scan($eanRecord, $workOrder, $pallet, $user, $box);
        $workOrder = $result['work_order'];
        $log = $result['scan_log'];

        return response()->json([
            'message' => __('Packed: :name', ['name' => $this->productLabel($workOrder)]),
            'data' => [
                'work_order' => [
                    'id' => $workOrder->id,
                    'order_no' => $workOrder->order_no,
                    'product' => $this->productLabel($workOrder),
                    'planned_qty' => (int) $workOrder->planned_qty,
                    'packed_qty' => $workOrder->packed_qty,
                    'status' => $workOrder->status,
                ],
                'scan' => [
                    'id' => $log->id,
                    'ean' => $log->ean,
                    'box_id' => $result['box']?->box_id,
                    'scanned_at' => $log->scanned_at->toIso8601String(),
                ],
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
        $eansByWorkOrder = WorkOrderEan::select('work_order_id', 'ean')
            ->get()
            ->groupBy('work_order_id');

        $items = WorkOrder::whereIn('status', [WorkOrder::STATUS_DONE, WorkOrder::STATUS_IN_PROGRESS])
            ->with('productType', 'line')
            ->orderByDesc('priority')
            ->get()
            ->filter(fn ($wo) => $eansByWorkOrder->has($wo->id))
            ->map(function ($wo) use ($eansByWorkOrder) {
                $planned = (int) $wo->planned_qty;
                $packed = (int) $wo->packed_qty;

                return [
                    'id' => $wo->id,
                    'order_no' => $wo->order_no,
                    'product' => $this->productLabel($wo),
                    'line' => $wo->line?->name,
                    'planned_qty' => $planned,
                    'packed_qty' => $packed,
                    'progress' => $planned > 0 ? min(100, (int) round($packed / $planned * 100)) : 0,
                    'done' => $planned > 0 && $packed >= $planned,
                    'eans' => $eansByWorkOrder[$wo->id]->pluck('ean')->values(),
                    'status' => $wo->status,
                ];
            })
            ->values()
            ->toArray();

        return response()->json(['data' => $items]);
    }

    public function stats(): JsonResponse
    {
        $shiftStart = $this->currentShiftStart();
        $todayPacked = PackagingScanLog::where('scanned_at', '>=', $shiftStart)->count();

        $plan = WorkOrder::whereIn('status', [WorkOrder::STATUS_DONE, WorkOrder::STATUS_IN_PROGRESS])
            ->whereHas('eans')
            ->sum('planned_qty');

        $totalPacked = WorkOrder::whereIn('status', [WorkOrder::STATUS_DONE, WorkOrder::STATUS_IN_PROGRESS])
            ->whereHas('eans')
            ->sum('packed_qty');

        return response()->json([
            'data' => [
                'today_packed' => $todayPacked,
                'plan' => (int) $plan,
                'total_packed' => (int) $totalPacked,
                'backlog' => max(0, (int) $plan - (int) $totalPacked),
                'shift_start' => $shiftStart->toIso8601String(),
            ],
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function productLabel(WorkOrder $wo): string
    {
        $parts = array_filter([$wo->productType?->name, $wo->order_no]);

        return implode(' — ', $parts) ?: $wo->order_no;
    }

    private function currentShiftStart(): Carbon
    {
        $now = Carbon::now();
        $hour = $now->hour;
        if ($hour >= 6 && $hour < 18) {
            return $now->copy()->setTime(6, 0, 0);
        }
        if ($hour >= 18) {
            return $now->copy()->setTime(18, 0, 0);
        }

        return $now->copy()->subDay()->setTime(18, 0, 0);
    }
}
