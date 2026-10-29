<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P2.3 · File 6b of 7
 * =============================================================================
 *
 * File:            ShareHoldingController.php
 * Unit:            P2.3 — dispatch to ShareHoldingPolicy::viewForShareholder
 * Delivery type:   Amendment
 * Net lines:       +2 dispatch-form changes; +1 show() docblock note;
 *                  +~35 docblock lines rewritten
 *
 * Before (after P1-3):
 *   index()  → Gate::authorize('view', $shareholder)
 *              dispatched to ShareholderPolicy::view, because the target
 *              was the Shareholder model instance.
 *
 *   show()   → Gate::authorize('view', $shareHolding)
 *              dispatched to ShareHoldingPolicy::view.
 *
 *   store()  → Gate::authorize('view', $shareholder)
 *              dispatched to ShareholderPolicy::view in both modes.
 *
 *   Net effect: the two share_holding-domain slugs
 *   (`share_holding.view`, `share_holding.view_own`) were never
 *   consulted. The authorization boundary was purely the shareholder
 *   policy, expressed in the shareholder vocabulary.
 *
 * After:
 *   index()  → Gate::authorize('viewForShareholder', [ShareHolding::class, $shareholder])
 *              dispatches to ShareHoldingPolicy::viewForShareholder.
 *
 *   show()   → Gate::authorize('view', $shareHolding)
 *              unchanged; still dispatches to ShareHoldingPolicy::view,
 *              which File 1 of this sequence extended to delegate
 *              through viewForShareholder.
 *
 *   store()  → Gate::authorize('viewForShareholder', [ShareHolding::class, $shareholder])
 *              in both modes; dispatches to ShareHoldingPolicy::viewForShareholder.
 *
 *   Net effect: `ShareHoldingPolicy::viewForShareholder` grants through
 *   an OR of three paths — `share_holding.view`, `share_holding.view_own`
 *   plus a direct link, or the delegated `ShareholderPolicy::view`
 *   (which transitively admits `shareholder.view`, `shareholder.view_own`
 *   plus a direct link, and representatives). The previously-inert
 *   share_holding slugs are now consulted.
 *
 * =============================================================================
 * The two-argument Gate dispatch form
 * =============================================================================
 * `Gate::authorize($ability, $arguments)` uses the first element of the
 * arguments array to resolve the policy class. When that element is a
 * class-string, Laravel:
 *
 *   1. Looks up the policy registered against that class
 *      (ShareHolding::class → ShareHoldingPolicy::class).
 *   2. Removes the class-string from the arguments array.
 *   3. Calls `$policy->{$method}($user, ...$remainingArguments)`.
 *
 * So `Gate::authorize('viewForShareholder', [ShareHolding::class, $shareholder])`
 * calls `ShareHoldingPolicy::viewForShareholder($user, $shareholder)`.
 *
 * This is the standard Laravel pattern for dispatching a policy ability
 * whose target is not the policy's own model class. It is used here
 * because `viewForShareholder` takes a Shareholder, not a ShareHolding.
 *
 * =============================================================================
 * Why store() switches dispatch
 * =============================================================================
 * `store()` records a ledger entry against a holding — the amount by
 * which the shareholder's holding changes. The minimum access required
 * is "the caller can see the target shareholder's holdings" — precisely
 * what `viewForShareholder` decides. Using the same ability for store as
 * for index keeps the two endpoints' authorization boundary identical
 * and lets a caller who can list a shareholder's holdings also record
 * entries against them (subject to the separate `share_holding.entry`
 * permission at the route level).
 *
 * =============================================================================
 * What does NOT change
 * =============================================================================
 *   · The controller's method signatures (index, show, store).
 *   · The tenant-crosscheck helpers (resolveTenantId,
 *     assertHoldingBelongsToTenant).
 *   · The service delegation (`$this->shareHoldingService->recordEntry`,
 *     `::recordEntryFor`).
 *   · The response shapes and the resource resolution.
 *   · The `Gate` import (already present since P1-3).
 *   · The `ShareHolding` and `Shareholder` imports (already present).
 *
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Route middleware touched:  none
 * Policy files touched:      none (File 1 already extended
 *                            ShareHoldingPolicy)
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Shareholder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Requests\StoreShareHoldingRequest;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\ShareHoldingResource;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareClass;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareHolding;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Shareholder\Repositories\ShareholderRepository;
use MaaleArna\MASES\Core\Domains\Shareholder\Services\ShareHoldingService;

/**
 * ShareHoldingController
 *
 * HTTP boundary for share holdings and the entry ledger.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * Three operations, mapped by the routes file (2A.36):
 *
 *   GET   /api/share-holdings?shareholder_id=X   → index()  list for a shareholder
 *   GET   /api/share-holdings/{shareHolding}     → show()   single holding
 *   POST  /api/share-holdings/entries            → store()  record an entry
 *
 * The endpoint paths are provisional; the final paths are declared by the
 * routes file when it is authored. The method contracts (inputs, outputs,
 * authorization) are frozen here.
 *
 * =============================================================================
 * What "record an entry" means
 * =============================================================================
 * A share-holding entry is an append-only ledger row that changes the
 * holding's `current_quantity`. The operation is not a simple "create a
 * resource" because it has side effects on an existing resource (the
 * holding's balance) and is subject to strict invariants enforced by
 * ShareHoldingService (2A.14):
 *
 *   - The resulting balance must be >= 0 (D16-R5).
 *   - The quantity must be a whole number (D6).
 *   - The entry type must be one of the five direct types (D16-R8).
 *   - The balance_before + quantity = balance_after invariant must hold.
 *
 * The controller does not attempt to enforce any of those rules. It
 * validates the shape of the input via StoreShareHoldingRequest (2A.19),
 * resolves the target, and hands the work to the service. The service
 * opens a database transaction, locks the holding row, applies the
 * arithmetic, writes the entry, and updates the projection — or rolls
 * back cleanly if any invariant would be violated.
 *
 * =============================================================================
 * Two target modes
 * =============================================================================
 * StoreShareHoldingRequest (2A.19) accepts two mutually exclusive modes
 * for identifying the target holding:
 *
 *   Mode 1 — direct:  { "share_holding_id": 42, "direction": "in",
 *                       "quantity": 100, "entry_type": "subscription", ... }
 *
 *   Mode 2 — pair:    { "shareholder_id": 7, "share_class_id": 2,
 *                       "direction": "in", "quantity": 100,
 *                       "entry_type": "subscription", ... }
 *
 * This controller branches on `$request->filled('share_holding_id')`:
 *
 *   - Mode 1 → recordEntry() with the resolved ShareHolding model.
 *   - Mode 2 → recordEntryFor() with the resolved Shareholder and ShareClass.
 *
 * Both paths converge on the same transactional write performed by the
 * service. The difference is only how the target holding is identified.
 *
 * =============================================================================
 * Tenant scoping
 * =============================================================================
 * A ShareHolding's tenant is determined by its Shareholder's `tenant_id`.
 * The controller therefore performs two tenant checks:
 *
 *   - Mode 1: after loading the target holding by id, verify that the
 *     holding's shareholder belongs to the resolved tenant. If not, treat
 *     the id as not found (404).
 *
 *   - Mode 2: resolve the shareholder via ShareholderRepository's
 *     `findForTenant()` — which already scopes by tenant — and treat a
 *     null result as not found (404).
 *
 * `show()` performs the same tenant check as Mode 1 before returning a
 * holding. `index()` requires a `shareholder_id` query parameter and
 * resolves it via `findForTenant()`, so a caller cannot list another
 * tenant's holdings.
 *
 * The tenant itself is resolved via `mases_tenant_id()` — the same
 * server-side helper used by the rest of the platform. It is never read
 * from the request body, query string, or a custom header.
 *
 * =============================================================================
 * Authorization
 * =============================================================================
 * Per-object and per-collection authorization uses Laravel's Gate against
 * the model → policy mapping registered by ShareholderServiceProvider
 * (2A.2 for ShareholderPolicy and ShareHoldingPolicy; P2.3 adds the
 * CertificatePolicy and ShareClassPolicy registrations):
 *
 *   index()  → Gate::authorize('viewForShareholder', [ShareHolding::class, $shareholder])
 *              [ShareHoldingPolicy, extended by P2.3]
 *
 *   show()   → Gate::authorize('view', $shareHolding)
 *              [ShareHoldingPolicy, extended by P2.3]
 *
 *   store()  → Gate::authorize('viewForShareholder', [ShareHolding::class, $shareholder])
 *              [ShareHoldingPolicy, extended by P2.3]
 *              (both modes; mode 1 uses the holding's shareholder)
 *
 * `index()` and `store()` dispatch to
 * `ShareHoldingPolicy::viewForShareholder`, which grants through an OR
 * of three paths:
 *
 *   1. the caller holds `share_holding.view`          (administrative);
 *   2. the caller holds `share_holding.view_own` AND
 *      is the individual shareholder's direct user link (self-service);
 *   3. `ShareholderPolicy::view` returns true         (delegation — this
 *                                                      transitively admits
 *                                                      `shareholder.view`,
 *                                                      `shareholder.view_own`
 *                                                      plus a direct link,
 *                                                      and representatives).
 *
 * `show()` dispatches to `ShareHoldingPolicy::view`, which resolves the
 * holding's shareholder and delegates to `viewForShareholder`. The same
 * three-path rule applies.
 *
 * The endpoints therefore admit any caller who can be admitted by either
 * the shareholder vocabulary (`shareholder.view`, `shareholder.view_own`
 * plus a direct link, or a representative relationship) or the holding
 * vocabulary (`share_holding.view`, `share_holding.view_own` plus a
 * direct link). This is a strict superset of the pre-P2.3 grant set,
 * which was limited to the shareholder vocabulary.
 *
 * The reason the endpoints use the policy layer rather than route
 * middleware is that a single `auth.permission:*` middleware cannot
 * express OR-of-grants semantics. See the ShareHoldingPolicy class
 * docblock for the full reasoning.
 *
 * A caller whose policy check fails receives a 403
 * `AUTH_PERMISSION_DENIED`. The shareholder's existence in the tenant has
 * already been established by the preceding `findForTenant()` call (or by
 * `assertHoldingBelongsToTenant` for `show()` and mode 1 of `store()`),
 * and a cross-tenant or non-existent id produces a 404 before the Gate
 * check runs.
 *
 * Note: there is no ShareHoldingPolicy `recordEntry` ability in the
 * frozen manifest. Adding one would be a CR. Recorded as F-2A23-3.
 *
 * =============================================================================
 * Response envelope
 * =============================================================================
 * Success responses follow the canonical envelope (Master Prompt
 * Section 33). No `meta` block; the collection in `index()` is bounded by
 * the single-shareholder filter, so pagination is not applied.
 *
 * Errors are not produced here. Validation failures, authorization
 * denials, and not-found conditions are all raised as exceptions and
 * rendered by the canonical error pipeline in bootstrap/app.php.
 *
 * =============================================================================
 * What this controller does NOT do
 * =============================================================================
 *   - It does not reverse an entry. Reversal has its own request and route
 *     (documented as part of 2A.36). See F-2A23-1.
 *   - It does not create ShareHolding rows directly. `recordEntryFor()`
 *     creates a holding at quantity 0 if the pair does not yet exist,
 *     inside the same transaction.
 *   - It does not delete or soft-delete entries or holdings. The ledger is
 *     append-only (D16-R1); holdings persist even at zero quantity.
 *   - It does not perform bulk operations.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Http\Controllers
 * @version 1.1.0
 */
final class ShareHoldingController extends Controller
{
    /**
     * Constructor.
     *
     * @param ShareHoldingService   $shareHoldingService
     * @param ShareholderRepository $shareholderRepository
     */
    public function __construct(
        private readonly ShareHoldingService $shareHoldingService,
        private readonly ShareholderRepository $shareholderRepository,
    ) {
    }

    // =========================================================================
    // index — list holdings for a shareholder
    // =========================================================================

    /**
     * List every share holding belonging to a single shareholder.
     *
     * The `shareholder_id` query parameter is required. It is resolved
     * against the tenant via ShareholderRepository, so a cross-tenant id
     * yields a 404 — the same response a caller would receive for a
     * non-existent shareholder.
     *
     * After resolution, the caller is authorized via
     * `Gate::authorize('viewForShareholder', [ShareHolding::class, $shareholder])`,
     * which dispatches to `ShareHoldingPolicy::viewForShareholder`. See
     * the class docblock §"Authorization" for the three paths the policy
     * grants through.
     *
     * The response is a non-paginated collection. A single shareholder
     * normally holds one to five positions (one per share class). If the
     * business ever needs bulk listing across a tenant, that is a distinct
     * endpoint with its own pagination contract, and a CR.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $shareholderId = $request->query('shareholder_id');

        if (!is_numeric($shareholderId)) {
            throw ValidationException::withMessages([
                'shareholder_id' => [
                    'The shareholder_id query parameter is required and must be an integer.',
                ],
            ]);
        }

        $shareholder = $this->shareholderRepository->findForTenant(
            $tenantId,
            (int) $shareholderId,
        );

        if ($shareholder === null) {
            throw (new ModelNotFoundException())->setModel(
                Shareholder::class,
                [(int) $shareholderId],
            );
        }

        /*
         * Authorize the caller via ShareHoldingPolicy::viewForShareholder.
         *
         * The dispatch form `[ShareHolding::class, $shareholder]` tells
         * Laravel to resolve the ShareHoldingPolicy (mapped to
         * ShareHolding::class) and call its `viewForShareholder` method
         * with the user and the Shareholder instance. Without the
         * class-string leading argument, Laravel would resolve the policy
         * by the Shareholder model and call ShareholderPolicy::view — the
         * pre-P2.3 dispatch.
         *
         * The policy grants through an OR of three paths:
         *   1. `share_holding.view` (administrative);
         *   2. `share_holding.view_own` + a direct user_id link
         *      (self-service);
         *   3. the delegated ShareholderPolicy::view (which admits
         *      `shareholder.view`, `shareholder.view_own` + a direct
         *      link, and representatives).
         *
         * The call throws AuthorizationException on denial; the
         * canonical renderer maps that to 403 AUTH_PERMISSION_DENIED.
         * Cross-tenant and non-existent ids are handled above, before
         * this check, matching the order used by ShareholderController::show()
         * and this class's own show() method.
         */
        Gate::authorize('viewForShareholder', [ShareHolding::class, $shareholder]);

        $holdings = ShareHolding::query()
            ->where('shareholder_id', $shareholder->id)
            ->with(['shareholder', 'shareClass'])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        app(AuditService::class)->log(
            action: 'share_holding.read.list',
            data: [
                'access_scope' => 'policy-authorized',
                'result_count' => $holdings->count(),
            ],
            user: $request->user(),
            tenantId: $tenantId,
            resourceType: Shareholder::class,
            resourceId: (string) $shareholder->getKey(),
        );

        return response()->json([
            'success' => true,
            'data'    => ShareHoldingResource::collection($holdings)->resolve(),
        ]);
    }

    // =========================================================================
    // show — single holding
    // =========================================================================

    /**
     * Show a single share holding.
     *
     * The route-bound holding is cross-checked against the resolved tenant
     * via its shareholder. A cross-tenant id is reported as not found (404)
     * — the same response a non-existent id would produce.
     *
     * Authorization dispatches to `ShareHoldingPolicy::view` (the model-
     * instance form of the ability), which was extended by P2.3 to apply
     * the same three-path OR as `viewForShareholder`. A caller admitted
     * through either the holding vocabulary or the shareholder vocabulary
     * is granted; a caller with neither receives a 403.
     *
     * @param Request     $request
     * @param ShareHolding $shareHolding  Bound by implicit route model binding.
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     */
    public function show(Request $request, ShareHolding $shareHolding): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $this->assertHoldingBelongsToTenant($shareHolding, $tenantId);

        Gate::authorize('view', $shareHolding);

        $shareHolding->loadMissing(['shareholder', 'shareClass', 'entries']);

        app(AuditService::class)->log(
            action: 'share_holding.read.view',
            data: ['access_scope' => 'policy-authorized'],
            user: $request->user(),
            tenantId: $tenantId,
            resourceType: ShareHolding::class,
            resourceId: (string) $shareHolding->getKey(),
        );

        return response()->json([
            'success' => true,
            'data'    => (new ShareHoldingResource($shareHolding))->resolve(),
        ]);
    }

    // =========================================================================
    // store — record a balance-changing entry
    // =========================================================================

    /**
     * Record a share-holding entry.
     *
     * The StoreShareHoldingRequest (2A.19) has already validated the input
     * shape, confirmed the target mode is unambiguous, and rejected
     * `reversal` as an entry type. This method resolves the target
     * (holding or pair), verifies tenant ownership, and delegates to
     * ShareHoldingService.
     *
     * Both target modes authorize the caller via
     * `Gate::authorize('viewForShareholder', [ShareHolding::class, $shareholder])`,
     * which dispatches to `ShareHoldingPolicy::viewForShareholder`. The
     * `share_holding.entry` permission required to record an entry is
     * enforced independently by the route middleware; the policy check
     * here establishes the caller's authority over the target shareholder
     * before the entry is written.
     *
     * On success the response carries the updated holding, with the newly-
     * created entry attached as the sole element of the `entries` relation
     * so that the client receives both the new balance and the ledger row
     * that produced it.
     *
     * @param StoreShareHoldingRequest $request
     * @return JsonResponse  201 Created
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function store(StoreShareHoldingRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $direction     = $request->holdingDirection();
        $quantity      = (int) $request->input('quantity');
        $entryType     = (string) $request->input('entry_type');
        $referenceType = $request->input('reference_type');
        $referenceId   = $request->input('reference_id');
        $description   = $request->input('description');
        $occurredAt    = $request->date('occurred_at');
        $createdBy     = $request->user()?->id;

        if ($request->filled('share_holding_id')) {
            // ---------------------------------------------------------------
            // Mode 1 — direct holding reference.
            // ---------------------------------------------------------------
            $holding = ShareHolding::query()
                ->with('shareholder')
                ->findOrFail((int) $request->input('share_holding_id'));

            $this->assertHoldingBelongsToTenant($holding, $tenantId);

            $shareholder = $holding->shareholder;

            if ($shareholder instanceof Shareholder) {
                Gate::authorize('viewForShareholder', [ShareHolding::class, $shareholder]);
            }

            $entry = $this->shareHoldingService->recordEntry(
                holding: $holding,
                direction: $direction,
                magnitude: $quantity,
                entryType: $entryType,
                referenceType: $referenceType,
                referenceId: $referenceId,
                description: $description,
                createdBy: $createdBy,
                occurredAt: $occurredAt,
            );
        } else {
            // ---------------------------------------------------------------
            // Mode 2 — shareholder + share class pair.
            // ---------------------------------------------------------------
            $shareholder = $this->shareholderRepository->findForTenant(
                $tenantId,
                (int) $request->input('shareholder_id'),
            );

            if ($shareholder === null) {
                throw (new ModelNotFoundException())->setModel(
                    Shareholder::class,
                    [(int) $request->input('shareholder_id')],
                );
            }

            Gate::authorize('viewForShareholder', [ShareHolding::class, $shareholder]);

            /** @var ShareClass $shareClass */
            $shareClass = ShareClass::query()->findOrFail(
                (int) $request->input('share_class_id')
            );

            $entry = $this->shareHoldingService->recordEntryFor(
                shareholder: $shareholder,
                shareClass: $shareClass,
                direction: $direction,
                magnitude: $quantity,
                entryType: $entryType,
                referenceType: $referenceType,
                referenceId: $referenceId,
                description: $description,
                createdBy: $createdBy,
                occurredAt: $occurredAt,
            );
        }

        // ---------------------------------------------------------------------
        // Reload the holding so the response reflects the post-write balance,
        // and attach the newly-created entry as the sole entry so the client
        // sees the ledger row in the same response.
        // ---------------------------------------------------------------------
        /** @var ShareHolding $updatedHolding */
        $updatedHolding = ShareHolding::query()
            ->with(['shareholder', 'shareClass'])
            ->findOrFail($entry->share_holding_id);

        $updatedHolding->setRelation('entries', new Collection([$entry]));

        return response()->json([
            'success' => true,
            'data'    => (new ShareHoldingResource($updatedHolding))->resolve(),
        ], 201);
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
     * Assert that a share holding belongs to a shareholder in the given tenant.
     *
     * The holding's tenant is determined by its shareholder's `tenant_id`.
     * A holding whose shareholder is null (broken referential integrity) or
     * whose shareholder belongs to a different tenant is treated as not
     * found — the same 404 a non-existent id would produce.
     *
     * @param ShareHolding $holding
     * @param int          $tenantId
     * @return void
     *
     * @throws ModelNotFoundException
     */
    private function assertHoldingBelongsToTenant(ShareHolding $holding, int $tenantId): void
    {
        $holding->loadMissing('shareholder');

        $shareholder = $holding->shareholder;

        if ($shareholder === null || (int) $shareholder->tenant_id !== $tenantId) {
            throw (new ModelNotFoundException())->setModel(
                ShareHolding::class,
                [$holding->getKey()],
            );
        }
    }
}