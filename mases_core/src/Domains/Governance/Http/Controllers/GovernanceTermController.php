<?php

declare(strict_types=1);



namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Http\Requests\StoreGovernanceTermRequest;
use MaaleArna\MASES\Core\Domains\Governance\Http\Requests\UpdateGovernanceTermRequest;
use MaaleArna\MASES\Core\Domains\Governance\Http\Resources\GovernanceTermResource;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;
use MaaleArna\MASES\Core\Domains\Governance\Repositories\GovernanceTermRepository;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceTermService;

/**
 * GovernanceTermController
 *
 * HTTP boundary for governance-term operations.
 *
 * See the file-level change-verification block for the controller's
 * scope, the two-layer tenant check, the `governance_body_id`
 * overwrite in `store()`, and the pagination and filter contract
 * (including the term-specific `is_current` filter).
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * Four operations, mapped by the routes file (G2b file 27 of 27):
 *
 *   GET    /api/governance/bodies/{governanceBody}/terms
 *              → index()
 *   POST   /api/governance/bodies/{governanceBody}/terms
 *              → store()
 *   GET    /api/governance/terms/{governanceTerm}
 *              → show()
 *   PATCH  /api/governance/terms/{governanceTerm}
 *              → update()
 *
 * =============================================================================
 * Thin by design (Master Prompt §62)
 * =============================================================================
 * This class contains no business rules:
 *
 *   - Validation       → StoreGovernanceTermRequest /
 *                        UpdateGovernanceTermRequest
 *   - Persistence      → GovernanceTermService
 *   - Reads / queries  → GovernanceTermRepository
 *   - Authorization    → GovernanceTermPolicy (via Gate)
 *   - Response shape   → GovernanceTermResource
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Controllers
 * @version 1.0.0
 */
final class GovernanceTermController extends Controller
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
     * term resource.
     *
     * `body` is eager-loaded so the resource's optional body summary
     * is present in the payload. The eager-load does not traverse the
     * body's own relations.
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
     * @param GovernanceTermRepository $repository
     * @param GovernanceTermService    $service
     */
    public function __construct(
        private readonly GovernanceTermRepository $repository,
        private readonly GovernanceTermService $service,
    ) {
    }

    // =========================================================================
    // index — list terms of a body
    // =========================================================================

    /**
     * List terms of the body named in the URL.
     *
     * The body is bound by Laravel's implicit model binding from the
     * `{governanceBody}` route parameter. The argument name
     * `$governanceBody` matches the parameter name exactly, so the
     * binding occurs.
     *
     * The body is cross-checked against the resolved tenant before
     * authorization. A body in a different tenant produces a 404.
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
            'data'    => GovernanceTermResource::collection($paginator->items())->resolve(),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    // =========================================================================
    // store — register a new term under a body
    // =========================================================================

    /**
     * Register a new term under the body named in the URL.
     *
     * The `StoreGovernanceTermRequest` has validated the input shape.
     * The controller overwrites the `governance_body_id` field with
     * the URL's body id, so a caller cannot create a term under a
     * different body by supplying one in the payload.
     *
     * @param  StoreGovernanceTermRequest $request
     * @param  GovernanceBody             $governanceBody  Bound by implicit route model binding.
     * @return JsonResponse  201 Created
     *
     * @throws ModelNotFoundException
     */
    public function store(
        StoreGovernanceTermRequest $request,
        GovernanceBody $governanceBody,
    ): JsonResponse {
        $tenantId = $this->resolveTenantId();

        $this->assertBodyBelongsToTenant($governanceBody, $tenantId);

        Gate::authorize('create', $governanceBody);

        // Merge the URL's body id into the payload.
        $data = $request->validated();
        $data['governance_body_id'] = (int) $governanceBody->id;

        $term = $this->service->register(
            data: $data,
            tenantId: $tenantId,
        );

        $term->loadMissing(self::EAGER_LOAD);

        return response()->json([
            'success' => true,
            'data'    => (new GovernanceTermResource($term))->resolve(),
        ], 201);
    }

    // =========================================================================
    // show — single term
    // =========================================================================

    /**
     * Show a single term.
     *
     * The term is bound by Laravel's implicit model binding from the
     * `{governanceTerm}` route parameter. The argument name
     * `$governanceTerm` matches the parameter name exactly.
     *
     * The term is cross-checked against the resolved tenant before
     * authorization. A cross-tenant term produces a 404.
     *
     * @param  Request        $request
     * @param  GovernanceTerm $governanceTerm  Bound by implicit route model binding.
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     */
    public function show(Request $request, GovernanceTerm $governanceTerm): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $this->assertTermBelongsToTenant($governanceTerm, $tenantId);

        Gate::authorize('view', $governanceTerm);

        $governanceTerm->loadMissing(self::EAGER_LOAD);

        return response()->json([
            'success' => true,
            'data'    => (new GovernanceTermResource($governanceTerm))->resolve(),
        ]);
    }

    // =========================================================================
    // update — modify an existing term
    // =========================================================================

    /**
     * Update an existing term.
     *
     * The `UpdateGovernanceTermRequest` has validated the input shape
     * against the post-update state. The service re-validates the
     * domain invariants.
     *
     * The `governance_body_id` is not merged here — a term's body is
     * immutable, and the service rejects any update that would change
     * it.
     *
     * @param  UpdateGovernanceTermRequest $request
     * @param  GovernanceTerm              $governanceTerm  Bound by implicit route model binding.
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     */
    public function update(
        UpdateGovernanceTermRequest $request,
        GovernanceTerm $governanceTerm,
    ): JsonResponse {
        $tenantId = $this->resolveTenantId();

        $this->assertTermBelongsToTenant($governanceTerm, $tenantId);

        Gate::authorize('update', $governanceTerm);

        $updated = $this->service->update(
            term: $governanceTerm,
            data: $request->validated(),
        );

        $updated->loadMissing(self::EAGER_LOAD);

        return response()->json([
            'success' => true,
            'data'    => (new GovernanceTermResource($updated))->resolve(),
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
     * Assert that a governance term belongs to the given tenant.
     *
     * @param  GovernanceTerm $term
     * @param  int            $tenantId
     * @return void
     *
     * @throws ModelNotFoundException
     */
    private function assertTermBelongsToTenant(GovernanceTerm $term, int $tenantId): void
    {
        if ((int) $term->tenant_id === $tenantId) {
            return;
        }

        throw (new ModelNotFoundException())->setModel(
            GovernanceTerm::class,
            [$term->getKey()],
        );
    }

    /**
     * Extract recognised filter keys from the request query string.
     *
     * Recognised keys: `is_active`, `is_current`, `search`. Unknown
     * keys are ignored. Empty strings are treated as absent.
     *
     * @param  Request              $request
     * @return array<string, mixed>
     */
    private function extractFilters(Request $request): array
    {
        $filters = [];

        foreach (['is_active', 'is_current'] as $booleanFilter) {
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