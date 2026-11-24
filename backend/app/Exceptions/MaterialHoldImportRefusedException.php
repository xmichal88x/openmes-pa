<?php

namespace App\Exceptions;

use App\Services\Settings\ScenarioException;

/**
 * A configuration file that tries to switch on the material shortage hold.
 *
 * The hold is a gate the admin opens on its own form, after the stock balances
 * are declared - a file cannot know the plant's balances. A file that turns it
 * on is refused whole - settings and scenario alike - instead of having the
 * value dropped quietly: an admin must learn that the file skips that step.
 *
 * Extends the scenario exception so the import screen keeps its single
 * "nothing from the file was saved" path.
 */
class MaterialHoldImportRefusedException extends ScenarioException
{
    /** The setting a file may not switch on. */
    public const KEY = 'hold_on_material_shortage';

    public function __construct()
    {
        parent::__construct(sprintf(
            'The configuration file cannot switch on "%s": the shortage hold is switched on in the settings form, once stock balances are declared. Switch it on there, or set it to false and import again.',
            self::KEY,
        ));
    }
}
