<?php

// Resolve allowed origins from environment so production never falls back to localhost.
// FRONTEND_URL must be set in production (e.g. https://app.example.com).
$frontendUrl = env('FRONTEND_URL');
$allowedOrigins = $frontendUrl
    ? [$frontendUrl]
    : ['http://localhost:3000', 'http://localhost:3001'];

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['X-Tenant-ID', 'Authorization', 'Content-Type', 'Accept', 'X-XSRF-TOKEN'],
    // Expose Retry-After so the frontend countdown toast can read it on 429 responses.
    'exposed_headers' => ['Retry-After'],
    // 2-hour cache for preflight responses — reduces OPTIONS round-trips in production.
    'max_age' => 7200,
    'supports_credentials' => true,
];
