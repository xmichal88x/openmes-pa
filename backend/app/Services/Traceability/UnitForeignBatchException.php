<?php

namespace App\Services\Traceability;

/**
 * A unit and a batch of two different work orders (P2.13). A batch always
 * belongs to one order, so accepting it would take the unit (and its
 * quantities) to another order's batch - the refusal names both orders.
 */
class UnitForeignBatchException extends BindingException
{
    /** The reason machine callers get back with the refusal. */
    public const CODE = 'unit_foreign_batch';

    public function __construct(
        public readonly string $batchNo,
        public readonly ?string $batchOrderNo,
        public readonly string $unitOrderNo,
        string $sn,
    ) {
        parent::__construct(__('Batch :batch belongs to work order :batch_order - unit :sn is on work order :unit_order.', [
            'batch' => $batchNo,
            'batch_order' => $batchOrderNo ?? __('unknown'),
            'sn' => $sn,
            'unit_order' => $unitOrderNo,
        ]));
    }
}
