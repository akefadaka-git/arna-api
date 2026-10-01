<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Wallet\Http\Requests\ReverseAllocationRequest;
use MaaleArna\MASES\Core\Domains\Wallet\Http\Requests\StoreAllocationRequest;
use MaaleArna\MASES\Core\Domains\Wallet\Http\Resources\PaymentAllocationResource;
use MaaleArna\MASES\Core\Domains\Wallet\Http\Resources\PaymentAllocationReversalResource;
use MaaleArna\MASES\Core\Domains\Wallet\Models\PaymentAllocation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\AllocationRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\WalletRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Services\AllocationService;

/**
 * AllocationController
 *
 * HTTP boundary for the `PaymentAllocation` and `PaymentAllocationReversal`
 * dependent attribution entities.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * Two operations, mapped by the routes file (2B.48) per Step 7 v1.1.0 §C.1:
 *
 *     POST /wallets/{wallet}/allocations/{allocation}/reverse  → reverse()
 *     POST /wallets/{wallet}/allocations                       → store()
 *
 * This is the first controller in the Wallet domain with a TWO-action
 * surface. The sibling controllers (2B.26–2B.28) each expose exactly one
 * write action per entity; the allocation domain exposes two — `allocate`
 * (which creates a `PaymentAllocation`) and `reverse` (which creates a
 * `PaymentAllocationReversal` against an existing allocation).
 *
 * The two actions are modelled on the same controller because they operate
 * on the same resource chain. The reversal is a correction of an
 * allocation, not an independent entity with its own navigation; splitting
 * the two into separate controllers would fragment the read/write surface
 * of a single conceptual resource.
 *
 * The controller deliberately exposes NO `index()`, `show()`, `update()`,
 * or `destroy()` action. The rationale:
 *
 *   - `index()` — allocations are embedded under the parent payment's or
 *     obligation's resource via `whenLoaded()` (2B.33 / 2B.32). There is
 *     no separate list endpoint (Step 7 v1.1.0 §A.4, F-2B-LIST-1).
 *
 *   - `show()` — the allocation is a sub-resource of the wallet. Its
 *     canonical address is the wallet's URL, plus the parent payment's or
 *     obligation's id where necessary.
 *
 *   - `update()` — allocations are fully immutable once recorded (2B.8
 *     §"Immutability"). Corrections flow through `reverse()`. There is
 *     no in-place edit path.
 *
 *   - `destroy()` — financial records are not deletable through HTTP
 *     (Q6, Architecture §17.3).
 *
 * =============================================================================
 * Authorization layering (Step 7 v1.1.0 §C.1, routes #7 and #8)
 * =============================================================================
 * The two actions carry different authorization scopes — the strongest
 * divergence between the two actions on this controller:
 *
 *   store()   — route middleware `auth.permission:wallet.allocation.create`.
 *               Held by `super_admin`, `platform_admin`, and `tenant_admin`
 *               in the frozen RBAC matrix (Step 7 v1.1.0 §A.2). It is the
 *               routine operation of applying a payment to an obligation.
 *
 *   reverse() — route middleware `auth.permission:wallet.allocation.reverse`.
 *               Held by `super_admin` and `platform_admin` ONLY. This
 *               permission is deliberately NOT bundled with the create
 *               permission (Step 7 v1.1.0 §A.2.1): a reversal corrects a
 *               historical attribution, and the RBAC matrix restricts the
 *               capability to a smaller set of roles.
 *
 * Both actions additionally invoke `Gate::authorize('view', $wallet)` on
 * the target wallet, per Step 7 v1.1.0 §C.1 routes #7 and #8. This is
 * the "view-ability gate on the wallet" mandated for every write action
 * under `{wallet}` in the frozen design.
 *
 * The two layers are independent: the route middleware gates the
 * permission; the policy gate ensures the caller's relationship to the
 * target wallet is legitimate. Both are required.
 *
 * =============================================================================
 * Two-stage tenant scoping (Step 7 v1.1.0 §C.6) — critical for reverse()
 * =============================================================================
 * The reverse action accepts TWO route parameters, both of which must be
 * resolved under the tenant-scoping composition (Step 7 v1.1.0 §C.6):
 *
 *     {wallet}      — the owning wallet.
 *     {allocation}  — the allocation to reverse.
 *
 * The layered enforcement, mirroring the pattern established by
 * AllocationService::allocate() (2B.18 §"allocate"):
 *
 *   1. resolveWalletOrFail() — the wallet is resolved through
 *      `WalletRepository::findForTenant($tenantId, $walletId)` (2B.11).
 *      A cross-tenant wallet id yields `null` and the controller
 *      short-circuits with 404. This is Step 7 v1.1.0 §C.3 test method
 *      T10.
 *
 *   2. resolveAllocationOrFail() — the allocation is then resolved
 *      through `AllocationRepository::findForWallet($wallet->id,
 *      $allocationId)` (2B.14). A cross-wallet allocation id (or one
 *      belonging to a wallet the caller cannot access) yields `null` and
 *      the controller short-circuits with 404. This is Step 7 v1.1.0
 *      §C.3 test method T11.
 *
 * The ORDER matters. Resolving the allocation before the wallet would
 * hydrate an allocation whose wallet belongs to another tenant, and only
 * then discover the mismatch — a pattern that leaks a model from outside
 * the tenant scope. The two-stage resolution avoids this by construction:
 * no allocation is ever hydrated unless its wallet has already been
 * proven to belong to the caller's tenant.
 *
 * Neither route parameter uses implicit route-model binding. See the
 * class docblock §"Resolution via repositories, not binding" below.
 *
 * The store action accepts only ONE route parameter (`{wallet}`); its
 * cross-entity references (`payment_id`, `obligation_id`) are in the
 * request body and are resolved by the service (2B.18) under the same
 * wallet scope. Step 7 v1.1.0 §C.3 test methods T7, T8, T9 exercise the
 * three vectors on this action.
 *
 * =============================================================================
 * Resolution via repositories, not binding
 * =============================================================================
 * Neither `{wallet}` nor `{allocation}` uses Laravel's implicit
 * route-model binding. Both are resolved through the repositories' scoped
 * lookup methods:
 *
 *     {wallet}      → WalletRepository::findForTenant()
 *     {allocation}  → AllocationRepository::findForWallet()
 *
 * The rationale is documented at length in 2B.26 §"Tenant scoping":
 * implicit binding would call `Wallet::find($id)` or
 * `PaymentAllocation::find($id)`, neither of which is tenant-scoped. A
 * cross-tenant id would resolve to a model, and the controller would then
 * have to re-verify the tenant — a pattern that works but exposes the
 * un-scoped lookup. Resolving through the repositories produces `null`
 * for a cross-tenant id, and the controller never hydrates an out-of-
 * scope model.
 *
 * The route constraints `->whereNumber('wallet')` and
 * `->whereNumber('allocation')` (2B.48) guarantee numeric input, so the
 * `(int)` casts in the helpers are safe.
 *
 * =============================================================================
 * Response envelope
 * =============================================================================
 * Both actions return 201 Created with the canonical MASES envelope:
 *
 *     { "success": true, "data": <resource payload> }
 *
 * `store()` returns the newly-created `PaymentAllocation` wrapped in
 * `PaymentAllocationResource` (2B.34).
 *
 * `reverse()` returns the newly-created `PaymentAllocationReversal`
 * wrapped in `PaymentAllocationReversalResource` (2B.35). The parent
 * allocation is NOT re-loaded: it is the URL context, not the response
 * subject. A caller that wants the parent allocation's updated state
 * reads it via the wallet's embedded allocations or via a future CR.
 *
 * Neither response includes the ledger entry produced by the operation.
 * Allocations and reversals do NOT produce ledger entries (Architecture
 * §5.1); the wallet's ledger reflects only the monetary movements that
 * were already posted when the payment and obligation were recorded.
 *
 * =============================================================================
 * No overposting of intra-service projections
 * =============================================================================
 * The service (2B.18) updates `payment.unapplied_amount` and
 * `obligation.outstanding_amount` as side effects of `allocate()` and
 * `reverse()`. Those projections are NOT reflected in the response body
 * — the response is the allocation or the reversal, not the parent
 * payment or obligation. A caller that needs the updated projections
 * reads the parent entities through their own endpoints (a future CR) or
 * through the wallet's embedded payments and obligations
 * (`whenLoaded()` on `WalletResource`, 2B.31).
 *
 * =============================================================================
 * What this controller deliberately does NOT do
 * =============================================================================
 *   - It does not accept or forward `wallet_id`, `currency`,
 *     `allocated_at`, `allocated_by`, `reversed_at`, or `reversed_by`.
 *     Each is either resolved from the route, set by the service, or
 *     resolved from `auth()`. The requests (2B.24, 2B.25 §"Fields
 *     accepted by this request") exclude them all.
 *
 *   - It does not evaluate the allocation policy. D110 declines to
 *     freeze a universal priority order; the
 *     `allocation_policy_version_id` recorded on the allocation (if
 *     supplied by the caller) is historical metadata, never read or
 *     interpreted (Architecture §10).
 *
 *   - It does not auto-allocate. Both operations are explicit: the
 *     caller names the payment and the obligation, or the allocation to
 *     reverse. There is no "settle everything" convenience method.
 *
 *   - It does not write ledger entries. Allocations and reversals are
 *     attribution records, not monetary movements (Architecture §5.1);
 *     the service (2B.18) performs no ledger write.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D110                         — Payment attribution
 *   Architecture v1.2.0 §3.5    — PaymentAllocation entity
 *   Architecture v1.2.0 §3.6    — PaymentAllocationReversal entity (A1)
 *   Architecture v1.2.0 §4.7    — Deterministic lock acquisition order
 *   Architecture v1.2.0 §5.1    — Allocations do not produce ledger entries
 *   Architecture v1.2.0 §6.1    — Obligation projection
 *   Architecture v1.2.0 §6.2    — Payment projection
 *   Architecture v1.2.0 §6.3    — Allocation invariants
 *   Architecture v1.2.0 §6.5    — Allocation-reversal invariants
 *   Architecture v1.2.0 §10     — Allocation-policy contract boundary
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §13     — RBAC integration
 *   Architecture v1.2.0 §16.2   — Services and their dependencies
 *   Architecture v1.2.0 §19     — Q6 resolution (no financial cascades)
 *   Step 5 (manifest) 2B.29     — This file
 *   Step 7 v1.1.0 §A.1.1        — Permission slugs
 *   Step 7 v1.1.0 §A.2.1        — Rationale for the reverse privilege scope
 *   Step 7 v1.1.0 §A.4          — F-2B-LIST-1 (allocations embedded)
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (routes #7, #8)
 *   Step 7 v1.1.0 §C.3          — Cross-tenant test matrix (T7, T8, T9, T10, T11)
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *   §2.5 (conventions)          — Controller conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers
 * @version 1.0.0
 */
final class AllocationController extends Controller
{
    /**
     * Constructor.
     *
     * Three dependencies:
     *
     *     AllocationService     — the write path for allocations and
     *                             their reversals.
     *     WalletRepository      — tenant-scoped wallet resolution.
     *     AllocationRepository  — wallet-scoped allocation resolution
     *                             for the reverse action's second route
     *                             parameter.
     *
     * The service (2B.18) already owns the cross-entity resolution of
     * `payment_id` and `obligation_id` for the store action, via its
     * pre-flight repository lookups. This controller does NOT touch
     * `PaymentRepository` or `ObligationRepository` directly — those are
     * the service's concern.
     *
     * @param AllocationService    $allocationService
     * @param WalletRepository     $walletRepository
     * @param AllocationRepository $allocationRepository
     */
    public function __construct(
        private readonly AllocationService $allocationService,
        private readonly WalletRepository $walletRepository,
        private readonly AllocationRepository $allocationRepository,
    ) {
    }

    // =========================================================================
    // store — record an allocation
    // =========================================================================

    /**
     * Record an allocation of a payment to an obligation.
     *
     * The StoreAllocationRequest (2B.24) has already validated the input
     * shape: `payment_id` (integer, exists-anywhere), `obligation_id`
     * (integer, exists-anywhere), `amount` (positive decimal, non-zero),
     * `allocation_policy_version_id` (optional, nullable, exists-anywhere).
     *
     * The route-bound `{wallet}` is resolved through the tenant-scoped
     * repository, then the controller gates on `view` of the resolved
     * wallet, then delegates to `AllocationService::allocate()` (2B.18),
     * which:
     *
     *   - Resolves `payment_id` and `obligation_id` through their
     *     wallet-scoped repositories (`PaymentRepository::findForWallet`,
     *     `ObligationRepository::findForWallet`). A cross-wallet or
     *     non-existent id produces the same 404 a non-existent id would
     *     — the "critical for this request" cross-tenant protection
     *     documented in 2B.24 §"Tenant scoping".
     *   - Enforces the six allocation invariants of Architecture §6.3
     *     (amount bounds, wallet equality, currency equality).
     *   - Creates the `PaymentAllocation` row.
     *   - Decrements `payment.unapplied_amount` and
     *     `obligation.outstanding_amount` by the allocation amount.
     *   - Dispatches `PaymentAllocated` after commit, gated on
     *     `config('wallet.audit.emit_events')`.
     *
     * The actor (`allocated_by`) is resolved from `auth()->id()` here.
     * The request does not accept it — see 2B.24 §"Fields accepted by
     * this request".
     *
     * @param StoreAllocationRequest $request
     * @param string                 $wallet    Route parameter — resolved manually.
     * @return JsonResponse  201 Created
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function store(StoreAllocationRequest $request, string $wallet): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $walletModel = $this->resolveWalletOrFail($tenantId, $wallet);

        Gate::authorize('view', $walletModel);

        $actorId = $request->user()?->id;

        $policyVersionId = $request->input('allocation_policy_version_id');

        $allocation = $this->allocationService->allocate(
            wallet: $walletModel,
            paymentId: (int) $request->input('payment_id'),
            obligationId: (int) $request->input('obligation_id'),
            amount: (string) $request->input('amount'),
            createdBy: $actorId !== null ? (int) $actorId : null,
            policyVersionId: $policyVersionId !== null ? (int) $policyVersionId : null,
        );

        // ---------------------------------------------------------------------
        // Eager-load the actor relation so the resource can render the
        // display name without a second query. The parent payment and
        // obligation are NOT eagerly loaded: their ids are on the
        // allocation row, and their full data is reachable through their
        // own endpoints and through the wallet's embedded resources.
        // ---------------------------------------------------------------------
        $allocation->loadMissing('allocatedBy');

        return response()->json([
            'success' => true,
            'data'    => (new PaymentAllocationResource($allocation))->resolve(),
        ], 201);
    }

    // =========================================================================
    // reverse — reverse an allocation
    // =========================================================================

    /**
     * Record the reversal of an existing allocation.
     *
     * The ReverseAllocationRequest (2B.25) has already validated the input
     * shape: `reversed_amount` (positive decimal, non-zero) and `reason`
     * (required, non-empty, ≤ 500 chars).
     *
     * The route-bound `{wallet}` and `{allocation}` are resolved in the
     * two-stage composition documented in the class docblock
     * §"Two-stage tenant scoping". The controller then gates on `view`
     * of the resolved wallet, then delegates to
     * `AllocationService::reverse()` (2B.18), which:
     *
     *   - Locks the payment, obligation, and parent allocation in the
     *     deterministic order of Architecture §4.7
     *     (Payment → Obligation → PaymentAllocation).
     *   - Enforces the four reversal invariants of Architecture §6.5,
     *     including the bound `reversed_amount ≤ effective_allocated(parent)`
     *     computed from the parent's existing reversals.
     *   - Creates the `PaymentAllocationReversal` row.
     *   - Increments `payment.unapplied_amount` and
     *     `obligation.outstanding_amount` by the reversed amount (the
     *     opposite direction of the decrements performed by `store()`).
     *   - Dispatches `PaymentAllocationReversed` after commit, gated on
     *     `config('wallet.audit.emit_events')`.
     *
     * The actor (`reversed_by`) is resolved from `auth()->id()` here.
     * The request does not accept it — see 2B.25 §"Fields accepted by
     * this request". The `reason` is supplied by the request.
     *
     * The response returns the NEW reversal, not the parent allocation.
     * See the class docblock §"Response envelope".
     *
     * @param ReverseAllocationRequest $request
     * @param string                   $wallet      Route parameter — resolved manually.
     * @param string                   $allocation  Route parameter — resolved manually.
     * @return JsonResponse  201 Created
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function reverse(
        ReverseAllocationRequest $request,
        string $wallet,
        string $allocation,
    ): JsonResponse {
        $tenantId = $this->resolveTenantId();

        // ---------------------------------------------------------------------
        // Two-stage resolution in the frozen order: wallet first, then
        // allocation scoped to that wallet. See the class docblock
        // §"Two-stage tenant scoping" for the reasoning.
        // ---------------------------------------------------------------------
        $walletModel = $this->resolveWalletOrFail($tenantId, $wallet);

        $allocationModel = $this->resolveAllocationOrFail(
            $walletModel->id,
            $allocation,
        );

        Gate::authorize('view', $walletModel);

        $actorId = $request->user()?->id;

        $reversal = $this->allocationService->reverse(
            wallet: $walletModel,
            allocation: $allocationModel,
            reversedAmount: (string) $request->input('reversed_amount'),
            reason: (string) $request->input('reason'),
            reversedBy: $actorId !== null ? (int) $actorId : null,
        );

        $reversal->loadMissing('reversedBy');

        return response()->json([
            'success' => true,
            'data'    => (new PaymentAllocationReversalResource($reversal))->resolve(),
        ], 201);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the tenant id from the server-side request context.
     *
     * The value comes from `mases_tenant_id()` — never from client input.
     * The `tenant.membership` middleware has already verified the caller's
     * membership before this controller runs. The defensive check exists
     * so that a misconfigured route (missing `tenant.membership`) fails
     * with a clear 422 rather than producing a query scoped by
     * `tenant_id = null`.
     *
     * This helper is identical to the ones in WalletController (2B.26),
     * ObligationController (2B.27), and PaymentController (2B.28).
     * Duplication across sibling controllers is deliberate: extracting it
     * to a trait or base class would couple the four controllers through
     * a shared abstraction whose only shared content is four lines of
     * code. If a fifth controller repeats the pattern, that calculus
     * changes.
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
     * See the class docblock §"Resolution via repositories, not binding"
     * for the rationale on resolving through the repository rather than
     * through implicit route-model binding.
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

    /**
     * Resolve the route-bound allocation under the wallet scope.
     *
     * The allocation is resolved through
     * `AllocationRepository::findForWallet($walletId, $allocationId)`
     * (2B.14), which scopes the query by `wallet_id`. A cross-wallet id
     * (or one belonging to a wallet the caller cannot access) yields
     * `null`, and the caller receives the same 404 a non-existent id
     * would produce.
     *
     * The method does NOT take a tenant id: the wallet id passed in has
     * already been resolved under the tenant scope by
     * `resolveWalletOrFail()`, and the wallet is the sole point of entry
     * for the tenant boundary on this resource. Adding a tenant id here
     * would duplicate the wallet's tenant scope and risk divergence.
     *
     * @param int    $walletId
     * @param string $allocationId
     * @return PaymentAllocation
     *
     * @throws ModelNotFoundException
     */
    private function resolveAllocationOrFail(int $walletId, string $allocationId): PaymentAllocation
    {
        $allocationId = (int) $allocationId;

        $allocation = $this->allocationRepository->findForWallet(
            $walletId,
            $allocationId,
        );

        if ($allocation === null) {
            throw (new ModelNotFoundException())->setModel(
                PaymentAllocation::class,
                [$allocationId],
            );
        }

        return $allocation;
    }
}