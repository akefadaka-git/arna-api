<?php

declare(strict_types=1);

/*
 * -----------------------------------------------------------------------------
 * MASES CORE — GLOBAL HELPER FUNCTIONS
 * -----------------------------------------------------------------------------
 *
 * Auto-loaded via Composer (`autoload.files`). Every function is wrapped in a
 * `function_exists()` guard to prevent redeclaration errors when legacy
 * bootstrap files or third-party packages attempt to define the same symbol.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery. `mases_tenant_id()` resolved from request input,
 *          header, user default, and config default, in that priority order.
 *
 * 1.1.0  — C-09 correction. HTTP tenant context is now sourced exclusively
 *          from `TenantContextService`. The helper no longer reads request
 *          body, query, or header independently. A request-body `tenant_id`
 *          can no longer override the middleware-verified tenant.
 *
 *          Full rationale and design are recorded in the C-09 design
 *          document (Phase 0 Part 2 / 2a). Summary:
 *
 *            · Prior behavior: `mases_tenant_id()` gave HIGHEST priority to
 *              `request('tenant_id')` (body/query), while the middleware
 *              chain (`TenantContextMiddleware` + `RequestTenantResolver`)
 *              resolved tenant from the `X-Tenant-ID` header and subdomain.
 *              When both were present and disagreed, the body value won for
 *              every consumer of the helper — including AuthorizationService,
 *              AuthorizationMiddleware, SecurityEventService, all audit
 *              listeners, and every domain controller/policy. That produced
 *              two competing tenant authorities within a single request.
 *
 *            · New behavior: for HTTP requests, `mases_tenant_id()` reads
 *              `TenantContextService::getTenantId()` verbatim. If the
 *              service has no tenant, the helper returns `null`. It does
 *              NOT fall back to the user's default tenant. This preserves
 *              the single-authority invariant established by the middleware
 *              pipeline.
 *
 *            · For non-HTTP execution (console, queue, scheduler), the
 *              helper falls back to `$user->default_tenant_id`, then to
 *              `config('mases.tenant.default_tenant_id')`, then to `null`.
 *              The fallback exists ONLY for non-HTTP contexts.
 *
 *          The `mases_tenant()` helper is unchanged in behavior; it
 *          continues to delegate to `mases_tenant_id()` and load the
 *          corresponding Tenant model.
 *
 * =============================================================================
 * DESIGN CONTRACT
 * =============================================================================
 *   1. NONE of these helpers may throw under any circumstance. Tenancy,
 *      authentication, and logging all rely on them for graceful degradation.
 *      Any internal exception is swallowed and logged as a warning.
 *
 *   2. Tenant and user resolution are memoized per-request. The cache is keyed
 *      on the current `Illuminate\Http\Request` instance, so long-running
 *      workers (Octane, Swoole, RoadRunner) never leak context between
 *      requests, while PHP-FPM (Plesk / Apache / nginx) sees the same
 *      optimisation with no additional bookkeeping.
 *
 *   3. Return types are nullable wherever a resolution may legitimately fail.
 *      Callers MUST treat `null` as "context unavailable", never as an error.
 *
 *   4. This file must remain free of side effects. It only defines functions —
 *      no bootstrapping, no service resolution at definition time.
 *
 *   5. (v1.1.0) HTTP requests MUST obtain tenant context from
 *      `TenantContextService`, never from request input. Non-HTTP contexts
 *      use the explicit fallback chain documented on `mases_tenant_id()`.
 *
 * -----------------------------------------------------------------------------
 * SECURITY NOTE (v1.1.0)
 * -----------------------------------------------------------------------------
 *   For HTTP requests, `mases_tenant_id()` and `mases_tenant()` return the
 *   tenant resolved by the middleware pipeline (via `TenantContextService`).
 *   They do NOT read request input independently. A `tenant_id` in the
 *   request body or query string is ordinary application input; it is not
 *   security tenant context and has no effect on the helper's return value.
 *
 *   For non-HTTP contexts (console, queue, scheduler), the helpers fall back
 *   to the authenticated user's default tenant, then to the configured
 *   platform default, then to `null`. This fallback exists only for non-HTTP
 *   execution.
 *
 *   Neither helper performs authorization. Callers are responsible for
 *   verifying that the resolved tenant is one the authenticated user is
 *   permitted to access.
 *
 * -----------------------------------------------------------------------------
 * DEPENDENCIES
 * -----------------------------------------------------------------------------
 *   MaaleArna\MASES\Core\Tenancy\Services\TenantContextService (v1.1.0)
 *   MaaleArna\MASES\Core\Tenancy\Models\Tenant
 *   MaaleArna\MASES\Core\Auth\Models\User
 *
 *   The `TenantContextService` binding must exist in the container for the
 *   HTTP branch to return a value. It is bound as a singleton by
 *   `CoreServiceProvider::registerCoreBindings()`. If the binding is absent
 *   (catastrophic misconfiguration), the helper logs a warning and returns
 *   `null`, preserving the "never throws" contract.
 * -----------------------------------------------------------------------------
 */

use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;

/*
 * -----------------------------------------------------------------------------
 * EXECUTION CONTEXT
 * -----------------------------------------------------------------------------
 */

if (! function_exists('mases_is_non_http_context')) {
    /**
     * Determine whether the current execution is a non-HTTP context
     * (console, queue worker, scheduler).
     *
     * Used by `mases_tenant_id()` to choose between the HTTP branch
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
    function mases_is_non_http_context(): bool
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

if (! function_exists('mases_tenant_id')) {
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
     *   · `config('mases.tenant...')`   — that fallback is non-HTTP only
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
     *   2. `config('mases.tenant.default_tenant_id')`.
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
    function mases_tenant_id(): int|string|null
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
            if (! mases_is_non_http_context()) {
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
            $user = mases_user();
            if (
                $user !== null
                && isset($user->default_tenant_id)
                && $user->default_tenant_id !== null
                && $user->default_tenant_id !== ''
            ) {
                return $resolved = $user->default_tenant_id;
            }

            // 2. Platform default from configuration.
            $default = config('mases.tenant.default_tenant_id');
            if ($default !== null && $default !== '') {
                return $resolved = $default;
            }
        } catch (\Throwable $e) {
            Log::warning('mases_tenant_id(): tenant resolution failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $resolved = null;
    }
}

if (! function_exists('mases_tenant')) {
    /**
     * Resolve the current tenant model instance.
     *
     * Loads and memoizes the `Tenant` model corresponding to
     * {@see mases_tenant_id()}. Returns `null` when no tenant is resolvable
     * or when the underlying lookup fails.
     *
     * Behavior is unchanged from v1.0.0. Because `mases_tenant_id()` now
     * returns the middleware-verified tenant for HTTP requests, the loaded
     * model is the same tenant the membership middleware checked — there is
     * no code change required here, only a behavioral consequence of the
     * `mases_tenant_id()` correction above.
     *
     * **Guaranteed not to throw.** Failures are logged as warnings.
     *
     * @return Tenant|null The hydrated tenant, or `null` when unavailable.
     */
    function mases_tenant(): ?Tenant
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

        $tenantId = mases_tenant_id();
        if ($tenantId === null) {
            return $resolved = null;
        }

        try {
            $resolved = Tenant::query()->find($tenantId);

            return $resolved;
        } catch (\Throwable $e) {
            Log::warning('mases_tenant(): failed to load tenant', [
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
            ]);

            return $resolved = null;
        }
    }
}

if (! function_exists('mases_is_tenant_context')) {
    /**
     * Determine whether a tenant context is currently active.
     *
     * Convenience wrapper around {@see mases_tenant_id()} for middleware
     * guards and conditional view logic.
     */
    function mases_is_tenant_context(): bool
    {
        return mases_tenant_id() !== null;
    }
}

if (! function_exists('mases_is_tenant_isolated')) {
    /**
     * Determine whether tenant data isolation is enabled.
     *
     * Controlled by `mases.tenant.isolation_enabled`. Defaults to `true` so
     * that a misconfigured environment fails closed, not open.
     */
    function mases_is_tenant_isolated(): bool
    {
        return (bool) config('mases.tenant.isolation_enabled', true);
    }
}

/*
 * -----------------------------------------------------------------------------
 * USER CONTEXT
 * -----------------------------------------------------------------------------
 */

if (! function_exists('mases_user')) {
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
    function mases_user(): ?User
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

if (! function_exists('mases_locale')) {
    /**
     * Get the platform's default locale.
     *
     * Falls back to Amharic (`am`) — the primary language for the Maale Arna
     * deployment. Individual requests may still override the active locale
     * via Laravel's standard `App::setLocale()` mechanism; this helper only
     * reports the configured default.
     */
    function mases_locale(): string
    {
        return (string) config('mases.localization.default_locale', 'am');
    }
}

/*
 * -----------------------------------------------------------------------------
 * ENVIRONMENT DETECTION
 * -----------------------------------------------------------------------------
 */

if (! function_exists('mases_environment')) {
    /**
     * Get the current platform environment label.
     *
     * Prefers the explicit `mases.env` config key when set, falling back to
     * Laravel's native `app()->environment()`. This allows the MASES platform
     * to be tagged with a deployment tier (e.g. `staging-eu`) that is
     * independent of `APP_ENV` in the `.env` file.
     */
    function mases_environment(): string
    {
        return (string) config('mases.env', app()->environment());
    }
}

if (! function_exists('mases_is_production')) {
    /**
     * Determine whether the platform is running in production mode.
     */
    function mases_is_production(): bool
    {
        return mases_environment() === 'production';
    }
}

if (! function_exists('mases_is_staging')) {
    /**
     * Determine whether the platform is running in a staging environment.
     */
    function mases_is_staging(): bool
    {
        return mases_environment() === 'staging';
    }
}

if (! function_exists('mases_is_local')) {
    /**
     * Determine whether the platform is running in a local development
     * environment.
     */
    function mases_is_local(): bool
    {
        return in_array(mases_environment(), ['local', 'development'], true);
    }
}

/*
 * -----------------------------------------------------------------------------
 * REQUEST CONTEXT & STRUCTURED LOGGING
 * -----------------------------------------------------------------------------
 */

if (! function_exists('mases_request_id')) {
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
    function mases_request_id(): ?string
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

if (! function_exists('mases_log_context')) {
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
    function mases_log_context(): array
    {
        $context = [
            'request_id' => mases_request_id(),
            'tenant_id'  => mases_tenant_id(),
            'user_id'    => mases_user()?->id,
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