<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Dispatch weight tolerance
    |--------------------------------------------------------------------------
    |
    | The dispatch weight may differ from the sum of the closed boxes' weights
    | by at most this percentage before the operator has to correct it. Set to
    | null to disable the check.
    |
    */
    'dispatch_weight_tolerance_pct' => env('PACKAGING_DISPATCH_WEIGHT_TOLERANCE_PCT', 2.0),
];
