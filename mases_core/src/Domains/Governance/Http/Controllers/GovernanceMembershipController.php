<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 25 of 31
 * =============================================================================
 *
 * File:            Http/Controllers/GovernanceMembershipController.php  (NEW)
 * Unit:            G2c — GovernanceMembership vertical slice (controller)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No controller dispatched the four
 *   GovernanceMembership HTTP routes the routes amendment (G2c
 *   file 31 of 31) will declare. Any request to
 *   `GET/POST /api/governance/bodies/{governanceBody}/memberships`
 *   or `GET/PATCH/PUT /api/governance/memberships/{governanceMembership}`
 *   would have fallen through to the platform's catch-all and
 *   returned 404.
 *
 * After:
 *   One file that declares the four canonical controller actions
 *   the routes amendment expects:
 *
 *     index()   — paginated list of a body's memberships
 *     store()   — register a new membership under a body
 *     show()    — read a specific membership
 *     update()  — modify a specific membership
 *
 *   The controller follows the conventions the five sibling
 *   controllers in this domain established at G1, G2a, G2b, and
 *   G2c's appointment half:
 *
 *     · `final class` extending `App\Http\Controllers\Controller`.
 *
 *     · Constructor injection of the service and the repository.
 *
 *     · Route parameter names match the controller argument names
 *       exactly (`{governanceBody}` → `$governanceBody`,
 *       `{governanceMembership}` → `$governanceMembership`). This
 *       is critical for implicit model binding.
 *
 *     · The tenant cross-check runs BEFORE `Gate::authorize()`.
 *       A cross-tenant id produces a 404, not a 403.
 *
 *     · `store()` merges `governance_body_id` from the URL into the
 *       validated payload, overwriting any client-supplied value.
 *
 *     · `update()` does NOT merge `governance_body_id`. The service
 *       rejects a change to that FK column (INV-2 in the service's
 *       docblock).
 *
 *     · The response envelope is `{ success: true, data: ... }`
 *       with a `meta` block for the paginated `index` action.
 *
 *   What the controller deliberately does NOT expose:
 *
 *     · The SIX lifecycle methods the service declares: `end()`,
 *       `suspend()`, `resume()`, `resign()`, `remove()`, and
 *       `expire()`. The G2c manifest allocated FOUR routes to
 *       memberships — the standard CRUD quartet. The six lifecycle
 *       methods are capabilities the service offers but the HTTP
 *       surface does not yet exercise.
 *
 *       This is the same deliberate gap the appointment controller
 *       (G2c file 12 of 31) documents for its three lifecycle
 *       methods, applied to a larger set. Each of the six
 *       transitions has its own authorization shape the manifest's
 *       route set does not accommodate:
 *
 *         · `end()` is the ordinary conclusion of a term — arguably
 *           equivalent to a field edit and possibly covered by the
 *           existing `update` ability.
 *
 *         · `suspend()` and `resume()` are paired operational
 *           transitions whose authority may belong to the chair
 *           only, not to every caller with `governance.body.manage`.
 *
 *         · `resign()` is a member's own act — a caller records
 *           their own resignation, which is a self-service operation
 *           the current single-permission model does not express.
 *
 *         · `remove()` is the most sensitive transition — an
 *           authority removing a member — and may warrant an audit
 *           entry that distinguishes it from a field edit.
 *
 *         · `expire()` is typically reached by a scheduled job when
 *           a membership's end date passes without an explicit
 *           transition; exposing it via HTTP is possible but the
 *           primary caller is not a human user.
 *
 *       Exposing the six transitions through the existing
 *       `PATCH /memberships/{id}` route — by having the controller
 *       inspect the payload for a `status` change — was considered
 *       and rejected, for the same reasons the appointment
 *       controller rejected the analogous consolidation: it would
 *       collapse seven semantically distinct operations into one
 *       HTTP action, require the update request to accept `status`
 *       (which the service deliberately rejects), and obscure the
 *       authorization boundary between "edit the notes" and
 *       "remove the member".
 *
 *       A future CR that exposes the six transitions adds six
 *       dedicated routes (e.g. `POST /memberships/{id}/end`,
 *       `POST /memberships/{id}/suspend`, …) and six matching
 *       controller methods. The service-layer methods the CR will
 *       call are already on disk.
 *
 *     · A `destroy()` action. The service has no `delete()` method;
 *       memberships end through their lifecycle methods, not
 *       through deletion. The policy's `delete` ability is
 *       declared for future use; no route in G2c calls it.
 *
 * =============================================================================
 * Route parameter names
 * =============================================================================
 * The four route parameter names this controller receives are:
 *
 *     {governanceBody}         →  $governanceBody (GovernanceBody)
 *     {governanceMembership}   →  $governanceMembership (GovernanceMembership)
 *
 * Both are camelCase forms of their model class names. Both must
 * match the parameter names declared in the corresponding
 * FormRequest's `route(...)` calls. In particular,
 * `UpdateGovernanceMembershipRequest` (G2c file 24 of 31) resolves
 * the current membership via
 * `$this->route('governanceMembership')`.
 *
 * A rename of either parameter in the routes file (G2c file 31 of
 * 31) MUST be mirrored here and in the corresponding request's
 * `route()` call.
 *
 * =============================================================================
 * The tenant cross-check — 404, not 403
 * =============================================================================
 * Same convention the sibling controllers use. See
 * `GovernanceAppointmentController` (G2c file 12 of 31) for the
 * full reasoning: a 403 would confirm to a cross-tenant caller that
 * a resource with that id exists in another tenant; a 404 tells the
 * same thing it would tell a caller asking for a nonexistent id.
 *
 * =============================================================================
 * Authorization layering — matching the sibling controllers
 * =============================================================================
 * The four actions split authorization responsibility as follows:
 *
 *     index    —  route middleware carries
 *                 `auth.permission:governance.body.view`.
 *
 *     store    —  route middleware carries
 *                 `auth.permission:governance.body.manage`.
 *
 *     show     —  the controller calls
 *                 `Gate::authorize('view', $governanceMembership)`.
 *
 *     update   —  the controller calls
 *                 `Gate::authorize('update', $governanceMembership)`.
 *
 * The policy (`GovernanceMembershipPolicy`, G2c file 21 of 31)
 * still enforces both layers for every action.
 *
 * =============================================================================
 * Eager-loading
 * =============================================================================
 * `index` eager-loads `position`, `term`, `appointment`, and
 * `user` — the four optional relations the resource exposes via
 * `whenLoaded()`. The `body` relation is not eager-loaded on
 * `index` because every row in the list shares the same body (the
 * one from the URL).
 *
 * `show` and `update` eager-load `body`, `position`, `term`,
 * `appointment`, and `user` via `loadMissing()` before constructing
 * the resource.
 *
 * `store` returns the freshly created membership without any
 * eager-loaded relations.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Controllers/GovernanceMembershipController.php
 *
 * After uploading, run:
 *
 *     composer dump-autoload
 *     php artisan optimize:clear
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Actions declared:          4 (index, store, show, update)
 * Lifecycle methods exposed: 0 (the six lifecycle methods —
 *                               end, suspend, resume, resign,
 *                               remove, expire — are deferred to a
 *                               future CR; see §"What the
 *                               controller deliberately does NOT
 *                               expose")
 * Services injected:         1 (GovernanceMembershipService)
 * Repositories injected:     1 (GovernanceMembershipRepository)
 * Gate::authorize calls:     2 (in show, in update)
 * ModelNotFoundException throws: 4 (one per action)
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use MaaleArna\MASES\Core\Domains\Governance\Http\Requests\StoreGovernanceMembershipRequest;
use MaaleArna\MASES\Core\Domains\Governance\Http\Requests\UpdateGovernanceMembershipRequest;
use MaaleArna\MASES\Core\Domains\Governance\Http\Resources\GovernanceMembershipResource;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;
use MaaleArna\MASES\Core\Domains\Governance\Repositories\GovernanceMembershipRepository;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceMembershipService;

/**
 * GovernanceMembershipController
 *
 * HTTP surface for governance membership operations.
 *
 * See the file-level change-verification block for the controller's
 * four actions, its tenant cross-check ordering (404 before 403),
 * its authorization layering, its response envelope, and its
 * deliberate non-exposure of the service's six lifecycle methods.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Controllers
 * @version 1.0.0
 */
final class GovernanceMembershipController extends Controller
{
    /**
     * Construct the controller with its dependencies.
     *
     * @param GovernanceMembershipService    $service
     * @param GovernanceMembershipRepository $repository
     */
    public function __construct(
        private readonly GovernanceMembershipService $service,
        private readonly GovernanceMembershipRepository $repository,
    ) {
    }

    // =========================================================================
    // index — paginated list of a body's memberships
    // =========================================================================

    /**
     * List the memberships of a body.
     *
     * Route:
     *     GET /api/governance/bodies/{governanceBody}/memberships
     *
     * Authorization:
     *     Route middleware `auth.permission:governance.body.view`.
     *
     * @param  Request        $request
     * @param  GovernanceBody $governanceBody
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     */
    public function index(Request $request, GovernanceBody $governanceBody): JsonResponse
    {
        $tenantId = $this->assertTenantOwnership(
            (int) $governanceBody->tenant_id,
            GovernanceBody::class,
            (int) $governanceBody->id,
        );

        $filters = array_filter(
            $request->only(['status', 'is_ex_officio', 'is_current', 'has_user', 'search']),
            static fn ($value): bool => $value !== null && $value !== '',
        );

        $perPage = (int) $request->input('per_page', 25);

        $paginator = $this->repository->paginateForBody(
            $tenantId,
            (int) $governanceBody->id,
            $filters,
            $perPage,
            ['position', 'term', 'appointment', 'user'],
        );

        return response()->json([
            'success' => true,
            'data'    => GovernanceMembershipResource::collection($paginator->items()),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
            ],
        ]);
    }

    // =========================================================================
    // store — register a new membership under a body
    // =========================================================================
    /**
     * Register a new membership.
     *
     * Route:
     *     POST /api/governance/bodies/{governanceBody}/memberships
     *
     * Authorization:
     *     Route middleware `auth.permission:governance.body.manage`.
     *
     * The `governance_body_id` is merged into the payload from the
     * URL, overwriting any client-supplied value. The route is
     * authoritative for the body.
     *
     * @param  StoreGovernanceMembershipRequest $request
     * @param  GovernanceBody                   $governanceBody
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function store(
        StoreGovernanceMembershipRequest $request,
        GovernanceBody $governanceBody,
    ): JsonResponse {
        $tenantId = $this->assertTenantOwnership(
            (int) $governanceBody->tenant_id,
            GovernanceBody::class,
            (int) $governanceBody->id,
        );

        $payload = $request->validated();

        // The route is authoritative for the body. See the file-level
        // change-verification block §"Route parameter names".
        $payload['governance_body_id'] = (int) $governanceBody->id;

        $membership = $this->service->register($payload, $tenantId);

        return response()->json([
            'success' => true,
            'data'    => new GovernanceMembershipResource($membership),
        ], 201);
    }

    // =========================================================================
    // show — read a specific membership
    // =========================================================================
    /**
     * Read a specific membership.
     *
     * Route:
     *     GET /api/governance/memberships/{governanceMembership}
     *
     * Authorization:
     *     `Gate::authorize('view', $governanceMembership)`.
     *
     * @param  GovernanceMembership $governanceMembership
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     * @throws AuthorizationException
     */
    public function show(GovernanceMembership $governanceMembership): JsonResponse
    {
        $this->assertTenantOwnership(
            (int) $governanceMembership->tenant_id,
            GovernanceMembership::class,
            (int) $governanceMembership->id,
        );

        Gate::authorize('view', $governanceMembership);

        $governanceMembership->loadMissing(['body', 'position', 'term', 'appointment', 'user']);

        return response()->json([
            'success' => true,
            'data'    => new GovernanceMembershipResource($governanceMembership),
        ]);
    }

    // =========================================================================
    // update — modify a specific membership
    // =========================================================================
    /**
     * Update a specific membership.
     *
     * Route:
     *     PATCH (or PUT) /api/governance/memberships/{governanceMembership}
     *
     * Authorization:
     *     `Gate::authorize('update', $governanceMembership)`.
     *
     * The controller does NOT merge `governance_body_id` into the
     * payload. The request class declares a shape rule for that FK
     * so the service's immutability check is reachable.
     *
     * The controller does NOT route to the service's six lifecycle
     * methods — see the file-level change-verification block
     * §"What the controller deliberately does NOT expose". A client
     * that supplies a `status` key will have it survive
     * `validated()` (the request declares a shape rule) and the
     * service will refuse it with a 422.
     *
     * @param  UpdateGovernanceMembershipRequest $request
     * @param  GovernanceMembership              $governanceMembership
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function update(
        UpdateGovernanceMembershipRequest $request,
        GovernanceMembership $governanceMembership,
    ): JsonResponse {
        $this->assertTenantOwnership(
            (int) $governanceMembership->tenant_id,
            GovernanceMembership::class,
            (int) $governanceMembership->id,
        );

        Gate::authorize('update', $governanceMembership);

        $membership = $this->service->update(
            $governanceMembership,
            $request->validated(),
        );

        $membership->loadMissing(['body', 'position', 'term', 'appointment', 'user']);

        return response()->json([
            'success' => true,
            'data'    => new GovernanceMembershipResource($membership),
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Verify that the model's tenant matches the request's tenant.
     *
     * Returns the resolved tenant id when the check passes. Throws a
     * `ModelNotFoundException` when the tenant cannot be resolved or
     * when the model's tenant differs.
     *
     * @param  int          $modelTenantId
     * @param  class-string $modelClass
     * @param  int|string   $modelId
     * @return int          The resolved tenant id.
     *
     * @throws ModelNotFoundException
     */
    private function assertTenantOwnership(
        int $modelTenantId,
        string $modelClass,
        int|string $modelId,
    ): int {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null || $modelTenantId !== $tenantId) {
            throw (new ModelNotFoundException())->setModel(
                $modelClass,
                [$modelId],
            );
        }

        return $tenantId;
    }

    /**
     * Resolve the tenant id from the server-side request context.
     *
     * Returns null when the helper is unavailable or no tenant is
     * resolved. The caller treats null as a mismatch.
     *
     * @return int|null
     */
    private function resolveTenantId(): ?int
    {
        if (!function_exists('mases_tenant_id')) {
            return null;
        }

        $tenantId = mases_tenant_id();

        if ($tenantId === null) {
            return null;
        }

        return (int) $tenantId;
    }
}