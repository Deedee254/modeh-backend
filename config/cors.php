<?php

return [
    'paths' => ['*'],
    'allowed_methods' => ['*'],
    // Allow configuring origins from .env (comma-separated). If not set,
    // fall back to a reasonable set of local dev and production origins.
    'allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS',
        'http://localhost:3000,http://127.0.0.1:3000,http://localhost:5173,http://127.0.0.1:5173,http://127.0.0.1:8000,https://modeh.co.ke,https://admin.modeh.co.ke'
    )))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
