<?php

namespace App\Support;

use App\Enums\PalletStatus;
use App\Models\PackagingBox;
use App\Models\PackagingScanLog;
use App\Models\Pallet;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;

final class WorkOrderPackingList
{
    public static function data(WorkOrder $workOrder): array
    {
        $workOrder->loadMissing(['productType:id,name,code,unit', 'customer:id,name']);

        $boxes = PackagingBox::where('work_order_id', $workOrder->id)
            ->where('status', '!=', PackagingBox::STATUS_VOID)
            ->with(['eans' => fn ($query) => $query->orderBy('part_seq')->orderBy('ean')])
            ->orderBy('box_no')
            ->get();

        $pallets = Pallet::where('work_order_id', $workOrder->id)->orderBy('pallet_no')->get();

        $looseLogs = PackagingScanLog::where('work_order_id', $workOrder->id)
            ->whereNull('packaging_box_id')
            ->whereNotNull('pallet_id')
            ->orderBy('scanned_at')
            ->orderBy('id')
            ->get();

        $codes = WorkOrderEan::where('work_order_id', $workOrder->id)
            ->whereIn('ean', $looseLogs->pluck('ean')->unique()->values())
            ->get()
            ->keyBy('ean');

        $boxRowsByPallet = [];
        foreach ($boxes as $box) {
            $boxRowsByPallet[$box->pallet_id ?? 0][] = self::boxRow($box);
        }

        $palletRows = [];
        foreach ($pallets as $pallet) {
            $palletBoxes = $boxRowsByPallet[$pallet->id] ?? [];
            $looseParts = $looseLogs
                ->where('pallet_id', $pallet->id)
                ->map(fn (PackagingScanLog $log) => self::loosePartRow($log, $codes))
                ->values()
                ->all();

            if ($palletBoxes === [] && $looseParts === []) {
                continue;
            }

            $palletRows[] = [
                'id' => $pallet->id,
                'pallet_no' => $pallet->pallet_no,
                'kind' => $pallet->kind,
                'status' => $pallet->status instanceof PalletStatus ? $pallet->status->value : $pallet->status,
                'qty' => (int) $pallet->qty,
                'boxes' => $palletBoxes,
                'loose_parts' => $looseParts,
                'box_count' => count($palletBoxes),
                'part_count' => array_sum(array_map(fn (array $box) => count($box['parts']), $palletBoxes)) + count($looseParts),
            ];
        }

        $unassignedBoxes = $boxes
            ->whereNull('pallet_id')
            ->map(fn (PackagingBox $box) => self::boxRow($box))
            ->values()
            ->all();

        $looseCodes = $looseLogs->pluck('ean')->all();
        $unassignedParts = WorkOrderEan::where('work_order_id', $workOrder->id)
            ->where('state', WorkOrderEan::STATE_SCANNED)
            ->whereNull('packaging_box_id')
            ->whereNotIn('ean', $looseCodes)
            ->orderBy('part_seq')
            ->orderBy('ean')
            ->get()
            ->map(fn (WorkOrderEan $ean) => self::partRow($ean))
            ->values()
            ->all();

        $boxCount = count($boxes);
        $palletCount = count($palletRows);
        $partCount = array_sum(array_map(fn (array $box) => count($box['parts']), $unassignedBoxes))
            + array_sum(array_map(fn (array $pallet) => $pallet['part_count'], $palletRows))
            + count($unassignedParts);
        $weight = (float) $boxes->sum(fn (PackagingBox $box) => (float) ($box->weight_kg ?? 0));

        return [
            'work_order' => [
                'id' => $workOrder->id,
                'order_no' => $workOrder->order_no,
                'customer_order_no' => $workOrder->customer_order_no,
                'customer' => $workOrder->customer?->name,
                'product' => $workOrder->productType?->name,
                'product_code' => $workOrder->productType?->code,
                'status' => $workOrder->status,
                'created_at' => $workOrder->created_at?->format('Y-m-d H:i'),
                'due_date' => $workOrder->due_date?->format('Y-m-d'),
            ],
            'pallets' => $palletRows,
            'unassigned_boxes' => $unassignedBoxes,
            'unassigned_parts' => $unassignedParts,
            'totals' => [
                'pallets' => $palletCount,
                'boxes' => $boxCount,
                'parts' => $partCount,
                'weight_kg' => self::formatQty($weight),
            ],
            'has_packing' => $boxCount > 0 || $palletRows !== [] || $unassignedParts !== [],
        ];
    }

    /**
     * The packing list of one pallet: boxes on it (boxes mode) or the parts
     * scanned straight onto it (loose mode), never both. Used by the per-pallet
     * PDF; the work-order list above remains the full picture.
     */
    public static function palletData(Pallet $pallet): array
    {
        $pallet->loadMissing(['workOrder.productType:id,name,code,unit', 'workOrder.customer:id,name']);
        $workOrder = $pallet->workOrder;

        $boxes = PackagingBox::where('pallet_id', $pallet->id)
            ->where('status', '!=', PackagingBox::STATUS_VOID)
            ->with(['eans' => fn ($query) => $query->orderBy('part_seq')->orderBy('ean')])
            ->orderBy('box_no')
            ->get();

        $looseLogs = PackagingScanLog::where('pallet_id', $pallet->id)
            ->whereNull('packaging_box_id')
            ->orderBy('scanned_at')
            ->orderBy('id')
            ->get();

        $codes = WorkOrderEan::where('work_order_id', $pallet->work_order_id)
            ->whereIn('ean', $looseLogs->pluck('ean')->unique()->values())
            ->get()
            ->keyBy('ean');

        $boxRows = $boxes->map(fn (PackagingBox $box) => self::boxRow($box))->values()->all();
        $looseParts = $looseLogs
            ->map(fn (PackagingScanLog $log) => self::loosePartRow($log, $codes))
            ->values()
            ->all();

        $partCount = array_sum(array_map(fn (array $box) => count($box['parts']), $boxRows)) + count($looseParts);
        $weight = (float) $boxes->sum(fn (PackagingBox $box) => (float) ($box->weight_kg ?? 0));

        return [
            'pallet' => [
                'id' => $pallet->id,
                'pallet_no' => $pallet->pallet_no,
                'kind' => $pallet->kind,
                'status' => $pallet->status instanceof PalletStatus ? $pallet->status->value : $pallet->status,
                'quality_status' => $pallet->quality_status,
                'qty' => (int) $pallet->qty,
                'location' => $pallet->location,
                'destination' => $pallet->destination,
                'erp_reference' => $pallet->erp_reference,
                'order_no' => $workOrder?->order_no,
                'customer_order_no' => $workOrder?->customer_order_no,
                'customer' => $workOrder?->customer?->name,
                'product' => $workOrder?->productType?->name,
                'product_code' => $workOrder?->productType?->code,
                'created_at' => $pallet->created_at?->format('Y-m-d H:i'),
                'shipped_at' => $pallet->shipped_at?->format('Y-m-d H:i'),
            ],
            'boxes' => $boxRows,
            'loose_parts' => $looseParts,
            'totals' => [
                'boxes' => count($boxRows),
                'parts' => $partCount,
                'weight_kg' => self::formatQty($weight),
            ],
            'has_packing' => $boxRows !== [] || $looseParts !== [],
        ];
    }

    private static function boxRow(PackagingBox $box): array
    {
        $weight = $box->weight_kg !== null ? self::formatQty($box->weight_kg) : null;

        return [
            'id' => $box->id,
            'box_id' => $box->box_id,
            'box_no' => (int) $box->box_no,
            'status' => $box->status,
            'pallet_id' => $box->pallet_id,
            'part_count' => $box->eans->count(),
            'weight_kg' => $weight,
            'closed_at' => $box->closed_at?->format('Y-m-d H:i'),
            'parts' => $box->eans
                ->map(fn (WorkOrderEan $ean) => self::partRow($ean, $weight))
                ->values()
                ->all(),
        ];
    }

    private static function loosePartRow(PackagingScanLog $log, $codes): array
    {
        $ean = $codes->get($log->ean);

        return $ean ? self::partRow($ean) : [
            'id' => null,
            'label_id' => $log->ean,
            'part_seq' => null,
            'part_name' => null,
            'material' => null,
            'width_mm' => null,
            'height_mm' => null,
            'thickness_mm' => null,
            'sheet_name' => null,
            'dimensions' => null,
            'state' => WorkOrderEan::STATE_SCANNED,
            'weight_kg' => null,
            'scanned_at' => $log->scanned_at?->format('Y-m-d H:i'),
        ];
    }

    private static function partRow(WorkOrderEan $ean, ?string $weight = null): array
    {
        $dimensions = collect([$ean->width_mm, $ean->height_mm, $ean->thickness_mm])
            ->filter(fn ($value) => $value !== null)
            ->map(fn ($value) => self::formatQty($value))
            ->implode(' × ');

        return [
            'id' => $ean->id,
            'label_id' => $ean->ean,
            'part_seq' => $ean->part_seq,
            'part_name' => $ean->part_name,
            'material' => $ean->material,
            'width_mm' => $ean->width_mm !== null ? (float) $ean->width_mm : null,
            'height_mm' => $ean->height_mm !== null ? (float) $ean->height_mm : null,
            'thickness_mm' => $ean->thickness_mm !== null ? (float) $ean->thickness_mm : null,
            'sheet_name' => $ean->sheet_name,
            'dimensions' => $dimensions !== '' ? $dimensions.' mm' : null,
            'state' => $ean->state,
            'weight_kg' => $weight,
            'scanned_at' => null,
        ];
    }

    private static function formatQty($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
