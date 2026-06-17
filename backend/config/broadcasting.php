<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "pusher", "ably", "redis", "log", "null"
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'pusher'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over websockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        /*
        |----------------------------------------------------------------------
        | Soketi (self-hosted Pusher-compatible WebSocket server)
        |----------------------------------------------------------------------
        |
        | Soketi is used as the self-hosted WebSocket server. In production,
        | this can be swapped for Pusher by changing environment variables.
        |
        | Channel design (all private, tenant-scoped):
        |   - private-tenant.{tenantId}.user.{userId}  — individual user
        |   - private-tenant.{tenantId}                — all tenant users
        |   - private-tenant.{tenantId}.approvals      — approvers only
        |
        */
        'pusher' => [
            'driver'  => 'pusher',
            'key'     => env('PUSHER_APP_KEY'),
            'secret'  => env('PUSHER_APP_SECRET'),
            'app_id'  => env('PUSHER_APP_ID'),
            'options' => [
                'host'      => env('PUSHER_HOST', 'soketi'),
                'port'      => env('PUSHER_PORT', 6001),
                'scheme'    => env('PUSHER_SCHEME', 'http'),
                'encrypted' => true,
                'useTLS'    => env('PUSHER_SCHEME', 'http') === 'https',
                'cluster'   => env('PUSHER_APP_CLUSTER', 'mt1'),
            ],
            'client_options' => [
                // Guzzle client options
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key'    => env('ABLY_KEY'),
        ],

        'redis' => [
            'driver'     => 'redis',
            'connection' => env('BROADCAST_REDIS_CONNECTION', 'default'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
