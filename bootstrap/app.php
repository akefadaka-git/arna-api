<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — bootstrap/app.php (AMEND)
 * =============================================================================
 *
 * File:            bootstrap/app.php  (v1.1.0)
 * Unit:            Core bootstrap — canonical error envelope enhancement
 * Delivery type:   Amendment (full-file replacement)
 *
 * Before (v1.0.2):
 *   The `renderable()` callback dispatched on the raw exception class
 *   and mapped `ModelNotFoundException` and `AuthorizationException`
 *   to their semantic error codes (`RESOURCE_NOT_FOUND`,
 *   `AUTH_PERMISSION_DENIED`). Laravel's `Handler::prepareException()`
 *   however runs BEFORE `renderViaCallbacks()`, wrapping those two
 *   exception types in HTTP exception subclasses. The result was that
 *   the semantic branches in `mases_resolve_error()` were effectively
 *   dead code: a missing model produced `ROUTE_NOT_FOUND` instead of
 *   `RESOURCE_NOT_FOUND`.
 *
 *   This went unnoticed because every Governance feature test asserts
 *   the code against a permissive set:
 *
 *       $this->assertContains(
 *           $response->json('error.code'),
 *           ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
 *       );
 *
 *   The distinction is semantically meaningful: a client handling a
 *   missing row should not receive the same code as a client that
 *   mistyped a URL.
 *
 *   Three additional exception types were not handled explicitly and
 *   fell through to the generic HttpException status-code map, which
 *   produced generic codes but did not propagate subclass-specific
 *   response headers (`Retry-After`, `X-RateLimit-*` on 429) and did
 *   not redact SQL from a `QueryException`'s message in non-production
 *   environments.
 *
 * After (v1.1.0):
 *   `mases_resolve_error()` gains five explicit branches and two
 *   defensive unwraps:
 *
 *     · `ModelNotFoundException` (direct) — unchanged, defensive.
 *     · `NotFoundHttpException` whose `getPrevious()` is a
 *       `ModelNotFoundException` — NEW. Returns `RESOURCE_NOT_FOUND`.
 *     · `NotFoundHttpException` (direct) — `ROUTE_NOT_FOUND` as before.
 *     · `LaravelAuthorizationException` (direct) — unchanged,
 *       defensive.
 *     · `AccessDeniedHttpException` — unchanged.
 *     · `QueryException` — NEW. Returns `DATABASE_ERROR` with a
 *       generic message, never leaking SQL or bindings.
 *     · `ThrottleRequestsException` — NEW. Returns
 *       `TOO_MANY_REQUESTS` (429); the renderer additionally
 *       propagates the exception's own response headers.
 *     · `PostTooLargeException` — NEW. Returns
 *       `PAYLOAD_TOO_LARGE` (413).
 *     · `TokenMismatchException` — NEW. Returns
 *       `TOKEN_MISMATCH` (419).
 *
 *   The `renderable()` callback:
 *
 *     · Builds the same canonical envelope as before.
 *     · For 429 responses, propagates the exception's
 *       `getHeaders()` output (`Retry-After`, `X-RateLimit-Limit`,
 *       `X-RateLimit-Remaining`) onto the response.
 *     · Gates the `debug` block on `config('app.debug')` rather
 *       than `!app()->environment('production')`. Staging
 *       environments typically set `APP_ENV=staging` and
 *       `APP_DEBUG=false`; the previous gate would leak file paths
 *       and exception class names in that configuration.
 *
 * =============================================================================
 * What does NOT change
 * =============================================================================
 *   · The three original file-scoped helper functions
 *     (`mases_resolve_error`, `mases_resolve_request_id`,
 *     `mases_is_api_request`) — same signatures, same semantics.
 *
 *   · The `Application::configure(...)` chain.
 *
 *   · `->withRouting(web:, api:, commands:, health: '/up')`.
 *
 *   · The middleware stack: prepended `TenantContextMiddleware` and
 *     `HandleCors` (in the correct runtime order), `trustProxies`
 *     ranges, `statefulApi()`, appended `LocalizationMiddleware`,
 *     and the five aliases (`auth`, `auth.permission`, `auth.role`,
 *     `auth.application`, `tenant.membership`).
 *
 *   · The canonical error envelope shape:
 *       { success: false, error: { code, message, request_id,
 *         timestamp, [validation_errors], [context], [debug] } }
 *
 *   · The 422 top-level `errors` key emitted for
 *     `assertJsonValidationErrors()` compatibility.
 *
 *   · The `CoreException` context propagation and safe-key filter.
 *
 *   · The `Cache-Control: no-store, max-age=0` header on every error
 *     response.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     bootstrap/app.php
 *
 * This file REPLACES the existing v1.0.2. No other changes are
 * required — the class references are unchanged, the middleware
 * stack is unchanged, and no new classes are introduced. Because
 * only this file changed:
 *
 *   · `composer dump-autoload` is NOT required (no new class files).
 *   · `php artisan optimize:clear` IS recommended — the framework
 *     caches the bootstrapped application in the compiled services
 *     cache on some deployments, and this file is read at bootstrap
 *     time.
 *
 * Verification (Plesk PHP Command runner, or a one-off Scheduled
 * Task):
 *
 *     php artisan optimize:clear
 *
 * Then confirm the semantic distinction with a manual HTTP check:
 *
 *     # A nonexistent route returns ROUTE_NOT_FOUND
 *     curl -i -H "Authorization: Bearer <token>" \
 *          -H "X-Tenant-ID: 1" \
 *          https://api.maalearna.com.et/api/governance/nonexistent
 *     # → 404, error.code === "ROUTE_NOT_FOUND"
 *
 *     # A nonexistent model id returns RESOURCE_NOT_FOUND
 *     curl -i -H "Authorization: Bearer <token>" \
 *          -H "X-Tenant-ID: 1" \
 *          https://api.maalearna.com.et/api/governance/bodies/99999
 *     # → 404, error.code === "RESOURCE_NOT_FOUND"
 *
 * Before v1.1.0, both returned "ROUTE_NOT_FOUND".
 *
 * =============================================================================
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Imports added:             4 (QueryException, PostTooLargeException,
 *                               ThrottleRequestsException,
 *                               TokenMismatchException)
 * Branches added to
 *   mases_resolve_error():   6 (wrapped ModelNotFound, QueryException,
 *                               ThrottleRequestsException,
 *                               PostTooLargeException,
 *                               TokenMismatchException,
 *                               defensive direct checks)
 * Response-header
 *   enhancements:            1 (Retry-After / X-RateLimit-* on 429)
 * Debug-gate change:         1 (config('app.debug') instead of
 *                               app()->environment('production'))
 * Version:                   1.0.2 → 1.1.0 (MINOR)
 * =============================================================================
 */

use Illuminate\Auth\Access\AuthorizationException as LaravelAuthorizationException;
use Illuminate\Auth\AuthenticationException as LaravelAuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Exceptions\CoreException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
|--------------------------------------------------------------------------
| MASES Application Bootstrap
|--------------------------------------------------------------------------
|
| Single source of truth for:
|
|   1. Routing (web, api, console, health).
|   2. Global middleware stack, proxy trust, and route aliases.
|   3. Sanctum stateful SPA middleware.
|   4. Canonical MASES error envelope rendering.
|
| -----------------------------------------------------------------------------
| Revision history
| -----------------------------------------------------------------------------
| 1.0.0 — initial delivery.
|
| 1.0.1 — HandleCors explicitly registered.
|         The framework's default global stack did not include it in this
|         deployment, so cross-origin responses from
|         api.maalearna.com.et carried no Access-Control-Allow-Origin
|         header and the browser blocked them.
|
| 1.0.2 — Prepend order corrected.
|         In Laravel's Middleware builder, each `prepend()` call adds
|         to the FRONT of the global stack, so the LAST prepend call
|         runs FIRST at runtime. The 1.0.1 file called
|         `prepend(HandleCors)` first and `prepend(TenantContextMiddleware)`
|         second, which put TenantContextMiddleware ahead of HandleCors
|         in the resolved stack. TenantContextMiddleware answers OPTIONS
|         preflight requests before HandleCors sees them — so a
|         cross-origin OPTIONS to /sanctum/csrf-cookie never received
|         CORS headers and the browser blocked the follow-up request.
|
|         This revision swapped the two calls: TenantContextMiddleware is
|         prepended first (running second), and HandleCors is prepended
|         second (running first). HandleCors now intercepts every
|         cross-origin OPTIONS request before any other middleware runs
|         and answers it with the headers config/cors.php declares.
|
| 1.1.0 — Canonical error envelope enhancement:
|
|         · FIXED: `mases_resolve_error()` now correctly distinguishes
|           `ModelNotFoundException` (→ `RESOURCE_NOT_FOUND`) from a
|           missing route (→ `ROUTE_NOT_FOUND`). Laravel's
|           `Handler::prepareException()` wraps the former in
|           `NotFoundHttpException` before the `renderable()` callback
|           runs, and the original is preserved in the
|           `getPrevious()` chain. The fix inspects that chain.
|           Before this revision, every missing-model 404 returned
|           `ROUTE_NOT_FOUND`. The Governance feature tests accepted
|           either code, so the defect was latent.
|
|         · ADDED: explicit handling for `QueryException` (500,
|           generic message — no SQL or bindings leak).
|
|         · ADDED: explicit handling for `ThrottleRequestsException`
|           (429) with propagation of the exception's own
|           response headers (`Retry-After`, `X-RateLimit-Limit`,
|           `X-RateLimit-Remaining`).
|
|         · ADDED: explicit handling for `PostTooLargeException`
|           (413).
|
|         · ADDED: explicit handling for `TokenMismatchException`
|           (419).
|
|         · CHANGED: the `debug` block is now gated on
|           `config('app.debug')` rather than
|           `!app()->environment('production')`. A staging
|           environment with `APP_ENV=staging APP_DEBUG=false` no
|           longer leaks file paths and exception class names.
|
| -----------------------------------------------------------------------------
| Canonical error envelope
| -----------------------------------------------------------------------------
|
| Every error response returned by the API — regardless of source —
| follows this shape:
|
|     {
|       "success": false,
|       "error": {
|         "code":       "SCREAMING_SNAKE_CASE",
|         "message":    "Human readable message.",
|         "request_id": "…",
|         "timestamp":  "2026-09-10T12:34:56+00:00"
|       }
|     }
|
| Additional keys are permitted depending on error type:
|
|   - "error.validation_errors" + top-level "errors" on 422 responses.
|     The top-level "errors" key exists purely for compatibility with
|     Laravel's assertJsonValidationErrors() and common HTTP clients.
|     It is NOT emitted on any other status code.
|
|   - "error.context" for non-sensitive CoreException metadata.
|
|   - "error.debug" for non-production environments only (exception
|     class, file, line). Never emitted when `config('app.debug')` is
|     false.
|
| -----------------------------------------------------------------------------
| API request detection
| -----------------------------------------------------------------------------
|
| The renderer below applies only when `mases_is_api_request()` returns
| true. This covers three cases:
|
|   1. `Accept: application/json` (or similar) via $request->expectsJson().
|   2. A path prefix of `api/*` or `sanctum/*`.
|   3. A request whose host matches APP_URL's host — i.e. requests
|      arriving on the API subdomain `api.maalearna.com.et`.
|
| Case 3 is essential for MASES: the API is exposed on a dedicated
| subdomain, not under a path prefix, so a naive `$request->is('api/*')`
| check would silently skip envelope rendering for browser requests
| (which do not set Accept: application/json by default).
|
| -----------------------------------------------------------------------------
| Proxy trust
| -----------------------------------------------------------------------------
|
| trustProxies() is configured for loopback plus RFC1918 ranges, which
| matches a Plesk deployment behind a private load balancer. If your LB
| sits on a public IP, replace the CIDR list with the actual LB address
| and ensure it strips client-supplied X-Forwarded-* headers.
|
| Without correct proxy trust, `$request->isSecure()` returns false and
| Sanctum refuses to set secure cookies — producing the classic
| "cookie set on HTTP but rejected on HTTPS" failure.
|
| -----------------------------------------------------------------------------
| Middleware aliases
| -----------------------------------------------------------------------------
|
| The `auth` alias is bound to App\Http\Middleware\Authenticate (the
| MASES middleware), which performs the revoked-token cache check on
| every authenticated request. Do not replace this alias with the
| framework default.
|
| @package MASES
| @version 1.1.0
*/

// -----------------------------------------------------------------------------
// File-scoped helper functions
// -----------------------------------------------------------------------------
//
// Declared BEFORE the top-level `return`, because functions wrapped in
// `if (!function_exists(...))` blocks are conditionally declared at
// runtime. If they were placed after the `return`, they would never be
// reached and would remain undefined when the renderer fires.
// -----------------------------------------------------------------------------

if (!function_exists('mases_resolve_error')) {
    /**
     * Resolve the canonical (code, message, status) tuple for a throwable.
     *
     * The order of instanceof checks matters: subclasses of HttpException
     * must be tested before the generic HttpException branch.
     *
     * @return array{0: string, 1: string, 2: int}
     */
    function mases_resolve_error(\Throwable $e): array
    {
        // 1. MASES CoreException hierarchy (Authorization, Tenant,
        //    Authentication, File, …). These carry their own error code,
        //    message, and HTTP status.
        if ($e instanceof CoreException) {
            return [
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
            ];
        }

        // 2. Laravel domain exceptions.
        if ($e instanceof ValidationException) {
            return [
                'VALIDATION_ERROR',
                'The given data was invalid.',
                422,
            ];
        }

        if ($e instanceof LaravelAuthenticationException) {
            return [
                'UNAUTHENTICATED',
                'Authentication is required to access this resource.',
                401,
            ];
        }

        // Laravel's `Handler::prepareException()` converts a Laravel-native
        // AuthorizationException into AccessDeniedHttpException BEFORE the
        // renderable callback runs. This direct branch is defensive — it
        // fires only if a future Laravel version changes the flow.
        if ($e instanceof LaravelAuthorizationException) {
            return [
                'AUTH_PERMISSION_DENIED',
                'You are not authorised to perform this action.',
                403,
            ];
        }

        // Direct check for ModelNotFoundException. Same defensive posture:
        // in normal flow Laravel has already wrapped it. The branch fires
        // only if the flow changes.
        if ($e instanceof ModelNotFoundException) {
            return [
                'RESOURCE_NOT_FOUND',
                'The requested resource was not found.',
                404,
            ];
        }

        // 3. Symfony HTTP exceptions — specific subclasses first.

        // `NotFoundHttpException` is thrown by the router for an unmatched
        // URL, but is ALSO the wrapping type Laravel applies to a
        // `ModelNotFoundException` in `prepareException()`. Inspect the
        // previous-exception chain to recover the original cause and
        // preserve the semantic distinction between "URL doesn't exist"
        // and "row doesn't exist".
        if ($e instanceof NotFoundHttpException) {
            if ($e->getPrevious() instanceof ModelNotFoundException) {
                return [
                    'RESOURCE_NOT_FOUND',
                    'The requested resource was not found.',
                    404,
                ];
            }

            return [
                'ROUTE_NOT_FOUND',
                'The requested endpoint does not exist.',
                404,
            ];
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return [
                'METHOD_NOT_ALLOWED',
                'The HTTP method is not allowed for this endpoint.',
                405,
            ];
        }

        // Laravel's prepareException() converts a Laravel-native
        // AuthorizationException into AccessDeniedHttpException BEFORE the
        // renderable callback runs. Without this branch, every Gate
        // denial surfaces as a 500 rather than a 403.
        if ($e instanceof AccessDeniedHttpException) {
            return [
                'AUTH_PERMISSION_DENIED',
                'You are not authorised to perform this action.',
                403,
            ];
        }

        // Throttle middleware (and anything else that raises the Laravel
        // ThrottleRequestsException) — 429. The response renderer
        // additionally propagates the exception's own response headers
        // (`Retry-After`, `X-RateLimit-*`).
        if ($e instanceof ThrottleRequestsException) {
            return [
                'TOO_MANY_REQUESTS',
                'Too many requests. Please try again later.',
                429,
            ];
        }

        // Request body exceeds the server's post_max_size (413).
        if ($e instanceof PostTooLargeException) {
            return [
                'PAYLOAD_TOO_LARGE',
                'The request payload is too large.',
                413,
            ];
        }

        // CSRF token mismatch (419). Not normally reachable from a pure
        // JSON API, but included for completeness — a first-party SPA
        // hitting /sanctum/csrf-cookie followed by a POST to a web-guarded
        // route could surface this.
        if ($e instanceof TokenMismatchException) {
            return [
                'TOKEN_MISMATCH',
                'The session token has expired or is invalid.',
                419,
            ];
        }

        // Database-level exceptions. The original QueryException carries
        // the failing SQL statement and its bindings in its message; do
        // not expose any of it. Log the full exception via the normal
        // reporting path (QueryException is not in Laravel's internal
        // dontReport list) and return a generic 500 to the caller.
        if ($e instanceof QueryException) {
            return [
                'DATABASE_ERROR',
                'A database error occurred while processing the request.',
                500,
            ];
        }

        // Generic HttpException fallback — ensures framework-level HTTP
        // exceptions degrade gracefully instead of surfacing as 500s.
        if ($e instanceof HttpException) {
            $status = $e->getStatusCode();

            $code = match ($status) {
                400 => 'BAD_REQUEST',
                401 => 'UNAUTHENTICATED',
                402 => 'PAYMENT_REQUIRED',
                403 => 'AUTH_PERMISSION_DENIED',
                404 => 'RESOURCE_NOT_FOUND',
                405 => 'METHOD_NOT_ALLOWED',
                406 => 'NOT_ACCEPTABLE',
                409 => 'CONFLICT',
                410 => 'GONE',
                413 => 'PAYLOAD_TOO_LARGE',
                415 => 'UNSUPPORTED_MEDIA_TYPE',
                422 => 'VALIDATION_ERROR',
                429 => 'TOO_MANY_REQUESTS',
                500 => 'INTERNAL_SERVER_ERROR',
                503 => 'SERVICE_UNAVAILABLE',
                default => 'HTTP_ERROR',
            };

            return [
                $code,
                $e->getMessage() ?: 'An error occurred while processing the request.',
                $status,
            ];
        }

        // 4. Unknown exception — never leak the original message in production.
        $message = app()->environment('production')
            ? 'An internal server error occurred. Please try again later.'
            : ($e->getMessage() ?: 'An internal server error occurred.');

        return [
            'INTERNAL_SERVER_ERROR',
            $message,
            500,
        ];
    }
}

if (!function_exists('mases_resolve_request_id')) {
    /**
     * Resolve a request identifier for the current request.
     *
     * Resolution order:
     *   1. X-Request-ID header (set by upstream proxies / clients).
     *   2. X-Correlation-ID header (alternative common name).
     *   3. A freshly generated UUID v4.
     */
    function mases_resolve_request_id(Request $request): string
    {
        foreach (['X-Request-ID', 'X-Correlation-ID'] as $header) {
            $value = $request->header($header);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

if (!function_exists('mases_is_api_request')) {
    /**
     * Determine whether the request should receive the canonical JSON
     * error envelope.
     *
     * Unlike a naive `$request->is('api/*')` check, this also matches
     * requests arriving on the API subdomain (api.maalearna.com.et),
     * which is how the MASES deployment exposes its API.
     *
     * @param  Request $request
     * @return bool
     */
    function mases_is_api_request(Request $request): bool
    {
        // 1. Client explicitly asked for JSON.
        if ($request->expectsJson()) {
            return true;
        }

        // 2. Path-prefixed API or Sanctum routes.
        if ($request->is('api/*') || $request->is('sanctum/*')) {
            return true;
        }

        // 3. Request arrived on the API host (matches APP_URL's host).
        $apiHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($apiHost) && $apiHost !== '' && $request->getHost() === $apiHost) {
            return true;
        }

        return false;
    }
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        // ---------------------------------------------------------------------
        // Prepend order — read this before editing.
        // ---------------------------------------------------------------------
        // Laravel's `Middleware::prepend($mw)` adds $mw to the FRONT of the
        // global stack. Each additional prepend call goes further forward.
        // Therefore, of the two calls below, the SECOND one to appear in
        // the source is the FIRST to run at runtime.
        //
        // Runtime order at the top of the global stack:
        //
        //     1. HandleCors               (prepended second)
        //     2. TenantContextMiddleware  (prepended first)
        //
        // This is the order this file intends: HandleCors must answer
        // cross-origin OPTIONS preflights BEFORE any other middleware,
        // because a preflight carries no auth, no tenant header, and no
        // user context — only an Origin, an Access-Control-Request-Method,
        // and an Access-Control-Request-Headers. A middleware that expects
        // an authenticated, tenant-resolved request would otherwise reject
        // or mis-handle it.
        // ---------------------------------------------------------------------

        // ---------------------------------------------------------------------
        // Tenant context — prepended FIRST so it runs SECOND in the stack.
        // ---------------------------------------------------------------------
        // Runs after HandleCors. For a normal API request (not a preflight),
        // it resolves the tenant from mases_tenant_id() and shares it with
        // downstream consumers. For an OPTIONS preflight, it is never
        // reached: HandleCors answers and returns before the request
        // reaches this middleware.
        // ---------------------------------------------------------------------
        $middleware->prepend(
            \MaaleArna\MASES\Core\Tenancy\Middleware\TenantContextMiddleware::class
        );

        // ---------------------------------------------------------------------
        // HandleCors — prepended SECOND so it runs FIRST in the stack.
        // ---------------------------------------------------------------------
        // Reads config/cors.php and adds, to every response whose request
        // carries an Origin header:
        //
        //   Access-Control-Allow-Origin: <matched origin>
        //   Access-Control-Allow-Credentials: true
        //   Access-Control-Allow-Methods: …
        //   Access-Control-Allow-Headers: …
        //   Vary: Origin
        //
        // It answers OPTIONS preflight requests directly, without passing
        // them to the router. Running first guarantees that even a response
        // short-circuited by any later middleware — TenantContextMiddleware
        // included — carries the headers, so the browser can read it.
        // ---------------------------------------------------------------------
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        // ---------------------------------------------------------------------
        // Proxy trust
        // ---------------------------------------------------------------------
        // Trusts loopback plus RFC1918 private ranges — correct for Plesk
        // and typical private-cloud load balancers. Replace with the actual
        // LB CIDR if the proxy sits on a public address, and ensure the LB
        // strips client-supplied X-Forwarded-* headers.
        // ---------------------------------------------------------------------
        $middleware->trustProxies(
            at: [
                '127.0.0.1',
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
            ],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        // ---------------------------------------------------------------------
        // Sanctum stateful SPA middleware
        // ---------------------------------------------------------------------
        // REQUIRED for cookie-based Sanctum authentication on first-party
        // SPAs. Enables EnsureFrontendRequestsAreStateful on /api/* and
        // reads SANCTUM_STATEFUL_DOMAINS from .env.
        //
        // Without this call, cookie-based flows silently fail and only
        // bearer tokens are accepted, making the entire `stateful` array
        // in config/sanctum.php inert.
        // ---------------------------------------------------------------------
        // Preserve incoming sender references byte-for-byte. These are external
        // identifiers and must not be trimmed or converted from '' to null.
        $middleware->trimStrings(except: ['sender_reference']);
        $middleware->convertEmptyStringsToNull(except: [
            // Skip whole-request conversion only for incoming registrations;
            // the controller normalizes optional fields while preserving the
            // sender's external reference exactly, including an empty string.
            static fn (\Illuminate\Http\Request $request): bool => $request->is('api/documents/incoming'),
        ]);

        $middleware->statefulApi();

        // ---------------------------------------------------------------------
        // Localization
        // ---------------------------------------------------------------------
        // Runs after tenant resolution so it can consider tenant-specific
        // locale defaults.
        // ---------------------------------------------------------------------
        $middleware->append(
            \MaaleArna\MASES\Core\Localization\Middleware\LocalizationMiddleware::class
        );

        // ---------------------------------------------------------------------
        // Middleware aliases
        // ---------------------------------------------------------------------
        $middleware->alias([
            // Resolves the MASES Authenticate middleware, which performs
            // the revoked-token cache check on every authenticated request.
            // Do NOT replace with the framework default.
            'auth'              => \App\Http\Middleware\Authenticate::class,

            // MASES domain aliases (all backed by AuthorizationMiddleware).
            'auth.permission'   => \MaaleArna\MASES\Core\Authorization\Middleware\AuthorizationMiddleware::class,
            'auth.role'         => \MaaleArna\MASES\Core\Authorization\Middleware\AuthorizationMiddleware::class,
            'auth.application'  => \MaaleArna\MASES\Core\Authorization\Middleware\AuthorizationMiddleware::class,
            'tenant.membership' => \MaaleArna\MASES\Core\Tenancy\Middleware\TenantMembershipMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        // ---------------------------------------------------------------------
        // Canonical JSON error rendering
        // ---------------------------------------------------------------------
        $exceptions->renderable(function (\Throwable $e, Request $request): ?JsonResponse {

            // Non-API requests fall through to Laravel's default HTML
            // renderer. This keeps web routes, error pages, and any
            // non-JSON consumer untouched.
            if (!mases_is_api_request($request)) {
                return null;
            }

            // Compute the canonical error code, message, and status code.
            [$code, $message, $status] = mases_resolve_error($e);

            // Build the canonical envelope.
            $envelope = [
                'success' => false,
                'error'   => [
                    'code'       => $code,
                    'message'    => $message,
                    'request_id' => mases_resolve_request_id($request),
                    'timestamp'  => now()->toIso8601String(),
                ],
            ];

            // -------------------------------------------------------------
            // 422 — attach per-field validation messages in two shapes:
            //
            //   - `error.validation_errors.*` — MASES canonical location.
            //   - `errors.*` at the response root — Laravel's conventional
            //     location, expected by assertJsonValidationErrors() and
            //     many HTTP clients. Emitted ONLY on 422 responses.
            // -------------------------------------------------------------
            if ($e instanceof ValidationException) {
                $fieldErrors = $e->errors();

                $envelope['error']['validation_errors'] = $fieldErrors;
                $envelope['errors'] = $fieldErrors;
            }

            // CoreException — attach any non-sensitive context keys.
            if ($e instanceof CoreException) {
                $context = $e->getContext();
                if (!empty($context)) {
                    $safeKeys = ['tenant_id', 'user_id', 'resource_type', 'resource_id', 'action'];
                    $safeContext = array_intersect_key($context, array_flip($safeKeys));
                    if (!empty($safeContext)) {
                        $envelope['error']['context'] = $safeContext;
                    }
                }
            }

            // Non-production — attach debug metadata. Gated on
            // `config('app.debug')` rather than the environment name: a
            // staging deployment that runs `APP_ENV=staging APP_DEBUG=false`
            // must not leak file paths and exception class names.
            if (config('app.debug') === true) {
                $envelope['error']['debug'] = [
                    'exception' => get_class($e),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ];
            }

            // -------------------------------------------------------------
            // Response headers.
            //
            // The `Cache-Control` directive prevents intermediaries and
            // browsers from caching an error response. On 429 responses,
            // propagate the throttle middleware's own headers
            // (`Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`)
            // so a client knows when to retry.
            // -------------------------------------------------------------
            $headers = [
                'Cache-Control' => 'no-store, max-age=0',
            ];

            if ($e instanceof ThrottleRequestsException) {
                foreach ($e->getHeaders() as $headerName => $headerValue) {
                    $headers[$headerName] = $headerValue;
                }
            }

            return response()->json($envelope, $status, $headers);
        });
    })
    ->create();