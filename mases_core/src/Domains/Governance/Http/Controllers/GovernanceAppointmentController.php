<?php

declare(strict_types=1);



namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use MaaleArna\MASES\Core\Domains\Governance\Http\Requests\StoreGovernanceAppointmentRequest;
use MaaleArna\MASES\Core\Domains\Governance\Http\Requests\UpdateGovernanceAppointmentRequest;
use MaaleArna\MASES\Core\Domains\Governance\Http\Resources\GovernanceAppointmentResource;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceAppointment;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Repositories\GovernanceAppointmentRepository;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceAppointmentService;

/**
 * GovernanceAppointmentController
 *
 * HTTP surface for governance appointment operations.
 *
 * See the file-level change-verification block for the controller's
 * four actions, its tenant cross-check ordering (404 before 403),
 * its authorization layering, its response envelope, and its
 * deliberate non-exposure of the service's three lifecycle methods.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Controllers
 * @version 1.0.0
 */
final class GovernanceAppointmentController extends Controller
{
    /**
     * Construct the controller with its dependencies.
     *
     * @param GovernanceAppointmentService    $service
     * @param GovernanceAppointmentRepository $repository
     */
    public function __construct(
        private readonly GovernanceAppointmentService $service,
        private readonly GovernanceAppointmentRepository $repository,
    ) {
    }

    // =========================================================================
    // index — paginated list of a body's appointments
    // =========================================================================

    /**
     * List the appointments of a body.
     *
     * Route:
     *     GET /api/governance/bodies/{governanceBody}/appointments
     *
     * Authorization:
     *     Route middleware `auth.permission:governance.body.view`.
     *     The controller performs only the tenant cross-check.
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
            $request->only(['status', 'appointment_mode', 'instrument_type', 'search']),
            static fn ($value): bool => $value !== null && $value !== '',
        );

        $perPage = (int) $request->input('per_page', 25);

        $paginator = $this->repository->paginateForBody(
            $tenantId,
            (int) $governanceBody->id,
            $filters,
            $perPage,
            ['position', 'term'],
        );

        return response()->json([
            'success' => true,
            'data'    => GovernanceAppointmentResource::collection($paginator->items()),
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
    // store — register a new appointment under a body
    // =========================================================================

    /**
     * Register a new appointment.
     *
     * Route:
     *     POST /api/governance/bodies/{governanceBody}/appointments
     *
     * Authorization:
     *     Route middleware `auth.permission:governance.body.manage`.
     *     The controller performs only the tenant cross-check.
     *
     * The `governance_body_id` is merged into the payload from the
     * URL, overwriting any client-supplied value. The route is
     * authoritative for the body. See the file-level
     * change-verification block §"Route parameter names" and the
     * `store()` merge convention the sibling controllers established.
     *
     * @param  StoreGovernanceAppointmentRequest $request
     * @param  GovernanceBody                    $governanceBody
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     */
    public function store(
        StoreGovernanceAppointmentRequest $request,
        GovernanceBody $governanceBody,
    ): JsonResponse {
        $tenantId = $this->assertTenantOwnership(
            (int) $governanceBody->tenant_id,
            GovernanceBody::class,
            (int) $governanceBody->id,
        );

        Gate::authorize('create', $governanceBody);

        $payload = $request->validated();

        // The route is authoritative for the body. This merge
        // overwrites any client-supplied value — the request class
        // does not declare a rule for `governance_body_id`, so the
        // key would not survive `validated()` in normal flow. The
        // explicit assignment makes the intent unambiguous and
        // guards against a future request class that accidentally
        // declares the rule.
        $payload['governance_body_id'] = (int) $governanceBody->id;

        $appointment = $this->service->register($payload, $tenantId);

        return response()->json([
            'success' => true,
            'data'    => new GovernanceAppointmentResource($appointment),
        ], 201);
    }

    // =========================================================================
    // show — read a specific appointment
    // =========================================================================
    /**
     * Read a specific appointment.
     *
     * Route:
     *     GET /api/governance/appointments/{governanceAppointment}
     *
     * Authorization:
     *     `Gate::authorize('view', $governanceAppointment)` — the
     *     policy enforces both the RBAC permission and the tenant
     *     boundary. The controller's tenant cross-check runs first.
     *
     * @param  GovernanceAppointment $governanceAppointment
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     * @throws AuthorizationException
     */
    public function show(GovernanceAppointment $governanceAppointment): JsonResponse
    {
        $this->assertTenantOwnership(
            (int) $governanceAppointment->tenant_id,
            GovernanceAppointment::class,
            (int) $governanceAppointment->id,
        );

        Gate::authorize('view', $governanceAppointment);

        $governanceAppointment->loadMissing(['body', 'position', 'term']);

        return response()->json([
            'success' => true,
            'data'    => new GovernanceAppointmentResource($governanceAppointment),
        ]);
    }

    // =========================================================================
    // update — modify a specific appointment
    // =========================================================================
    /**
     * Update a specific appointment.
     *
     * Route:
     *     PATCH (or PUT) /api/governance/appointments/{governanceAppointment}
     *
     * Authorization:
     *     `Gate::authorize('update', $governanceAppointment)` — the
     *     policy enforces both the RBAC permission and the tenant
     *     boundary. The controller's tenant cross-check runs first.
     *
     * The controller does NOT merge `governance_body_id` into the
     * payload. The request class (G2c file 11 of 31) declares a
     * shape rule for that FK so the service's immutability check is
     * reachable; if a client supplies a value, the service refuses
     * a change.
     *
     * The controller does NOT route to the service's lifecycle
     * methods (`activate`, `revoke`, `supersede`) — see the
     * file-level change-verification block §"What the controller
     * deliberately does NOT expose". A client that supplies a
     * `status` key in the payload will have it survive `validated()`
     * (the request declares a shape rule) and the service will
     * refuse it with a 422.
     *
     * @param  UpdateGovernanceAppointmentRequest $request
     * @param  GovernanceAppointment              $governanceAppointment
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     * @throws AuthorizationException
     */
    public function update(
        UpdateGovernanceAppointmentRequest $request,
        GovernanceAppointment $governanceAppointment,
    ): JsonResponse {
        $this->assertTenantOwnership(
            (int) $governanceAppointment->tenant_id,
            GovernanceAppointment::class,
            (int) $governanceAppointment->id,
        );

        Gate::authorize('update', $governanceAppointment);

        $appointment = $this->service->update(
            $governanceAppointment,
            $request->validated(),
        );

        $appointment->loadMissing(['body', 'position', 'term']);

        return response()->json([
            'success' => true,
            'data'    => new GovernanceAppointmentResource($appointment),
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
     * See the file-level change-verification block §"The tenant
     * cross-check — why 404, not 403" for the reasoning.
     *
     * @param  int                $modelTenantId
     * @param  class-string       $modelClass
     * @param  int|string         $modelId
     * @return int                The resolved tenant id.
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
     * resolved. The caller treats null as a mismatch and throws
     * `ModelNotFoundException` — the fail-closed posture. In normal
     * flow, the route's `tenant.membership` middleware has already
     * established a tenant before any controller action runs.
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