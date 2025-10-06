<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WebSockets dashboard and apps
    |--------------------------------------------------------------------------
    |
    | Minimal default config so the application can run without running
    | `vendor:publish`. You can publish the full config with the package.
    |
    */
    'apps' => [
        [
            'id' => env('PUSHER_APP_ID', 'local'),
            'name' => env('APP_NAME', 'Laravel'),
            'key' => env('PUSHER_APP_KEY', 'local'),
            'secret' => env('PUSHER_APP_SECRET', 'local'),
            'path' => env('PUSHER_APP_PATH', ''),
            'capacity' => null,
            'enable_client_messages' => false,
            'enable_statistics' => true,
        ],
    ],

    'dashboard' => [
        'port' => env('WEBSOCKETS_DASHBOARD_PORT', 6001),
    ],

    'statistics' => [
        'model' => \BeyondCode\LaravelWebSockets\Statistics\Models\WebSocketsStatisticsEntry::class,
    ],

    'ssl' => [
        'local_cert' => null,
        'local_pk' => null,
        'passphrase' => null,
    ],

    'replication' => [
        'enabled' => false,
    ],

    'max_request_size_in_kb' => 250,
];
