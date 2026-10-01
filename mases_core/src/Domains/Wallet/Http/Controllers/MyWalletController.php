<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — CR-W1
 * =============================================================================
 *
 * File:            MyWalletController.php  (NEW)
 * Unit:            CR-W1 — Portal self-service wallet endpoint
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The portal had no way to fetch the caller's
 *   own wallet. Every existing Wallet-domain route addresses a specific
 *   wallet by id (GET /api/wallets/{wallet}), an administrative listing
 *   (GET /api/wallets), or a privileged operator action
 *   (POST /api/wallets/{wallet}/adjust). None of them answers the
 *   self-service question "what is my wallet?" without the caller
 *   supplying a wallet id they would not know.
 *
 *   The Shareholder domain solved the identical problem with
 *   MyShareholderController (P1-1a) and the route /api/shareholders/me.
 *   The Wallet domain had no analogous endpoint.
 *
 * After:
 *   A single-action controller that resolves the authenticated user's
 *   own wallet through the structural chain
 *
 *       mases_tenant_id()  →  $request->user()->id
 *                          →  ShareholderRepository::findByUser()
 *                          →  WalletRepository::findForShareholder()
 *
 *   and returns the two-outcome identity contract the portal's wallet
 *   page (CR-W2) will consume. The contract mirrors
 *   /api/shareholders/me byte-for-byte in shape:
 *
 *       linked: true   →  { success: true, data: { linked: true,  wallet: <WalletResource> } }
 *       linked: false  →  { success: true, data: { linked: false, wallet: null } }
 *
 *   The unlinked branch collapses every reason the caller has no wallet
 *   — no shareholder link, a shareholder link but no wallet, a wallet
 *   in another tenant — into a single response shape, exactly as
 *   MyShareholderController collapses its own unlinked cases. No
 *   distinguishing signal is returned.
 *
 *   No Gate::authorize. The endpoint cannot be steered at another row;
 *   the entire lookup chain is server-resolved. The reasoning is
 *   reproduced in the class docblock §"Authorization — why there is no
 *   Gate::authorize", and is structurally identical to the reasoning
 *   MyShareholderController records for its own endpoint.
 *
 * Public surface introduced:
 *   · Class:  MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers\MyWalletController
 *   · Method: show(Request): JsonResponse
 *
 * Files modified:            none (the route is registered by CR-W1 file 2)
 * Files deleted:             none
 * Routes registered:         none (declared by CR-W1 file 2 — /api/wallets/me)
 * Policies modified:         none
 * Resources modified:        none (WalletResource's _display amendment is
 *                                  CR-W1 file 3)
 * Repository modified:       none
 * Migrations:                none
 * Seeders:                   none
 *
 * Dependency note:
 *   · Depends on ShareholderRepository::findByUser() — present since 2A,
 *     confirmed in the current on-disk version.
 *   · Depends on WalletRepository::findForShareholder() — present since
 *     2B.11 v1.0.0, confirmed in the current on-disk version.
 *   · Depends on WalletResource — present since 2B.31, confirmed in the
 *     current on-disk version (v1.0.1).
 *   · No new class, no trait, no interface. No composer dump-autoload
 *     is required for this file alone; the class is autoloaded on first
 *     reference by the route that CR-W1 file 2 registers.
 *
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Shareholder\Repositories\ShareholderRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Http\Resources\WalletResource;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\WalletRepository;

/**
 * MyWalletController
 *
 * HTTP boundary for the authenticated user's OWN wallet — the portal's
 * wallet self-service endpoint.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * One operation:
 *
 *     GET /api/wallets/me  →  show()   self-lookup
 *
 * The route is registered by the domain's Routes/api.php (CR-W1 file 2) as
 * a static sibling of /wallets, declared BEFORE the dynamic /{wallet}
 * route. The route carries NO middleware — see §"Authorization" below for
 * the reasoning, which mirrors the Wallet domain's existing treatment of
 * WalletController::show() (route #3 in the nine-route surface).
 *
 * The controller exists as its own class rather than as a method on
 * WalletController because the two surfaces have different
 * responsibilities:
 *
 *   WalletController  — wallet administration. Its four actions
 *                       (index, store, show, adjust) are decisions about
 *                       OTHER wallets, either across the tenant (index,
 *                       store) or by id (show, adjust). Its class
 *                       docblock declares a scope of four operations tied
 *                       to the aggregate root; its route file declares
 *                       "nine routes frozen in Step 7 v1.1.0 §C.1" and
 *                       states that adding a route is a Change Request.
 *
 *   MyWalletController — self-service identity resolution. Its single
 *                        action resolves the caller's OWN wallet through
 *                        the structural link
 *                        (wallets.shareholder_id → shareholders.user_id =
 *                        caller id within the resolved tenant), not
 *                        through a grant.
 *
 * Merging the two would either broaden WalletController's declared scope
 * or put a policy bypass on a controller whose docblock declares the
 * policy as its authorization layer. Keeping them separate keeps each
 * class's contract honest. This mirrors the Shareholder domain's decision
 * to place MyShareholderController alongside ShareholderController rather
 * than as a fifth method on it.
 *
 * =============================================================================
 * The identity contract — two outcomes, no leak
 * =============================================================================
 * The response is one of exactly two shapes:
 *
 *     linked: true   →  { success: true, data: { linked: true,  wallet: <WalletResource> } }
 *     linked: false  →  { success: true, data: { linked: false, wallet: null } }
 *
 * The `linked: false` outcome deliberately COLLAPSES every reason the
 * caller has no own wallet. Because this endpoint resolves through TWO
 * sequential lookups, the collapse covers the union of both null-returning
 * steps:
 *
 *   From ShareholderRepository::findByUser() returning null:
 *     · no shareholder row references this user at all
 *     · the user is only linked as a representative
 *       (representative_user_id set, user_id null) — representation is
 *       not self-service
 *     · the direct link exists but belongs to a different tenant
 *     · the direct link exists but the row is soft-deleted
 *     · the direct link is to a non-individual shareholder (a
 *       hypothetical state the domain prevents at write time, but which
 *       a manual DB edit could produce)
 *
 *   From WalletRepository::findForShareholder() returning null:
 *     · the shareholder has no wallet in this tenant
 *     · the shareholder's wallet belongs to a different tenant (the
 *       repository scopes by tenant_id; a foreign-tenant wallet resolves
 *       to null and is indistinguishable from an absent one)
 *
 * The collapse is intentional and mirrors MyShareholderController's own
 * collapse. Distinguishing the cases would turn the endpoint into a
 * cross-tenant and cross-role enumeration oracle: a caller could iterate
 * user ids and discover, from the response shape, which ids exist as
 * linked shareholders or as wallet-holders in another tenant. The
 * frontend's contract models this collapse exactly — one boolean, one
 * nullable resource — and CR-W2 will consume it accordingly.
 *
 * =============================================================================
 * Authorization — why there is no Gate::authorize
 * =============================================================================
 * This endpoint does NOT call Gate::authorize. The rationale is
 * structurally identical to MyShareholderController's, and is a property
 * of what the endpoint resolves, not an oversight:
 *
 *   1. The endpoint cannot return another user's wallet. The lookup chain
 *      is entirely server-resolved:
 *
 *          $tenantId  = mases_tenant_id()
 *          $userId    = $request->user()->id
 *          $holder    = ShareholderRepository::findByUser($tenantId, $userId)
 *          $wallet    = WalletRepository::findForShareholder($tenantId, $holder->id)
 *
 *      A caller cannot influence any of these four values. There is no
 *      `?wallet_id=` parameter, no path id, no request body that could
 *      redirect the query at a different row.
 *
 *   2. The WalletPolicy's `view` ability governs access to OTHER wallets.
 *      It grants through an OR of `wallet.view` (administrative) and
 *      `wallet.view_own` + ownership (self-service). Applying the policy
 *      here would invert the dependency: a `wallet.view_own` permission
 *      would gate the identity-resolution step that determines whether
 *      the permission is meaningful in the first place. The Wallet
 *      domain's own route #3 (WalletController::show) already relies on
 *      the policy's OR to grant via either path; the `/me` route is the
 *      strongest case of the OR — the caller typically holds only the
 *      own-only variant.
 *
 *   3. Requiring `wallet.view_own` on this endpoint would also change
 *      the meaning of `linked: false`. A shareholder-role user whose
 *      RBAC is not yet aligned would receive `linked: false` for a
 *      wallet they are structurally linked to — a semantically wrong
 *      answer to the question "do I have a wallet?", even though the
 *      resource-fetch gate on the wallet's own endpoints would still
 *      correctly deny access.
 *
 * The policy is therefore untouched by this controller. If a future CR
 * decides the endpoint should be permission-gated, that is a change to
 * the identity contract — the frontend's `linked` semantics, the CR-W2
 * page's state machine, and this class's contract would all need to be
 * re-evaluated in the same CR. It is not a bug fix and is not done here.
 *
 * =============================================================================
 * Tenant scoping
 * =============================================================================
 * The tenant id is resolved from `mases_tenant_id()` — the same
 * server-side helper every Wallet-domain controller uses
 * (WalletController, ObligationController, PaymentController,
 * AllocationController, StatementController). The value originates from
 * TenantContextMiddleware via TenantContextService, which is populated
 * before the auth and tenant-membership middlewares run. The controller
 * does NOT read the tenant from:
 *
 *   · the request body,
 *   · a query string parameter,
 *   · a custom header other than the one RequestTenantResolver itself
 *     consumes (X-Tenant-ID, handled earlier in the pipeline).
 *
 * The route's `tenant.membership` middleware (inherited from the Wallet
 * domain's config-declared middleware stack) guarantees a tenant is
 * present and that the caller is a member. The defensive validation
 * here mirrors the sibling controllers' pattern: if the helper is
 * unavailable or returns null, the request cannot be processed as-is
 * and a 422 with a `tenant` field error is raised — a deployment or
 * routing defect, surfaced through the canonical error envelope.
 *
 * =============================================================================
 * Response envelope
 * =============================================================================
 * Success responses follow the canonical MASES envelope:
 *
 *     { "success": true, "data": { … } }
 *
 * The `data` block is the identity contract — `{ linked, wallet }`.
 * The WalletResource's `$wrap = null` (declared on that class) means the
 * resource contributes no additional `data` wrapper; its `resolve()`
 * output is inserted verbatim as the value of `data.wallet`.
 *
 * No `meta` block. The endpoint is not paginated and returns a single
 * resource or null.
 *
 * Errors are not produced here. Validation failures, tenant-membership
 * denials, and authentication failures are raised as exceptions by this
 * controller (for the missing-tenant case) or by middleware (for the
 * other cases), and rendered by the canonical error pipeline in
 * bootstrap/app.php. This keeps every error — regardless of origin —
 * shaped identically, with `request_id` and `timestamp` present.
 *
 * =============================================================================
 * The shareholder relation on the wallet payload
 * =============================================================================
 * The controller calls `$wallet->loadMissing('shareholder')` before
 * rendering. This mirrors WalletController::show()'s identical call and
 * causes WalletResource's `whenLoaded('shareholder', …)` guard to fire,
 * embedding the full ShareholderResource under `data.wallet.shareholder`.
 *
 * The embedded shareholder is the caller themselves. It is not strictly
 * required by CR-W2 — the portal's identity card already holds the
 * shareholder payload from /api/shareholders/me — but it costs one
 * indexed join and keeps the wallet payload shape identical between the
 * self-service and administrative paths. A future CR that needs to trim
 * the payload can drop the load; the resource already handles absence.
 *
 * =============================================================================
 * What this controller does NOT do
 * =============================================================================
 *   · It does not accept a wallet id, shareholder id, user id, email,
 *     or name as input.
 *
 *   · It does not consult `representative_user_id`. Representation is a
 *     structural relationship that grants ACCESS, not self-service
 *     IDENTITY. A representative of a legal-entity shareholder does not
 *     receive that entity's wallet from this endpoint. When a future CR
 *     adds representative-context switching to the portal, it will be a
 *     distinct endpoint (e.g. /api/wallets/me/represented) with its own
 *     contract — not a widening of this one.
 *
 *   · It does not fall back to any other endpoint. There is no id
 *     search, no "find the closest wallet".
 *
 *   · It does not cache. Every call performs the same two indexed
 *     lookups. The `shareholders_tenant_id_user_id_unique` index makes
 *     the first constant-time; the `wallets_tenant_shareholder_unique`
 *     index makes the second constant-time.
 *
 *   · It does not log the request. The middleware stack and the
 *     authorization layers already produce audit rows for the cases
 *     that warrant them; a successful self-lookup is a routine read.
 *
 *   · It does not write. No audit row, no security event, no cache
 *     invalidation. The endpoint is a pure read.
 *
 * =============================================================================
 * Frontend alignment
 * =============================================================================
 * The response is the shape CR-W2's MyWalletPage.vue and its
 * useMyWallet composable will consume:
 *
 *     interface MyWalletResponse {
 *       readonly linked: boolean;
 *       readonly wallet: Wallet | null;
 *     }
 *
 * No frontend contract change is required from this endpoint's shape
 * alone; the wallet object's fields are the same shape the administrative
 * show endpoint already produces, extended by CR-W1 file 3 with the
 * `_display` siblings that the portal's date-rendering convention
 * requires.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §3.2    — Wallet aggregate root
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §13     — RBAC integration
 *   Step 5 (manifest) 2B.11     — WalletRepository (findForShareholder)
 *   Step 5 (manifest) 2B.26     — WalletController (pattern reference)
 *   Step 5 (manifest) 2B.31     — WalletResource
 *   Step 5 (manifest) 2A.11     — ShareholderRepository (findByUser)
 *   P1-1a                       — MyShareholderController (pattern precedent)
 *   CR-W1                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers
 * @version 1.0.0
 */
final class MyWalletController extends Controller
{
    /**
     * Constructor.
     *
     * Two dependencies:
     *
     *     WalletRepository      — for the wallet lookup by shareholder.
     *     ShareholderRepository — for the user → shareholder resolution.
     *                             From the Shareholder domain; the
     *                             dependency direction is
     *                             `Wallet → Shareholder`, one-way,
     *                             mirroring WalletController's identical
     *                             cross-domain dependency.
     *
     * Both repositories are bound as singletons by their respective
     * service providers, so resolving this controller within a request
     * yields the same instances every other consumer in that request
     * would receive.
     *
     * No service layer is required. The endpoint is a single read chain;
     * no transaction, no event dispatch, no ledger write.
     *
     * @param WalletRepository      $walletRepository
     * @param ShareholderRepository $shareholderRepository
     */
    public function __construct(
        private readonly WalletRepository $walletRepository,
        private readonly ShareholderRepository $shareholderRepository,
    ) {
    }

    // =========================================================================
    // show — resolve the authenticated user's own wallet
    // =========================================================================

    /**
     * Return the authenticated user's own wallet, resolved server-side.
     *
     * The resolution is structural, at two levels:
     *
     *   1. Within the tenant the request-scoped context has established,
     *      find the shareholder whose `user_id` column equals the
     *      authenticated user's id. This is
     *      ShareholderRepository::findByUser().
     *
     *   2. Within the same tenant, find the wallet whose
     *      `shareholder_id` equals the shareholder's id. This is
     *      WalletRepository::findForShareholder(). At most one wallet
     *      exists per (tenant, shareholder) — the composite UNIQUE
     *      constraint on wallets (2B.49) enforces it — so this step is a
     *      single-value lookup.
     *
     * The caller supplies no identity input; the response is therefore
     * impossible to steer toward a different row.
     *
     * -----------------------------------------------------------------------------
     * Two-null handling
     * -----------------------------------------------------------------------------
     * Either step can return null, and either null produces the same
     * `linked: false` response. The class docblock §"The identity
     * contract — two outcomes, no leak" enumerates the conditions that
     * collapse into each branch and the reasoning for the collapse.
     *
     * The wallet is loaded with its `shareholder` relation so that
     * WalletResource's `whenLoaded` guard fires and the embedded
     * holder summary is present in the payload — matching the shape
     * WalletController::show() produces on the administrative path.
     *
     * @param Request $request
     * @return JsonResponse
     *
     * @throws ValidationException  When no tenant context is available.
     */
    public function show(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        /*
         * The authenticated user. The route inherits `auth:sanctum` from
         * the Wallet domain's config-declared middleware stack, so
         * `$request->user()` is guaranteed non-null when this method
         * runs. The docblock annotation narrows the type for static
         * analysis without adding a runtime null check that the
         * middleware stack already guarantees.
         *
         * Same pattern as MyShareholderController::show() and the inline
         * `/api/user` closure in the central routes file.
         *
         * @var User $user
         */
        $user = $request->user();

        // ---------------------------------------------------------------------
        // Step 1: resolve the caller's own shareholder record.
        //
        // No eager-loads. The wallet payload does not embed the
        // shareholder's `user` or `representative` relations; embedding
        // them would add cost the endpoint does not need. The
        // ShareholderResource's own conditional guards omit any relation
        // the caller does not require.
        // ---------------------------------------------------------------------
        $shareholder = $this->shareholderRepository->findByUser(
            $tenantId,
            (int) $user->id,
        );

        /*
         * Unlinked outcome — first branch. The caller has no direct
         * shareholder link in this tenant. See the class docblock for
         * the conditions that collapse into this branch.
         */
        if ($shareholder === null) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'linked' => false,
                    'wallet' => null,
                ],
            ]);
        }

        // ---------------------------------------------------------------------
        // Step 2: resolve the caller's wallet within the same tenant.
        //
        // findForShareholder() scopes by tenant_id; a wallet belonging to
        // another tenant resolves to null and is indistinguishable from
        // an absent wallet. See the class docblock §"The identity
        // contract" for the collapse reasoning.
        // ---------------------------------------------------------------------
        $wallet = $this->walletRepository->findForShareholder(
            $tenantId,
            (int) $shareholder->id,
        );

        /*
         * Unlinked outcome — second branch. The caller has a shareholder
         * link but no wallet. Same response shape; no distinguishing
         * signal.
         */
        if ($wallet === null) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'linked' => false,
                    'wallet' => null,
                ],
            ]);
        }

        /*
         * Linked outcome. Load the shareholder relation so the
         * WalletResource's whenLoaded guard fires and the embedded
         * holder summary is present. Matches WalletController::show()'s
         * identical call.
         */
        $wallet->loadMissing('shareholder');

        /*
         * The WalletResource's static $wrap = null means `resolve()`
         * returns the field map directly, without an additional `data`
         * wrapper. The outer envelope remains the single wrapping layer,
         * as it is for every other endpoint in the domain.
         */
        return response()->json([
            'success' => true,
            'data'    => [
                'linked' => true,
                'wallet' => (new WalletResource($wallet))->resolve(),
            ],
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the tenant id from the server-side request context.
     *
     * The value comes from `mases_tenant_id()`, which reads the tenant
     * established by TenantContextMiddleware earlier in the pipeline.
     * It is NEVER read from the request body, query string, or an
     * arbitrary header.
     *
     * A missing tenant at this point is a deployment or routing defect —
     * the route's `tenant.membership` middleware refuses to let the
     * request reach the controller without one. The correct response at
     * the HTTP boundary is still a 422, because the request cannot be
     * processed as-is; the canonical renderer maps the
     * ValidationException to the standard error envelope.
     *
     * Same shape as the identical helper on WalletController,
     * MyShareholderController, ShareholderController, ShareHoldingController,
     * and CertificateController. The duplication is intentional: each
     * controller is an independent HTTP boundary, and the helper's body
     * is a three-line guard whose extraction into a shared trait would
     * add indirection without removing any invariant.
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
}