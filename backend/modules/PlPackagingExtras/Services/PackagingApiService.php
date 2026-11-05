<?php

namespace Modules\PlPackagingExtras\Services;

use App\Models\PackagingScanLog;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use App\Services\Packaging\PackagingScanService;
use Carbon\Carbon;

/**
 * Read models and expected-code bookkeeping for the P1 packaging API.
 *
 * All scans still go through the core PackagingScanService: this class only
 * prepares payloads and owns the idempotent expected-code upsert.
 */
class PackagingApiService
{
    /**
     * Packing dashboard items, P1 shape (EAN lists only, no serial units).
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        $eansByWorkOrder = WorkOrderEan::select('work_order_id', 'ean')
            ->get()
            ->groupBy('work_order_id');

        return WorkOrder::whereIn('status', [WorkOrder::STATUS_DONE, WorkOrder::STATUS_IN_PROGRESS])
            ->with('productType', 'line')
            ->orderByDesc('priority')
            ->get()
            ->filter(fn (WorkOrder $wo) => $eansByWorkOrder->has($wo->id))
            ->map(function (WorkOrder $wo) use ($eansByWorkOrder) {
                $planned = (int) $wo->planned_qty;
                $packed = (int) $wo->packed_qty;

                return [
                    'id' => $wo->id,
                    'order_no' => $wo->order_no,
                    'product' => PackagingScanService::productLabel($wo),
                    'line' => $wo->line?->name,
                    'planned_qty' => $planned,
                    'packed_qty' => $packed,
                    'progress' => $planned > 0 ? min(100, (int) round($packed / $planned * 100)) : 0,
                    'done' => $planned > 0 && $packed >= $planned,
                    'eans' => $eansByWorkOrder[$wo->id]->pluck('ean')->values()->all(),
                    'status' => $wo->status,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{today_packed: int, plan: int, total_packed: int, backlog: int, shift_start: string}
     */
    public function stats(): array
    {
        $shiftStart = $this->currentShiftStart();
        $todayPacked = PackagingScanLog::where('scanned_at', '>=', $shiftStart)->count();

        $plan = WorkOrder::whereIn('status', [WorkOrder::STATUS_DONE, WorkOrder::STATUS_IN_PROGRESS])
            ->whereHas('eans')
            ->sum('planned_qty');

        $totalPacked = WorkOrder::whereIn('status', [WorkOrder::STATUS_DONE, WorkOrder::STATUS_IN_PROGRESS])
            ->whereHas('eans')
            ->sum('packed_qty');

        return [
            'today_packed' => $todayPacked,
            'plan' => (int) $plan,
            'total_packed' => (int) $totalPacked,
            'backlog' => max(0, (int) $plan - (int) $totalPacked),
            'shift_start' => $shiftStart->toIso8601String(),
        ];
    }

    /**
     * Replace the full expected-code list for a work order (idempotent).
     *
     * Part labels in the payload become EXPECTED (or keep SCANNED / revive
     * VOID); part labels no longer present are voided. Legacy EAN codes are
     * left untouched.
     *
     * @param  array<int, array{code: string, label_batch_id?: ?string, part_seq?: ?int}>  $codes
     * @return array{expected: int, voided: int, codes: \Illuminate\Support\Collection<int, WorkOrderEan>}
     */
    public function upsertExpectedCodes(WorkOrder $workOrder, array $codes, bool $voidMissing = true): array
    {
        $incoming = [];
        foreach ($codes as $row) {
            $incoming[$row['code']] = [
                'label_batch_id' => $row['label_batch_id'] ?? null,
                'part_seq' => $row['part_seq'] ?? null,
                'metadata' => $this->partMetadata($row),
            ];
        }

        $existingByEan = WorkOrderEan::withTrashed()
            ->whereIn('ean', array_keys($incoming))
            ->get()
            ->keyBy('ean');

        $conflicts = $existingByEan
            ->filter(fn (WorkOrderEan $code) => $code->trashed() || (int) $code->work_order_id !== (int) $workOrder->id)
            ->keys()
            ->all();

        if ($conflicts !== []) {
            abort(409, __('Codes already belong to another work order: :codes', ['codes' => implode(', ', $conflicts)]));
        }

        foreach ($incoming as $ean => $meta) {
            $record = $existingByEan->get($ean);

            if (! $record) {
                WorkOrderEan::create(array_merge([
                    'work_order_id' => $workOrder->id,
                    'ean' => $ean,
                    'code_type' => WorkOrderEan::CODE_TYPE_PART_LABEL,
                    'label_batch_id' => $meta['label_batch_id'],
                    'part_seq' => $meta['part_seq'],
                    'state' => WorkOrderEan::STATE_EXPECTED,
                ], $meta['metadata']));

                continue;
            }

            $state = $record->state === WorkOrderEan::STATE_VOID
                ? WorkOrderEan::STATE_EXPECTED
                : $record->state;

            $record->update(array_merge([
                'code_type' => WorkOrderEan::CODE_TYPE_PART_LABEL,
                'label_batch_id' => $meta['label_batch_id'],
                'part_seq' => $meta['part_seq'],
                'state' => $state,
            ], $meta['metadata']));
        }

        $voided = 0;
        if ($voidMissing) {
            $voided = WorkOrderEan::where('work_order_id', $workOrder->id)
                ->where('code_type', WorkOrderEan::CODE_TYPE_PART_LABEL)
                ->whereNotIn('ean', array_keys($incoming))
                ->where('state', '!=', WorkOrderEan::STATE_VOID)
                ->update(['state' => WorkOrderEan::STATE_VOID]);
        }

        $expected = WorkOrderEan::where('work_order_id', $workOrder->id)
            ->where('state', WorkOrderEan::STATE_EXPECTED)
            ->count();

        return [
            'expected' => $expected,
            'voided' => $voided,
            'codes' => $this->packingCodes($workOrder),
        ];
    }

    /**
     * @param  array<int, array{code: string}>  $codes
     */
    public function assertNoDuplicateCodes(array $codes): void
    {
        $duplicates = collect($codes)->pluck('code')->duplicates()->values()->all();
        if ($duplicates !== []) {
            abort(422, __('Duplicate codes in payload: :codes', ['codes' => implode(', ', $duplicates)]));
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, WorkOrderEan>
     */
    public function packingCodes(WorkOrder $workOrder)
    {
        return WorkOrderEan::where('work_order_id', $workOrder->id)
            ->where('code_type', WorkOrderEan::CODE_TYPE_PART_LABEL)
            ->orderByRaw('part_seq IS NULL')
            ->orderBy('part_seq')
            ->orderBy('ean')
            ->get();
    }

    /**
     * Only fields present in the payload are written, so an older PA that still
     * sends three fields does not erase metadata stored by a newer one.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function partMetadata(array $row): array
    {
        $metadata = [];
        foreach (['part_name', 'material', 'width_mm', 'height_mm', 'thickness_mm', 'sheet_name'] as $field) {
            if (array_key_exists($field, $row)) {
                $metadata[$field] = $row[$field];
            }
        }

        return $metadata;
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
