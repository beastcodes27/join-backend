<?php
return [
 'paths' => ['api/*', 'sanctum/csrf-cookie'],
 'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
 'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173')],
 'allowed_origins_patterns' => [],
 'allowed_headers' => ['Content-Type', 'Accept', 'X-XSRF-TOKEN', 'X-Requested-With'],
 'exposed_headers' => [], 'max_age' => 600, 'supports_credentials' => true,
];
