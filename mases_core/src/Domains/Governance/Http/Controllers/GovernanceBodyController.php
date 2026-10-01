<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2a · File 14 of 18 (revised)
 * =============================================================================
 *
 * File:            Http/Controllers/GovernanceBodyController.php  (AMEND)
 * Unit:            G2a — GovernanceBody vertical slice (controller)
 * Delivery type:   Amendment — corrects parameter names for route-model
 *                  binding.
 *
 * Before (as G2a file 14 of 17 originally delivered it):
 *   The controller's `show()` and `update()` methods declared their
 *   route-bound model argument as `GovernanceBody $body`. The routes
 *   file (G2a file 16 of 18) declares the dynamic path segment as
 *   `{governanceBody}`. The two names did not match.
 *
 *   Laravel's implicit model binding requires the controller
 *   argument name to match the route parameter name. Because they
 *   did not match, no binding was performed. The framework instead
 *   resolved the type-hinted argument from the container, producing
 *   a fresh empty `GovernanceBody` instance with `id = null` and
 *   `tenant_id = null`.
 *
 *   The controller then called
 *   `assertBelongsToTenant($body, $tenantId)`, which found
 *   `$body->tenant_id === null !== $tenantId` and threw
 *   `ModelNotFoundException`. The canonical error renderer maps that
 *   exception to a 404 `RESOURCE_NOT_FOUND` response.
 *
 *   Consequence: every request to `GET /bodies/{id}` and
 *   `PATCH /bodies/{id}` returned 404, regardless of whether the id
 *   existed and regardless of the caller's permissions. Four tests
 *   in `GovernanceBodyTest` failed for this reason:
 *
 *     · test_authorized_user_can_show_body
 *     · test_authorized_user_can_update_body
 *     · test_body_type_is_immutable_after_registration
 *     · test_update_writes_audit_row
 *
 *   The thirteen other tests passed because they exercise `index`
 *   and `store`, which have no dynamic path segment.
 *
 * After:
 *   The two method signatures declare their parameter as
 *   `GovernanceBody $governanceBody`, and every internal reference
 *   to `$body` within those two methods is renamed accordingly.
 *   The parameter name now matches the route parameter name exactly,
 *   so Laravel's implicit binding resolves the model from the path
 *   segment.
 *
 *   Consequences:
 *
 *     · `GET /api/governance/bodies/{id}` returns the resolved model
 *       to `show()`, which proceeds through tenant check, policy,
 *       eager-load, and resource serialization. A caller with the
 *       right permissions receives 200 with the body.
 *
 *     · `PATCH /api/governance/bodies/{id}` returns the resolved
 *       model to `update()`. The `UpdateGovernanceBodyRequest` (G2a
 *       file 13 of 18) reads the same route parameter via
 *       `$this->route('governanceBody')`, which now returns the
 *       bound model rather than the raw id string. The request's
 *       post-update validation checks therefore see the body's
 *       current state and can correctly reject a `body_type`
 *       change, an invalid effective-date range after merge, and a
 *       name-clearing update.
 *
 * =============================================================================
 * Why the parameter name was chosen to be `governanceBody`, not
 * `body`
 * =============================================================================
 * The route parameter is `{governanceBody}` (camelCase of the model
 * class name, matching the platform's convention — see the
 * Shareholder domain's `{shareholder}`, `{shareHolding}`,
 * `{certificate}`, `{shareClass}`). The controller parameter must
 * match. Renaming the route parameter to `{body}` would have been
 * the alternative fix, but it would have introduced a parameter
 * name that collides conceptually with "the HTTP request body" and
 * departed from the platform's naming discipline.
 *
 * The alternative fix (renaming the route parameter) was rejected
 * for that reason.
 *
 * =============================================================================
 * What does NOT change
 * =============================================================================
 *   · Every import statement.
 *
 *   · The class declaration, the `final` modifier, the extension of
 *     `Controller`.
 *
 *   · The `DEFAULT_PER_PAGE` and `MAX_PER_PAGE` constants.
 *
 *   · The `INDEX_EAGER_LOAD` constant.
 *
 *   · The constructor and its two promoted properties
 *     (`$repository`, `$service`).
 *
 *   · The `index()` method — every line.
 *
 *   · The `store()` method — every line.
 *
 *   · The private `resolveTenantId()`, `assertBelongsToTenant()`,
 *     `extractFilters()`, and `resolvePerPage()` helpers — every
 *     line.
 *
 *   · The class docblock, including the "two-layer tenant check",
 *     "pagination and filters", and "route model binding"
 *     sections. Those sections remain accurate.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Controllers/GovernanceBodyController.php
 *
 * This file REPLACES the original G2a file 14. No configuration
 * change is required — the class name, namespace, and location are
 * unchanged; only two method signatures and their internal variable
 * references differ.
 *
 * After uploading, run the autoloader refresh:
 *
 *     composer dump-autoload
 *
 * (Via Plesk's Composer UI, or the PHP Command runner equivalent.)
 *
 * No `php artisan optimize:clear` is required — the provider
 * manifest and config cache are unchanged. Route registration is
 * unchanged.
 *
 * Then re-run the Governance test:
 *
 *     php artisan test --filter=GovernanceBodyTest
 *
 * Expected: 17 tests, all passing (some with the framework-level
 * PHP deprecation warning, which is not our concern).
 *
 * =============================================================================
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Method signatures changed: 2 (show, update)
 * Internal references renamed: ~14 (across the two methods)
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Http\Requests\StoreGovernanceBodyRequest;
use MaaleArna\MASES\Core\Domains\Governance\Http\Requests\UpdateGovernanceBodyRequest;
use MaaleArna\MASES\Core\Domains\Governance\Http\Resources\GovernanceBodyResource;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Repositories\GovernanceBodyRepository;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceBodyService;

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
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Controllers
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