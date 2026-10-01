<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Authorization\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;

/**
 * CheckApplicationAccess Middleware
 *
 * Verifies that the authenticated user can access a specific platform application.
 *
 * Application access is typically controlled by a permission with the slug
 * 'app.{slug}.access' (e.g., 'app.finance.access'). This middleware checks
 * that permission using the injected AuthorizationService.
 *
 * The middleware should be placed after authentication (e.g., `auth:sanctum`)
 * and optionally after tenant context middleware (`tenant.context`) if the
 * application access is tenant‑scoped.
 *
 * The application slug is passed as a parameter (e.g., `auth.application:finance`).
 *
 * @package MaaleArna\MASES\Core\Authorization\Middleware
 */
final class CheckApplicationAccess
{
    /**
     * The authorization service used to perform the access check.
     *
     * @var AuthorizationService
     */
    private AuthorizationService $authService;

    /**
     * Create a new CheckApplicationAccess instance.
     *
     * @param AuthorizationService $authService
     */
    public function __construct(AuthorizationService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string $applicationSlug The slug of the platform application.
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $applicationSlug): mixed
    {
        // 1. AUTHENTICATION CHECK
        // The middleware expects an authenticated user. If none is found,
        // return a 401 Unauthorized response immediately.
        $user = $request->user();
        if (!$user) {
            Log::warning('CheckApplicationAccess: Unauthenticated request blocked.', [
                'application' => $applicationSlug,
                'ip' => $request->ip(),
                'url' => $request->fullUrl(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AUTH_REQUIRED',
                    'message' => 'Authentication required.',
                ],
            ], 401);
        }

        // 2. APPLICATION ACCESS CHECK
        // The authorization service checks if the user has the required
        // 'app.{slug}.access' permission, taking into account tenant and
        // application contexts automatically.
        $canAccess = $this->authService->canAccessApplication(
            $applicationSlug,
            $user
        );

        // 3. DENY ACCESS IF THE USER LACKS THE REQUIRED PERMISSION
        if (!$canAccess) {
            Log::warning('CheckApplicationAccess: Access denied.', [
                'user_id' => $user->id,
                'application' => $applicationSlug,
                'ip' => $request->ip(),
                'url' => $request->fullUrl(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AUTH_APPLICATION_DENIED',
                    'message' => sprintf(
                        'Access to application "%s" is required.',
                        $applicationSlug
                    ),
                ],
            ], 403);
        }

        // 4. ACCESS GRANTED – LOG AND CONTINUE
        Log::debug('CheckApplicationAccess: Access granted.', [
            'user_id' => $user->id,
            'application' => $applicationSlug,
        ]);

        return $next($request);
    }
}