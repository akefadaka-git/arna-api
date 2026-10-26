<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Platform\Contracts\TenantContextResolverInterface;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use MaaleArna\MASES\Core\Exceptions\TenantException;
use Symfony\Component\HttpFoundation\Response;

/**
 * TenantContextMiddleware
 *
 * Resolves the current tenant from the request and binds it into the
 * TenantContextService for the duration of the request.
 *
 * Responsibilities:
 *   1. Ask the configured `TenantContextResolverInterface` to resolve a tenant ID.
 *   2. If resolved, load the tenant via `TenantContextService::setTenantById()`.
 *   3. If no tenant is resolved (or the ID is invalid), clear the context.
 *   4. Optionally enforce that a tenant MUST be present (via `$requireTenant`).
 *
 * **IMPORTANT:**
 *   - This middleware does **NOT** verify user membership. That is the
 *     responsibility of `TenantMembershipMiddleware`.
 *   - When `$requireTenant` is `false` (default), the request always
 *     continues, even if no tenant could be resolved.
 *   - When `$requireTenant` is `true`, a missing/invalid tenant results in a
 *     `TenantException` (400 or 404 respectively).
 *
 * The `$requireTenant` parameter can be passed via a route middleware alias:
 *
 *     Route::middleware('tenant.context:true')->group(...);
 *
 * @package MaaleArna\MASES\Core\Tenancy\Middleware
 */
final class TenantContextMiddleware
{
    /**
     * @var TenantContextResolverInterface
     */
    private TenantContextResolverInterface $resolver;

    /**
     * @var TenantContextService
     */
    private TenantContextService $tenantContext;

    public function __construct(
        TenantContextResolverInterface $resolver,
        TenantContextService $tenantContext
    ) {
        $this->resolver = $resolver;
        $this->tenantContext = $tenantContext;
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string|bool $requireTenant  Accepts "true", "1", true, or false.
     * @return mixed
     *
     * @throws TenantException
     */
    public function handle(Request $request, Closure $next, string|bool $requireTenant = false): mixed
    {
        // Normalise the parameter – middleware aliases pass strings.
        $requireTenant = filter_var($requireTenant, FILTER_VALIDATE_BOOLEAN);

        // This service is registered as a singleton. Clear any context left
        // by an earlier request (notably under long-lived workers) before
        // resolving this request's tenant.
        $this->tenantContext->clearTenant();

        // Resolve the tenant identifier from the request.
        try {
            $tenantId = $this->resolver->resolve($request);
        } catch (\Throwable $e) {
            Log::error('Tenant resolver threw an exception', [
                'error' => $e->getMessage(),
                'url'   => $request->fullUrl(),
                'ip'    => $request->ip(),
            ]);

            // A resolver failure is never a valid reason to crash the request.
            $this->tenantContext->clearTenant();

            if ($requireTenant) {
                throw new TenantException(
                    'Unable to resolve tenant context.',
                    'TENANT_RESOLUTION_FAILED',
                    400
                );
            }

            return $next($request);
        }

        // No tenant ID resolved – clear context and continue (or throw if required).
        if ($tenantId === null) {
            $this->tenantContext->clearTenant();

            Log::debug('Tenant context cleared (no tenant requested)', [
                'url' => $request->fullUrl(),
                'ip'  => $request->ip(),
            ]);

            if ($requireTenant) {
                throw new TenantException(
                    'No tenant context could be resolved for this request.',
                    'TENANT_REQUIRED',
                    400
                );
            }

            return $next($request);
        }

        // A non-empty X-Tenant-ID is an explicit primary-key contract.
        // Only when no such header was supplied may the validated platform
        // subdomain be interpreted as a tenant slug.
        $headerTenant = $request->header('X-Tenant-ID');
        $host = strtolower($request->getHost());
        $suffix = '.maalearna.com.et';
        $subdomain = str_ends_with($host, $suffix)
            ? substr($host, 0, -strlen($suffix))
            : null;
        $isSubdomainSlug = ($headerTenant === null || $headerTenant === '')
            && $subdomain !== null
            && $tenantId === $subdomain
            && preg_match('/\\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\z/D', $subdomain) === 1;

        try {
            $tenant = $isSubdomainSlug
                ? $this->tenantContext->setTenantBySlug($subdomain)
                : $this->tenantContext->setTenantById($tenantId);
        } catch (\Throwable $e) {
            Log::error('Failed to set tenant context', [
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
                'url'       => $request->fullUrl(),
            ]);

            $this->tenantContext->clearTenant();

            if ($requireTenant) {
                throw new TenantException(
                    'Failed to load the requested tenant.',
                    'TENANT_LOAD_FAILED',
                    404
                );
            }

            return $next($request);
        }

        // Tenant ID was resolved, but the tenant does not exist or is inactive.
        if ($tenant === null) {
            Log::warning('Tenant resolution failed: tenant not found or inactive', [
                'tenant_id' => $tenantId,
                'ip'        => $request->ip(),
                'url'       => $request->fullUrl(),
            ]);

            $this->tenantContext->clearTenant();

            if ($requireTenant) {
                throw new TenantException(
                    'The requested tenant does not exist or is inactive.',
                    'TENANT_NOT_FOUND',
                    404
                );
            }

            return $next($request);
        }

        // Success – tenant is set. Log it for diagnostics.
        Log::debug('Tenant context set', [
            'tenant_id'   => $tenant->id,
            'tenant_slug' => $tenant->slug ?? null,
            'url'         => $request->fullUrl(),
        ]);

        // Continue the request with the tenant context bound.
        // NOTE: Membership is NOT verified here. Use TenantMembershipMiddleware
        // for routes that require an authenticated user to belong to the tenant.
        return $next($request);
    }
}