<?php

namespace Modules\PlPackagingExtras\Support;

use App\Models\WorkOrder;
use App\Models\WorkOrderEan;

/**
 * P1 response shapes shared by the packaging API controllers.
 *
 * Kept static and pure so the JSON contract stays byte-for-byte comparable with
 * the production P1 controller that the PA queue already consumes.
 */
final class PackagingPayload
{
    /**
     * @param  iterable<int, WorkOrderEan>  $codes
     * @return array<int, array<string, mixed>>
     */
    public static function codes(iterable $codes): array
    {
        $payload = [];
        foreach ($codes as $code) {
            $payload[] = [
                'ean' => $code->ean,
                'part_seq' => $code->part_seq,
                'label_batch_id' => $code->label_batch_id,
                'state' => $code->state,
                ...self::partMetadata($code),
            ];
        }

        return $payload;
    }

    /**
     * R07 M4 metadata sent by PA with a part label.
     *
     * @return array{part_name: ?string, material: ?string, width_mm: ?float, height_mm: ?float, thickness_mm: ?float, sheet_name: ?string}
     */
    public static function partMetadata(WorkOrderEan $code): array
    {
        return [
            'part_name' => $code->part_name,
            'material' => $code->material,
            'width_mm' => $code->width_mm !== null ? (float) $code->width_mm : null,
            'height_mm' => $code->height_mm !== null ? (float) $code->height_mm : null,
            'thickness_mm' => $code->thickness_mm !== null ? (float) $code->thickness_mm : null,
            'sheet_name' => $code->sheet_name,
        ];
    }

    /**
     * @return array{id: int, order_no: string, customer_order_no: ?string, status: string, planned_qty: int, line_code: ?string}
     */
    public static function workOrder(WorkOrder $workOrder, ?string $lineCode): array
    {
        return [
            'id' => $workOrder->id,
            'order_no' => $workOrder->order_no,
            'customer_order_no' => $workOrder->customer_order_no,
            'status' => $workOrder->status,
            'planned_qty' => (int) $workOrder->planned_qty,
            'line_code' => $lineCode,
        ];
    }
}
