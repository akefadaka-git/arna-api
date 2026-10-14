<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| Enables the MASES frontend applications (running on sibling subdomains
| of maalearna.com.et) to call the API with credentials (cookies).
|
| -----------------------------------------------------------------------------
| Why this file exists
| -----------------------------------------------------------------------------
|
| Laravel ships without a published config/cors.php. When the file is
| absent, the framework falls back to permissive defaults that allow any
| origin but DO NOT allow credentials. Under those defaults:
|
|   - The browser strips the `Cookie` header from cross-origin requests.
|   - The browser rejects any response that combines
|     `Access-Control-Allow-Origin: *` with a credentialed request.
|
| Because MASES uses Sanctum stateful cookies for first-party SPA
| authentication, this file is mandatory and `supports_credentials` MUST
| be true.
|
| -----------------------------------------------------------------------------
| Interaction with SANCTUM_STATEFUL_DOMAINS
| -----------------------------------------------------------------------------
|
| The `allowed_origins` list below MUST match the origin list in
| SANCTUM_STATEFUL_DOMAINS (see the production .env). If the two lists
| diverge, a frontend may pass CORS but fail the stateful-domain check
| (or vice versa), producing authentication failures that are difficult
| to diagnose from the browser console alone.
|
| -----------------------------------------------------------------------------
| Do NOT use wildcards here
| -----------------------------------------------------------------------------
|
| `allowed_origins` must be an explicit list. Two reasons:
|
|   1. With `supports_credentials => true`, the CORS spec forbids `*`.
|      The browser will reject the response regardless of what the server
|      intends.
|
|   2. An explicit allow-list is a CSRF-adjacent security control. Any
|      origin not listed here is not permitted to make credentialed
|      requests to the API.
|
| @package MASES
| @version 1.0.0
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    |
    | Only requests matching these paths receive CORS headers. Everything
    | else is left alone.
    |
    | The list covers:
    |   - api/*                — every versioned API route.
    |   - sanctum/csrf-cookie  — required to obtain the XSRF-TOKEN cookie
    |                            before a stateful login.
    |   - login / logout / user — first-party auth endpoints if they are
    |                             mounted at the root rather than under
    |                             /api.
    |   - up                   — health check endpoint (Laravel 11 default).
    |
    | Adjust if any endpoint is moved to a different prefix.
    |
    */
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login',
        'logout',
        'user',
        'up',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Methods
    |--------------------------------------------------------------------------
    |
    | HTTP methods permitted for cross-origin requests. `*` covers the
    | full REST surface (GET, POST, PUT, PATCH, DELETE, OPTIONS).
    |
    */
    'allowed_methods' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | Explicit allow-list of MASES frontend origins. Every value must be
    | a full origin (scheme + host, no trailing slash). Subdomains must
    | be listed individually — a bare `maalearna.com.et` entry does not
    | cover its subdomains.
    |
    | Keep this list in lock-step with SANCTUM_STATEFUL_DOMAINS in .env.
    |
    */
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins Patterns
    |--------------------------------------------------------------------------
    |
    | Regex patterns for origins. Left empty deliberately: an explicit
    | allow-list is easier to audit and less prone to accidental
    | over-permission than a regex.
    |
    */
    'allowed_origins_patterns' => [],

    /*
    |--------------------------------------------------------------------------
    | Allowed Headers
    |--------------------------------------------------------------------------
    |
    | Request headers the browser is permitted to send. `*` allows all,
    | which is appropriate for first-party SPAs that need to send
    | Authorization, X-XSRF-TOKEN, X-Requested-With, Content-Type, and
    | any MASES-specific headers.
    |
    */
    'allowed_headers' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Exposed Headers
    |--------------------------------------------------------------------------
    |
    | Response headers the browser is allowed to read from a cross-origin
    | response. Left empty: the MASES canonical envelope carries error
    | metadata in the body, not in custom response headers.
    |
    | If the frontend ever needs to read X-Request-ID or similar for
    | correlation, add it here.
    |
    */
    'exposed_headers' => [],

    /*
    |--------------------------------------------------------------------------
    | Max Age
    |--------------------------------------------------------------------------
    |
    | Seconds the browser may cache the preflight (OPTIONS) response.
    | Caching reduces preflight traffic substantially for SPAs that issue
    | many authenticated requests. 3600 seconds (1 hour) is a reasonable
    | production value.
    |
    */
    'max_age' => 3600,

    /*
    |--------------------------------------------------------------------------
    | Supports Credentials
    |--------------------------------------------------------------------------
    |
    | REQUIRED for Sanctum cookie-based authentication. When true, the
    | browser is permitted to send and receive cookies on cross-origin
    | requests, and the server instructs it accordingly via
    | `Access-Control-Allow-Credentials: true`.
    |
    | Do not disable this. Disabling it silently breaks every stateful
    | Sanctum flow while leaving bearer-token flows untouched, which
    | makes the resulting failure mode confusing to diagnose.
    |
    */
    'supports_credentials' => true,

];