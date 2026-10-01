<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Authorization\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;

/**
 * CheckPermission Middleware
 *
 * Verifies that the authenticated user possesses the required permission
 * before allowing access to a route.
 *
 * This middleware is designed to be used after the authentication middleware
 * (e.g., `auth:sanctum`) and, optionally, after the tenant context middleware
 * (`tenant.context`) if the permission is tenant‑scoped.
 *
 * The permission slug is passed as a parameter (e.g., `auth.permission:finance.transaction.create`).
 * The authorization service automatically resolves tenant and application contexts
 * from the current request using helpers like `mases_tenant_id()`.
 *
 * @package MaaleArna\MASES\Core\Authorization\Middleware
 */
final class CheckPermission
{
    /**
     * The authorization service used to perform the permission check.
     *
     * @var AuthorizationService
     */
    private AuthorizationService $authService;

    /**
     * Create a new CheckPermission instance.
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
     * @param string $permissionSlug The required permission slug.
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $permissionSlug): mixed
    {
        // 1. AUTHENTICATION CHECK
        // The middleware expects an authenticated user. If none is found,
        // return a 401 Unauthorized response immediately.
        $user = $request->user();
        if (!$user) {
            Log::warning('CheckPermission: Unauthenticated request blocked.', [
                'permission' => $permissionSlug,
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

        // 2. PERMISSION CHECK
        // The authorization service checks both direct user permissions and
        // permissions inherited via roles. Tenant and application contexts are
        // resolved automatically if not explicitly provided.
        $hasPermission = $this->authService->hasPermission($permissionSlug, $user);

        // 3. DENY ACCESS IF PERMISSION IS MISSING
        if (!$hasPermission) {
            Log::warning('CheckPermission: Permission denied.', [
                'user_id' => $user->id,
                'permission' => $permissionSlug,
                'ip' => $request->ip(),
                'url' => $request->fullUrl(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AUTH_PERMISSION_DENIED',
                    'message' => sprintf('Permission "%s" is required.', $permissionSlug),
                ],
            ], 403);
        }

        // 4. PERMISSION GRANTED – LOG AND CONTINUE
        Log::debug('CheckPermission: Permission granted.', [
            'user_id' => $user->id,
            'permission' => $permissionSlug,
        ]);

        return $next($request);
    }
}