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

    /*
    |--------------------------------------------------------------------------
    | AlphaCAM sheet labels (PA / CSA service)
    |--------------------------------------------------------------------------
    |
    | Read-only proxy to the production-automation label store. The MES never
    | generates these labels; it only lists and streams the PDFs that PA has
    | already produced for a batch. Leave the URL empty to hide the feature.
    |
    */
    'alphacam_labels' => [
        'base_url' => env('ALPHACAM_LABELS_BASE_URL'),
        'token' => env('ALPHACAM_LABELS_TOKEN'),
        'timeout' => (float) env('ALPHACAM_LABELS_TIMEOUT', 5.0),
    ],
];
