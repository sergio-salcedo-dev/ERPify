<?php

declare(strict_types=1);

$corsOriginsRaw = $_ENV['CORS_ALLOW_ORIGINS'] ?? 'http://localhost,http://127.0.0.1,http://localhost:3000,http://127.0.0.1:3000,https://localhost,https://127.0.0.1';
$corsOrigins = array_values(
    array_filter(array_map(trim(...), explode(',', (string) $corsOriginsRaw))),
);

return [
    'nelmio_cors' => [
        'defaults' => [
            'allow_credentials' => false,
            'allow_origin' => $corsOrigins,
            'allow_methods' => ['GET', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE'],
            // X-CSRF-Token makes a request non-simple, so a cross-origin caller reaches the endpoint
            // only if the preflight echoes the header back. The browser default is same-origin (no
            // preflight at all); this keeps the configured cross-origin dev origins working too.
            'allow_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-CSRF-Token'],
            'expose_headers' => ['Link'],
            'max_age' => 3600,
        ],
        'paths' => [
            '^/api/' => [],
        ],
    ],
];
