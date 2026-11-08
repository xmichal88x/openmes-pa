<?php

namespace App\Http\Controllers\Api\V1\Quality;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Quality\RecutCandidatesRequest;
use App\Models\UnitStepHistory;
use Illuminate\Http\JsonResponse;

/**
 * Incremental feed of QA `recut` decisions for the queue runtime.
 *
 * The cursor is the history `id`, so paging is stable even when several rows
 * share a `processed_at` timestamp. `unit_step_history` carries no tenant_id —
 * tenancy is inherited through `serialUnit`, and `whereHas('serialUnit')` is
 * what applies the HasTenant global scope (and keeps orphan rows out).
 */
class RecutCandidatesController extends Controller
{
    public function index(RecutCandidatesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $afterId = (int) ($data['after_id'] ?? 0);
        $limit = (int) ($data['limit'] ?? 200);

        $rows = UnitStepHistory::query()
            ->where('decision', 'recut')
            ->where('id', '>', $afterId)
            ->whereHas('serialUnit')
            ->with([
                'serialUnit:id,serial_no,psn,work_order_id,batch_id,status',
                'serialUnit.workOrder:id,order_no',
            ])
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);

        return response()->json([
            'data' => $page->map(function (UnitStepHistory $row): array {
                $unit = $row->serialUnit;

                return [
                    'history_id' => $row->id,
                    'serial_unit_id' => $row->serial_unit_id,
                    'serial_no' => $unit?->serial_no,
                    'psn' => $unit?->psn,
                    'work_order_id' => $unit?->work_order_id,
                    'order_no' => $unit?->workOrder?->order_no,
                    'batch_id' => $unit?->batch_id,
                    'decision' => $row->decision,
                    'processed_at' => $row->processed_at?->toIso8601String(),
                    'unit_status' => $unit?->status,
                ];
            })->all(),
            'meta' => [
                'last_id' => $page->isNotEmpty() ? (int) $page->last()->id : $afterId,
                'has_more' => $hasMore,
            ],
        ]);
    }
}
