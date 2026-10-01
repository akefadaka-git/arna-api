<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G1 · File 6 of 10
 * =============================================================================
 *
 * File:            Http/Controllers/GovernancePingController.php  (NEW)
 * Unit:            G1 — Governance domain foundation (ping controller)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The routes file (G1 file 5 of 10)
 *   referenced `GovernancePingController::show`, but the class was
 *   absent. A request to `GET /api/governance/ping` — once the
 *   provider had loaded the routes file and both `auth:sanctum` and
 *   `auth.permission:governance.view` had passed — would fail at
 *   dispatch time with a class-not-found error.
 *
 * After:
 *   One file that implements the ping endpoint's controller. The
 *   class is deliberately minimal:
 *
 *     · One public method: `show(Request): JsonResponse`.
 *     · One private helper: `resolveTenantId()`, mirroring the same
 *       helper on every Shareholder-domain controller.
 *     · Two private constants: the domain slug and the domain
 *       version, both used only for the diagnostic payload.
 *     · No constructor. No injected services. No repository. No
 *       policy. No audit call.
 *
 *   The method returns the canonical MASES envelope with a
 *   diagnostic `data` block. The block's shape is documented in the
 *   class docblock §"Response shape".
 *
 *   What this file deliberately does NOT do:
 *
 *     · It does NOT write an audit row. A read-only diagnostic
 *       endpoint is not a material governance mutation (master
 *       prompt §31 enumerates what must be audited: body creation,
 *       appointment, membership change, meeting creation,
 *       participant change, attendance change, agenda change,
 *       minute creation/approval, resolution creation/adoption,
 *       action assignment/completion/verification, delegation
 *       creation/change, declaration creation/review, election
 *       certification, document association). A ping is none of
 *       those. Recording every ping as an audit row would flood
 *       the trail and defeat its purpose — the same reasoning that
 *       keeps `MyShareholderController::show()` unaudited.
 *
 *     · It does NOT invoke a policy. There is no Governance model
 *       to authorize against at G1; there is no per-object
 *       decision to make. The route-level
 *       `auth.permission:governance.view` middleware enforces the
 *       only relevant authorization before the controller runs.
 *       When G2 introduces the first model + policy pair, the
 *       controllers for those entities will invoke
 *       `Gate::authorize(...)`; this controller will remain a
 *       permission-only endpoint.
 *
 *     · It does NOT accept request input. There are no query
 *       parameters, no path parameters, no request body. The
 *       endpoint is a pure read of request-scoped state that the
 *       middleware stack has already resolved.
 *
 *     · It does NOT read from the database. It reads only from:
 *         - `mases_tenant_id()` (in-process, resolved by
 *           TenantContextMiddleware)
 *         - `$request->user()` (in-process, resolved by
 *           Sanctum)
 *         - `app()->getLocale()` (in-process, resolved by
 *           LocalizationMiddleware)
 *         - `now()` (system clock)
 *       No query is issued. The endpoint's cost is dominated by
 *       the middleware stack, not by the controller.
 *
 *     · It does NOT cache. No `Cache::remember`, no response
 *       caching header. A diagnostic endpoint whose result could
 *       be stale is not diagnostic.
 *
 *     · It does NOT return a localized date. The `timestamp` field
 *       is ISO 8601 (machine-readable). The `_display` sibling
 *       pattern that the Shareholder, Wallet, and Loan resources
 *       use is a RESOURCE-layer convention, not a controller-layer
 *       one; a controller that returned `timestamp_display` would
 *       be the first such controller in the platform, and would
 *       pre-empt the ADR-GOV-011 decision about where the
 *       `RendersLocalizedDates` trait lives. The ping endpoint
 *       stays out of that decision.
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
 * Field-by-field rationale:
 *
 *   `domain`     — The literal string `governance`. Redundant with
 *                  the URL, but harmless in a diagnostic payload,
 *                  and useful when the operator has several
 *                  diagnostic endpoints open in different tabs and
 *                  needs to disambiguate which response came from
 *                  which domain.
 *
 *   `status`     — The literal string `ok`. A caller that reaches
 *                  this field has already passed `auth:sanctum`,
 *                  `tenant.membership`, and
 *                  `auth.permission:governance.view`; the fact that
 *                  the field is present is the assertion that all
 *                  three layers ran. No other value is produced by
 *                  this endpoint — if any of the three gates fails,
 *                  the request never reaches the controller, so a
 *                  non-`ok` status is not a state this endpoint
 *                  can represent.
 *
 *   `version`    — The domain scaffold's version. Used for
 *                  diagnostics when the operator needs to confirm
 *                  which build of the domain is deployed. Updated
 *                  by amendments to this file and to the domain
 *                  provider as the domain grows.
 *
 *   `tenant_id`  — The tenant id resolved by the middleware stack.
 *                  Present on every response; the caller's
 *                  membership in this tenant was verified by
 *                  `tenant.membership` before the controller ran.
 *
 *   `user_id`    — The authenticated user's id. Present on every
 *                  response; `auth:sanctum` guarantees a
 *                  non-null user.
 *
 *   `locale`     — The active locale at the time of the request.
 *                  Resolved by `LocalizationMiddleware` from the
 *                  standard precedence chain (user preference →
 *                  session → cookie → Accept-Language → default).
 *                  Useful for diagnosing locale-related issues in
 *                  a deployment.
 *
 *   `timestamp`  — The server's current time, in the deployment's
 *                  configured timezone, serialized as ISO 8601.
 *                  Present as the standard diagnostic field for
 *                  any endpoint; useful for latency checks when the
 *                  caller compares it against the client's clock.
 *
 * =============================================================================
 * Why this class is `final`
 * =============================================================================
 * Every controller in every MASES domain is declared `final`. The
 * convention is uniform across Shareholder, Wallet, and Loan. This
 * class follows it. Subclassing a controller is not a pattern the
 * platform supports; the extension point for a controller's
 * behaviour is the service it calls, not the class hierarchy.
 *
 * =============================================================================
 * The `resolveTenantId()` helper
 * =============================================================================
 * The helper is a copy of the same-named method on
 * `ShareholderController`, `ShareHoldingController`,
 * `CertificateController`, `MyShareholderController`, and
 * `ShareClassController`. It is not extracted into a trait because
 * the Shareholder domain's own controllers did not do so, and the
 * platform's convention is that each controller is an independent
 * HTTP boundary whose three-line guard is small enough that
 * extraction would add indirection without removing an invariant.
 *
 * The helper returns the tenant id as an `int`, or throws a
 * `ValidationException` with a `tenant` field error if no tenant is
 * available. In practice the exception never fires: the route's
 * `tenant.membership` middleware refuses to let the request reach
 * the controller without a tenant. The exception is the defensive
 * guard that surfaces a routing or middleware misconfiguration as
 * a 422 with a clear field error, rather than a 500 from a null
 * dereference.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Controllers/GovernancePingController.php
 *
 * The directory `mases_core/src/Domains/Governance/Http/Controllers/`
 * must exist. If it does not, create it before uploading — the
 * file's path is the full path.
 *
 * Until GovernanceServiceProvider (G1 file 4) is registered in
 * `bootstrap/providers.php` (G1 file 10 of 10), the class is inert:
 * present on disk but never instantiated, because the routes file
 * that references it is never `require`-d.
 *
 * Verification after files 4, 5, 7, 8, and 10 are deployed, and
 * the two seeders have run:
 *
 *     php artisan config:clear
 *     curl -H "Authorization: Bearer <token>" \
 *          -H "X-Tenant-ID: 1" \
 *          https://api.maalearna.com.et/api/governance/ping
 *
 * Expected: HTTP 200 with the canonical envelope and the seven
 * diagnostic fields described above.
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Constants added:           2 (DOMAIN_SLUG, DOMAIN_VERSION)
 * Methods added:             2 (show, resolveTenantId)
 * Services injected:         none
 * Policies invoked:          none
 * Audit calls:               none
 * =============================================================================
 */

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