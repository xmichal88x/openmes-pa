<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Api\V1\Erp\Concerns\BuildsCursorMeta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Erp\ProductionExportRequest;
use App\Models\BatchStep;
use App\Models\TemplateStep;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;

/**
 * OpenMES → ERP: production completion export. Emits produced quantities and
 * completion timestamps per work order, cursor-paginated for incremental
 * polling. Requires the `erp:production:read` scope; rate limited via the
 * `erp-read` limiter. Tenant scoping is applied automatically from the API
 * key's tenant (TenantContext).
 */
class ProductionExportController extends Controller
{
    use BuildsCursorMeta;

    public function completions(ProductionExportRequest $request): JsonResponse
    {
        $query = WorkOrder::query()
            ->with([
                'line:id,code,name',
                'productType:id,code,name',
                'batches:id,work_order_id,batch_number',
                'batches.steps' => fn ($q) => $q->where('kind', TemplateStep::KIND_PACKING),
            ])
            ->orderBy('id');

        // Incremental sync: everything changed at/after the ERP's last poll.
        if ($since = $request->input('since')) {
            $query->where('updated_at', '>=', $since);
        }

        // Default to completed orders — the usual ERP "post production" grain.
        $query->where('status', $request->input('status', WorkOrder::STATUS_DONE));

        if ($line = $request->input('line')) {
            $query->whereHas('line', fn ($q) => $q->where('code', $line));
        }

        $page = $query->cursorPaginate($request->perPage())->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (WorkOrder $wo) => $this->present($wo)),
            'meta' => $this->cursorMeta($page),
        ]);
    }

    /**
     * Single work order by id — lets the ERP confirm the current state of one
     * order it previously imported. 404 when the id is unknown within the key's
     * tenant.
     *
     * The model is resolved here rather than via implicit route-model binding on
     * purpose: SubstituteBindings runs before this route's API-key middleware
     * (which is not in the framework's middleware-priority list, unlike
     * auth:sanctum), so a bound model would be looked up before TenantContext is
     * set — with TenantScope disabled — and could return another tenant's order.
     * Resolving in the controller runs after the middleware, so the tenant scope
     * is always applied.
     */
    public function show(int $workOrder): JsonResponse
    {
        $order = WorkOrder::with([
            'line:id,code,name',
            'productType:id,code,name',
            'batches:id,work_order_id,batch_number',
            'batches.steps' => fn ($q) => $q->where('kind', TemplateStep::KIND_PACKING),
        ])->findOrFail($workOrder);

        return response()->json(['data' => $this->present($order)]);
    }

    private function present(WorkOrder $wo): array
    {
        $wo->loadMissing(['batches.steps' => fn ($q) => $q->where('kind', TemplateStep::KIND_PACKING)]);

        $packingSteps = $wo->batches
            ->flatMap(fn ($batch) => $batch->steps)
            ->filter(fn (BatchStep $step) => $step->kind === TemplateStep::KIND_PACKING)
            ->values();

        $sortedPackingSteps = $packingSteps
            ->sortBy([['step_number', 'asc'], ['id', 'asc']])
            ->values();

        $openPackingSteps = $sortedPackingSteps
            ->reject(fn (BatchStep $step) => in_array($step->status, [BatchStep::STATUS_DONE, BatchStep::STATUS_SKIPPED], true));

        $packingStepId = ($openPackingSteps->first() ?? $sortedPackingSteps->last())?->id;

        $packingCompletedAt = $packingSteps->isNotEmpty() && $openPackingSteps->isEmpty()
            ? $packingSteps->max('completed_at')
            : null;

        $wcOrderNumber = $wo->extra_data['wc_order_number'] ?? null;

        return [
            'order_no' => $wo->order_no,
            'customer_order_no' => $wo->customer_order_no,
            'wc_order_number' => $wcOrderNumber !== null ? (string) $wcOrderNumber : null,
            'status' => $wo->status,
            'planned_qty' => (float) $wo->planned_qty,
            'produced_qty' => (float) $wo->produced_qty,
            'unit_price' => $wo->unit_price !== null ? (float) $wo->unit_price : null,
            'counting_source' => $wo->counting_source,
            'line_code' => $wo->line?->code,
            'product_type_code' => $wo->productType?->code,
            'has_packing_step' => $packingSteps->isNotEmpty(),
            'packing_step_id' => $packingStepId,
            'packing_completed_at' => $packingCompletedAt?->toIso8601String(),
            'packing_steps_open' => $openPackingSteps->count(),
            'completed_at' => $wo->completed_at?->toIso8601String(),
            'updated_at' => $wo->updated_at?->toIso8601String(),
        ];
    }
}
