<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Authorization\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Exceptions\AuthorizationException;

final class ApplicationAccessMiddleware
{
    private AuthorizationService $authService;

    public function __construct(AuthorizationService $authService)
    {
        $this->authService = $authService;
    }

    public function handle(Request $request, Closure $next, string $applicationSlug): mixed
    {
        $user = auth()->user();
        if (!$user) {
            throw new AuthorizationException(
                'Authentication required for authorization check.',
                'AUTH_UNAUTHENTICATED',
                401
            );
        }

        // canAccessApplication signature: (string $applicationSlug, ?User $user = null, int|string|null $tenantId = null)
        // So pass slug, user, tenantId (no applicationSlug because it's already the first argument)
        $tenantId = mases_tenant_id();

        if (!$this->authService->canAccessApplication($applicationSlug, $user, $tenantId)) {
            Log::warning('Application access check failed', [
                'user_id' => $user->id,
                'application' => $applicationSlug,
                'tenant_id' => $tenantId,
                'ip' => $request->ip(),
            ]);

            throw new AuthorizationException(
                sprintf('Required application "%s" access not granted.', $applicationSlug),
                'AUTH_APPLICATION_DENIED',
                403
            );
        }

        return $next($request);
    }
}