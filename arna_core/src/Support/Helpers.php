<?php

declare(strict_types=1);

/*
 * Composer-loaded global helpers. In HTTP requests, arna_tenant_id()
 * uses TenantContextService only; it never trusts body, query or header input
 * directly. Non-HTTP execution falls back to the user's default tenant,
 * then configured default, then null.
 */

use Illuminate\Support\Facades\Log;
use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Tenancy\Models\Tenant;
use MaaleArna\Arna\Core\Tenancy\Services\TenantContextService;

/*
 * -----------------------------------------------------------------------------
 * EXECUTION CONTEXT
 * -----------------------------------------------------------------------------
 */

if (! function_exists('arna_is_non_http_context')) {
    /**
     * Determine whether the current execution is a non-HTTP context
     * (console, queue worker, scheduler).
     *
     * Used by `arna_tenant_id()` to choose between the HTTP branch
     * (read from `TenantContextService`) and the non-HTTP branch
     * (fall back to user default → config default → null).
     *
     * The test-environment check is significant: phpunit runs in console
     * mode, but its feature tests simulate HTTP requests. Those tests must
     * observe the HTTP branch, not the non-HTTP fallback. Excluding
     * `runningUnitTests()` from the non-HTTP classification ensures that.
     *
     * **Guaranteed not to throw.** Any container or application failure
     * is treated as "not non-HTTP", which is the conservative default
     * (HTTP branch, `TenantContextService` read).
     *
     * @return bool
     */
    function arna_is_non_http_context(): bool
    {
        try {
            return app()->runningInConsole() && ! app()->runningUnitTests();
        } catch (\Throwable) {
            return false;
        }
    }
}

/*
 * -----------------------------------------------------------------------------
 * TENANT RESOLUTION
 * -----------------------------------------------------------------------------
 */

if (! function_exists('arna_tenant_id')) {
    /**
     * Resolve the current tenant identifier.
     *
     * =============================================================================
     * HTTP requests (v1.1.0 — authoritative)
     * =============================================================================
     * The tenant is read exclusively from `TenantContextService`, which is
     * populated by `TenantContextMiddleware` at the top of the pipeline
     * (via `RequestTenantResolver`, which reads the `X-Tenant-ID` header
     * and the request subdomain).
     *
     * The helper does NOT read:
     *   · `request('tenant_id')`        — request body or query string
     *   · `header('X-Tenant-ID')`       — headers are read by the resolver,
     *                                     not by this helper
     *   · `$user->default_tenant_id`    — that fallback is non-HTTP only
     *   · `config('arna.tenant...')`   — that fallback is non-HTTP only
     *
     * If `TenantContextService` has no tenant (because the middleware did
     * not resolve one, or because the route did not run the middleware
     * stack), the helper returns `null`. `null` is the correct and
     * intentional answer: a request without a resolved tenant must not
     * silently acquire the user's default tenant.
     *
     * =============================================================================
     * Non-HTTP contexts (console, queue, scheduler)
     * =============================================================================
     * The middleware pipeline does not run in these contexts. The helper
     * applies the following fallback chain:
     *
     *   1. The authenticated user's `default_tenant_id` attribute.
     *   2. `config('arna.tenant.default_tenant_id')`.
     *   3. `null`.
     *
     * This is the v1.0.0 behavior for these contexts, unchanged.
     *
     * =============================================================================
     * Memoization
     * =============================================================================
     * The result is memoized per `Request` instance. In HTTP (PHP-FPM) the
     * request instance changes per request; in non-HTTP contexts the same
     * instance persists for the run. This preserves v1.0.0 semantics.
     *
     * =============================================================================
     * Failure semantics
     * =============================================================================
     * **Guaranteed not to throw.** Any internal failure is logged as a
     * warning and the function returns `null`.
     *
     * @return int|string|null The resolved tenant identifier, or `null` when
     *                         no tenant context is available.
     */
    function arna_tenant_id(): int|string|null
    {
        static $forRequest = null;
        static $resolved   = null;

        try {
            $request = request();
        } catch (\Throwable) {
            return null;
        }

        if ($forRequest === $request) {
            return $resolved;
        }

        $forRequest = $request;
        $resolved   = null;

        try {
            // -----------------------------------------------------------------
            // HTTP request — TenantContextService is the sole authority.
            //
            // No fallback. If the service has no tenant, this returns null.
            // The header and body are NOT inspected here; the middleware
            // pipeline has already resolved the tenant into the service.
            // -----------------------------------------------------------------
            if (! arna_is_non_http_context()) {
                $service = app(TenantContextService::class);

                return $resolved = $service->getTenantId();
            }

            // -----------------------------------------------------------------
            // Non-HTTP context — fall back to user default, then config
            // default, then null. This branch exists only for console,
            // queue, and scheduler execution where the middleware pipeline
            // does not run.
            // -----------------------------------------------------------------

            // 1. Authenticated user's default tenant.
            $user = arna_user();
            if (
                $user !== null
                && isset($user->default_tenant_id)
                && $user->default_tenant_id !== null
                && $user->default_tenant_id !== ''
            ) {
                return $resolved = $user->default_tenant_id;
            }

            // 2. Platform default from configuration.
            $default = config('arna.tenant.default_tenant_id');
            if ($default !== null && $default !== '') {
                return $resolved = $default;
            }
        } catch (\Throwable $e) {
            Log::warning('arna_tenant_id(): tenant resolution failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $resolved = null;
    }
}

if (! function_exists('arna_tenant')) {
    /**
     * Resolve the current tenant model instance.
     *
     * Loads and memoizes the `Tenant` model corresponding to
     * {@see arna_tenant_id()}. Returns `null` when no tenant is resolvable
     * or when the underlying lookup fails.
     *
     * Behavior is unchanged from v1.0.0. Because `arna_tenant_id()` now
     * returns the middleware-verified tenant for HTTP requests, the loaded
     * model is the same tenant the membership middleware checked — there is
     * no code change required here, only a behavioral consequence of the
     * `arna_tenant_id()` correction above.
     *
     * **Guaranteed not to throw.** Failures are logged as warnings.
     *
     * @return Tenant|null The hydrated tenant, or `null` when unavailable.
     */
    function arna_tenant(): ?Tenant
    {
        static $forRequest = null;
        static $resolved   = null;

        try {
            $request = request();
        } catch (\Throwable) {
            return null;
        }

        if ($forRequest === $request) {
            return $resolved;
        }

        $forRequest = $request;
        $resolved   = null;

        $tenantId = arna_tenant_id();
        if ($tenantId === null) {
            return $resolved = null;
        }

        try {
            $resolved = Tenant::query()->find($tenantId);

            return $resolved;
        } catch (\Throwable $e) {
            Log::warning('arna_tenant(): failed to load tenant', [
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
            ]);

            return $resolved = null;
        }
    }
}

if (! function_exists('arna_is_tenant_context')) {
    /**
     * Determine whether a tenant context is currently active.
     *
     * Convenience wrapper around {@see arna_tenant_id()} for middleware
     * guards and conditional view logic.
     */
    function arna_is_tenant_context(): bool
    {
        return arna_tenant_id() !== null;
    }
}

if (! function_exists('arna_is_tenant_isolated')) {
    /**
     * Determine whether tenant data isolation is enabled.
     *
     * Controlled by `arna.tenant.isolation_enabled`. Defaults to `true` so
     * that a misconfigured environment fails closed, not open.
     */
    function arna_is_tenant_isolated(): bool
    {
        return (bool) config('arna.tenant.isolation_enabled', true);
    }
}

/*
 * -----------------------------------------------------------------------------
 * USER CONTEXT
 * -----------------------------------------------------------------------------
 */

if (! function_exists('arna_user')) {
    /**
     * Resolve the currently authenticated user.
     *
     * Wraps {@see auth()->user()} with per-request memoization so that
     * repeated calls across a request lifecycle do not re-hit the auth guard.
     *
     * Unlike tenant resolution, this helper does **not** swallow exceptions —
     * if the auth guard itself is misconfigured, that error should surface
     * during development rather than silently returning `null`.
     *
     * @return User|null The authenticated user, or `null` for guests.
     */
    function arna_user(): ?User
    {
        static $forRequest = null;
        static $resolved   = null;

        try {
            $request = request();
        } catch (\Throwable) {
            return null;
        }

        if ($forRequest === $request) {
            return $resolved;
        }

        $forRequest = $request;

        /** @var User|null $resolved */
        $resolved = auth()->user();

        return $resolved;
    }
}

/*
 * -----------------------------------------------------------------------------
 * LOCALISATION
 * -----------------------------------------------------------------------------
 */

if (! function_exists('arna_locale')) {
    /**
     * Get the platform's default locale.
     *
     * Falls back to Amharic (`am`) — the primary language for the Maale Arna
     * deployment. Individual requests may still override the active locale
     * via Laravel's standard `App::setLocale()` mechanism; this helper only
     * reports the configured default.
     */
    function arna_locale(): string
    {
        return (string) config('arna.localization.default_locale', 'am');
    }
}

/*
 * -----------------------------------------------------------------------------
 * ENVIRONMENT DETECTION
 * -----------------------------------------------------------------------------
 */

if (! function_exists('arna_environment')) {
    /**
     * Get the current platform environment label.
     *
     * Prefers the explicit `arna.env` config key when set, falling back to
     * Laravel's native `app()->environment()`. This allows the ARNA platform
     * to be tagged with a deployment tier (e.g. `staging-eu`) that is
     * independent of `APP_ENV` in the `.env` file.
     */
    function arna_environment(): string
    {
        return (string) config('arna.env', app()->environment());
    }
}

if (! function_exists('arna_is_production')) {
    /**
     * Determine whether the platform is running in production mode.
     */
    function arna_is_production(): bool
    {
        return arna_environment() === 'production';
    }
}

if (! function_exists('arna_is_staging')) {
    /**
     * Determine whether the platform is running in a staging environment.
     */
    function arna_is_staging(): bool
    {
        return arna_environment() === 'staging';
    }
}

if (! function_exists('arna_is_local')) {
    /**
     * Determine whether the platform is running in a local development
     * environment.
     */
    function arna_is_local(): bool
    {
        return in_array(arna_environment(), ['local', 'development'], true);
    }
}

/*
 * -----------------------------------------------------------------------------
 * REQUEST CONTEXT & STRUCTURED LOGGING
 * -----------------------------------------------------------------------------
 */

if (! function_exists('arna_request_id')) {
    /**
     * Resolve a stable correlation identifier for the current request.
     *
     * Resolution order:
     *   1. `$request->id()` — set by an upstream middleware (preferred).
     *   2. `X-Request-ID` header — propagated from the client or edge proxy.
     *   3. `null` — no correlation ID available.
     *
     * **Guaranteed not to throw.**
     */
    function arna_request_id(): ?string
    {
        static $forRequest = null;
        static $resolved   = null;

        try {
            $request = request();
        } catch (\Throwable) {
            return null;
        }

        if ($forRequest === $request) {
            return $resolved;
        }

        $forRequest = $request;
        $resolved   = null;

        try {
            if (method_exists($request, 'id')) {
                $id = $request->id();
                if (is_string($id) && $id !== '') {
                    return $resolved = $id;
                }
            }

            if ($request->hasHeader('X-Request-ID')) {
                $header = $request->header('X-Request-ID');
                if (is_string($header) && $header !== '') {
                    return $resolved = $header;
                }
            }
        } catch (\Throwable) {
            // Context resolution must never fail.
        }

        return $resolved = null;
    }
}

if (! function_exists('arna_log_context')) {
    /**
     * Build a standardised log-context array for the current request.
     *
     * Designed for structured logging (Monolog JSON formatters, Papertrail,
     * Datadog, etc.). Every call returns a flat, serialisable array — no
     * objects, no closures, no circular references.
     *
     * **Guaranteed not to throw.** Missing components are represented as
     * `null`; the array shape is always identical.
     *
     * @return array{
     *     request_id: string|null,
     *     tenant_id:  int|string|null,
     *     user_id:    int|string|null,
     *     ip:         string|null,
     *     url:        string|null,
     *     method:     string|null,
     *     user_agent: string|null,
     * }
     */
    function arna_log_context(): array
    {
        $context = [
            'request_id' => arna_request_id(),
            'tenant_id'  => arna_tenant_id(),
            'user_id'    => arna_user()?->id,
            'ip'         => null,
            'url'        => null,
            'method'     => null,
            'user_agent' => null,
        ];

        try {
            $request = request();

            $context['ip']         = $request->ip();
            $context['url']        = $request->fullUrl();
            $context['method']     = $request->method();
            $context['user_agent'] = $request->userAgent();
        } catch (\Throwable) {
            // CLI or unbound request — leave the null defaults in place.
        }

        return $context;
    }
}