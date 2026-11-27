<?php

declare(strict_types=1);



namespace MaaleArna\Arna\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use MaaleArna\Arna\Core\Domains\Governance\Http\Requests\StoreGovernancePositionRequest;
use MaaleArna\Arna\Core\Domains\Governance\Http\Requests\UpdateGovernancePositionRequest;
use MaaleArna\Arna\Core\Domains\Governance\Http\Resources\GovernancePositionResource;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\Arna\Core\Domains\Governance\Repositories\GovernancePositionRepository;
use MaaleArna\Arna\Core\Domains\Governance\Services\GovernancePositionService;

/**
 * GovernancePositionController
 *
 * HTTP boundary for governance-position operations.
 *
 * See the file-level change-verification block for the controller's
 * scope, the two-layer tenant check, the `governance_body_id`
 * overwrite in `store()`, and the pagination and filter contract.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * Four operations, mapped by the routes file (G2b file 27 of 27):
 *
 *   GET    /api/governance/bodies/{governanceBody}/positions
 *              → index()
 *   POST   /api/governance/bodies/{governanceBody}/positions
 *              → store()
 *   GET    /api/governance/positions/{governancePosition}
 *              → show()
 *   PATCH  /api/governance/positions/{governancePosition}
 *              → update()
 *
 * The two `bodies/…/positions` routes carry the body in the URL;
 * the two `positions/…` routes carry the position. The split is
 * deliberate: a caller listing a body's positions knows which body
 * it is asking about; a caller reading a specific position knows
 * the position but may not care about the body's id.
 *
 * =============================================================================
 * Thin by design (Master Prompt §62)
 * =============================================================================
 * This class contains no business rules:
 *
 *   - Validation       → StoreGovernancePositionRequest /
 *                        UpdateGovernancePositionRequest
 *   - Persistence      → GovernancePositionService
 *   - Reads / queries  → GovernancePositionRepository
 *   - Authorization    → GovernancePositionPolicy (via Gate)
 *   - Response shape   → GovernancePositionResource
 *
 * =============================================================================
 * @package MaaleArna\Arna\Core\Domains\Governance\Http\Controllers
 * @version 1.0.0
 */
final class GovernancePositionController extends Controller
{
    /**
     * Default page size for the `index` action.
     */
    private const DEFAULT_PER_PAGE = 25;

    /**
     * Maximum page size the caller may request.
     */
    private const MAX_PER_PAGE = 200;

    /**
     * The eager-load list applied to every action that returns a
     * position resource.
     *
     * `body` is eager-loaded so the resource's optional body summary
     * is present in the payload and the derived `is_standing` field
     * resolves correctly. The eager-load does not traverse the
     * body's own relations (its parent, its reporting body, its own
     * positions); the summary is shallow by design.
     *
     * @var list<string>
     */
    private const EAGER_LOAD = [
        'body',
    ];

    /**
     * Constructor.
     *
     * Both dependencies are bound as singletons by the amended
     * `GovernanceServiceProvider` (G2b file 26 of 27).
     *
     * @param GovernancePositionRepository $repository
     * @param GovernancePositionService    $service
     */
    public function __construct(
        private readonly GovernancePositionRepository $repository,
        private readonly GovernancePositionService $service,
    ) {
    }

    // =========================================================================
    // index — list positions of a body
    // =========================================================================

    /**
     * List positions of the body named in the URL.
     *
     * The body is bound by Laravel's implicit model binding from the
     * `{governanceBody}` route parameter. The argument name
     * `$governanceBody` matches the parameter name exactly, so the
     * binding occurs.
     *
     * The body is cross-checked against the resolved tenant before
     * authorization. A body in a different tenant produces a 404.
     *
     * The response is paginated with a `meta` block carrying the
     * pagination data.
     *
     * @param  Request        $request
     * @param  GovernanceBody $governanceBody  Bound by implicit route model binding.
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     */
    public function index(Request $request, GovernanceBody $governanceBody): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $this->assertBodyBelongsToTenant($governanceBody, $tenantId);

        Gate::authorize('viewAny', $governanceBody);

        $filters = $this->extractFilters($request);
        $perPage = $this->resolvePerPage($request);

        $paginator = $this->repository->paginateForBody(
            tenantId: $tenantId,
            bodyId: (int) $governanceBody->id,
            filters: $filters,
            perPage: $perPage,
            with: self::EAGER_LOAD,
        );

        return response()->json([
            'success' => true,
            'data'    => GovernancePositionResource::collection($paginator->items())->resolve(),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    // =========================================================================
    // store — register a new position under a body
    // =========================================================================

    /**
     * Register a new position under the body named in the URL.
     *
     * The `StoreGovernancePositionRequest` has validated the input
     * shape. The controller overwrites the `governance_body_id`
     * field with the URL's body id, so a caller cannot create a
     * position under a different body by supplying one in the
     * payload.
     *
     * Authorization is performed via
     * `Gate::authorize('create', $governanceBody)` — the ability
     * takes the BODY, not the position.
     *
     * @param  StoreGovernancePositionRequest $request
     * @param  GovernanceBody                 $governanceBody  Bound by implicit route model binding.
     * @return JsonResponse  201 Created
     *
     * @throws ModelNotFoundException
     */
    public function store(
        StoreGovernancePositionRequest $request,
        GovernanceBody $governanceBody,
    ): JsonResponse {
        $tenantId = $this->resolveTenantId();

        $this->assertBodyBelongsToTenant($governanceBody, $tenantId);

        Gate::authorize('create', $governanceBody);

        // Merge the URL's body id into the payload. See the
        // file-level change-verification block
        // §"The governance_body_id is set by the controller".
        $data = $request->validated();
        $data['governance_body_id'] = (int) $governanceBody->id;

        $position = $this->service->register(
            data: $data,
            tenantId: $tenantId,
        );

        $position->loadMissing(self::EAGER_LOAD);

        return response()->json([
            'success' => true,
            'data'    => (new GovernancePositionResource($position))->resolve(),
        ], 201);
    }

    // =========================================================================
    // show — single position
    // =========================================================================

    /**
     * Show a single position.
     *
     * The position is bound by Laravel's implicit model binding from
     * the `{governancePosition}` route parameter. The argument name
     * `$governancePosition` matches the parameter name exactly, so
     * the binding occurs.
     *
     * The position is cross-checked against the resolved tenant
     * before authorization. A cross-tenant position produces a 404.
     *
     * @param  Request            $request
     * @param  GovernancePosition $governancePosition  Bound by implicit route model binding.
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     */
    public function show(Request $request, GovernancePosition $governancePosition): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $this->assertPositionBelongsToTenant($governancePosition, $tenantId);

        Gate::authorize('view', $governancePosition);

        $governancePosition->loadMissing(self::EAGER_LOAD);

        return response()->json([
            'success' => true,
            'data'    => (new GovernancePositionResource($governancePosition))->resolve(),
        ]);
    }

    // =========================================================================
    // update — modify an existing position
    // =========================================================================

    /**
     * Update an existing position.
     *
     * The `UpdateGovernancePositionRequest` has validated the input
     * shape against the post-update state. The service re-validates
     * the domain invariants.
     *
     * The `governance_body_id` is not merged here — a position's
     * body is immutable, and the service rejects any update that
     * would change it. If a caller includes a `governance_body_id`
     * matching the position's current body, the service drops the
     * field silently.
     *
     * @param  UpdateGovernancePositionRequest $request
     * @param  GovernancePosition              $governancePosition  Bound by implicit route model binding.
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     */
    public function update(
        UpdateGovernancePositionRequest $request,
        GovernancePosition $governancePosition,
    ): JsonResponse {
        $tenantId = $this->resolveTenantId();

        $this->assertPositionBelongsToTenant($governancePosition, $tenantId);

        Gate::authorize('update', $governancePosition);

        $updated = $this->service->update(
            position: $governancePosition,
            data: $request->validated(),
        );

        $updated->loadMissing(self::EAGER_LOAD);

        return response()->json([
            'success' => true,
            'data'    => (new GovernancePositionResource($updated))->resolve(),
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the tenant id from the server-side request context.
     *
     * @return int
     *
     * @throws ValidationException
     */
    private function resolveTenantId(): int
    {
        $tenantId = function_exists('arna_tenant_id') ? arna_tenant_id() : null;

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

    /**
     * Assert that a governance body belongs to the given tenant.
     *
     * @param  GovernanceBody $body
     * @param  int            $tenantId
     * @return void
     *
     * @throws ModelNotFoundException
     */
    private function assertBodyBelongsToTenant(GovernanceBody $body, int $tenantId): void
    {
        if ((int) $body->tenant_id === $tenantId) {
            return;
        }

        throw (new ModelNotFoundException())->setModel(
            GovernanceBody::class,
            [$body->getKey()],
        );
    }

    /**
     * Assert that a governance position belongs to the given tenant.
     *
     * @param  GovernancePosition $position
     * @param  int                $tenantId
     * @return void
     *
     * @throws ModelNotFoundException
     */
    private function assertPositionBelongsToTenant(GovernancePosition $position, int $tenantId): void
    {
        if ((int) $position->tenant_id === $tenantId) {
            return;
        }

        throw (new ModelNotFoundException())->setModel(
            GovernancePosition::class,
            [$position->getKey()],
        );
    }

    /**
     * Extract recognised filter keys from the request query string.
     *
     * Recognised keys: `is_active`, `is_voting`, `is_officer`,
     * `search`. Unknown keys are ignored. Empty strings are treated
     * as absent.
     *
     * @param  Request              $request
     * @return array<string, mixed>
     */
    private function extractFilters(Request $request): array
    {
        $filters = [];

        foreach (['is_active', 'is_voting', 'is_officer'] as $booleanFilter) {
            if (!$request->has($booleanFilter)) {
                continue;
            }

            $raw = $request->query($booleanFilter);

            if ($raw === null || $raw === '') {
                continue;
            }

            $bool = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($bool !== null) {
                $filters[$booleanFilter] = $bool;
            }
        }

        $search = $request->query('search');
        if (is_string($search)) {
            $search = trim($search);

            if ($search !== '') {
                $filters['search'] = $search;
            }
        }

        return $filters;
    }

    /**
     * Resolve the requested page size, clamped to the allowed range.
     *
     * @param  Request $request
     * @return int
     */
    private function resolvePerPage(Request $request): int
    {
        $requested = $request->query('per_page');

        if (!is_numeric($requested)) {
            return self::DEFAULT_PER_PAGE;
        }

        return max(1, min((int) $requested, self::MAX_PER_PAGE));
    }
}