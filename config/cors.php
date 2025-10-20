<?php

return [
    'paths' => ['api/*', 'login', 'logout', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],

    // Load from .env, fallback to production + local dev URLs
    'allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS',
        'https://modeh.co.ke,https://admin.modeh.co.ke,http://localhost:5173,http://127.0.0.1:5173'
    )))),

    // Optionally allow all subdomains of modeh.co.ke:
    'allowed_origins_patterns' => ['/^https:\/\/.*\.modeh\.co\.ke$/'],

    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];