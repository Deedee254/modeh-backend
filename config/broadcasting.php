<?php

return [

    'default' => env('BROADCAST_CONNECTION', env('BROADCAST_DRIVER', 'null')),

    'connections' => [

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'useTLS' => true,
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

        // Reverb (Pusher-compatible) connection. Configure REVERB_* env vars
        // in your environment to point the client to the Reverb server.
        'reverb' => [
            'driver' => 'pusher',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT'),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_USE_TLS', true),
                'encrypted' => env('REVERB_USE_TLS', true),
            ],
        ],

    ],

];

