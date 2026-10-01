<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 21 of 27
 * =============================================================================
 *
 * File:            Http/Controllers/GovernanceTermController.php  (NEW)
 * Unit:            G2b — GovernanceTerm vertical slice (controller)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The four term routes that will be declared
 *   by G2b file 27 of 27 had no controller to dispatch to. Every
 *   request to those URLs would fail at route resolution with a
 *   class-not-found error, after the outer middleware stack had run.
 *
 * After:
 *   One file that declares the HTTP boundary for governance-term
 *   operations. Four actions, each sequencing four delegations and
 *   wrapping the result in the canonical envelope:
 *
 *     1. Validation     → StoreGovernanceTermRequest /
 *                         UpdateGovernanceTermRequest
 *     2. Authorization  → GovernanceTermPolicy (via Gate)
 *     3. Persistence    → GovernanceTermService (writes) /
 *                         GovernanceTermRepository (reads)
 *     4. Response shape → GovernanceTermResource
 *
 *   The controller is deliberately thin. It contains no business
 *   rules; every rule lives in the service, the policy, the request,
 *   or the repository.
 *
 *   What the controller does:
 *
 *     index()
 *         Lists terms of the body named in the URL, paginated.
 *         Accepts three optional query filters: `is_active`,
 *         `is_current`, `search`. Accepts `per_page`. Delegates
 *         authorization to `Gate::authorize('viewAny', $governanceBody)`
 *         — note the BODY, not the term, because the ability operates
 *         on a body's collection.
 *
 *     store()
 *         Registers a new term under the body named in the URL. The
 *         body's id is merged into the payload by the controller
 *         BEFORE the service is called, so a caller cannot create a
 *         term under a different body. Delegates authorization to
 *         `Gate::authorize('create', $governanceBody)`. Returns 201.
 *
 *     show()
 *         Returns a single term. Cross-checks the route-bound model
 *         against the resolved tenant BEFORE the Gate call, so a
 *         cross-tenant id yields 404 (not 403). Delegates
 *         authorization to `Gate::authorize('view', $governanceTerm)`.
 *
 *     update()
 *         Modifies an existing term. Same tenant-cross-check pattern
 *         as `show()`. Delegates authorization to
 *         `Gate::authorize('update', $governanceTerm)`. Returns 200.
 *
 *   What the controller deliberately does NOT do:
 *
 *     · It does NOT perform authorization via `$this->authorize()`
 *       (the Laravel trait). Every MASES controller uses
 *       `Gate::authorize()` directly.
 *
 *     · It does NOT resolve the tenant via a request parameter. The
 *       tenant id is read from `mases_tenant_id()`.
 *
 *     · It does NOT return error responses. Validation failures,
 *       authorization denials, and not-found conditions are raised
 *       as exceptions; the canonical error renderer converts each to
 *       the platform's error envelope.
 *
 *     · It does NOT write to any table directly. Every write is
 *       delegated to `GovernanceTermService` (G2b file 16 of 27).
 *
 *     · It does NOT perform the tenant cross-check after the Gate
 *       call. The order matters: the tenant boundary is checked
 *       BEFORE authorization so a cross-tenant id produces the same
 *       404 the caller would receive for a nonexistent id.
 *
 * =============================================================================
 * The two-layer tenant check — controller and policy
 * =============================================================================
 * For `show()` and `update()`, the controller calls
 * `assertTermBelongsToTenant($governanceTerm, $tenantId)` BEFORE
 * `Gate::authorize()`. The method throws `ModelNotFoundException`
 * (→ 404) when the term's `tenant_id` does not match the resolved
 * tenant.
 *
 * For `index()` and `store()`, the controller calls
 * `assertBodyBelongsToTenant($governanceBody, $tenantId)` BEFORE the
 * Gate. The check is on the body, not the term — the two abilities
 * operate on a body's collection, and the body is what must be in
 * the resolved tenant.
 *
 * The policy (G2b file 17 of 27) independently enforces the same
 * boundary inside its abilities, returning `false` when the tenant
 * does not match. That check is unreachable in normal flow — the
 * controller's assertion runs first — but it is a defence-in-depth
 * safety net for a future caller that reaches the policy without the
 * controller's guard.
 *
 * =============================================================================
 * The `governance_body_id` is set by the controller, not the request
 * =============================================================================
 * The `store()` action performs one extra step before calling the
 * service:
 *
 *     $data = $request->validated();
 *     $data['governance_body_id'] = $governanceBody->id;
 *
 * This overwrite ensures the term is created under the body named in
 * the URL, regardless of what the payload said. The
 * `StoreGovernanceTermRequest` (G2b file 19 of 27) does not declare
 * `governance_body_id` as a validated field — a caller who includes
 * it in the payload finds it replaced by the controller's
 * assignment. Same pattern as `GovernancePositionController::store()`
 * (G2b file 9 of 27).
 *
 * The `update()` action does NOT perform this overwrite. A term's
 * `governance_body_id` is immutable; the service (G2b file 16 of 27)
 * rejects any update that would change it.
 *
 * =============================================================================
 * Pagination and filters — `index()`
 * =============================================================================
 * The `index()` action accepts four query parameters, all optional:
 *
 *     ?is_active=true     — filter by is_active
 *     ?is_current=true    — filter by whether the term is in force
 *                           today (date range contains today)
 *     ?search=2026        — case-insensitive LIKE across label_am,
 *                           label_en
 *     ?per_page=50        — page size (clamped to [1, 200])
 *
 * The `is_current` filter is the term-specific addition. It applies
 * the model's `current()` scope (or its negation, for
 * `is_current=false`). A caller listing "terms that are currently in
 * force" sends `?is_current=true`.
 *
 * The response includes a `meta` block with the pagination data.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Controllers/GovernanceTermController.php
 *
 * The directory already exists.
 *
 * The controller is referenced by the routes file once G2b file 27 of
 * 27 lands. Until then, this controller is inert.
 *
 * A `composer dump-autoload` may be required if the class map is
 * cached.
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Actions declared:          4 (index, show, store, update)
 * Dependencies injected:     2 (GovernanceTermService,
 *                               GovernanceTermRepository)
 * Private helpers:           5 (resolveTenantId,
 *                               assertBodyBelongsToTenant,
 *                               assertTermBelongsToTenant,
 *                               extractFilters,
 *                               resolvePerPage)
 * Authorization calls:       4 (one per action, via Gate)
 * =============================================================================
 */

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