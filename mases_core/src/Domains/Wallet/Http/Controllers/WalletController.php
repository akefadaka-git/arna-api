<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers;

use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Shareholder\Repositories\ShareholderRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Http\Requests\AdjustWalletRequest;
use MaaleArna\MASES\Core\Domains\Wallet\Http\Requests\OpenWalletRequest;
use MaaleArna\MASES\Core\Domains\Wallet\Http\Resources\WalletResource;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\WalletRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Services\WalletService;

/**
 * WalletController
 *
 * HTTP boundary for the `Wallet` aggregate root.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery.
 *
 * 1.0.1  — index() honours the list filters the frontend sends.
 *
 *          The frontend's wallet list (src/features/wallet/WalletListView.vue)
 *          issues GET /api/wallets with any combination of `search`,
 *          `is_active`, and `shareholder_id` query parameters, plus a
 *          `page` parameter that is currently ignored (the endpoint is
 *          unpaginated by design; see WalletRepository 1.0.1). The v1.0.0
 *          controller called `listForTenant($tenantId)` without reading
 *          any of these — the filter parameters arrived at the endpoint
 *          and were silently discarded, so the UI's search box and status
 *          dropdown had no effect on the response.
 *
 *          index() now extracts the three supported filter keys from the
 *          request and forwards them to `listForTenant()`. The
 *          repository applies them; see its 1.0.1 revision entry for the
 *          semantics of each filter.
 *
 *          Design notes:
 *
 *            · `is_active` is normalised through `$request->boolean()`,
 *              which converts the query-string literals "true" / "false"
 *              / "1" / "0" to booleans. Without this, the string "false"
 *              would be truthy in PHP and the filter would invert.
 *
 *            · `search` and `shareholder_id` are only included in the
 *              filter array when the request actually carries them
 *              (via `filled()`). This keeps the filter array sparse,
 *              which is what the repository expects.
 *
 *            · No other method changes. store(), show(), and adjust()
 *              are byte-for-byte identical to 1.0.0.
 *
 *            · No change to the response shape. The envelope remains
 *              `{ success: true, data: [...] }` for index.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * Four operations, mapped by the routes file (2B.48) per Step 7 v1.1.0 §C.1:
 *
 *     GET   /wallets                    → index()    list tenant's wallets
 *     POST  /wallets                    → store()    open a wallet
 *     GET   /wallets/{wallet}           → show()     single wallet
 *     POST  /wallets/{wallet}/adjust    → adjust()   operator adjustment
 *
 * The obligations, payments, allocations, and statement actions are handled
 * by sibling controllers (2B.27–2B.30). This controller is scoped to the
 * wallet aggregate root only.
 *
 * =============================================================================
 * Authorization layering (Step 7 v1.1.0 §C.1 / §C.1.1)
 * =============================================================================
 * Two layers cooperate:
 *
 *   - Route middleware (`auth.permission:wallet.*`) runs before the
 *     controller is instantiated. It enforces the single unambiguous
 *     permission required by the endpoint.
 *
 *   - The policy layer (`WalletPolicy`, 2B.37) is invoked by the controller
 *     via `Gate::authorize()`. It grants abilities through multiple paths
 *     (broad permission, or `_view_own` + ownership).
 *
 * Per-endpoint mapping:
 *
 *   index()   — route middleware `wallet.view`.
 *               Controller calls `Gate::authorize('viewAny', Wallet::class)`.
 *               This closes F-2A47-1 (CR-022 analogue): the policy layer
 *               is invoked even though the route middleware already gates
 *               the endpoint.
 *
 *   store()   — route middleware `wallet.open`. No additional policy gate.
 *               The controller resolves the target shareholder through the
 *               tenant-scoped ShareholderRepository (2A.11); a cross-tenant
 *               shareholder_id produces a 404 before the service runs.
 *
 *   show()    — NO route middleware. The `view` ability has an OR across
 *               `wallet.view` and `wallet.view_own`. Applying a route-level
 *               `wallet.view` middleware would lock out a self-service
 *               caller who holds only `wallet.view_own`. The controller
 *               calls `Gate::authorize('view', $wallet)`, and the policy
 *               grants via either slug.
 *
 *   adjust()  — route middleware `wallet.adjust` (privileged; held only by
 *               super_admin in the frozen RBAC matrix). Controller calls
 *               `Gate::authorize('view', $wallet)` as a second layer, per
 *               Step 7 v1.1.0 §C.1 route #4.
 *
 * =============================================================================
 * Tenant scoping (Step 7 v1.1.0 §C.6)
 * =============================================================================
 * The route-bound `{wallet}` parameter is resolved through
 * `WalletRepository::findForTenant($tenantId, $walletId)` (2B.11) — NOT
 * through implicit route-model binding.
 *
 * Rationale: implicit binding would call `Wallet::find($id)`, which is
 * NOT tenant-scoped. A cross-tenant wallet id would resolve to a model,
 * and the controller would then have to re-verify the tenant — a
 * pattern that works but exposes the un-scoped lookup. Resolving through
 * the repository instead produces `null` for a cross-tenant id and lets
 * the controller translate it to the same 404 a non-existent id would
 * produce, without ever hydrating an out-of-scope model. This mirrors
 * the architecture §12 mandate: "Every controller resolves the wallet
 * through this method".
 *
 * The tenant id itself is resolved server-side by `mases_tenant_id()`,
 * never from client input. See `resolveTenantId()`.
 *
 * =============================================================================
 * Response envelope
 * =============================================================================
 * Every successful response follows the canonical MASES envelope
 * (Architecture §6.2 of the handoff conventions):
 *
 *     { "success": true, "data": ... }
 *
 * `index()` returns `data: [...]` — a collection of WalletResource
 * payloads. The list is NOT paginated: a tenant's wallet count is bounded
 * by its shareholder count, and the 2B manifest does not include a
 * pagination strategy for this endpoint. See 2B.11 §"listForTenant".
 * The `page` query parameter the frontend sends is therefore accepted
 * and ignored.
 *
 * `store()` returns 201 with the newly-created wallet.
 * `show()` returns 200 with the wallet.
 * `adjust()` returns 200 with the wallet, refreshed to reflect the
 * updated `current_balance`. The ledger entry that produced the
 * adjustment is recorded and dispatched as an event; it is visible via
 * the statement endpoint (2B.30) but is not the direct response body
 * here — the wallet is the resource addressed by the URL.
 *
 * Errors — validation failures, authorization denials, not-found
 * conditions — are raised as exceptions and rendered by the canonical
 * error pipeline in `bootstrap/app.php`. No error is constructed in this
 * controller.
 *
 * =============================================================================
 * What this controller deliberately does NOT do
 * =============================================================================
 *   - It does not accept or forward `tenant_id`, `wallet_id`,
 *     `current_balance`, `created_by`, or any other server-controlled
 *     field. Each is resolved by the controller or by the service.
 *
 *   - It does not evaluate interest, allocation, or KYC policies. The
 *     wallet's three policy references (2B.5) are slots; no code in this
 *     controller reads or interprets a policy.
 *
 *   - It does not write ledger entries directly. The `adjust()` action
 *     delegates to `WalletService::adjust()` (2B.15), which owns the
 *     transactional write path and the row lock.
 *
 *   - It does not implement a `destroy()` action. Wallets are not
 *     deletable through HTTP (Q6, Architecture §17.3).
 *
 *   - It does not expose `close()` or `reopen()` actions. Q5 defers those
 *     operations to a future CR.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §3.2    — Wallet aggregate root
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §13     — RBAC integration
 *   Architecture v1.2.0 §16.2   — Services and their dependencies
 *   Architecture v1.2.0 §17.3   — Foreign-key deletion semantics (no delete)
 *   Architecture v1.2.0 §19     — Q4, Q5, Q6 resolutions
 *   Step 5 (manifest) 2B.26     — This file
 *   Step 7 v1.1.0 §A.1.1        — Permission slugs
 *   Step 7 v1.1.0 §A.2.1        — Rationale for wallet.adjust scope
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (routes #1–#4)
 *   Step 7 v1.1.0 §C.1.1        — Statement policy-only authorization
 *                                 (analogous reasoning for `show`)
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *   §2.5 (conventions)          — Controller conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers
 * @version 1.0.1
 */
final class WalletController extends Controller
{
    /**
     * Constructor.
     *
     * Three dependencies:
     *
     *     WalletService         — for the open and adjust write paths.
     *     WalletRepository      — for tenant-scoped wallet resolution and
     *                             the tenant's wallet list.
     *     ShareholderRepository — for tenant-scoped shareholder resolution
     *                             in `store()`.
     *
     * The ShareholderRepository is from the Shareholder domain (2A.11).
     * The dependency direction is `Wallet → Shareholder`, one-way,
     * mirroring the model-level relation declared on the Wallet model
     * (2B.5 §"shareholder").
     *
     * @param WalletService         $walletService
     * @param WalletRepository      $walletRepository
     * @param ShareholderRepository $shareholderRepository
     */
    public function __construct(
        private readonly WalletService $walletService,
        private readonly WalletRepository $walletRepository,
        private readonly ShareholderRepository $shareholderRepository,
    ) {
    }

    // =========================================================================
    // index — list wallets for the tenant
    // =========================================================================

    /**
     * List wallets belonging to the authenticated caller's tenant.
     *
     * The list is not paginated. Each wallet is returned with its own
     * relations conditionally loaded by the resource layer (2B.31) via
     * `whenLoaded()` — obligations and payments are embedded only when
     * the controller eager-loads them, which it does not for this
     * endpoint. See Step 7 v1.1.0 §A.4 (F-2B-LIST-1).
     *
     * -----------------------------------------------------------------------------
     * Filters (revision 1.0.1)
     * -----------------------------------------------------------------------------
     * The method reads three optional query parameters and forwards them
     * to `WalletRepository::listForTenant()`:
     *
     *   · `is_active`      — "true" / "false" / "1" / "0". Normalised to
     *                        a bool via `$request->boolean()`.
     *
     *   · `search`         — a free-text term matched case-insensitively
     *                        against wallet id, shareholder id, currency
     *                        code, and the owner's bilingual name columns.
     *
     *   · `shareholder_id` — a numeric owner filter.
     *
     * The `page` parameter the frontend also sends is accepted and
     * ignored — the endpoint is unpaginated by design. See the class
     * docblock §"Response envelope".
     *
     * -----------------------------------------------------------------------------
     * Authorization
     * -----------------------------------------------------------------------------
     *   - Route middleware `auth.permission:wallet.view` rejects callers
     *     without the broad view permission with a 403 before the
     *     controller runs.
     *
     *   - The explicit `Gate::authorize('viewAny', Wallet::class)` below
     *     is a second layer: it invokes `WalletPolicy::viewAny()` (2B.37),
     *     which returns true only when the caller holds `wallet.view`.
     *     This closes F-2A47-1 — the 2A finding that a Shareholder
     *     controller's index lacked the explicit gate.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        Gate::authorize('viewAny', Wallet::class);

        /*
         * Extract the supported filter parameters. The array is kept
         * sparse — a key is present only when the request actually
         * carries it — because the repository treats an absent key as
         * "filter not applied" and a present key as "filter this value".
         *
         * `is_active` goes through `$request->boolean()`, which normalises
         * the query-string literals "true" / "false" / "1" / "0" (and
         * PHP-native booleans) to a bool. Without this, the string "false"
         * would be truthy in PHP and the filter would invert — silently
         * returning active wallets when the caller asked for inactive.
         */
        $filters = [
            'is_active'      => $request->has('is_active')
                ? $request->boolean('is_active')
                : null,
            'search'         => $request->filled('search')
                ? (string) $request->input('search')
                : null,
            'shareholder_id' => $request->filled('shareholder_id')
                ? (int) $request->input('shareholder_id')
                : null,
        ];

        /*
         * Drop null entries so the repository's presence checks work.
         * `array_filter` with an explicit callback keeps the boolean
         * `false` (which is a meaningful filter value) while dropping
         * `null` (which means "filter not supplied").
         */
        $filters = array_filter(
            $filters,
            static fn (mixed $value): bool => $value !== null,
        );

        $wallets = $this->walletRepository->listForTenant($tenantId, $filters);

        return response()->json([
            'success' => true,
            'data'    => WalletResource::collection($wallets)->resolve(),
        ]);
    }

    // =========================================================================
    // store — open a wallet
    // =========================================================================

    /**
     * Open a new wallet for a shareholder.
     *
     * The OpenWalletRequest (2B.20) has already validated the input shape:
     * `shareholder_id` (integer, exists-anywhere) and `currency` (ISO
     * 4217 shape). This action resolves the shareholder under the tenant
     * scope, then delegates to `WalletService::open()` (2B.15).
     *
     * Authorization: route middleware `auth.permission:wallet.open`. No
     * additional policy gate is invoked — see the class docblock
     * §"Authorization layering" for the reasoning.
     *
     * A cross-tenant `shareholder_id` produces a 404 (a `ModelNotFoundException`
     * rendered by the canonical error pipeline). The service (2B.15)
     * additionally re-verifies the shareholder's tenant_id as a
     * defence-in-depth layer.
     *
     * @param OpenWalletRequest $request
     * @return JsonResponse  201 Created
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function store(OpenWalletRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $shareholderId = (int) $request->input('shareholder_id');

        // ---------------------------------------------------------------------
        // Resolve the shareholder through the tenant-scoped repository. A
        // cross-tenant shareholder_id yields null → 404, indistinguishable
        // from a non-existent id. See Step 7 v1.1.0 §C.6.
        // ---------------------------------------------------------------------
        $shareholder = $this->shareholderRepository->findForTenant(
            $tenantId,
            $shareholderId,
        );

        if ($shareholder === null) {
            throw (new ModelNotFoundException())->setModel(
                Shareholder::class,
                [$shareholderId],
            );
        }

        $wallet = $this->walletService->open(
            tenantId: $tenantId,
            shareholder: $shareholder,
            currency: (string) $request->input('currency'),
        );

        $wallet->loadMissing('shareholder');

        return response()->json([
            'success' => true,
            'data'    => (new WalletResource($wallet))->resolve(),
        ], 201);
    }

    // =========================================================================
    // show — single wallet
    // =========================================================================

    /**
     * Show a single wallet.
     *
     * The route-bound `{wallet}` is resolved through the tenant-scoped
     * repository (2B.11). A cross-tenant or non-existent id yields the
     * same 404.
     *
     * Authorization: no route middleware — see the class docblock
     * §"Authorization layering". The controller calls
     * `Gate::authorize('view', $wallet)`, and `WalletPolicy::view()`
     * (2B.37) grants via `wallet.view` (broad) OR `wallet.view_own` +
     * ownership.
     *
     * The wallet is loaded with its `shareholder` relation so the
     * resource can render the holder's display name. Obligations,
     * payments, allocations, and entries are NOT eager-loaded by default;
     * a caller that needs them requests them via a query parameter in a
     * future CR (not part of 2B).
     *
     * @param Request $request
     * @param string  $wallet    Route parameter — resolved manually.
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function show(Request $request, string $wallet): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $walletModel = $this->resolveWalletOrFail($tenantId, $wallet);

        Gate::authorize('view', $walletModel);

        $walletModel->loadMissing('shareholder');

        return response()->json([
            'success' => true,
            'data'    => (new WalletResource($walletModel))->resolve(),
        ]);
    }

    // =========================================================================
    // adjust — operator-initiated balance adjustment
    // =========================================================================

    /**
     * Record an operator-initiated balance adjustment.
     *
     * This is the most privileged wallet operation in Phase 2B. The route
     * middleware `auth.permission:wallet.adjust` is held only by
     * `super_admin` in the frozen RBAC matrix (Step 7 v1.1.0 §A.2.1).
     * The controller additionally calls `Gate::authorize('view', $wallet)`
     * as a second layer, per Step 7 v1.1.0 §C.1 route #4.
     *
     * The `AdjustWalletRequest` (2B.21) has validated the payload:
     * `signed_amount` (signed decimal, non-zero) and `description`
     * (optional, ≤ 500 chars). The `created_by` actor is resolved from
     * `auth()->id()` here; a caller cannot forge an actor through the
     * request body.
     *
     * The response returns the wallet, refreshed to reflect the updated
     * `current_balance`. The ledger entry that produced the adjustment is
     * recorded inside the service's transaction and is available via the
     * statement endpoint (2B.30) or the ledger relation on the wallet
     * (2B.5 `entries()`).
     *
     * @param AdjustWalletRequest $request
     * @param string              $wallet    Route parameter — resolved manually.
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function adjust(AdjustWalletRequest $request, string $wallet): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $walletModel = $this->resolveWalletOrFail($tenantId, $wallet);

        Gate::authorize('view', $walletModel);

        $actorId = $request->user()?->id;

        $this->walletService->adjust(
            wallet: $walletModel,
            signedAmount: (string) $request->input('signed_amount'),
            createdBy: $actorId !== null ? (int) $actorId : null,
            description: $request->input('description') !== null
                ? (string) $request->input('description')
                : null,
        );

        // ---------------------------------------------------------------------
        // The service updates the wallet's current_balance via a locked
        // read that produces a distinct Wallet instance; the model we
        // hold here is stale. Refresh it before rendering, so the
        // response reflects the post-adjustment balance.
        // ---------------------------------------------------------------------
        $walletModel->refresh();
        $walletModel->loadMissing('shareholder');

        return response()->json([
            'success' => true,
            'data'    => (new WalletResource($walletModel))->resolve(),
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the tenant id from the server-side request context.
     *
     * The value comes from `mases_tenant_id()` — never from client input.
     * The `tenant.membership` middleware has already verified the caller's
     * membership before this controller runs; this method's only job is
     * to extract the resolved id and refuse to proceed if none is present.
     *
     * The defensive check exists so that a misconfigured route (missing
     * the `tenant.membership` middleware) fails with a clear 422 rather
     * than producing a query scoped by `tenant_id = null`.
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
     * Resolve the route-bound wallet under the tenant scope.
     *
     * A cross-tenant wallet id yields the same 404 a non-existent id
     * would produce. The method never hydrates a wallet belonging to a
     * different tenant — the repository's `findForTenant` enforces the
     * scope at the query level.
     *
     * The route parameter is a string because the route declares
     * `->whereNumber('wallet')` but does not use implicit model binding
     * (see the class docblock §"Tenant scoping"). The cast to int is safe
     * because the route constraint guarantees a numeric value.
     *
     * @param int    $tenantId
     * @param string $walletId
     * @return Wallet
     *
     * @throws ModelNotFoundException
     */
    private function resolveWalletOrFail(int $tenantId, string $walletId): Wallet
    {
        $walletId = (int) $walletId;

        $wallet = $this->walletRepository->findForTenant($tenantId, $walletId);

        if ($wallet === null) {
            throw (new ModelNotFoundException())->setModel(
                Wallet::class,
                [$walletId],
            );
        }

        return $wallet;
    }
}