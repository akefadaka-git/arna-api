<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\FirstPartySpaOrigin;
use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use MaaleArna\MASES\Core\Auth\Models\User as CoreUser;
use MaaleArna\MASES\Core\Auth\Services\DeviceSessionService;
use MaaleArna\MASES\Core\Exceptions\AuthenticationException;

/**
 * Rejects unauthenticated requests and revoked bearer tokens.
 * MASES exceptions are rendered by the canonical application handler.
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
        $this->applySsoCookieBearer($request);

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

        // Android bearer tokens are valid only while their server-side
        // device session remains active and linked to this exact user.
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if ($token instanceof PersonalAccessToken
            && app(DeviceSessionService::class)->isMobileToken($token)) {
            $session = $user instanceof CoreUser
                ? app(DeviceSessionService::class)->validateToken($user, $token)
                : null;

            if ($session === null) {
                throw AuthenticationException::invalidToken('device_session');
            }

            if ($session->last_seen_at === null || $session->last_seen_at->lt(now()->subMinutes(5))) {
                $session->forceFill(['last_seen_at' => now()])->save();
            }
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
     * Promote the shared HttpOnly token cookie to the bearer credential
     * expected by Sanctum. A browser Origin must be in both the explicit
     * CORS allow-list and Sanctum's stateful-domain list.
     */
    private function applySsoCookieBearer(Request $request): void
    {
        if (! FirstPartySpaOrigin::isTrusted($request)) {
            return;
        }

        // The shared cookie is canonical for trusted sibling SPAs. This
        // prevents a stale origin-local sessionStorage token from overriding
        // a newer login completed on another ARNA platform.
        $token = $request->cookie('arna_sso_token');

        if (is_string($token) && $token !== '') {
            $request->headers->set('Authorization', 'Bearer '.$token);
        }
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