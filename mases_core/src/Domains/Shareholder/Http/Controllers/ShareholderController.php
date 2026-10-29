<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Requests\StoreShareholderRequest;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Requests\UpdateShareholderRequest;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\ShareholderResource;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Shareholder\Repositories\ShareholderRepository;
use MaaleArna\MASES\Core\Domains\Shareholder\Services\ShareholderService;

/**
 * ShareholderController
 *
 * HTTP boundary for shareholder identity management.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * Four operations, mapped by the routes file (2A.36):
 *
 *   GET    /api/shareholders               → index()   list paginated
 *   GET    /api/shareholders/{shareholder} → show()    single shareholder
 *   POST   /api/shareholders               → store()   create
 *   PATCH  /api/shareholders/{shareholder} → update()  modify
 *
 * Nothing else. No deactivation endpoint (use `is_active` on update), no
 * soft-delete endpoint (out of scope for Phase 2A), no bulk import, no
 * search-only endpoint (search is a filter on index).
 *
 * =============================================================================
 * Thin by design (Master Prompt Section 62)
 * =============================================================================
 * This class contains no business rules:
 *
 *   - Validation       → StoreShareholderRequest / UpdateShareholderRequest
 *   - Persistence      → ShareholderService
 *   - Reads / queries  → ShareholderRepository
 *   - Authorization    → ShareholderPolicy (invoked via Gate)
 *   - Response shape   → ShareholderResource
 *
 * The controller's job is to sequence those four delegations and wrap the
 * result in the canonical envelope. Anything more would be a section 62
 * violation.
 *
 * =============================================================================
 * Tenant scoping (Master Prompt Section 36)
 * =============================================================================
 * The tenant is resolved server-side via the `mases_tenant_id()` helper —
 * the same helper used by `AuthorizationService` (Phase 1) and
 * `ShareClassService` (2A.13). The helper reads the tenant that the
 * tenant-resolution middleware (`TenantContextMiddleware`, Phase 1)
 * established earlier in the request pipeline. The controller does NOT
 * read a tenant identifier from:
 *
 *   - the request body (a client could forge it)
 *   - a query string parameter
 *   - a custom header other than the one the established resolver uses
 *
 * For `show()` and `update()`, the route-bound Shareholder is additionally
 * cross-checked against the resolved tenant. If a caller attempts to
 * access a shareholder that belongs to a different tenant, the controller
 * treats it as not found and returns 404 — the same response a caller
 * would receive for a non-existent id.
 *
 * This prevents a cross-tenant attacker from using the API to discover
 * whether a given shareholder id exists in another tenant.
 *
 * =============================================================================
 * Authorization (Master Prompt Section 37)
 * =============================================================================
 * Every action is preceded by a `Gate::authorize()` call:
 *
 *   index()   → Gate::authorize('viewAny', Shareholder::class)
 *   show()    → Gate::authorize('view',    $shareholder)
 *   store()   → Gate::authorize('create',  Shareholder::class)
 *   update()  → Gate::authorize('update',  $shareholder)
 *
 * The model → policy mapping is registered by ShareholderServiceProvider
 * (2A.2) via `Gate::policy(Shareholder::class, ShareholderPolicy::class)`.
 * The policy itself is delivered as 2A.29.
 *
 * Using the `Gate` facade directly (rather than the `$this->authorize()`
 * helper) is deliberate. Laravel 11+ removed the `AuthorizesRequests` trait
 * from the default base controller; the `Gate` facade works regardless of
 * whether the application's `Controller` class re-includes it.
 *
 * If the policy denies, the framework throws `Illuminate\Auth\Access\
 * AuthorizationException`, which the canonical renderer in
 * `bootstrap/app.php` maps to a 403 `AUTH_PERMISSION_DENIED` response.
 *
 * =============================================================================
 * Response envelope (Master Prompt Section 33)
 * =============================================================================
 * Every successful response is wrapped in the canonical envelope:
 *
 *     {
 *       "success": true,
 *       "data":    {...}         // single resource, or list of resources
 *       "meta":    {...}         // pagination metadata, only on index
 *     }
 *
 * Errors are not produced here. Validation failures, authorization
 * denials, and model-not-found exceptions are all raised as exceptions and
 * handled by the canonical renderer. This keeps the controller free of
 * error-handling code and guarantees that every error — regardless of
 * origin — has exactly the same shape.
 *
 * =============================================================================
 * Route model binding
 * =============================================================================
 * The `{shareholder}` route parameter is bound to a `Shareholder` model by
 * Laravel's implicit binding. If the id does not resolve to a row — either
 * because it does not exist or because the row is soft-deleted — Laravel
 * throws `ModelNotFoundException` BEFORE the controller method runs. The
 * canonical renderer maps it to `RESOURCE_NOT_FOUND` with a 404 status.
 *
 * The controller therefore does not need to handle "not found" for the
 * route-bound model; it only needs to handle "found but wrong tenant",
 * which it does with an explicit tenant cross-check.
 *
 * =============================================================================
 * Why no destroy() / deactivate() / reactivate()
 * =============================================================================
 * Deactivation is expressed through `is_active` on an update. Soft-deletion
 * is not part of the Phase 2A workflow (F-2A12-6). A future phase may add
 * dedicated endpoints for those operations, at which point a CR will extend
 * this controller. Until then, fewer endpoints means fewer surfaces to
 * secure and fewer tests to maintain.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Http\Controllers
 */
final class ShareholderController extends Controller
{
    /**
     * Default page size for the index action.
     */
    private const DEFAULT_PER_PAGE = 25;

    /**
     * Maximum page size the caller may request.
     *
     * Matches the cap enforced by ShareholderRepository (2A.11); a caller
     * asking for more is silently clamped.
     */
    private const MAX_PER_PAGE = 200;

    /**
     * Constructor.
     *
     * Both dependencies are bound as singletons by ShareholderServiceProvider
     * (2A.2), so resolving a controller within a request gives the same
     * instance that any other consumer in the same request would receive.
     *
     * @param ShareholderRepository $repository
     * @param ShareholderService    $service
     */
    public function __construct(
        private readonly ShareholderRepository $repository,
        private readonly ShareholderService $service,
    ) {
    }

    // =========================================================================
    // index — list paginated
    // =========================================================================

    /**
     * List shareholders belonging to the resolved tenant.
     *
     * Supports three optional filters (all ignored if absent):
     *
     *   ?type=individual|company|...   — restrict to a ShareholderType
     *   ?is_active=true|false           — restrict to active / inactive
     *   ?search=<term>                  — name search across all bilingual
     *                                     name columns (Amharic and English)
     *
     * And one pagination parameter:
     *
     *   ?per_page=<int>                 — page size, clamped to MAX_PER_PAGE
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Shareholder::class);

        $tenantId = $this->resolveTenantId();
        $filters  = $this->extractFilters($request);
        $perPage  = $this->resolvePerPage($request);

        $paginator = $this->repository->paginateForTenant(
            tenantId: $tenantId,
            filters: $filters,
            perPage: $perPage,
            with: ['user', 'representative'],
        );

        // Audit the successful sensitive register read, not query values.
        app(AuditService::class)->log(
            action: 'shareholder.read.list',
            data: ['result_count' => count($paginator->items())],
            user: $request->user(),
            tenantId: $tenantId,
            resourceType: 'shareholder_register',
        );

        return response()->json([
            'success' => true,
            'data'    => ShareholderResource::collection($paginator->items())->resolve(),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    // =========================================================================
    // show — single shareholder
    // =========================================================================

    /**
     * Show a single shareholder.
     *
     * The route-bound model is cross-checked against the resolved tenant.
     * A shareholder that belongs to a different tenant is reported as not
     * found (404), not as forbidden (403) — the API does not disclose that
     * the id exists elsewhere.
     *
     * @param Request     $request
     * @param Shareholder $shareholder  Bound by implicit route model binding.
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     */
    public function show(Request $request, Shareholder $shareholder): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $this->assertBelongsToTenant($shareholder, $tenantId);

        Gate::authorize('view', $shareholder);

        $shareholder->loadMissing(['user', 'representative']);

        app(AuditService::class)->log(
            action: 'shareholder.read.view',
            data: ['access_scope' => 'policy-authorized'],
            user: $request->user(),
            tenantId: $tenantId,
            resourceType: Shareholder::class,
            resourceId: (string) $shareholder->getKey(),
        );

        return response()->json([
            'success' => true,
            'data'    => (new ShareholderResource($shareholder))->resolve(),
        ]);
    }

    // =========================================================================
    // store — create
    // =========================================================================

    /**
     * Create a new shareholder.
     *
     * The StoreShareholderRequest (2A.16) has already validated the input
     * shape and its type-conditional invariants. The service (2A.12) will
     * re-validate the domain invariants (representative requirement,
     * user-link uniqueness within the tenant) before persisting.
     *
     * The tenant id is NOT taken from the request — it is resolved
     * server-side. A caller cannot create a shareholder in another tenant.
     *
     * @param StoreShareholderRequest $request
     * @return JsonResponse  201 Created
     */
    public function store(StoreShareholderRequest $request): JsonResponse
    {
        Gate::authorize('create', Shareholder::class);

        $tenantId = $this->resolveTenantId();

        $shareholder = $this->service->register(
            data: $request->validated(),
            tenantId: $tenantId,
        );

        $shareholder->loadMissing(['user', 'representative']);

        return response()->json([
            'success' => true,
            'data'    => (new ShareholderResource($shareholder))->resolve(),
        ], 201);
    }

    // =========================================================================
    // update — modify
    // =========================================================================

    /**
     * Update an existing shareholder.
     *
     * The UpdateShareholderRequest (2A.17) has validated the input shape
     * against the CURRENT type of the shareholder (type is immutable).
     *
     * As with show(), the route-bound model is cross-checked against the
     * resolved tenant before authorization is attempted.
     *
     * @param UpdateShareholderRequest $request
     * @param Shareholder              $shareholder  Bound by implicit route model binding.
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     */
    public function update(UpdateShareholderRequest $request, Shareholder $shareholder): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $this->assertBelongsToTenant($shareholder, $tenantId);

        Gate::authorize('update', $shareholder);

        $updated = $this->service->update(
            shareholder: $shareholder,
            data: $request->validated(),
        );

        $updated->loadMissing(['user', 'representative']);

        return response()->json([
            'success' => true,
            'data'    => (new ShareholderResource($updated))->resolve(),
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the tenant id from the server-side request context.
     *
     * The value comes from `mases_tenant_id()`, which reads the tenant that
     * was established by the tenant-resolution middleware. It is NEVER
     * read from the request body, query string, or an arbitrary header.
     *
     * A missing tenant is a deployment or routing defect, not a caller
     * error, but the correct response at the HTTP boundary is a 422 — the
     * request cannot be processed as-is. The renderer maps
     * ValidationException to the canonical error envelope.
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
     * Assert that a shareholder belongs to the given tenant.
     *
     * Throws ModelNotFoundException on mismatch, which the canonical
     * renderer maps to a 404 RESOURCE_NOT_FOUND response. This prevents a
     * caller from learning whether a shareholder id exists in another
     * tenant.
     *
     * The check is deliberately performed BEFORE the authorization check.
     * A policy that authorizes a caller to view a shareholder in tenant A
     * must not accidentally authorize a view of a shareholder in tenant B
     * — the tenant boundary is not the policy's responsibility, it is the
     * controller's.
     *
     * @param Shareholder $shareholder
     * @param int         $tenantId
     * @return void
     *
     * @throws ModelNotFoundException
     */
    private function assertBelongsToTenant(Shareholder $shareholder, int $tenantId): void
    {
        if ((int) $shareholder->tenant_id === $tenantId) {
            return;
        }

        throw (new ModelNotFoundException())->setModel(
            Shareholder::class,
            [$shareholder->getKey()],
        );
    }

    /**
     * Extract recognised filter keys from the request query string.
     *
     * Unknown keys are ignored. Empty strings are treated as absent. The
     * values are passed through to ShareholderRepository (2A.11), which
     * understands `type`, `is_active`, and `search`.
     *
     * @param Request $request
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
     * @param Request $request
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