<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Sanctum Configuration
|--------------------------------------------------------------------------
|
| Configuration for Laravel Sanctum, which provides API token
| authentication and first-party SPA cookie authentication for the
| MASES platform.
|
| -----------------------------------------------------------------------------
| Production deployment notes
| -----------------------------------------------------------------------------
|
| 1. Stateful domains
|    The `stateful` array below resolves from SANCTUM_STATEFUL_DOMAINS. In
|    production that variable MUST be set explicitly to the approved
|    MASES frontend origins (see the production .env). The fallback list
|    is local-development only and must never be relied on in production.
|
| 2. Custom User model
|    Sanctum has NO `user_model` config key. The MASES Core User model
|    (MaaleArna\MASES\Core\Auth\Models\User) is resolved exclusively via
|    config/auth.php → providers.users.model. Adding a `user_model` key
|    here would be dead code and misleading to future maintainers.
|
| 3. Rate limiting
|    Sanctum has NO `rate_limiting` config key. Rate limiting is defined
|    via the RateLimiter facade (typically in AppServiceProvider or
|    bootstrap/app.php) and applied to routes with the `throttle:`
|    middleware. Do not attempt to configure it here.
|
| 4. Stateful SPA middleware
|    For the stateful-domain list below to take effect, the application
|    MUST enable `$middleware->statefulApi()` in bootstrap/app.php.
|    Without it, cookie-based SPA authentication silently fails and
|    only bearer tokens are accepted.
|
| 5. Token lifecycle
|    The `expiration` key only controls personal access tokens. Session
|    lifetime, refresh, logout revocation, password-change revocation,
|    inactive-user revocation, and device/session management are the
|    responsibility of application code (listeners, middleware, and the
|    MASES Authenticate middleware).
|
| @package MASES
| @version 1.0.0
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from these domains / hosts will receive stateful API
    | authentication cookies. In production you MUST set
    | SANCTUM_STATEFUL_DOMAINS in .env to the explicit list of approved
    | MASES frontend origins.
    |
    | The fallback list below is local-development only. It includes
    | localhost variants plus the current application URL (which resolves
    | to APP_URL) so that local development against any host works
    | without .env changes.
    |
    | Do not rely on the fallback in production — a misconfigured APP_URL
    | or proxy header would silently add an unintended origin to the
    | stateful allow-list.
    |
    */
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort()
    ))),

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Guards checked when Sanctum authenticates a first-party request. If
    | none of them can authenticate the request, Sanctum falls back to the
    | bearer token present on the request.
    |
    | `web` is the session guard defined in config/auth.php. Keep this in
    | sync with the `defaults.guard` and `guards` definitions there.
    |
    */
    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Token Expiration (Minutes)
    |--------------------------------------------------------------------------
    |
    | Lifetime of issued personal access tokens. Set to null to disable
    | expiration (not recommended). Default: 10080 minutes (7 days).
    |
    | Override via SANCTUM_TOKEN_EXPIRATION in .env.
    |
    | NOTE: this value does NOT control session lifetime, refresh tokens,
    | or revocation policy. See the file header for details.
    |
    */
    'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION', 60 * 24 * 7),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix applied to newly issued tokens. A deliberate prefix enables
    | secret-scanning tools to detect accidentally committed tokens.
    |
    | Recommended for MASES production: `mases_` (set via
    | SANCTUM_TOKEN_PREFIX in .env). This is not a substitute for secure
    | token handling — it is a detection aid only.
    |
    */
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware used by Sanctum when authenticating first-party SPAs.
    | Class names verified against Laravel 11 / Sanctum 4. If you upgrade
    | either package, re-verify these namespaces before deploying.
    |
    */
    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies'      => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token'  => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];