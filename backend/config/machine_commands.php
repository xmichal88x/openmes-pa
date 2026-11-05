<?php

$csv = static function (mixed $value, array $default = []): array {
    if ($value === null || $value === '') {
        return $default;
    }

    return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $item) => $item !== ''));
};

return [
    /*
    |--------------------------------------------------------------------------
    | Machine commands (P1a)
    |--------------------------------------------------------------------------
    |
    | MES writes machine_commands rows; the machine gateway consumes them and
    | reports the result back. Everything is off by default so a fork upgrade
    | does not start emitting commands on a live shop floor.
    |
    */

    'enabled' => (bool) env('MACHINE_COMMANDS_ENABLED', false),

    'types' => [
        'load_nc' => (bool) env('MACHINE_COMMANDS_LOAD_NC', false),
        'manual_m30' => (bool) env('MACHINE_COMMANDS_MANUAL_M30', false),
        'generate_labels' => (bool) env('MACHINE_COMMANDS_GENERATE_LABELS', false),
    ],

    // Lines that may issue machine commands (case-insensitive).
    'line_codes' => $csv(env('MACHINE_COMMANDS_LINE_CODES', 'CNC'), ['CNC']),

    // Tokens matched against step name, workstation code and workstation type
    // code to decide whether a step is a CNC step (case-insensitive). The line
    // code is deliberately not a needle: a CNC line can also run packing or
    // other non-CNC steps.
    // "cut" is deliberately absent: it matches substrings like "Execution".
    'step_match' => $csv(
        env('MACHINE_COMMANDS_STEP_MATCH', 'cnc,ciecie,cięcie,nest,frez'),
        ['cnc', 'ciecie', 'cięcie', 'nest', 'frez'],
    ),

    // Optional allowlist of workstation type codes. Empty = any type passes.
    'workstation_type_codes' => $csv(env('MACHINE_COMMANDS_WORKSTATION_TYPES', '')),

    'lease_seconds' => (int) env('MACHINE_COMMANDS_LEASE_SECONDS', 300),

    'max_attempts' => (int) env('MACHINE_COMMANDS_MAX_ATTEMPTS', 5),

    'retention_days' => (int) env('MACHINE_COMMANDS_RETENTION_DAYS', 90),
];
