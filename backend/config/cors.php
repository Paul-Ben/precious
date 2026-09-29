<?php

/*
| The Next.js app talks to this API from its server (BFF pattern), so browsers
| normally never call the API directly. CORS is still restricted to the
| frontend origin as defence in depth.
*/

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter([
        rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/'),
    ])),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-Request-Id', 'Idempotency-Key'],
    'exposed_headers' => ['X-Request-Id'],
    'max_age' => 3600,
    'supports_credentials' => false,
];
