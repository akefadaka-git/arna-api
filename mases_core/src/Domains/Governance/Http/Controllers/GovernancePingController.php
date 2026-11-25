<?php

declare(strict_types=1);



namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * GovernancePingController
 *
 * The Governance domain's G1 smoke endpoint.
 *
 * =============================================================================
 * Purpose
 * =============================================================================
 * A single diagnostic endpoint whose sole purpose is to give the
 * G1 acceptance test a URL to hit. It exercises ten independent
 * integration points in one HTTP request:
 *
 *    1. GovernanceServiceProvider booted and merged its config.
 *    2. The provider registered the domain's translation namespace.
 *    3. The provider loaded the routes file.
 *    4. The provider applied the configured prefix and middleware.
 *    5. Sanctum authenticates the request.
 *    6. TenantContextMiddleware resolves a tenant.
 *    7. TenantMembershipMiddleware verifies membership.
 *    8. The `governance.view` permission gate is enforced by
 *       `auth.permission`.
 *    9. This controller dispatches.
 *   10. The canonical response envelope is produced.
 *
 * The endpoint is NOT a health check for production monitoring. It
 * is not registered in any monitoring system. It is a scaffold
 * artifact whose continuing value is diagnostic — it remains
 * useful for verifying a fresh deployment of the domain after a
 * configuration change, an environment migration, or a provider
 * amendment.
 *
 * =============================================================================
 * Response shape
 * =============================================================================
 * The endpoint returns the canonical MASES envelope:
 *
 *     {
 *       "success": true,
 *       "data": {
 *         "domain":    "governance",
 *         "status":    "ok",
 *         "version":   "1.0.0",
 *         "tenant_id": 1,
 *         "user_id":   2,
 *         "locale":    "am",
 *         "timestamp": "2026-09-17T15:30:45+03:00"
 *       }
 *     }
 *
 * The field-by-field rationale appears in the file-level
 * change-verification block §"Response shape". In summary:
 *
 *   · `domain` and `version` identify the scaffold that produced
 *     the response.
 *   · `status` asserts that the three authentication gates ran
 *     (there is no other state this endpoint can reach).
 *   · `tenant_id`, `user_id`, and `locale` are the three
 *     request-scoped values the middleware stack resolved.
 *   · `timestamp` is the server's current time, ISO 8601.
 *
 * =============================================================================
 * Authorization
 * =============================================================================
 * The endpoint is protected by two layers:
 *
 *   1. The outer middleware stack applied by the provider:
 *      `api`, `auth:sanctum`, `tenant.membership`.
 *
 *   2. The route-level permission middleware declared in the routes
 *      file: `auth.permission:governance.view`.
 *
 * No policy check is made by the controller. There is no
 * Governance model to authorize against at G1, and the ping
 * endpoint does not read, write, or expose any governed record.
 * When G2 introduces the first model + policy pair, the
 * controllers for those entities will invoke `Gate::authorize()`;
 * this controller remains a permission-only endpoint.
 *
 * =============================================================================
 * Tenant resolution
 * =============================================================================
 * The tenant id is resolved from `mases_tenant_id()` — the same
 * server-side helper every other domain uses. It is never read
 * from:
 *
 *   · the request body,
 *   · a query-string parameter,
 *   · a custom header other than the one the established
 *     `RequestTenantResolver` consumes (`X-Tenant-ID`), which is
 *     handled earlier in the pipeline by
 *     `TenantContextMiddleware`.
 *
 * The route's `tenant.membership` middleware guarantees a tenant
 * is present and that the caller is a member. The defensive
 * validation in `resolveTenantId()` mirrors the siblings' pattern:
 * if the helper returns null, the request cannot be processed
 * as-is and a 422 with a `tenant` field error is raised — a
 * deployment or routing defect, surfaced through the canonical
 * error envelope.
 *
 * =============================================================================
 * Audit posture
 * =============================================================================
 * This endpoint does not write an audit row. A read-only
 * diagnostic is not one of the material governance mutations
 * enumerated by the master prompt §31, and recording every ping
 * would flood the audit trail without providing a corresponding
 * governance fact. The same reasoning keeps
 * `MyShareholderController::show()` unaudited.
 *
 * =============================================================================
 * No database access
 * =============================================================================
 * The controller issues no query. Every value in the response is
 * derived from request-scoped state that the middleware stack has
 * already resolved, plus the system clock. See the file-level
 * change-verification block §"What this file deliberately does NOT
 * do" for the enumeration.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Controllers
 * @version 1.0.0
 */
final class GovernancePingController extends Controller
{
    /**
     * The domain's slug. Reported in the diagnostic payload's
     * `domain` field.
     *
     * The literal value matches the `slug` of the domain's
     * `platform_applications` row (G1 file 9 of 10) and the
     * `governance::` translation namespace prefix. Changing it
     * requires a coordinated change to both.
     */
    private const DOMAIN_SLUG = 'governance';

    /**
     * The scaffold's version. Reported in the diagnostic payload's
     * `version` field.
     *
     * The value tracks the domain provider's `@version` tag. It is
     * updated by amendments to this file and to the provider as the
     * domain grows; a future phase may move the value into
     * `config('governance.version')` if the number becomes
     * meaningful to operators.
     */
    private const DOMAIN_VERSION = '1.0.0';

    // =========================================================================
    // show — the ping response
    // =========================================================================

    /**
     * Return the domain's diagnostic payload.
     *
     * No input. No database access. No service call. The response
     * is a pure projection of request-scoped state and the system
     * clock; see the class docblock for the field-by-field shape.
     *
     * @param Request $request
     * @return JsonResponse
     *
     * @throws ValidationException  When no tenant context is
     *                              available. In practice this
     *                              never fires — the route's
     *                              `tenant.membership` middleware
     *                              refuses to let the request reach
     *                              the controller without a tenant.
     *                              The exception is the defensive
     *                              guard for a middleware or
     *                              routing misconfiguration.
     */
    public function show(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        return response()->json([
            'success' => true,
            'data'    => [
                'domain'    => self::DOMAIN_SLUG,
                'status'    => 'ok',
                'version'   => self::DOMAIN_VERSION,
                'tenant_id' => $tenantId,
                'user_id'   => $request->user()?->id,
                'locale'    => app()->getLocale(),
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the tenant id from the server-side request context.
     *
     * The value comes from `mases_tenant_id()`, which reads the
     * tenant that was established by `TenantContextMiddleware`
     * earlier in the pipeline. It is NEVER read from the request
     * body, query string, or an arbitrary header.
     *
     * A missing tenant at this point is a deployment or routing
     * defect — the route's `tenant.membership` middleware refuses
     * to let the request reach the controller without one. The
     * correct response at the HTTP boundary is still a 422, because
     * the request cannot be processed as-is; the canonical renderer
     * maps the ValidationException to the standard error envelope.
     *
     * Same shape as the identical helper on
     * `ShareholderController`, `ShareHoldingController`,
     * `CertificateController`, and `MyShareholderController`. The
     * duplication is intentional: each controller is an independent
     * HTTP boundary, and the helper's body is a three-line guard
     * whose extraction into a shared trait would add indirection
     * without removing any invariant.
     *
     * @return int
     *
     * @throws ValidationException
     */
    private function resolveTenantId(): int
    {
        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;

        if ($tenantId === null) {
            throw ValidationException::withMessages([
                'tenant' => [
                    'No tenant context is available for this request. '
                    . 'This route must be protected by the tenant resolution middleware.',
                ],
            ]);
        }

        return (int) $tenantId;
    }
}