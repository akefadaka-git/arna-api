<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P2.3 · File 6a of 7
 * =============================================================================
 *
 * File:            ShareClassController.php
 * Unit:            P2.3 — wire ShareClassPolicy via Gate::authorize
 * Delivery type:   Amendment
 * Net lines:       +2 Gate::authorize calls; +1 import; +~40 docblock lines
 *
 * Before:
 *   The controller had no authorization calls of its own. Authorization
 *   was performed at the route level by the group middleware
 *   `auth.permission:share_class.view` on the classes block in
 *   Routes/api.php. The class docblock's §"Authorization (via route
 *   middleware, not the controller)" section documented this pattern
 *   and asserted that the controller "does NOT perform its own
 *   permission check".
 *
 * After:
 *   Two Gate::authorize calls are added:
 *
 *     index()  → Gate::authorize('viewAny', ShareClass::class)
 *     show()   → Gate::authorize('view', $shareClass)
 *
 *   Both dispatch to the ShareClassPolicy registered by File 4 of this
 *   sequence, which grants if the caller holds `share_class.view` OR
 *   `share_class.manage`. The disjunction is what makes the previously-
 *   inert `share_class.manage` slug effective on the read routes.
 *
 *   The class docblock's §"Authorization" section is rewritten: the old
 *   description of the route-middleware pattern is replaced with a
 *   description of the policy-layer pattern, and the docblock records
 *   the removal of the middleware as the mechanism that lets a
 *   manage-only caller reach the policy.
 *
 * Dependency on Files 3, 4, and 5 of this sequence:
 *   · File 3 — ShareClassPolicy. The abilities this controller now
 *     invokes do not exist without that file.
 *   · File 4 — ShareholderServiceProvider. The Gate::policy mapping that
 *     binds ShareClass::class to ShareClassPolicy is registered there.
 *     Without it, Gate::authorize('viewAny', ShareClass::class) would
 *     fail to resolve the policy and throw AuthorizationException
 *     unconditionally.
 *   · File 5 — Routes/api.php. The group-level middleware removal on the
 *     classes block is what allows a manage-only caller to reach this
 *     controller.
 *
 *   All three files must be deployed together with this one. Deploying
 *   this file alone, against the current routes file, has no negative
 *   effect — the middleware still denies a manage-only caller before the
 *   controller runs. Deploying Files 3, 4, 5 without this file leaves
 *   the classes read routes ungated. The four files are one unit.
 *
 * Preserved:
 *   · The class remains `final`.
 *   · The constructor's promoted `ShareClassService` property.
 *   · The `index()` and `show()` method signatures.
 *   · The `index()` action's call to `$this->shareClassService->all()`
 *     and its response shape.
 *   · The `show()` action's implicit route model binding on
 *     `{shareClass}` and its response shape.
 *   · The `down()`-not-applicable status of this class (no `store`,
 *     `update`, `destroy`) — unchanged.
 *   · Every docblock section other than §"Authorization".
 *
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Route middleware touched:  none (File 5 already removed the group-level
 *                            middleware on the classes block)
 * Policies touched:          none (the policy itself is File 3)
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Shareholder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\ShareClassResource;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareClass;
use MaaleArna\MASES\Core\Domains\Shareholder\Services\ShareClassService;

/**
 * ShareClassController
 *
 * HTTP boundary for share-class reference data.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * Two read-only operations, mapped by the routes file (2A.36):
 *
 *   GET  /api/share-classes               → index()   list all classes
 *   GET  /api/share-classes/{shareClass}  → show()    single class
 *
 * Share classes are REFERENCE DATA (D3). The two canonical classes in
 * Phase 2A — FOUNDERS (መስራች) and STANDARD (መደበኛ) — are inserted by the
 * ShareClassSeeder (CR-008, planned). They are not created, updated, or
 * deleted through the API.
 *
 * =============================================================================
 * Why there is no store() method
 * =============================================================================
 * The frozen manifest describes this controller's scope as
 * "index / show / optional admin store". The store endpoint is optional,
 * and in Phase 2A it is deliberately NOT implemented. Two reasons:
 *
 *   1. The service (ShareClassService, 2A.13) is read-only. It exposes no
 *      `create()` method, because share classes are reference data that
 *      should be seeded, not created ad hoc through an API.
 *
 *   2. Implementing `store()` today would require either calling a
 *      non-existent method (returning 500 for every request) or throwing
 *      a "not implemented" error explicitly (an ugly contract). Neither is
 *      acceptable for a production endpoint.
 *
 * The StoreShareClassRequest (2A.18) exists and remains deployable. It
 * becomes the validator for this endpoint when:
 *
 *   - CR-012 adds `ShareClassService::create()`; and
 *   - A follow-up CR adds `store()` to this controller.
 *
 * Until both are authorised, the endpoint does not exist. Recorded as
 * F-2A22-1.
 *
 * =============================================================================
 * Why there is no update() or destroy()
 * =============================================================================
 * Same reasoning. A share class's `is_active` and `is_treasury_eligible`
 * flags are set at seeding time. Modifying them during Phase 2A would be
 * a business decision requiring an approved change record — not an
 * operation to expose speculatively through the API.
 *
 * =============================================================================
 * Authorization (policy layer, as of P2.3)
 * =============================================================================
 * Both endpoints authorize through the ShareClassPolicy (P2.3, File 3)
 * registered by ShareholderServiceProvider (File 4):
 *
 *   index()  → Gate::authorize('viewAny', ShareClass::class)
 *   show()   → Gate::authorize('view', $shareClass)
 *
 * The policy grants if the caller holds EITHER:
 *
 *   · share_class.view   — the read permission, or
 *   · share_class.manage — the manage permission, treated as a superset
 *                          of view (see the policy class docblock
 *                          §"The manage-as-superset rule")
 *
 * Prior to P2.3, the routes block applied
 * `->middleware('auth.permission:share_class.view')` at the group level,
 * and this controller performed no authorization of its own. That
 * pattern could not express the disjunction — a manage-only caller was
 * rejected at middleware, before the controller ran. File 5 of this
 * sequence removed the middleware; this file adds the policy check that
 * replaces it.
 *
 * The new pattern matches the one used by ShareHoldingController and
 * CertificateController for their collection and single-resource reads:
 * a policy that grants through multiple paths is invoked by the
 * controller, not the route, because a single middleware cannot express
 * OR-of-grants semantics.
 *
 * =============================================================================
 * Tenant scoping
 * =============================================================================
 * Share classes are NOT tenant-scoped. They are declared globally in
 * Config/shareholder.php (file 2A.1) and seeded once into the database.
 * Every tenant sees the same two classes.
 *
 * The `tenant.membership` middleware still runs on the route (because the
 * Shareholder domain is only meaningful within a tenant context), but the
 * controller does not compare the tenant id against anything on the
 * ShareClass row — the row has no tenant_id column.
 *
 * The policy layer, in contrast, does consult the tenant context: the
 * caller's permission set is evaluated against the resolved tenant, so a
 * caller with no tenant context is denied even for reference data. This
 * is the same fail-closed rule used by every other policy in the domain.
 *
 * =============================================================================
 * Response envelope
 * =============================================================================
 * Success responses follow the canonical envelope (Master Prompt
 * Section 33):
 *
 *     { "success": true, "data": ... }
 *
 * No `meta` block — the class list is not paginated. Errors are not
 * produced here; any exception raised by the service (a missing class
 * during a future phase) or the Gate (authorization denial) is handled
 * by the canonical renderer in bootstrap/app.php.
 *
 * =============================================================================
 * Ordering
 * =============================================================================
 * `index()` returns classes in canonical order, as defined by
 * `config('shareholder.share_classes.codes')` — FOUNDERS first, then
 * STANDARD, then any additional codes in their configured position. The
 * ordering is applied by ShareClassService::all() (2A.13); the controller
 * does not re-sort.
 *
 * =============================================================================
 * Route model binding
 * =============================================================================
 * The `{shareClass}` route parameter is bound implicitly to a `ShareClass`
 * model by Laravel. If the id does not resolve, Laravel throws
 * `ModelNotFoundException` BEFORE the controller method runs; the
 * canonical renderer maps it to a 404 RESOURCE_NOT_FOUND response.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Http\Controllers
 * @version 1.1.0
 */
final class ShareClassController extends Controller
{
    /**
     * Constructor.
     *
     * `ShareClassService` is bound as a singleton by
     * ShareholderServiceProvider (2A.2), so resolving this controller
     * within a request gives the same service instance any other consumer
     * would receive.
     *
     * @param ShareClassService $shareClassService
     */
    public function __construct(
        private readonly ShareClassService $shareClassService,
    ) {
    }

    // =========================================================================
    // index — list
    // =========================================================================

    /**
     * List every share class, in canonical order.
     *
     * The list is intentionally not paginated. The set of share classes in
     * Phase 2A is two rows; even in a mature system it would rarely exceed
     * a handful. Adding pagination would impose a client-side loop for no
     * benefit.
     *
     * Every class is returned, including inactive ones. A client that
     * needs only active classes filters on the `is_active` field it
     * receives in the resource payload. Filtering server-side would add a
     * query parameter without reducing the response to a manageable size,
     * so it is deferred until (and unless) a real need arises.
     *
     * Authorization is performed by the Gate against the caller's
     * permissions in the resolved tenant. See the class docblock
     * §"Authorization" for the two paths the policy grants through.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ShareClass::class);

        $classes = $this->shareClassService->all();

        return response()->json([
            'success' => true,
            'data'    => ShareClassResource::collection($classes)->resolve(),
        ]);
    }

    // =========================================================================
    // show — single
    // =========================================================================

    /**
     * Show a single share class by its primary key.
     *
     * The route parameter `{shareClass}` is bound to a `ShareClass` model
     * by implicit route model binding. A non-existent id produces a
     * `ModelNotFoundException` before this method is invoked.
     *
     * If a future caller needs to look up a class by its code (`FOUNDERS`)
     * rather than by id, a CR will add a separate endpoint or change the
     * binding. The current API uses the primary key, which is stable and
     * unambiguous.
     *
     * Authorization is performed by the Gate against the resolved model.
     * The policy does not inspect the model beyond its existence — see
     * the ShareClassPolicy class docblock §"Ability map" for the
     * reasoning. The Gate call is placed after the model binding has
     * already resolved the row; a nonexistent id produces a 404 before
     * this call runs.
     *
     * @param Request     $request
     * @param ShareClass  $shareClass  Bound by implicit route model binding.
     * @return JsonResponse
     */
    public function show(Request $request, ShareClass $shareClass): JsonResponse
    {
        Gate::authorize('view', $shareClass);

        return response()->json([
            'success' => true,
            'data'    => (new ShareClassResource($shareClass))->resolve(),
        ]);
    }
}