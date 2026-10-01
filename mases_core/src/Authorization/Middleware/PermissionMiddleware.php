<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Authorization\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Exceptions\AuthorizationException;

final class PermissionMiddleware
{
    private AuthorizationService $authService;

    public function __construct(AuthorizationService $authService)
    {
        $this->authService = $authService;
    }

    public function handle(Request $request, Closure $next, string $permissionSlug): mixed
    {
        $user = auth()->user();
        if (!$user) {
            throw new AuthorizationException(
                'Authentication required for authorization check.',
                'AUTH_UNAUTHENTICATED',
                401
            );
        }

        $tenantId = mases_tenant_id();
        $applicationSlug = $this->resolveApplicationSlug($request);

        // Correct argument order: slug first, user second, then tenant/app.
        if (!$this->authService->hasPermission($permissionSlug, $user, $tenantId, $applicationSlug)) {
            Log::warning('Permission check failed', [
                'user_id' => $user->id,
                'permission' => $permissionSlug,
                'tenant_id' => $tenantId,
                'application_slug' => $applicationSlug,
                'ip' => $request->ip(),
            ]);

            throw new AuthorizationException(
                sprintf('Required permission "%s" not granted.', $permissionSlug),
                'AUTH_PERMISSION_DENIED',
                403
            );
        }

        return $next($request);
    }

    private function resolveApplicationSlug(Request $request): ?string
    {
        $routeApplication = $request->route('application');
        if (is_string($routeApplication) && $routeApplication !== '') {
            return $routeApplication;
        }

        $headerApp = $request->header('X-Application');
        if (is_string($headerApp) && $headerApp !== '') {
            return $headerApp;
        }

        $host = $request->getHost();
        $parts = explode('.', $host);
        if (count($parts) >= 3) {
            $subdomain = $parts[0];
            $applicationSlugs = config('mases.applications', []);
            $slugs = array_column($applicationSlugs, 'slug');
            if (in_array($subdomain, $slugs, true)) {
                return $subdomain;
            }
        }

        return null;
    }
}