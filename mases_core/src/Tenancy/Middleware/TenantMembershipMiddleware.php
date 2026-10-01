<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Exceptions\AuthenticationException;
use MaaleArna\MASES\Core\Exceptions\TenantException;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Throwable;

/**
 * TenantMembershipMiddleware
 *
 * Enforces that the authenticated user is a member of the currently
 * resolved tenant context.
 *
 * -----------------------------------------------------------------------------
 * Placement
 * -----------------------------------------------------------------------------
 * This middleware MUST run AFTER:
 *   - `TenantContextMiddleware` (which resolves the tenant)
 *   - Authentication middleware (`auth:sanctum` or equivalent)
 *
 * -----------------------------------------------------------------------------
 * Behaviour
 * -----------------------------------------------------------------------------
 *   1. If the user is not authenticated → throws
 *      `AuthenticationException::unauthenticated()`, which the renderer
 *      maps to 401 `AUTH_UNAUTHENTICATED`.
 *   2. If `requireTenant` is true and no tenant is resolved → throws 400
 *      `TENANT_REQUIRED`.
 *   3. If a tenant is resolved but the user is not a member → throws 403
 *      `TENANT_ACCESS_DENIED`.
 *   4. Otherwise → request continues.
 *
 * -----------------------------------------------------------------------------
 * Error contract
 * -----------------------------------------------------------------------------
 * This middleware does NOT build its own JSON response. On every failure it
 * throws a MASES exception, which the canonical error renderer in
 * `bootstrap/app.php` converts into the standard envelope:
 *
 *     {
 *       "success": false,
 *       "error": {
 *         "code":       "…",
 *         "message":    "…",
 *         "request_id": "…",
 *         "timestamp":  "…"
 *       }
 *     }
 *
 * This guarantees that the response shape is identical to every other error
 * path in the platform, including `request_id` and `timestamp`.
 *
 * -----------------------------------------------------------------------------
 * Revision history
 * -----------------------------------------------------------------------------
 * 1.0.0 — initial delivery. Three-way distinction between unauthenticated,
 *         tenant-required, and tenant-not-a-member conditions. The
 *         unauthenticated branch threw `TenantException` with a bespoke
 *         code `AUTH_REQUIRED`.
 *
 * 1.0.1 — R-18 / authentication-code consistency. The unauthenticated
 *         branch now throws the MASES
 *         `AuthenticationException::unauthenticated()` instead of
 *         constructing a `TenantException` with the ad-hoc code
 *         `AUTH_REQUIRED`.
 *
 *         WHY
 *         ---
 *         `AUTH_REQUIRED` was a bespoke literal, not a class constant
 *         on any MASES exception. Every other authentication error in
 *         the platform uses a code from the
 *         `MaaleArna\MASES\Core\Exceptions\AuthenticationException`
 *         taxonomy — D-15 (H-13) specifically aligned
 *         `App\Http\Middleware\Authenticate` to
 *         `AUTH_UNAUTHENTICATED` for exactly this semantic. Leaving
 *         `AUTH_REQUIRED` in place here means the platform still has
 *         two codes for the same condition, so clients branching on
 *         `error.code` must check both.
 *
 *         The condition this branch handles — `$request->user()` is
 *         null — is exactly what `AUTH_UNAUTHENTICATED` represents.
 *         It is not a tenant condition (no tenant is examined), not a
 *         membership condition (no membership is examined), and not
 *         an authorization condition (no permission is examined).
 *
 *         WHAT DOES NOT CHANGE
 *         ---------------------
 *         · The tenant-required branch (`TENANT_REQUIRED`, 400) is
 *           untouched.
 *         · The tenant-not-a-member branch
 *           (`TenantException::accessDenied()` → `TENANT_ACCESS_DENIED`,
 *           403) is untouched.
 *         · The `MEMBERSHIP_CHECK_FAILED` and `TENANT_RESOLUTION_FAILED`
 *           defensive branches are untouched.
 *         · The `requireTenant` parameter semantics are untouched.
 *         · The authenticated success path is untouched.
 *
 *         ONLY the message on the unauthenticated branch changes as a
 *         side effect: it becomes the canonical
 *         "Authentication is required to access this resource."
 *         instead of the previous
 *         "Authentication required to verify tenant membership."
 *         This is a minor diagnostic difference; clients branch on
 *         `error.code`, not `error.message`.
 *
 * @package MaaleArna\MASES\Core\Tenancy\Middleware
 * @version 1.0.1
 */
final class TenantMembershipMiddleware
{
    /**
     * The tenant context service.
     *
     * @var TenantContextService
     */
    private TenantContextService $tenantContext;

    /**
     * Create a new TenantMembershipMiddleware instance.
     *
     * @param TenantContextService $tenantContext
     */
    public function __construct(TenantContextService $tenantContext)
    {
        $this->tenantContext = $tenantContext;
    }

    /**
     * Handle an incoming request.
     *
     * @param Request     $request
     * @param Closure     $next
     * @param string|bool $requireTenantParam  Accepts 'true', 'false', true, false.
     *
     * @return mixed
     *
     * @throws AuthenticationException  When the request is unauthenticated.
     * @throws TenantException          For tenant-related failures.
     */
    public function handle(
        Request $request,
        Closure $next,
        string|bool $requireTenantParam = true
    ): mixed {
        // Normalize the parameter to a boolean.
        $requireTenant = filter_var($requireTenantParam, FILTER_VALIDATE_BOOLEAN);

        /** @var User|null $user */
        $user = $request->user();

        // ---------------------------------------------------------------------
        // 1. Authentication check.
        // ---------------------------------------------------------------------
        // A null user means the request is unauthenticated. Throw the
        // canonical MASES authentication exception so the renderer
        // produces `AUTH_UNAUTHENTICATED` (401) with the same
        // request_id/timestamp envelope used by every other error path.
        // See the class docblock §"Revision history" for the rationale
        // behind replacing the previous bespoke `AUTH_REQUIRED` code.
        // ---------------------------------------------------------------------
        if (!$user) {
            Log::info('TenantMembershipMiddleware: unauthenticated request blocked', [
                'ip'  => $request->ip(),
                'url' => $request->fullUrl(),
            ]);

            throw AuthenticationException::unauthenticated();
        }

        // ---------------------------------------------------------------------
        // 2. Resolve the current tenant.
        // ---------------------------------------------------------------------
        try {
            $tenant = $this->tenantContext->getTenant();
        } catch (Throwable $e) {
            Log::error('TenantMembershipMiddleware: failed to resolve tenant', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            throw new TenantException(
                'Unable to determine tenant context.',
                'TENANT_RESOLUTION_FAILED',
                500
            );
        }

        // ---------------------------------------------------------------------
        // 3. Tenant requirement check.
        // ---------------------------------------------------------------------
        if ($tenant === null) {
            if ($requireTenant) {
                Log::info('TenantMembershipMiddleware: tenant required but not present', [
                    'user_id' => $user->id,
                    'ip'      => $request->ip(),
                    'url'     => $request->fullUrl(),
                ]);

                throw TenantException::required();
            }

            // No tenant and not required → allow through.
            Log::debug('TenantMembershipMiddleware: no tenant context, skipping membership check', [
                'user_id' => $user->id,
                'url'     => $request->fullUrl(),
            ]);

            return $next($request);
        }

        // ---------------------------------------------------------------------
        // 4. Membership verification.
        // ---------------------------------------------------------------------
        try {
            $isMember = $user->tenants()
                ->where('tenant_id', $tenant->id)
                ->exists();
        } catch (Throwable $e) {
            Log::error('TenantMembershipMiddleware: membership check failed', [
                'user_id'   => $user->id,
                'tenant_id' => $tenant->id,
                'error'     => $e->getMessage(),
            ]);

            throw new TenantException(
                'Unable to verify tenant membership.',
                'MEMBERSHIP_CHECK_FAILED',
                500
            );
        }

        if (!$isMember) {
            Log::info('Tenant membership denied', [
                'user_id'     => $user->id,
                'tenant_id'   => $tenant->id,
                'tenant_slug' => $tenant->slug ?? null,
                'ip'          => $request->ip(),
                'url'         => $request->fullUrl(),
            ]);

            throw TenantException::accessDenied(
                'User is not a member of the requested tenant.'
            );
        }

        // ---------------------------------------------------------------------
        // 5. Membership verified.
        // ---------------------------------------------------------------------
        Log::debug('Tenant membership verified', [
            'user_id'     => $user->id,
            'tenant_id'   => $tenant->id,
            'tenant_slug' => $tenant->slug ?? null,
        ]);

        return $next($request);
    }
}