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
 * Wallet queries and mutations remain tenant-scoped and ownership-checked.
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
        app(\MaaleArna\MASES\Core\Auth\Services\AdaptiveStepUpGuard::class)->assert($request, 'wallet.balance.adjust', $tenantId);

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