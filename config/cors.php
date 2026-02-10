<?php

// Require CORS_ALLOWED_ORIGINS to be explicitly provided via environment.
// This avoids silently falling back to localhost values that may be
// baked into builds or left in .env files. The value should be a
// comma-separated list of origins, for example:
//   CORS_ALLOWED_ORIGINS=https://modeh.co.ke,https://admin.modeh.co.ke

$corsOrigins = env('CORS_ALLOWED_ORIGINS');
if (!$corsOrigins) {
    throw new \RuntimeException('Missing required environment variable: CORS_ALLOWED_ORIGINS');
}

$allowedOrigins = array_filter(array_map('trim', explode(',', $corsOrigins)));

return [
    'paths' => ['api/*', 'login', 'logout', 'sanctum/csrf-cookie', 'broadcasting/*'],
    'allowed_methods' => ['*'],

    // Origins must come from the CORS_ALLOWED_ORIGINS env var (comma-separated)
    'allowed_origins' => $allowedOrigins,

    // Optionally allow all subdomains of modeh.co.ke:
    'allowed_origins_patterns' => ['/^https:\/\/(.*\.)?modeh\.co\.ke$/'],

    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];