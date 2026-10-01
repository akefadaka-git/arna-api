<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 9 of 27
 * =============================================================================
 *
 * File:            Http/Controllers/GovernancePositionController.php  (NEW)
 * Unit:            G2b — GovernancePosition vertical slice (controller)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The four routes that will be declared for
 *   positions (G2b file 27 of 27) had no controller to dispatch to.
 *   Every request to those URLs would fail at route resolution with
 *   a class-not-found error, after the outer middleware stack had
 *   run.
 *
 * After:
 *   One file that declares the HTTP boundary for governance-position
 *   operations. Four actions, each sequencing four delegations and
 *   wrapping the result in the canonical envelope:
 *
 *     1. Validation     → StoreGovernancePositionRequest /
 *                         UpdateGovernancePositionRequest
 *     2. Authorization  → GovernancePositionPolicy (via Gate)
 *     3. Persistence    → GovernancePositionService (writes) /
 *                         GovernancePositionRepository (reads)
 *     4. Response shape → GovernancePositionResource
 *
 *   The controller is deliberately thin. It contains no business
 *   rules; every rule lives in the service, the policy, the request,
 *   or the repository.
 *
 *   What the controller does:
 *
 *     index()
 *         Lists positions of the body named in the URL, paginated.
 *         Accepts four optional query filters: `is_active`,
 *         `is_voting`, `is_officer`, `search`. Accepts `per_page`.
 *         Delegates authorization to
 *         `Gate::authorize('viewAny', $governanceBody)` — note the
 *         BODY, not the position, because the ability operates on
 *         a body's collection.
 *
 *     store()
 *         Registers a new position under the body named in the URL.
 *         The body's id is merged into the payload by the
 *         controller BEFORE the service is called, so a caller
 *         cannot create a position under a different body by
 *         including `governance_body_id` in the request body.
 *         Delegates authorization to
 *         `Gate::authorize('create', $governanceBody)`. Returns 201.
 *
 *     show()
 *         Returns a single position. Cross-checks the route-bound
 *         model against the resolved tenant BEFORE the Gate call,
 *         so a cross-tenant id yields 404 (not 403). Delegates
 *         authorization to
 *         `Gate::authorize('view', $governancePosition)`.
 *
 *     update()
 *         Modifies an existing position. Same tenant-cross-check
 *         pattern as `show()`. Delegates authorization to
 *         `Gate::authorize('update', $governancePosition)`.
 *         Returns 200.
 *
 *   What the controller deliberately does NOT do:
 *
 *     · It does NOT perform authorization via `$this->authorize()`
 *       (the Laravel trait). Laravel 11+ removed the
 *       `AuthorizesRequests` trait from the default base
 *       controller; the `Gate` facade works regardless. Every MASES
 *       controller uses `Gate::authorize()` directly.
 *
 *     · It does NOT resolve the tenant via a request parameter. The
 *       tenant id is read from `mases_tenant_id()`.
 *
 *     · It does NOT return error responses. Validation failures,
 *       authorization denials, and not-found conditions are raised
 *       as exceptions; the canonical error renderer converts each
 *       to the platform's error envelope.
 *
 *     · It does NOT write to any table directly. Every write is
 *       delegated to `GovernancePositionService` (G2b file 4 of 27).
 *
 *     · It does NOT perform the tenant cross-check after the Gate
 *       call. The order matters: the tenant boundary is checked
 *       BEFORE authorization so a cross-tenant id produces the
 *       same 404 the caller would receive for a nonexistent id.
 *
 * =============================================================================
 * The two-layer tenant check — controller and policy
 * =============================================================================
 * For `show()` and `update()`, the controller calls
 * `assertPositionBelongsToTenant($governancePosition, $tenantId)`
 * BEFORE `Gate::authorize()`. The method throws
 * `ModelNotFoundException` (→ 404) when the position's `tenant_id`
 * does not match the resolved tenant.
 *
 * For `index()` and `store()`, the controller calls
 * `assertBodyBelongsToTenant($governanceBody, $tenantId)` BEFORE
 * the Gate. The check is on the body, not the position — the two
 * abilities operate on a body's collection, and the body is what
 * must be in the resolved tenant.
 *
 * The policy (G2b file 5 of 27) independently enforces the same
 * boundary inside its abilities, returning `false` when the tenant
 * does not match. That check is unreachable in normal flow — the
 * controller's assertion runs first — but it is a defence-in-depth
 * safety net for a future caller that reaches the policy without
 * the controller's guard.
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
 * This overwrite ensures the position is created under the body
 * named in the URL, regardless of what the payload said. The
 * `StoreGovernancePositionRequest` (G2b file 7 of 27) does not
 * declare `governance_body_id` as a validated field — a caller
 * who includes it in the payload finds it replaced by the
 * controller's assignment. The behaviour matches the platform's
 * convention for nested-resource creation.
 *
 * The `update()` action does NOT perform this overwrite. A
 * position's `governance_body_id` is immutable; the service
 * (G2b file 4 of 27) rejects any update that would change it. If
 * a caller includes a `governance_body_id` matching the position's
 * current body, the service drops the field silently and the
 * update proceeds. If the caller includes a different value, the
 * service throws a `ValidationException` with a
 * `governance_body_id` field error.
 *
 * =============================================================================
 * Pagination and filters — `index()`
 * =============================================================================
 * The `index()` action accepts five query parameters, all optional:
 *
 *     ?is_active=true     — filter by is_active
 *     ?is_voting=true     — filter by is_voting
 *     ?is_officer=true    — filter by is_officer
 *     ?search=chair       — case-insensitive LIKE across code,
 *                           name_am, name_en
 *     ?per_page=50        — page size (clamped to [1, 200])
 *
 * Unknown query parameters are ignored. Empty-string or null values
 * for a filter key are treated as "filter not present".
 *
 * The response includes a `meta` block with the pagination data.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Controllers/GovernancePositionController.php
 *
 * The directory already exists (created for the G2a controllers).
 *
 * The controller is referenced by the routes file once G2b file 27
 * of 27 lands. Until then, this controller is inert.
 *
 * A `composer dump-autoload` may be required if the class map is
 * cached.
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Actions declared:          4 (index, show, store, update)
 * Dependencies injected:     2 (GovernancePositionService,
 *                               GovernancePositionRepository)
 * Private helpers:           4 (resolveTenantId,
 *                               assertBodyBelongsToTenant,
 *                               assertPositionBelongsToTenant,
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
use MaaleArna\MASES\Core\Domains\Governance\Http\Requests\StoreGovernancePositionRequest;
use MaaleArna\MASES\Core\Domains\Governance\Http\Requests\UpdateGovernancePositionRequest;
use MaaleArna\MASES\Core\Domains\Governance\Http\Resources\GovernancePositionResource;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Domains\Governance\Repositories\GovernancePositionRepository;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernancePositionService;

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
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Controllers
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