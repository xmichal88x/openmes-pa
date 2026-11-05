<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PalletStatus;
use App\Http\Controllers\Concerns\PackagingLineAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignPackagingBoxCodeRequest;
use App\Http\Requests\AssignPackagingBoxPalletRequest;
use App\Http\Requests\ClosePackagingBoxRequest;
use App\Http\Requests\StorePackagingBoxRequest;
use App\Http\Requests\StorePackagingPalletRequest;
use App\Models\PackagingBox;
use App\Models\Pallet;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use App\Services\Packaging\PackagingBoxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackagingBoxController extends Controller
{
    use PackagingLineAccess;

    public function index(Request $request): JsonResponse
    {
        $query = PackagingBox::with('workOrder:id,order_no,line_id', 'pallet:id,pallet_no')
            ->when($request->integer('work_order_id'), fn ($q, $id) => $q->where('work_order_id', $id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->integer('pallet_id'), fn ($q, $id) => $q->where('pallet_id', $id));

        $workOrderId = $request->integer('work_order_id');
        $legacyWithoutBoxes = false;
        if ($workOrderId) {
            $workOrder = WorkOrder::findOrFail($workOrderId);
            $this->authorize('view', $workOrder);
            $legacyWithoutBoxes = $workOrder->status === WorkOrder::STATUS_DONE
                && ! PackagingBox::where('work_order_id', $workOrder->id)->exists();
        }

        if ($lineIds = $this->operatorLineIds($request)) {
            $query->whereHas('workOrder', fn ($q) => $q->whereIn('line_id', $lineIds));
        }

        $boxes = $query->orderByDesc('id')->limit(200)->get()
            ->map(fn (PackagingBox $box) => $this->present($box));

        return response()->json([
            'data' => $boxes,
            'meta' => ['legacy_without_boxes' => $legacyWithoutBoxes],
        ]);
    }

    public function store(StorePackagingBoxRequest $request, PackagingBoxService $service): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($request->integer('work_order_id'));
        $this->authorize('dispatch', $workOrder);
        $this->assertWorkOrderLineAccess($request, $workOrder);

        $box = $service->create($workOrder, $request->filled('weight_kg') ? (float) $request->input('weight_kg') : null);

        return response()->json(['data' => $this->present($box)], 201);
    }

    public function show(Request $request, PackagingBox $box): JsonResponse
    {
        $box->loadMissing('workOrder:id,order_no,line_id', 'pallet:id,pallet_no', 'eans');
        $this->authorize('view', $box->workOrder);
        $this->assertWorkOrderLineAccess($request, $box->workOrder);

        return response()->json(['data' => $this->present($box, withParts: true)]);
    }

    public function close(ClosePackagingBoxRequest $request, PackagingBox $box, PackagingBoxService $service): JsonResponse
    {
        $this->authorize('dispatch', $box->workOrder);
        $this->assertBoxLineAccess($request, $box);

        $box = $service->close($box, $request->filled('weight_kg') ? (float) $request->input('weight_kg') : null);

        return response()->json(['data' => $this->present($box)]);
    }

    public function reopen(Request $request, PackagingBox $box, PackagingBoxService $service): JsonResponse
    {
        $this->authorize('dispatch', $box->workOrder);
        $this->assertBoxLineAccess($request, $box);

        return response()->json(['data' => $this->present($service->reopen($box))]);
    }

    public function assignCode(AssignPackagingBoxCodeRequest $request, PackagingBox $box, PackagingBoxService $service): JsonResponse
    {
        $this->authorize('dispatch', $box->workOrder);
        $this->assertBoxLineAccess($request, $box);

        $code = $request->filled('work_order_ean_id')
            ? WorkOrderEan::find($request->integer('work_order_ean_id'))
            : WorkOrderEan::where('ean', $request->input('ean'))->first();

        if (! $code) {
            return response()->json(['message' => __('Unknown EAN')], 404);
        }

        $box = $service->assignCode($box, $code);

        return response()->json([
            'data' => $this->present($box),
            'message' => __('Part :code assigned to :box.', ['code' => $code->ean, 'box' => $box->box_id]),
        ]);
    }

    public function assignPallet(AssignPackagingBoxPalletRequest $request, PackagingBox $box, PackagingBoxService $service): JsonResponse
    {
        $this->authorize('dispatch', $box->workOrder);
        $this->assertBoxLineAccess($request, $box);

        $pallet = Pallet::findOrFail($request->integer('pallet_id'));
        $box = $service->assignPallet($box, $pallet);

        return response()->json([
            'data' => $this->present($box),
            'pallet_qty' => (int) $pallet->fresh()->qty,
        ]);
    }

    public function unassignPallet(Request $request, PackagingBox $box, PackagingBoxService $service): JsonResponse
    {
        $this->authorize('dispatch', $box->workOrder);
        $this->assertBoxLineAccess($request, $box);

        $pallet = $box->pallet;

        $box = $service->unassignPallet($box);

        return response()->json([
            'data' => $this->present($box),
            'pallet_qty' => $pallet ? (int) $pallet->fresh()->qty : null,
        ]);
    }

    public function destroy(Request $request, PackagingBox $box, PackagingBoxService $service): JsonResponse
    {
        $this->authorize('dispatch', $box->workOrder);
        $this->assertBoxLineAccess($request, $box);

        $service->delete($box);

        return response()->json(null, 204);
    }

    // ── Boxes-mode pallets ───────────────────────────────────────────────────

    public function pallets(Request $request): JsonResponse
    {
        $query = Pallet::where('kind', Pallet::KIND_BOXES)
            ->with('workOrder:id,order_no,line_id')
            ->withCount('boxes')
            ->when($request->integer('work_order_id'), fn ($q, $id) => $q->where('work_order_id', $id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status));

        if ($request->integer('work_order_id')) {
            $this->authorize('view', WorkOrder::findOrFail($request->integer('work_order_id')));
        }

        if ($lineIds = $this->operatorLineIds($request)) {
            $query->whereHas('workOrder', fn ($q) => $q->whereIn('line_id', $lineIds));
        }

        $pallets = $query->orderByDesc('id')->limit(200)->get()->map(fn (Pallet $pallet) => [
            'id' => $pallet->id,
            'pallet_no' => $pallet->pallet_no,
            'work_order_id' => $pallet->work_order_id,
            'order_no' => $pallet->workOrder?->order_no,
            'qty' => (int) $pallet->qty,
            'kind' => $pallet->kind,
            'status' => $pallet->status?->value,
            'quality_status' => $pallet->quality_status,
            'location' => $pallet->location,
            'boxes_count' => (int) $pallet->boxes_count,
        ]);

        return response()->json(['data' => $pallets]);
    }

    public function storePallet(StorePackagingPalletRequest $request): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($request->integer('work_order_id'));
        $this->authorize('dispatch', $workOrder);
        $this->assertWorkOrderLineAccess($request, $workOrder);

        $pallet = Pallet::create([
            'work_order_id' => $workOrder->id,
            'qty' => 0,
            'kind' => Pallet::KIND_BOXES,
            'status' => PalletStatus::Open->value,
            'location' => $request->input('location'),
            'active_by_id' => $request->user()?->id,
            'active_workstation_id' => $request->user()?->workstation_id,
            'activated_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'id' => $pallet->id,
                'pallet_no' => $pallet->pallet_no,
                'work_order_id' => $pallet->work_order_id,
                'qty' => 0,
                'kind' => $pallet->kind,
                'status' => $pallet->status?->value,
                'location' => $pallet->location,
            ],
        ], 201);
    }

    // ── Presenters ───────────────────────────────────────────────────────────

    private function present(PackagingBox $box, bool $withParts = false): array
    {
        $payload = [
            'id' => $box->id,
            'work_order_id' => $box->work_order_id,
            'order_no' => $box->workOrder?->order_no,
            'box_no' => (int) $box->box_no,
            'box_id' => $box->box_id,
            'part_count' => (int) $box->part_count,
            'status' => $box->status,
            'weight_kg' => $box->weight_kg !== null ? (string) $box->weight_kg : null,
            'pallet_id' => $box->pallet_id,
            'pallet_no' => $box->pallet?->pallet_no,
            'closed_at' => $box->closed_at?->toIso8601String(),
            'created_at' => $box->created_at?->toIso8601String(),
            'label_pdf' => route('packaging.boxes.label.pdf', $box),
            'label_zpl' => route('packaging.boxes.label.zpl', $box),
        ];

        if ($withParts) {
            $payload['parts'] = $box->eans->map(fn (WorkOrderEan $ean) => [
                'id' => $ean->id,
                'ean' => $ean->ean,
                'code_type' => $ean->code_type,
                'part_seq' => $ean->part_seq,
                'part_name' => $ean->part_name,
                'material' => $ean->material,
                'width_mm' => $ean->width_mm,
                'height_mm' => $ean->height_mm,
                'thickness_mm' => $ean->thickness_mm,
                'sheet_name' => $ean->sheet_name,
            ])->values()->all();
        }

        return $payload;
    }

    private function assertBoxLineAccess(Request $request, PackagingBox $box): void
    {
        $box->loadMissing('workOrder:id,order_no,line_id');

        if (! $box->workOrder) {
            abort(404, __('Work order not found'));
        }

        $this->assertWorkOrderLineAccess($request, $box->workOrder);
    }
}
