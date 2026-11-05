<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue start preflight
    |--------------------------------------------------------------------------
    |
    | Before a batch step is started, OpenMES asks the queue runtime whether the
    | work order is safe to run: is a nesting rebuild in progress, is the order
    | merged into a shared sheet, and if so what is the target job? The queue
    | endpoint is optional infrastructure — when it is unreachable the start is
    | allowed with a warning (option B), never blocked by transport failure.
    |
    */

    'enabled' => env('QUEUE_PREFLIGHT_ENABLED', true),

    'url' => env('QUEUE_PREFLIGHT_URL', 'http://192.168.100.123:8095/v1/start-preflight'),

    /*
    | The start lease (R16/1) and its release live next to the read-only
    | preflight. When unset they are derived from `url` by replacing
    | `/start-preflight`, so a single base URL keeps both endpoints in sync.
    */

    'lease_url' => env('QUEUE_LEASE_URL'),

    'release_url' => env('QUEUE_LEASE_RELEASE_URL'),

    'token' => env('QUEUE_PREFLIGHT_TOKEN'),

    'timeout' => env('QUEUE_PREFLIGHT_TIMEOUT', 1.0),

];
