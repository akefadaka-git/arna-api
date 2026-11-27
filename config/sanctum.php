<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;

/*
| Sanctum authentication configuration.
| Stateful SPA hosts: app.maalearna.com.et and portal.maalearna.com.et only;
| CORS origins are configured separately.
| The User model is configured in auth.php; Sanctum has no user_model key.
| Rate limits use Laravel RateLimiter and route throttle middleware.
| Cookie SPA auth requires statefulApi() in bootstrap/app.php.
| Token expiration covers personal access tokens; session/device lifecycle
| is enforced by application services and middleware.
| @package ARNA
| @version 1.0.0
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from these domains / hosts will receive stateful API
    | authentication cookies. In production set SANCTUM_STATEFUL_DOMAINS in .env to exactly the
    | Workspace and Shareholder Portal hosts. Bearer-token clients and public
    | browser origins are not Sanctum stateful hosts.
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
    | Recommended for ARNA production: `arna_` (set via
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