<?php

namespace App\Exceptions;

use App\Services\Settings\ScenarioException;

/**
 * A configuration file that would rewrite a material whose withdrawal still
 * belongs to its production operation.
 *
 * One item, one author: while the settlement is pending the balance the
 * operation withdrew is the truth, and importing another one over it would book
 * the consumption twice (or lose it). The file is refused whole instead of
 * importing everything except the one row - an admin must learn that the file
 * conflicts with production in flight.
 *
 * Extends the scenario exception so the import screen keeps its single
 * "nothing from the file was saved" path.
 */
class MaterialSettlementOwnershipException extends ScenarioException
{
    public function __construct(public readonly string $materialCode)
    {
        parent::__construct(sprintf(
            'The configuration file rewrites material "%s", whose stock is still awaiting settlement by its production operation. '
            .'One item is settled once, by the operation that consumed it: settle or reverse that withdrawal and import again.',
            $materialCode,
        ));
    }
}
