<?php

declare(strict_types=1);

/*
|---------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|---------------------------------------------------------------------------
|
| Enables the ten approved ARNA first-party subdomains of maalearna.com.et
| to call the API with credentialed browser requests.
|
| The Owner-approved trust boundary includes:
| www, auth, app, portal, finance, admin, arna, ai, file, and api.
|
| The CORS allow-list and SANCTUM_STATEFUL_DOMAINS must remain aligned.
| Use explicit origins only; do not replace this list with a wildcard.
|
*/

return [

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login',
        'logout',
        'user',
        'up',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'https://www.maalearna.com.et',
        'https://auth.maalearna.com.et',
        'https://app.maalearna.com.et',
        'https://portal.maalearna.com.et',
        'https://finance.maalearna.com.et',
        'https://admin.maalearna.com.et',
        'https://arna.maalearna.com.et',
        'https://ai.maalearna.com.et',
        'https://file.maalearna.com.et',
        'https://api.maalearna.com.et',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => true,

];
