<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\FirstPartySpaOrigin;
use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use MaaleArna\Arna\Core\Auth\Models\User as CoreUser;
use MaaleArna\Arna\Core\Auth\Services\DeviceSessionService;
use MaaleArna\Arna\Core\Exceptions\AuthenticationException;

/**
 * Rejects unauthenticated requests and revoked bearer tokens.
 * ARNA exceptions are rendered by the canonical application handler.
 */
class Authenticate extends Middleware
{
    /** Shared with AuthController's revocation cache. */
    private const REVOKED_TOKEN_PREFIX = 'revoked_token:';

    /** Authenticates requests and validates revoked tokens and mobile sessions. */
    public function handle($request, Closure $next, ...$guards)
    {
        $this->applySsoCookieBearer($request);

        // The exception handler renders the canonical AUTH_UNAUTHENTICATED response.
        $this->authenticate($request, $guards);

 
        if ($this->isTokenRevoked($request)) {
            throw AuthenticationException::invalidToken('authentication');
        }

        // Require an active device session for Android bearer tokens.
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

    /** Throws the canonical ARNA unauthenticated exception. */
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

        // The shared SSO cookie takes precedence over origin-local tokens.
        $token = $request->cookie('arna_sso_token');

        if (is_string($token) && $token !== '') {
            $request->headers->set('Authorization', 'Bearer '.$token);
        }
    }

    /** Checks the SHA-256 token hash against the revocation cache. */
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

    /** No redirect target exists in this API-only application. */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}