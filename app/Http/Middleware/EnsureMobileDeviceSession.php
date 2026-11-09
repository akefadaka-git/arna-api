<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Auth\Services\DeviceSessionService;
use Symfony\Component\HttpFoundation\Response;

final class EnsureMobileDeviceSession
{
    public function __construct(private readonly DeviceSessionService $sessions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        // Browser and legacy API tokens retain their existing middleware path.
        if (!$token instanceof PersonalAccessToken || !$token->can(DeviceSessionService::MOBILE_ABILITY)) {
            if ($session->last_seen_at === null || $session->last_seen_at->lt(now()->subMinutes(5))) {
            $session->forceFill(['last_seen_at' => now()])->save();
        }

        return $next($request);
        }

        $session = $user instanceof User ? $this->sessions->validateToken($user, $token) : null;
        if ($session === null) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AUTH_SESSION_INVALID',
                    'message' => 'Authentication session is unavailable.',
                ],
            ], 401);
        }

        return $next($request);
    }
}
