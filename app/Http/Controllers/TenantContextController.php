<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * TenantContextController
 *
 * Diagnostic endpoint that reports the currently resolved tenant context
 * for the authenticated user.
 *
 * **Endpoint:** `GET /api/tenant-context`
 *
 * **Purpose:**
 *   - Allow developers, tests, and front-ends to inspect which tenant
 *     is currently active for the request.
 *   - Debug tenant resolution issues (header, subdomain).
 *
 * **Contract:**
 *   - ALWAYS returns HTTP 200, even when no tenant is resolved.
 *   - Returns `tenant_id: null` when no context is present.
 *   - Does NOT throw 4xx for missing or invalid tenants.
 *   - Does NOT enforce membership (that is delegated to
 *     `TenantMembershipMiddleware` on tenant-scoped routes).
 *
 * **Response shape:**
 * ```json
 * {
 *   "success": true,
 *   "data": {
 *     "tenant_id": 1,
 *     "tenant_slug": "main",
 *     "tenant_name": null,
 *     "is_active": true,
 *     "user_id": 42,
 *     "user_email": "user@example.com",
 *     "is_member": true,
 *     "resolution_source": "header",
 *     "body_tenant_id_ignored": false
 *   }
 * }
 * ```
 *
 * When no tenant is resolved:
 * ```json
 * {
 *   "success": true,
 *   "data": {
 *     "tenant_id": null,
 *     "tenant_slug": null,
 *     "tenant_name": null,
 *     "is_active": false,
 *     "user_id": 42,
 *     "user_email": "user@example.com",
 *     "is_member": false,
 *     "resolution_source": "none",
 *     "body_tenant_id_ignored": false
 *   }
 * }
 * ```
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery. `resolution_source` reported one of
 *          'header', 'input', 'user_default', 'config', 'none', mirroring
 *          the pre-C-09 priority order of `mases_tenant_id()`.
 *
 * 1.1.0  — C-09 correction. The tenant resolution model has changed.
 *
 *          Under C-09, for HTTP requests `mases_tenant_id()` reads tenant
 *          context exclusively from `TenantContextService`, which is
 *          populated by `TenantContextMiddleware` via `RequestTenantResolver`.
 *          The resolver's priority is:
 *
 *              1. `X-Tenant-ID` header
 *              2. Subdomain of the request host (excluding reserved names)
 *              3. null
 *
 *          A `tenant_id` supplied in the request body or query string is
 *          NO LONGER a resolution source. It is ordinary application input.
 *
 *          This endpoint is updated to reflect the new model:
 *
 *            · `resolution_source` now reports one of:
 *                'header'    — the request carried a non-empty X-Tenant-ID
 *                'subdomain' — no header, but the host carried a non-reserved subdomain
 *                'none'      — neither header nor subdomain applied
 *
 *              The previous values 'input', 'user_default', and 'config'
 *              are no longer reachable in an HTTP request and have been
 *              removed from the value set.
 *
 *            · A new diagnostic field is added:
 *                'body_tenant_id_ignored' — boolean. True when the request
 *                carried a non-empty body/query `tenant_id` that was NOT
 *                used to resolve the tenant. This makes the C-09 behavior
 *                self-documenting: a developer who sends body `tenant_id`
 *                and gets a different tenant sees immediately that the
 *                body value was ignored.
 *
 *          The endpoint remains a pure diagnostic. It does not enforce
 *          anything. Consumer code that reads `resolution_source` should
 *          treat unknown values as 'none' — the two branches a caller cares
 *          about remain: "a tenant was resolved" vs. "no tenant was resolved".
 *
 *          No route change. No envelope shape change for the pre-existing
 *          fields. One new field added. `Cache-Control: no-store, max-age=0`
 *          retained on every response.
 *
 * @package App\Http\Controllers
 * @version 1.1.0
 */
final class TenantContextController extends Controller
{
    /**
     * The platform's registrable domain suffix.
     *
     * MUST be kept in sync with `RequestTenantResolver::DOMAIN_SUFFIX`.
     * The duplication is a diagnostic convenience: the resolver exposes
     * neither its domain suffix nor its reserved list as public API, and
     * this controller needs them only to label the resolution source.
     *
     * Drift risk is low: a change to the platform's registrable domain
     * affects many other places besides this controller.
     *
     * @var string
     */
    private const DOMAIN_SUFFIX = 'maalearna.com.et';

    /**
     * Reserved subdomains that are NOT treated as tenant identifiers.
     *
     * MUST be kept in sync with `RequestTenantResolver::DEFAULT_RESERVED_DOMAINS`.
     * See the note on DOMAIN_SUFFIX.
     *
     * @var list<string>
     */
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

    /**
     * Return the current tenant context.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        // Resolve the current authenticated user (may be null for public endpoints).
        /** @var User|null $user */
        $user = $request->user();

        // Determine the resolution source under the C-09 model (diagnostic).
        $resolutionSource = $this->detectResolutionSource($request);

        // Report whether the request carried a body/query `tenant_id` that
        // was ignored. This is the C-09 behavior made visible.
        $bodyTenantIdIgnored = $this->requestCarriedBodyTenantId($request);

        // Resolve the tenant ID using the platform helper.
        // Under C-09, this reads from `TenantContextService` in HTTP context.
        // It NEVER throws — it returns null when no context is available.
        $tenantId = null;
        try {
            $tenantId = mases_tenant_id();
        } catch (\Throwable $e) {
            Log::warning('TenantContextController: tenant resolution failed', [
                'error' => $e->getMessage(),
                'user_id' => $user?->id,
            ]);
        }

        // No tenant context — return a clean null response.
        if ($tenantId === null) {
            return $this->respond(
                tenant: null,
                user: $user,
                isMember: false,
                resolutionSource: $resolutionSource,
                bodyTenantIdIgnored: $bodyTenantIdIgnored,
            );
        }

        // Attempt to load the tenant model.
        $tenant = null;
        try {
            $tenant = Tenant::query()->find($tenantId);
        } catch (\Throwable $e) {
            Log::warning('TenantContextController: failed to load tenant', [
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
            ]);
        }

        // If the tenant row does not exist, report it as no context.
        if ($tenant === null) {
            return $this->respond(
                tenant: null,
                user: $user,
                isMember: false,
                resolutionSource: $resolutionSource,
                bodyTenantIdIgnored: $bodyTenantIdIgnored,
            );
        }

        // Determine membership for the authenticated user (diagnostic only).
        $isMember = false;
        if ($user instanceof User) {
            try {
                $isMember = $user->tenants()
                    ->where('tenant_id', $tenant->id)
                    ->exists();
            } catch (\Throwable $e) {
                Log::warning('TenantContextController: membership check failed', [
                    'user_id'   => $user->id,
                    'tenant_id' => $tenant->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        // Return the full context.
        return $this->respond(
            tenant: $tenant,
            user: $user,
            isMember: $isMember,
            resolutionSource: $resolutionSource,
            bodyTenantIdIgnored: $bodyTenantIdIgnored,
        );
    }

    /**
     * Build the JSON response envelope.
     *
     * The envelope shape is preserved from v1.0.0 with one addition:
     * `body_tenant_id_ignored` — see the class docblock §"Revision history".
     *
     * @param  Tenant|null  $tenant
     * @param  User|null    $user
     * @param  bool         $isMember
     * @param  string       $resolutionSource      One of: 'header', 'subdomain', 'none'.
     * @param  bool         $bodyTenantIdIgnored   True when the request carried
     *                                              a body/query `tenant_id` that
     *                                              was not used for resolution.
     * @return JsonResponse
     */
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

    /**
     * Determine the resolution source under the C-09 model.
     *
     * In HTTP context, the platform's tenant context is established by
     * `TenantContextMiddleware` calling `RequestTenantResolver`. The
     * resolver's priority is:
     *
     *   1. Non-empty `X-Tenant-ID` header
     *   2. Non-reserved subdomain of the request host
     *   3. null
     *
     * A body/query `tenant_id` is NOT a source. Its presence is reported
     * separately via `requestCarriedBodyTenantId()`.
     *
     * The two constants DOMAIN_SUFFIX and RESERVED_SUBDOMAINS mirror the
     * private constants on `RequestTenantResolver`. See their docblocks.
     *
     * This method performs no I/O and never throws.
     *
     * @param  Request  $request
     * @return string  One of: 'header', 'subdomain', 'none'.
     */
    private function detectResolutionSource(Request $request): string
    {
        // Priority 1 — the X-Tenant-ID header.
        if ($request->hasHeader('X-Tenant-ID') && $request->header('X-Tenant-ID') !== '') {
            return 'header';
        }

        // Priority 2 — non-reserved subdomain of the request host.
        $host = strtolower($request->getHost());
        $suffix = '.' . self::DOMAIN_SUFFIX;

        if (str_ends_with($host, $suffix)) {
            $subdomain = substr($host, 0, -strlen($suffix));

            if (
                $subdomain !== ''
                && ! in_array($subdomain, self::RESERVED_SUBDOMAINS, true)
            ) {
                return 'subdomain';
            }
        }

        // No resolution source applied. Under C-09 the middleware may still
        // have set a tenant (via config default, for example), but for the
        // purpose of this diagnostic label, the source is "none".
        return 'none';
    }

    /**
     * Whether the request carried a non-empty body/query `tenant_id`.
     *
     * Under C-09, this value is NOT used for tenant resolution — it is
     * ordinary application input. The controller reports its presence so
     * a developer can see, at a glance, that the field they sent was
     * not the field the platform used.
     *
     * @param  Request  $request
     * @return bool
     */
    private function requestCarriedBodyTenantId(Request $request): bool
    {
        return $request->has('tenant_id') && $request->input('tenant_id') !== '';
    }
}