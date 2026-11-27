<?php

declare(strict_types=1);



namespace MaaleArna\Arna\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use MaaleArna\Arna\Core\Domains\Governance\Http\Requests\StoreGovernanceBodyRequest;
use MaaleArna\Arna\Core\Domains\Governance\Http\Requests\UpdateGovernanceBodyRequest;
use MaaleArna\Arna\Core\Domains\Governance\Http\Resources\GovernanceBodyResource;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\Arna\Core\Domains\Governance\Repositories\GovernanceBodyRepository;
use MaaleArna\Arna\Core\Domains\Governance\Services\GovernanceBodyService;

/**
 * GovernanceBodyController
 *
 * HTTP boundary for governance-body operations.
 *
 * See the file-level change-verification block for the controller's
 * scope, the two-layer tenant check, the pagination and filter
 * contract, the route-model-binding convention, and the revision
 * that corrected the controller parameter names.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * Four operations, mapped by the routes file (G2a file 16 of 18):
 *
 *   GET    /api/governance/bodies                     → index()
 *   GET    /api/governance/bodies/{governanceBody}    → show()
 *   POST   /api/governance/bodies                     → store()
 *   PATCH  /api/governance/bodies/{governanceBody}    → update()
 *
 * The dynamic path segment `{governanceBody}` binds to the
 * `GovernanceBody` model via Laravel's implicit model binding. The
 * controller method signature MUST declare its argument with the
 * same name — `GovernanceBody $governanceBody` — for the binding to
 * occur. This is the platform's convention (see the Shareholder
 * controller's `{shareholder}` → `$shareholder` pairing).
 *
 * =============================================================================
 * Thin by design (Master Prompt §62)
 * =============================================================================
 * This class contains no business rules:
 *
 *   - Validation       → StoreGovernanceBodyRequest /
 *                        UpdateGovernanceBodyRequest
 *   - Persistence      → GovernanceBodyService
 *   - Reads / queries  → GovernanceBodyRepository
 *   - Authorization    → GovernanceBodyPolicy (via Gate)
 *   - Response shape   → GovernanceBodyResource
 *
 * The controller's job is to sequence those delegations and wrap
 * the result in the canonical envelope.
 *
 * =============================================================================
 * @package MaaleArna\Arna\Core\Domains\Governance\Http\Controllers
 * @version 1.0.1
 */
final class GovernanceBodyController extends Controller
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
     * The eager-load list applied to the `index`, `show`, `store`,
     * and `update` actions.
     *
     * @var list<string>
     */
    private const INDEX_EAGER_LOAD = [
        'parent',
        'reportingBody',
    ];

    /**
     * Constructor.
     *
     * @param GovernanceBodyRepository $repository
     * @param GovernanceBodyService    $service
     */
    public function __construct(
        private readonly GovernanceBodyRepository $repository,
        private readonly GovernanceBodyService $service,
    ) {
    }

    // =========================================================================
    // index — list governance bodies
    // =========================================================================

    /**
     * List governance bodies belonging to the resolved tenant.
     *
     * Supports four optional query filters and one pagination
     * parameter. All are read by the private
     * `extractFilters()` / `resolvePerPage()` helpers; the
     * controller passes the resulting arrays to the repository.
     *
     * @param  Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', GovernanceBody::class);

        $tenantId = $this->resolveTenantId();
        $filters  = $this->extractFilters($request);
        $perPage  = $this->resolvePerPage($request);

        $paginator = $this->repository->paginateForTenant(
            tenantId: $tenantId,
            filters: $filters,
            perPage: $perPage,
            with: self::INDEX_EAGER_LOAD,
        );

        return response()->json([
            'success' => true,
            'data'    => GovernanceBodyResource::collection($paginator->items())->resolve(),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    // =========================================================================
    // show — single governance body
    // =========================================================================

    /**
     * Show a single governance body.
     *
     * The route-bound model is resolved by Laravel's implicit model
     * binding. The parameter name `$governanceBody` matches the route
     * parameter `{governanceBody}`; a mismatch would prevent the
     * binding and the controller would receive an empty model
     * instance.
     *
     * The resolved model is cross-checked against the resolved tenant
     * BEFORE authorization. A body that belongs to a different tenant
     * is reported as not found (404), not as forbidden (403) — the
     * API does not disclose that the id exists elsewhere.
     *
     * @param  Request        $request
     * @param  GovernanceBody $governanceBody  Bound by implicit route model binding.
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     */
    public function show(Request $request, GovernanceBody $governanceBody): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $this->assertBelongsToTenant($governanceBody, $tenantId);

        Gate::authorize('view', $governanceBody);

        $governanceBody->loadMissing(self::INDEX_EAGER_LOAD);

        return response()->json([
            'success' => true,
            'data'    => (new GovernanceBodyResource($governanceBody))->resolve(),
        ]);
    }

    // =========================================================================
    // store — register a new governance body
    // =========================================================================

    /**
     * Register a new governance body.
     *
     * The `StoreGovernanceBodyRequest` has already validated the input
     * shape. The service re-validates the domain invariants before
     * persisting.
     *
     * @param  StoreGovernanceBodyRequest $request
     * @return JsonResponse  201 Created
     */
    public function store(StoreGovernanceBodyRequest $request): JsonResponse
    {
        Gate::authorize('create', GovernanceBody::class);

        $tenantId = $this->resolveTenantId();

        $body = $this->service->register(
            data: $request->validated(),
            tenantId: $tenantId,
        );

        $body->loadMissing(self::INDEX_EAGER_LOAD);

        return response()->json([
            'success' => true,
            'data'    => (new GovernanceBodyResource($body))->resolve(),
        ], 201);
    }

    // =========================================================================
    // update — modify an existing governance body
    // =========================================================================

    /**
     * Update an existing governance body.
     *
     * The route-bound model is resolved by Laravel's implicit model
     * binding. The parameter name `$governanceBody` matches the route
     * parameter `{governanceBody}`.
     *
     * The `UpdateGovernanceBodyRequest` has already validated the
     * input shape against the post-update state. The service
     * re-validates the domain invariants before persisting.
     *
     * As with `show()`, the resolved model is cross-checked against
     * the resolved tenant BEFORE authorization.
     *
     * @param  UpdateGovernanceBodyRequest $request
     * @param  GovernanceBody              $governanceBody  Bound by implicit route model binding.
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     */
    public function update(UpdateGovernanceBodyRequest $request, GovernanceBody $governanceBody): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $this->assertBelongsToTenant($governanceBody, $tenantId);

        Gate::authorize('update', $governanceBody);

        $updated = $this->service->update(
            body: $governanceBody,
            data: $request->validated(),
        );

        $updated->loadMissing(self::INDEX_EAGER_LOAD);

        return response()->json([
            'success' => true,
            'data'    => (new GovernanceBodyResource($updated))->resolve(),
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
    private function assertBelongsToTenant(GovernanceBody $body, int $tenantId): void
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
     * Extract recognised filter keys from the request query string.
     *
     * @param  Request              $request
     * @return array<string, mixed>
     */
    private function extractFilters(Request $request): array
    {
        $filters = [];

        $type = $request->query('type');
        if (is_string($type) && $type !== '') {
            $filters['type'] = $type;
        }

        if ($request->has('is_active')) {
            $raw = $request->query('is_active');

            if ($raw !== null && $raw !== '') {
                $bool = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                if ($bool !== null) {
                    $filters['is_active'] = $bool;
                }
            }
        }

        $parentId = $request->query('parent_body_id');
        if (is_numeric($parentId)) {
            $filters['parent_body_id'] = (int) $parentId;
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