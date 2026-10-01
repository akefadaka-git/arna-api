<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P1-1a
 * =============================================================================
 *
 * File:            MyShareholderController.php  (NEW)
 * Unit:            P1-1a — Portal identity contract
 * Delivery type:   New file
 * Net lines:       +…
 *
 * Before:
 *   The file did not exist. The portal's identity-resolution path
 *   (frontend: mase_app/src/features/shareholders/api.ts ::
 *   getMyShareholder) called GET /api/shareholders/me. No backend route
 *   or controller produced that response. Every call from the portal
 *   fell through to the domain's route fallback and returned
 *   HTTP 404 ROUTE_NOT_FOUND. useMyShareholder in the portal therefore
 *   entered its 'error' state on every mount, and MyShareholdingsPage /
 *   MyCertificatesPage rendered their ErrorState on every load.
 *
 * After:
 *   A single-action controller that resolves the authenticated user's
 *   own shareholder record within the resolved tenant context and
 *   returns the two-outcome identity contract the frontend already
 *   expects.
 *
 * Public surface introduced:
 *   · Class:  MaaleArna\MASES\Core\Domains\Shareholder\Http\Controllers\MyShareholderController
 *   · Method: show(Request): JsonResponse
 *
 * Files modified:            none
 * Files deleted:             none
 * Routes registered:         none (declared by P1-1b)
 * Policies modified:         none
 * Resources modified:        none
 * Repository modified:       none
 * Migrations:                none
 * Seeders:                   none
 *
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Shareholder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\ShareholderResource;
use MaaleArna\MASES\Core\Domains\Shareholder\Repositories\ShareholderRepository;

/**
 * MyShareholderController
 *
 * HTTP boundary for the authenticated user's OWN shareholder record —
 * the portal's identity-resolution endpoint.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * One operation:
 *
 *     GET /api/shareholders/me  →  show()   self-lookup
 *
 * The route is registered by the domain's Routes/api.php (P1-1b) as a
 * static sibling of /classes, /holdings, and /certificates, declared
 * BEFORE the dynamic /{shareholder} route so that the router's first-
 * match resolution does not treat `me` as a numeric id.
 *
 * The controller exists as its own class rather than as a method on
 * ShareholderController because the two surfaces have different
 * responsibilities:
 *
 *   ShareholderController  — register administration. Its four actions
 *                            (index, show, store, update) are decisions
 *                            about OTHER shareholders, gated by the
 *                            ShareholderPolicy's three-path OR.
 *
 *   MyShareholderController — self-service identity. Its single action
 *                             resolves the caller's OWN record. The
 *                             resolution is by structural link
 *                             (shareholders.user_id = caller id within
 *                             the resolved tenant), not by grant.
 *
 * Merging the two would put a policy bypass on a controller whose
 * docblock explicitly declares the policy as the authorization layer.
 * Keeping them separate keeps each class's contract honest.
 *
 * =============================================================================
 * The identity contract — two outcomes, no leak
 * =============================================================================
 * The response is one of exactly two shapes:
 *
 *     linked: true   →  { linked: true,  shareholder: <ShareholderResource> }
 *     linked: false  →  { linked: false, shareholder: null }
 *
 * The `linked: false` outcome deliberately COLLAPSES every reason the
 * caller has no own record:
 *
 *   · no shareholder row references this user at all
 *   · the user is only linked as a representative (representative_user_id
 *     set, user_id null) — representation is not self-service
 *   · the direct link exists but belongs to a different tenant
 *   · the direct link exists but the row is soft-deleted
 *   · the direct link is to a non-individual shareholder (a hypothetical
 *     state the domain prevents at write time, but which a manual DB
 *     edit could produce)
 *
 * The collapse is intentional. Distinguishing the cases would turn the
 * endpoint into a cross-tenant and cross-role enumeration oracle: a
 * caller could iterate user ids and discover, from the response shape,
 * which ids exist as shareholders in another tenant or as
 * representatives of legal entities. The frontend's contract (see
 * MyShareholderResponse in features/shareholders/api.ts) models this
 * collapse exactly — one boolean, one nullable resource.
 *
 * The single non-collapsed case is an INACTIVE record. A caller who is
 * the direct link to a shareholder whose `is_active` is false receives
 * `linked: true` with the resource. The record is returned, not hidden.
 * The frontend renders an inactive notice rather than treating the row
 * as unlinked. Inactive is a state of the record the caller owns, not
 * a signal of a missing record.
 *
 * =============================================================================
 * Authorization — why there is no Gate::authorize
 * =============================================================================
 * This endpoint does NOT call Gate::authorize. The rationale is
 * deliberate and is a property of what the endpoint resolves, not an
 * oversight:
 *
 *   1. The endpoint cannot return another user's record. The lookup is
 *      `where('tenant_id', $tenantId)->where('user_id', $userId)` — a
 *      caller cannot influence either value. There is no
 *      `?shareholder_id=` parameter, no path id, no request body that
 *      could redirect the query at a different row.
 *
 *   2. The ShareholderPolicy's `view` ability governs access to
 *      OTHER shareholders' records. It grants through an OR of three
 *      paths — an administrative permission (`shareholder.view`), a
 *      self-service grant on an individual's own record
 *      (`shareholder.view_own` + user_id match), or a representative
 *      relationship. Applying the policy here would invert the
 *      dependency: a `shareholder.view_own` permission would gate the
 *      identity-resolution step that determines whether the permission
 *      is meaningful in the first place.
 *
 *   3. Requiring `shareholder.view_own` on this endpoint would also
 *      change the meaning of `linked: false`. A shareholder-role user
 *      whose RBAC is not yet aligned (see P1-2 of the portal workstream)
 *      would receive `linked: false` for a record they are structurally
 *      linked to — a semantically wrong answer to the question "do I
 *      have an own record?", even though the resource-fetch gate on
 *      holdings and certificates would still correctly deny access.
 *
 * The policy is therefore untouched by this controller. If a future CR
 * decides the endpoint should be permission-gated, that is a change to
 * the identity contract — the frontend's `linked` semantics, the
 * MyShareholderResponse type, and the useMyShareholder state machine
 * would all need to be re-evaluated in the same CR. It is not a bug
 * fix and is not done here.
 *
 * =============================================================================
 * Tenant scoping
 * =============================================================================
 * The tenant id is resolved from `mases_tenant_id()` — the same
 * server-side helper every other Shareholder-domain controller uses
 * (ShareholderController, ShareHoldingController, CertificateController,
 * ShareClassController). The value originates from
 * TenantContextMiddleware via TenantContextService, which is populated
 * before the auth and tenant-membership middlewares run. The controller
 * does NOT read the tenant from:
 *
 *   · the request body,
 *   · a query string parameter,
 *   · a custom header other than the one RequestTenantResolver itself
 *     consumes (X-Tenant-ID, handled earlier in the pipeline).
 *
 * The route's `tenant.membership` middleware guarantees a tenant is
 * present and that the caller is a member. The defensive validation
 * here mirrors the siblings' pattern: if the helper is unavailable or
 * returns null, the request cannot be processed as-is and a 422 with
 * a `tenant` field error is raised — a deployment or routing defect,
 * surfaced through the canonical error envelope.
 *
 * =============================================================================
 * Response envelope
 * =============================================================================
 * Success responses follow the canonical MASES envelope:
 *
 *     { "success": true, "data": { … } }
 *
 * The `data` block is the identity contract — `{ linked, shareholder }`.
 * The ShareholderResource's `$wrap = null` (declared on that class)
 * means the resource contributes no additional `data` wrapper; its
 * `resolve()` output is inserted verbatim as the value of
 * `data.shareholder`.
 *
 * No `meta` block. The endpoint is not paginated and returns a single
 * resource or null.
 *
 * Errors are not produced here. Validation failures, tenant-membership
 * denials, and authentication failures are raised as exceptions by this
 * controller (for the missing-tenant case) or by middleware (for the
 * other cases), and rendered by the canonical error pipeline in
 * bootstrap/app.php. This keeps every error — regardless of origin —
 * shaped identically, with `request_id` and `timestamp` present.
 *
 * =============================================================================
 * What this controller does NOT do
 * =============================================================================
 *   · It does not accept a shareholder id, user id, email, or name as
 *     input.
 *
 *   · It does not consult `representative_user_id`. Representation is
 *     a structural relationship that grants ACCESS, not self-service
 *     IDENTITY. A representative of a legal-entity shareholder does
 *     not receive that entity's record from this endpoint. When a
 *     future CR adds representative-context switching to the portal,
 *     it will be a distinct endpoint (e.g. /api/shareholders/me/represented)
 *     with its own contract — not a widening of this one.
 *
 *   · It does not fall back to any other endpoint. There is no name
 *     search, no email lookup, no "find the closest match".
 *
 *   · It does not cache. Every call performs the same single-indexed
 *     query. The `shareholders_tenant_id_user_id_unique` index makes
 *     the lookup constant-time.
 *
 *   · It does not log the request. The middleware stack and the
 *     authorization layers already produce audit rows for the cases
 *     that warrant them; a successful self-lookup is a routine read.
 *
 *   · It does not write. No audit row, no security event, no cache
 *     invalidation. The endpoint is a pure read.
 *
 * =============================================================================
 * Frontend alignment
 * =============================================================================
 * The response matches the approved Stage 15.12 identity contract and
 * the frontend's MyShareholderResponse type byte for byte:
 *
 *     interface MyShareholderResponse {
 *       readonly linked: boolean;
 *       readonly shareholder: Shareholder | null;
 *     }
 *
 * No frontend change is required to consume this endpoint. The
 * useMyShareholder composable (features/portal/useMyShareholder.ts)
 * already implements the two-outcome state machine that this response
 * drives.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Http\Controllers
 * @version 1.0.0
 */
final class MyShareholderController extends Controller
{
    /**
     * Constructor.
     *
     * The repository is bound as a singleton by ShareholderServiceProvider
     * (2A.2), so resolving this controller within a request yields the
     * same instance every other consumer in that request would receive.
     *
     * No other dependency is required. The endpoint is a single read;
     * it has no service layer, no transaction, no event dispatch.
     *
     * @param ShareholderRepository $repository
     */
    public function __construct(
        private readonly ShareholderRepository $repository,
    ) {
    }

    // =========================================================================
    // show — resolve the authenticated user's own shareholder record
    // =========================================================================

    /**
     * Return the authenticated user's own shareholder record, resolved
     * server-side.
     *
     * The resolution is structural: within the tenant that the
     * request-scoped context has established, find the shareholder
     * whose `user_id` column equals the authenticated user's id. The
     * caller supplies no identity input; the response is therefore
     * impossible to steer toward a different row.
     *
     * The repository's `findByUser()` method performs exactly this
     * lookup and accepts an optional eager-load list. The `user` and
     * `representative` relations are eager-loaded here so that the
     * resulting ShareholderResource's `whenLoaded` guards fire and the
     * embedded summaries are present in the payload — matching the
     * shape the workspace's shareholder detail endpoints produce.
     *
     * @param Request $request
     * @return JsonResponse
     *
     * @throws ValidationException  When no tenant context is available.
     */
    public function show(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        /*
         * The authenticated user. The route is protected by
         * `auth:sanctum`, so `$request->user()` is guaranteed non-null
         * when this method runs. The docblock annotation narrows the
         * type for static analysis without adding a runtime null check
         * that the middleware stack already guarantees.
         *
         * Same pattern as the inline `/api/user` closure in the central
         * routes file.
         *
         * @var User $user
         */
        $user = $request->user();

        $shareholder = $this->repository->findByUser(
            $tenantId,
            (int) $user->id,
            ['user', 'representative'],
        );

        /*
         * Unlinked outcome. See the class docblock §"The identity
         * contract — two outcomes, no leak" for the full list of
         * conditions that collapse into this branch and the reasoning
         * behind the collapse.
         */
        if ($shareholder === null) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'linked'      => false,
                    'shareholder' => null,
                ],
            ]);
        }

        /*
         * Linked outcome. The ShareholderResource's static $wrap = null
         * means `resolve()` returns the field map directly, without an
         * additional `data` wrapper. The outer envelope remains the
         * single wrapping layer, as it is for every other endpoint in
         * the domain.
         */
        return response()->json([
            'success' => true,
            'data'    => [
                'linked'      => true,
                'shareholder' => (new ShareholderResource($shareholder))->resolve(),
            ],
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the tenant id from the server-side request context.
     *
     * The value comes from `mases_tenant_id()`, which reads the tenant
     * established by TenantContextMiddleware earlier in the pipeline.
     * It is NEVER read from the request body, query string, or an
     * arbitrary header.
     *
     * A missing tenant at this point is a deployment or routing
     * defect — the route's `tenant.membership` middleware refuses to
     * let the request reach the controller without one. The correct
     * response at the HTTP boundary is still a 422, because the
     * request cannot be processed as-is; the canonical renderer maps
     * the ValidationException to the standard error envelope.
     *
     * Same shape as the identical helper on ShareholderController,
     * ShareHoldingController, and CertificateController. The
     * duplication is intentional: each controller is an independent
     * HTTP boundary, and the helper's body is a three-line guard whose
     * extraction into a shared trait would add indirection without
     * removing any invariant.
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