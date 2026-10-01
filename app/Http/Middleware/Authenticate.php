<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use MaaleArna\MASES\Core\Exceptions\AuthenticationException;

/**
 * Custom Authentication Middleware
 *
 * Extends Laravel's default Authenticate middleware to add MASES-specific
 * behaviour on top of the standard guard checks:
 *
 *   1. Rejects bearer tokens that have been revoked via the cache
 *      blacklist maintained by AuthController.
 *   2. Ensures unauthenticated requests on API routes never attempt a
 *      redirect to a web login page (this deployment has no web login
 *      route; the application is API-only).
 *
 * This middleware deliberately does NOT construct its own JSON error
 * envelope. When authentication fails it throws a MASES
 * `AuthenticationException`, which the canonical renderer in
 * `bootstrap/app.php` transforms into the MASES error envelope.
 *
 * -----------------------------------------------------------------------------
 * Error-code contract (v2.1.0 — D-15 / H-13)
 * -----------------------------------------------------------------------------
 * Two distinct failure modes produce two distinct canonical MASES codes,
 * both drawn from `MaaleArna\MASES\Core\Exceptions\AuthenticationException`
 * so that HTTP-layer 401 codes and application-layer 401 codes agree:
 *
 *   (a) UNAUTHENTICATED REQUEST — no valid guard authenticated the
 *       request. `AuthenticationException::unauthenticated()` is thrown,
 *       producing:
 *
 *           {
 *             "success": false,
 *             "error": {
 *               "code":       "AUTH_UNAUTHENTICATED",
 *               "message":    "Authentication is required to access this resource.",
 *               "request_id": "<from header or generated>",
 *               "timestamp":  "<ISO 8601>"
 *             }
 *           }
 *
 *   (b) REVOKED / INVALID TOKEN — a bearer token was presented but is
 *       present in the cache blacklist.
 *       `AuthenticationException::invalidToken('authentication')` is
 *       thrown, producing:
 *
 *           {
 *             "success": false,
 *             "error": {
 *               "code":       "AUTH_TOKEN_INVALID",
 *               "message":    "Invalid authentication token provided.",
 *               "request_id": "<from header or generated>",
 *               "timestamp":  "<ISO 8601>"
 *             }
 *           }
 *
 * Rationale for not rendering here: the MASES canonical envelope must
 * have exactly one producer, so that `request_id`, `timestamp`, message,
 * and any future envelope fields stay consistent across every error path.
 * Before Phase 1 Amendment A12, the middleware emitted a stripped-down
 * `{success:false,error:{code:"UNAUTHENTICATED"}}` response directly,
 * which (a) lacked `request_id` and `timestamp`, and (b) bypassed the
 * renderer because `abort()` throws `HttpResponseException`, which the
 * renderer does not recognise — surfacing as a 500 rather than a 401.
 *
 * -----------------------------------------------------------------------------
 * Revision history
 * -----------------------------------------------------------------------------
 * 2.0.0 — Phase 1 Amendment A12. Throws
 *         `Illuminate\Auth\AuthenticationException` (delegating envelope
 *         rendering to `bootstrap/app.php`); adds the revoked-token cache
 *         check; overrides `redirectTo()` to return null. Auth failures
 *         surfaced with code `UNAUTHENTICATED`.
 *
 * 2.1.0 — D-15 / H-13. Aligns this middleware's exception family with
 *         the MASES exception taxonomy.
 *
 *         BEFORE: both failure modes threw
 *         `Illuminate\Auth\AuthenticationException`. The renderer mapped
 *         that to the code `UNAUTHENTICATED` — a string with no `AUTH_`
 *         prefix, unlike every other code the MASES exception hierarchy
 *         produces (`AUTH_PERMISSION_DENIED`, `AUTH_INVALID_CREDENTIALS`,
 *         `AUTH_TENANT_ACCESS_DENIED`, …). Clients that branch on
 *         `error.code` could not distinguish a 401 produced by this
 *         middleware from a 401 produced by MASES application code that
 *         threw `AuthenticationException` directly.
 *
 *         AFTER: the middleware throws MASES `AuthenticationException`
 *         subclasses directly, so the renderer's `CoreException` branch
 *         (already the first `instanceof` check in
 *         `mases_resolve_error()`) maps both cases to canonical `AUTH_*`
 *         codes with no renderer change:
 *
 *           - Path A (no valid guard):
 *               `AuthenticationException::unauthenticated()`
 *               → code `AUTH_UNAUTHENTICATED`
 *
 *           - Path B (revoked token):
 *               `AuthenticationException::invalidToken('authentication')`
 *               → code `AUTH_TOKEN_INVALID`
 *
 *         The `LaravelAuthenticationException` branch of the renderer is
 *         NOT removed — it remains reachable for framework-internal
 *         sources such as Sanctum's own guard failures. Removing it
 *         would require a `bootstrap/app.php` amendment, which is out of
 *         D-15's scope.
 *
 *         `redirectTo()` becomes unreachable in normal operation, because
 *         `unauthenticated()` no longer calls it. It is retained as
 *         defensive code against a future change that reintroduces a
 *         redirect path.
 *
 *         No change to: guard resolution, revoked-token detection logic,
 *         `Cache::has()` check, bearer-token parsing, or any binding.
 *
 * @package App\Http\Middleware
 * @author  MASES Development Team
 * @version 2.1.0
 */
class Authenticate extends Middleware
{
    /**
     * Cache key prefix for revoked tokens (must match AuthController).
     */
    private const REVOKED_TOKEN_PREFIX = 'revoked_token:';

    /**
     * Handle an incoming request.
     *
     * Delegates guard resolution to the parent class, whose
     * `authenticate()` method calls this class's overridden
     * `unauthenticated()` on failure. That override throws MASES
     * `AuthenticationException::unauthenticated()`, producing the
     * canonical `AUTH_UNAUTHENTICATED` code via the renderer.
     *
     * After a successful authentication, rejects the request if the
     * presented bearer token has been revoked via the cache blacklist,
     * throwing MASES `AuthenticationException::invalidToken()`, producing
     * the canonical `AUTH_TOKEN_INVALID` code via the renderer.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @param  string[] ...$guards
     * @return mixed
     *
     * @throws AuthenticationException  When no guard authenticates the
     *                                  request, or when the presented
     *                                  bearer token has been revoked.
     */
    public function handle($request, Closure $next, ...$guards)
    {
        // Throws MASES AuthenticationException::unauthenticated() on
        // failure via the overridden unauthenticated() method below.
        // The canonical renderer in bootstrap/app.php produces the
        // MASES error envelope with code AUTH_UNAUTHENTICATED; this
        // middleware does not render.
        $this->authenticate($request, $guards);

        // Authentication succeeded. Enforce the revoked-token blacklist.
        if ($this->isTokenRevoked($request)) {
            throw AuthenticationException::invalidToken('authentication');
        }

        return $next($request);
    }

    /**
     * Handle an unauthenticated request.
     *
     * Overrides the parent method — which throws Laravel's own
     * `Illuminate\Auth\AuthenticationException` — to throw the MASES
     * `AuthenticationException` instead. This keeps the 401 code
     * produced by the middleware aligned with the 401 codes produced
     * by application-layer authentication code (which throws MASES
     * `AuthenticationException` directly from `AuthenticationService`).
     *
     * The renderer's `CoreException` branch is the first `instanceof`
     * check in `mases_resolve_error()`, so it maps this exception to
     * `AUTH_UNAUTHENTICATED` with no renderer change required.
     *
     * @param  Request  $request
     * @param  array<int, string>  $guards
     * @return never
     *
     * @throws AuthenticationException  Always.
     */
    protected function unauthenticated($request, array $guards)
    {
        throw AuthenticationException::unauthenticated();
    }

    /**
     * Check whether the presented bearer token is present in the
     * revoked-token cache blacklist.
     *
     * The cache key is derived from a SHA-256 hash of the raw bearer
     * token, matching the key written by AuthController when a token is
     * revoked via logout.
     *
     * @param  Request  $request
     * @return bool
     */
    private function isTokenRevoked(Request $request): bool
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return false;
        }

        $hashedToken = hash('sha256', $token);
        $cacheKey    = self::REVOKED_TOKEN_PREFIX . $hashedToken;

        return Cache::has($cacheKey);
    }

    /**
     * Get the path the user should be redirected to when they are not
     * authenticated.
     *
     * NOTE (v2.1.0): This method is unreachable in normal operation,
     * because `unauthenticated()` above throws directly rather than
     * delegating to the parent implementation that would call this
     * method. It is retained as defensive code against a future change
     * that reintroduces a redirect path.
     *
     * This is an API-only deployment with no web login route registered,
     * so no redirect target exists. Returning `null` would cause the
     * parent `unauthenticated()` implementation to throw without
     * attempting to build a URL for a non-existent `login` route.
     *
     * @param  Request  $request
     * @return string|null
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}