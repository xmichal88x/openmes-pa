<?php

namespace App\Exceptions;

use Exception;

/**
 * Raised whenever something tries to book raw material from a pallet.
 *
 * Raw material is settled exactly once, by the operation that consumes it.
 * Pallet creation, closing, retrying or deleting must never write a stock
 * movement - so the pallet backflush entry points raise this instead.
 */
class PalletBackflushDisabledException extends Exception
{
    /**
     * Machine-readable reason. `Exception::getCode()` is final and integer-only,
     * so the reason travels in its own (immutable) property.
     */
    public const CODE = 'backflush_disabled_pending_reconciliation';

    public function __construct(
        public readonly string $reasonCode = self::CODE,
    ) {
        parent::__construct(
            'Automatic material booking from pallets is disabled: raw material is settled once by the operation that consumes it. No stock movement was recorded.'
        );
    }
}
