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
            //
            // `If-None-Match` and `ETag` are the two halves of conditional GET: the request header a
            // preflight must echo back, and the response header `fetch()` must be allowed to read before it
            // can send that request header at all. Both are here so the pair is complete whenever the route
            // becomes reachable cross-origin.
            //
            // **What they do NOT do today, said because the obvious reading is wrong.** They do not make the
            // image read route usable from a configured cross-origin caller. Authentication on that route is
            // the session cookie, `allow_credentials` is false seven lines above, and a browser will not
            // attach a cookie to a cross-origin request whose CORS policy does not allow credentials — so
            // the request arrives anonymous and the firewall answers 401, whether or not the preflight
            // echoes anything. Neither entry moves that outcome by one step. What blocks the cross-origin
            // 304 is the credentials policy, not the header allowlist, and relaxing THAT is a decision about
            // the session's exposure rather than about HTTP caching.
            'allow_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-CSRF-Token', 'If-None-Match'],
            'expose_headers' => ['Link', 'ETag'],
            'max_age' => 3600,
        ],
        'paths' => [
            '^/api/' => [],
        ],
    ],
];
