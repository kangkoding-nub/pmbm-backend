<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The frontend (React SPA) authenticates against the API using a Bearer
    | token issued via Sanctum. We therefore do NOT need cookie-based auth
    | (`supports_credentials=false`) and can keep the browser policy strict.
    |
    | Allowed origins are sourced from the FRONTEND_URL .env variable so the
    | same code can be deployed against any environment without code changes.
    | Multiple origins can be supplied as a comma-separated list.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('FRONTEND_URL', 'http://localhost:3000'))
    ))),

    // In local development we want to be tolerant of any localhost /
    // 127.0.0.1 port (Vite may pick a different port if 3000 is taken,
    // and editors sometimes proxy via a custom port). In production this
    // pattern list is empty so only the explicit `allowed_origins` entries
    // above are accepted.
    'allowed_origins_patterns' => env('APP_ENV', 'production') === 'local'
        ? [
            '#^http://localhost(:\d+)?$#',
            '#^http://127\.0\.0\.1(:\d+)?$#',
            '#^http://\[::1\](:\d+)?$#',
        ]
        : [],

    // Specific safe headers only — avoid `*` so browsers do not echo
    // arbitrary attacker-controlled headers back.
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        // Sent by the SPA's axios client so ngrok tunnels don't show the
        // browser warning interstitial. Harmless for non-ngrok backends,
        // but must be whitelisted here or every preflight will fail.
        'ngrok-skip-browser-warning',
    ],

    'exposed_headers' => [],

    'max_age' => 3600,

    // Bearer-token auth flow does not require credentials. If the frontend
    // is migrated to Sanctum SPA cookie auth, set this to true and add the
    // SPA hostname to SANCTUM_STATEFUL_DOMAINS in .env.
    'supports_credentials' => false,

];
