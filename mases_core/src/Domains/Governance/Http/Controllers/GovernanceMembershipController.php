<?php

declare(strict_types=1);



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

        Gate::authorize('viewAny', $governanceBody);

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

        Gate::authorize('create', $governanceBody);

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