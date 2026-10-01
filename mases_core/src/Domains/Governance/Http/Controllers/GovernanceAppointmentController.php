<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 12 of 31
 * =============================================================================
 *
 * File:            Http/Controllers/GovernanceAppointmentController.php  (NEW)
 * Unit:            G2c — GovernanceAppointment vertical slice (controller)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No controller dispatched the four
 *   GovernanceAppointment HTTP routes the routes amendment (G2c
 *   file 31 of 31) will declare. Any request to
 *   `GET/POST /api/governance/bodies/{governanceBody}/appointments`
 *   or `GET/PATCH/PUT /api/governance/appointments/{governanceAppointment}`
 *   would have fallen through to the platform's catch-all and
 *   returned 404.
 *
 * After:
 *   One file that declares the four canonical controller actions
 *   the routes amendment expects:
 *
 *     index()   — paginated list of a body's appointments
 *     store()   — register a new appointment under a body
 *     show()    — read a specific appointment
 *     update()  — modify a specific appointment
 *
 *   The controller follows the conventions the four sibling
 *   controllers in this domain established at G1, G2a, and G2b:
 *
 *     · `final class` extending `App\Http\Controllers\Controller`.
 *
 *     · Constructor injection of the service and the repository.
 *
 *     · Route parameter names match the controller argument names
 *       exactly (`{governanceBody}` → `$governanceBody`,
 *       `{governanceAppointment}` → `$governanceAppointment`). This
 *       is critical for implicit model binding — see the
 *       file-level change-verification block §"Route parameter
 *       names" below.
 *
 *     · The tenant cross-check runs BEFORE `Gate::authorize()`.
 *       A cross-tenant id produces a 404, not a 403 — the resource
 *       does not exist AS FAR AS THE CALLER'S TENANT IS CONCERNED.
 *
 *     · `store()` merges `governance_body_id` from the URL into the
 *       validated payload, overwriting any client-supplied value.
 *       The route is authoritative for the body.
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
 *     · The three lifecycle methods the service declares:
 *       `activate()`, `revoke()`, and `supersede()`. The G2c
 *       manifest allocated FOUR routes to appointments — the
 *       standard CRUD quartet. The lifecycle methods are
 *       capabilities the service offers but the HTTP surface does
 *       not yet exercise.
 *
 *       This is a deliberate gap, not an oversight. The three
 *       lifecycle transitions each have their own authorization
 *       shape that the manifest's route set does not accommodate:
 *
 *         · Revoking an active appointment and superseding an
 *           active appointment are arguably sensitive operations
 *           that a body's chair authorizes differently from a
 *           general field update.
 *
 *         · Activating a draft appointment may need to be gated on
 *           an approval (the corresponding resolution being
 *           adopted), which is a workflow the current single-role
 *           RBAC model does not express.
 *
 *       Exposing the three transitions through the existing
 *       `PATCH /appointments/{id}` route — by having the controller
 *       inspect the payload for a `status` change — was
 *       considered and rejected. It would collapse four
 *       semantically distinct operations into one HTTP action,
 *       would require the update request to accept `status` (which
 *       the service deliberately rejects), and would obscure the
 *       authorization boundary between "edit the title" and
 *       "revoke the appointment".
 *
 *       A future CR that exposes the three transitions adds three
 *       dedicated routes (e.g. `POST /appointments/{id}/activate`,
 *       `POST /appointments/{id}/revoke`,
 *       `POST /appointments/{id}/supersede`) and three matching
 *       controller methods. The service-layer methods the CR will
 *       call are already on disk — this controller simply does not
 *       route to them yet.
 *
 *     · A `destroy()` action. The service has no `delete()` method;
 *       appointments end through `revoked` or `superseded`, not
 *       through deletion. The policy's `delete` ability is
 *       declared for future use; no route in G2c calls it.
 *
 * =============================================================================
 * Route parameter names
 * =============================================================================
 * The four route parameter names this controller receives are:
 *
 *     {governanceBody}          →  $governanceBody (GovernanceBody)
 *     {governanceAppointment}   →  $governanceAppointment (GovernanceAppointment)
 *
 * Both are camelCase forms of their model class names. Both must
 * match the parameter names declared in the corresponding FormRequest's
 * `route(...)` calls (see `UpdateGovernanceAppointmentRequest`
 * (G2c file 11 of 31), which resolves the current appointment via
 * `$this->route('governanceAppointment')`).
 *
 * A rename of either parameter in the routes file (G2c file 31 of
 * 31) MUST be mirrored here and in the corresponding request's
 * `route()` call. Laravel's implicit model binding matches by
 * name; a mismatch produces a silent failure where the controller
 * receives a fresh empty model rather than the resolved row.
 *
 * =============================================================================
 * The tenant cross-check — why 404, not 403
 * =============================================================================
 * For every action that operates on a specific model — `show`,
 * `update`, and the body-scoped `index` and `store` — the controller
 * verifies that the resolved model's `tenant_id` matches the tenant
 * resolved from the request context. On mismatch, the controller
 * throws a `ModelNotFoundException`.
 *
 * The exception is caught by the canonical error renderer in
 * `bootstrap/app.php` and converted into a 404 `RESOURCE_NOT_FOUND`
 * response. This is deliberate:
 *
 *   · A 403 would confirm to a cross-tenant caller that a resource
 *     with that id exists in ANOTHER tenant. That is an information
 *     leak: the caller learns the id is in use somewhere.
 *
 *   · A 404 tells the caller the same thing it would tell a caller
 *     asking for an id that does not exist AT ALL: "this resource
 *     is not visible from your tenant." The two cases are
 *     indistinguishable to the caller, which is the correct
 *     posture.
 *
 * The check runs BEFORE `Gate::authorize()`. If it ran AFTER, a
 * cross-tenant caller would receive a 403 from the policy instead
 * of a 404 — the leak the ordering prevents.
 *
 * =============================================================================
 * Authorization layering — matching the sibling controllers
 * =============================================================================
 * The four actions split authorization responsibility as follows:
 *
 *     index    —  route middleware carries
 *                 `auth.permission:governance.body.view`.
 *                 The controller does NOT call Gate::authorize.
 *
 *     store    —  route middleware carries
 *                 `auth.permission:governance.body.manage`.
 *                 The controller does NOT call Gate::authorize.
 *
 *     show     —  the controller calls
 *                 `Gate::authorize('view', $governanceAppointment)`.
 *                 The route does NOT carry permission middleware.
 *
 *     update   —  the controller calls
 *                 `Gate::authorize('update', $governanceAppointment)`.
 *                 The route does NOT carry permission middleware.
 *
 * The asymmetry is the platform's three-tier convention. See the
 * routes file (G2c file 31 of 31) §"Authorization layering" for the
 * full rationale. The policy
 * (`GovernanceAppointmentPolicy`, G2c file 8 of 31) still enforces
 * both layers for every action — the route middleware and the
 * controller's `Gate::authorize` calls are two paths to the same
 * policy checks.
 *
 * =============================================================================
 * Response envelope
 * =============================================================================
 * Every successful response follows the canonical MASES envelope:
 *
 *     {
 *       "success": true,
 *       "data":    <resource | resource collection>
 *     }
 *
 * The paginated `index` action adds a `meta` block:
 *
 *     {
 *       "success": true,
 *       "data":    [<resource>, <resource>, ...],
 *       "meta": {
 *         "current_page": 1,
 *         "per_page":     25,
 *         "total":        42,
 *         "last_page":    2,
 *         "from":         1,
 *         "to":           25
 *       }
 *     }
 *
 * Errors are not produced by any action in this controller.
 * Validation failures, authorization denials, and not-found
 * conditions are all raised as exceptions and rendered by the
 * canonical error pipeline.
 *
 * =============================================================================
 * Eager-loading
 * =============================================================================
 * `index` eager-loads `position` and `term` on the paginated
 * query — those are the two optional relations the resource exposes
 * via `whenLoaded()`. The `body` relation is not eager-loaded on
 * `index` because every row in the list shares the same body (the
 * one from the URL) and the resource exposes the body's id as a
 * scalar already.
 *
 * `show` and `update` eager-load `body`, `position`, and `term`
 * via `loadMissing()` before constructing the resource. This
 * guarantees the resource's three `whenLoaded` entries are all
 * present without issuing a separate query per relation.
 *
 * `store` returns the freshly created appointment without any
 * eager-loaded relations — the resource's `whenLoaded` entries
 * are simply absent from the response. A client that needs the
 * relations follows up with `GET /appointments/{id}` or re-queries
 * the list endpoint.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Controllers/GovernanceAppointmentController.php
 *
 * The directory already exists.
 *
 * After uploading, run:
 *
 *     composer dump-autoload
 *     php artisan optimize:clear
 *
 * (Both via Plesk's UI or command runner.) The autoloader must
 * regenerate its class map to include the new class; the
 * `optimize:clear` ensures the route cache is refreshed once the
 * routes amendment (G2c file 31 of 31) has also been deployed.
 *
 * The controller has no independent provider registration — Laravel
 * resolves it via the container when a route dispatches to it.
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Actions declared:          4 (index, store, show, update)
 * Lifecycle methods exposed: 0 (activate, revoke, supersede — deferred
 *                               to a future CR; see §"What the
 *                               controller deliberately does NOT
 *                               expose")
 * Services injected:         1 (GovernanceAppointmentService)
 * Repositories injected:     1 (GovernanceAppointmentRepository)
 * Gate::authorize calls:     2 (in show, in update)
 * ModelNotFoundException throws: 4 (one per action; the tenant
 *                                   cross-check in each)
 * =============================================================================
 */

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