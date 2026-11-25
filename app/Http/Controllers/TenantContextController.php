<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * Reports resolved tenant context for diagnostics.
 * Does not enforce membership; tenant-scoped middleware owns that check.
 * Body and query tenant_id values are never resolution authorities.
 */
final class TenantContextController extends Controller
{
    /** Must match RequestTenantResolver::DOMAIN_SUFFIX. */
    private const DOMAIN_SUFFIX = 'maalearna.com.et';

    /** Must match RequestTenantResolver::DEFAULT_RESERVED_DOMAINS. */
    private const RESERVED_SUBDOMAINS = [
        'www',
        'api',
        'auth',
        'app',
        'portal',
        'finance',
        'hr',
        'admin',
        'arna',
        'ai',
        'file',
        'launcher',
    ];

    /** Returns the resolved tenant context for diagnostics. */
    public function show(Request $request): JsonResponse
    {
 
        /** @var User|null $user */
        $user = $request->user();

 
        $resolutionSource = $this->detectResolutionSource($request);

        // Report body/query tenant_id values that were ignored for resolution.
        $bodyTenantIdIgnored = $this->requestCarriedBodyTenantId($request);

        // Resolve tenant context established by middleware.
        $tenantId = null;
        try {
            $tenantId = mases_tenant_id();
        } catch (\Throwable $e) {
            Log::warning('TenantContextController: tenant resolution failed', [
                'error' => $e->getMessage(),
                'user_id' => $user?->id,
            ]);
        }

 
        if ($tenantId === null) {
            return $this->respond(
                tenant: null,
                user: $user,
                isMember: false,
                resolutionSource: $resolutionSource,
                bodyTenantIdIgnored: $bodyTenantIdIgnored,
            );
        }

 
        $tenant = null;
        try {
            $tenant = Tenant::query()->find($tenantId);
        } catch (\Throwable $e) {
            Log::warning('TenantContextController: failed to load tenant', [
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
            ]);
        }

 
        if ($tenant === null) {
            return $this->respond(
                tenant: null,
                user: $user,
                isMember: false,
                resolutionSource: $resolutionSource,
                bodyTenantIdIgnored: $bodyTenantIdIgnored,
            );
        }

        // Membership is reported for diagnostics; this endpoint does not enforce it.
        $isMember = false;
        if ($user instanceof User) {
            try {
                $isMember = $user->activeTenants()
                    ->where('tenants.id', $tenant->id)
                    ->exists();
            } catch (\Throwable $e) {
                Log::warning('TenantContextController: membership check failed', [
                    'user_id'   => $user->id,
                    'tenant_id' => $tenant->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

 
        return $this->respond(
            tenant: $tenant,
            user: $user,
            isMember: $isMember,
            resolutionSource: $resolutionSource,
            bodyTenantIdIgnored: $bodyTenantIdIgnored,
        );
    }

    /** Builds the no-cache tenant diagnostic response. */
    private function respond(
        ?Tenant $tenant,
        ?User $user,
        bool $isMember,
        string $resolutionSource,
        bool $bodyTenantIdIgnored,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data'    => [
                'tenant_id'              => $tenant?->id,
                'tenant_slug'            => $tenant?->slug,
                'tenant_name'            => $tenant?->name,
                'is_active'              => (bool) ($tenant?->is_active ?? false),
                'user_id'                => $user?->id,
                'user_email'             => $user?->email,
                'is_member'              => $isMember,
                'resolution_source'      => $resolutionSource,
                'body_tenant_id_ignored' => $bodyTenantIdIgnored,
            ],
        ], 200, [
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    /** Labels the header or subdomain source used by the resolver. */
    private function detectResolutionSource(Request $request): string
    {
 
        if ($request->hasHeader('X-Tenant-ID') && $request->header('X-Tenant-ID') !== '') {
            return 'header';
        }

 
        $host = strtolower($request->getHost());
        $suffix = '.' . self::DOMAIN_SUFFIX;

        if (str_ends_with($host, $suffix)) {
            $subdomain = substr($host, 0, -strlen($suffix));

            if (
                preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $subdomain) === 1
                && ! in_array($subdomain, self::RESERVED_SUBDOMAINS, true)
            ) {
                return 'subdomain';
            }
        }

        // Other tenant defaults do not change this diagnostic source label.
        return 'none';
    }

    /** Detects a body/query tenant_id that was not used for resolution. */
    private function requestCarriedBodyTenantId(Request $request): bool
    {
        return $request->has('tenant_id') && $request->input('tenant_id') !== '';
    }
}