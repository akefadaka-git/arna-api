<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * AppServiceProvider
 *
 * Central application service provider.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — Laravel 12 default scaffold. Empty register() and boot() methods.
 *
 * 1.0.1  — D-10. Rate limiting restored.
 *
 *          BEFORE
 *          ------
 *          The platform had no rate limiting in effect. The middleware
 *          chain observed on API requests was:
 *
 *              HandleCors
 *              → TenantContextMiddleware
 *              → LocalizationMiddleware
 *              → auth:sanctum
 *              → tenant.membership
 *              → route-specific
 *
 *          No `throttle:*` middleware was present. Consequences:
 *
 *            · `POST /api/login` could be called indefinitely from a
 *              single source. Credential stuffing and password
 *              spraying are unmitigated.
 *            · `POST /api/register` could be called indefinitely.
 *            · `POST /api/password/email` (password reset request)
 *              could be called indefinitely. This is an email-bomb
 *              vector and a mail-transport exhaustion vector.
 *            · Every authenticated endpoint could be called
 *              indefinitely, which matters for endpoints that
 *              perform real work (file uploads, ledger writes).
 *
 *          The `config/mases.php` file already declared the intended
 *          cap:
 *
 *              'security' => [
 *                  'rate_limiting' => (int) env('MASES_RATE_LIMIT', 60),
 *              ]
 *
 *          but no code read it. The configuration key was inert.
 *
 *          AFTER
 *          -----
 *          Two changes are made in this provider:
 *
 *          (1) RATE LIMITER REGISTRATION
 *
 *              `RateLimiter::for('api', ...)` registers a limiter
 *              named `api`. The limiter's key is the authenticated
 *              user's ID when a user is present, else the request IP.
 *              The cap is read from
 *              `config('mases.security.rate_limiting')` so the
 *              existing `MASES_RATE_LIMIT` environment variable is
 *              now honoured. Default 60 requests per minute.
 *
 *              Keying by user when present is the standard Laravel
 *              convention. It means an authenticated user is not
 *              penalised for traffic from other users behind the
 *              same NAT, and an unauthenticated flood is still
 *              bounded per source IP.
 *
 *          (2) MIDDLEWARE APPLICATION
 *
 *              Registering the limiter is not sufficient on its own
 *              — the limiter only fires when a `throttle:api`
 *              middleware is in the pipeline. In Laravel 11/12 the
 *              routes in `routes/api.php` are wrapped in the `api`
 *              middleware group by `bootstrap/app.php`'s
 *              `withRouting(api: ...)` call, and by default that
 *              group does NOT include `throttle:api`. It must be
 *              added.
 *
 *              The standard place to add it is `bootstrap/app.php`,
 *              via `$middleware->throttleApi()`. That file is not in
 *              this change's scope. As an alternative that stays
 *              inside this one file, a `$this->app->booted(...)`
 *              callback pushes `'throttle:api'` onto the `'api'`
 *              middleware group after all providers have booted but
 *              before the first request is handled.
 *
 *              WHY NOT IN register() OR EARLY IN boot()
 *              ---------------------------------------------
 *              The `booted` callback runs after the router has been
 *              fully configured and after route files have been
 *              loaded. Pushing to the middleware group at that point
 *              is safe: middleware groups are expanded at request-
 *              dispatch time, not at route-registration time.
 *
 *          NOT CHANGED IN THIS FILE
 *          ------------------------
 *            · No new named limiter beyond `api`. No evidence the
 *              platform needs finer-grained throttles yet.
 *            · No global throttle. The `api` group is the correct
 *              target; throttling `web` would affect admin UI and
 *              asset delivery.
 *            · No 429 response shim. Laravel's default
 *              `ThrottleRequests` middleware returns a standard
 *              429 with `Retry-After`. The canonical MASES error
 *              envelope in `bootstrap/app.php` does not currently
 *              handle `ThrottleRequestsException`, so the 429 will
 *              surface as a generic 500 through the fallback
 *              renderer. This is a gap; it is NOT fixed here. See
 *              the note in the delivery message.
 *
 * =============================================================================
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * Registers the `api` rate limiter (reads its cap from
     * `config('mases.security.rate_limiting')`) and applies the
     * `throttle:api` middleware to the `api` middleware group so the
     * limiter is actually invoked for API requests.
     *
     * The middleware push runs inside `$this->app->booted(...)` so it
     * happens after all service providers have booted and after route
     * files have been loaded. Middleware groups are expanded at
     * request-dispatch time, so a push at that point is honoured for
     * every subsequent request.
     */
    public function boot(): void
    {
        /*
         * ---------------------------------------------------------------------
         * Rate limiter registration.
         *
         * The limiter is named `api`; the `throttle:api` middleware
         * references it by that name.
         *
         * Key resolution:
         *   · Authenticated request → user ID.
         *   · Unauthenticated request → source IP.
         *
         * The `?:` operator is deliberate. `??` would keep a falsy
         * user ID (id 0) as the key, collapsing every such user onto
         * a single bucket. `?:` falls through to the IP in that
         * (impossible-in-practice) case.
         *
         * Cap resolution:
         *   · `config('mases.security.rate_limiting')` → from
         *     `config/mases.php`, which reads
         *     `env('MASES_RATE_LIMIT', 60)`.
         *   · Cast to int to guard against a non-numeric env value.
         *   · Falls back to 60 if the config key is missing entirely
         *     (e.g. in a stripped-down test environment).
         * ---------------------------------------------------------------------
         */
        RateLimiter::for('api', function (Request $request) {
            $perMinute = (int) config('mases.security.rate_limiting', 60);

            return Limit::perMinute($perMinute)
                ->by($request->user()?->id ?: $request->ip());
        });

        /*
         * ---------------------------------------------------------------------
         * Middleware application.
         *
         * Push `throttle:api` onto the `api` middleware group. This is
         * the group that `bootstrap/app.php`'s `withRouting(api: ...)`
         * call applies to every route in `routes/api.php`. Without
         * this push, the limiter above is registered but never
         * invoked.
         *
         * The callback runs in `booted()` — after every service
         * provider has booted and after route files have been loaded,
         * but before the first request is handled. Middleware groups
         * are expanded at request-dispatch time, so this ordering is
         * sufficient.
         * ---------------------------------------------------------------------
         */
        $this->app->booted(function (): void {
            $this->app->make(Router::class)
                ->pushMiddlewareToGroup('api', 'throttle:api');
        });
    }
}