<?php

namespace App\Services\Packaging;

use App\Models\SerialUnit;
use App\Services\Traceability\BindingException;

/**
 * A label read again at the bench (P2.13). Once a label is packed it belongs
 * to its carton or pallet; a second scan is refused with where and when, never
 * quietly treated as the same move - a unit accepted twice would drift into
 * the wrong box and be counted at two stations without anyone noticing.
 */
class UnitAlreadyPackedException extends BindingException
{
    private function __construct(
        string $message,
        public readonly ?string $cartonNo,
        public readonly ?string $palletNo,
        public readonly string $packedAtLabel,
    ) {
        parent::__construct($message);
    }

    public static function for(SerialUnit $unit): self
    {
        $carton = $unit->carton?->carton_no;
        $pallet = $unit->pallet?->pallet_no;
        // Only a packed unit is refused, so the stamp is there; shown as
        // unknown rather than invented when a row says otherwise.
        $at = $unit->packed_at?->format('Y-m-d H:i') ?? __('unknown');

        $message = match (true) {
            $carton !== null && $pallet !== null => __('Unit :sn is already packed in carton :carton on pallet :pallet (:at) - rescan refused.', [
                'sn' => $unit->display_id, 'carton' => $carton, 'pallet' => $pallet, 'at' => $at,
            ]),
            $carton !== null => __('Unit :sn is already packed in carton :carton (:at) - rescan refused.', [
                'sn' => $unit->display_id, 'carton' => $carton, 'at' => $at,
            ]),
            $pallet !== null => __('Unit :sn is already packed on pallet :pallet (:at) - rescan refused.', [
                'sn' => $unit->display_id, 'pallet' => $pallet, 'at' => $at,
            ]),
            default => __('Unit :sn is already packed (:at) - rescan refused.', [
                'sn' => $unit->display_id, 'at' => $at,
            ]),
        };

        return new self($message, $carton, $pallet, $at);
    }
}
