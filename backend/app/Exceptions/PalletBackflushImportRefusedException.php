<?php

namespace App\Exceptions;

use App\Services\Settings\ScenarioException;

/**
 * A configuration file that tries to switch on pallet backflush.
 *
 * Raw material is settled exactly once, by the operation that consumes it, so
 * the switch is retired. A file that turns it on is refused whole - settings
 * and scenario alike - instead of having the value dropped quietly: an admin
 * must learn that the file says something the plant does not do.
 *
 * Extends the scenario exception so the import screen keeps its single
 * "nothing from the file was saved" path.
 */
class PalletBackflushImportRefusedException extends ScenarioException
{
    /** The setting a file may not switch on. */
    public const KEY = 'backflush_on_pallet_creation';

    public function __construct()
    {
        parent::__construct(sprintf(
            'The configuration file cannot switch on "%s": pallet material booking is disabled. Set it to false and import again.',
            self::KEY,
        ));
    }
}
