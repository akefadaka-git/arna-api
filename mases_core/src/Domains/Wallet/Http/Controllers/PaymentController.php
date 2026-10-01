<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Wallet\Http\Requests\StorePaymentRequest;
use MaaleArna\MASES\Core\Domains\Wallet\Http\Resources\PaymentResource;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\WalletRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Services\PaymentService;

/**
 * PaymentController
 *
 * HTTP boundary for the `Payment` scoped business aggregate.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * One operation, mapped by the routes file (2B.48) per Step 7 v1.1.0 §C.1:
 *
 *     POST /wallets/{wallet}/payments  → store()  record a payment
 *
 * The controller deliberately exposes NO `index()`, `show()`, `update()`,
 * or `destroy()` action. The rationale is identical to the sibling
 * `ObligationController` (2B.27 §"Scope") and is summarised here:
 *
 *   - `index()` — payments are embedded under the parent wallet's `show`
 *     response via `WalletResource::whenLoaded()` (Step 7 v1.1.0 §A.4,
 *     F-2B-LIST-1 resolution). No separate list endpoint exists.
 *
 *   - `show()` — the payment is a sub-resource of the wallet; its
 *     canonical address is the wallet's URL.
 *
 *   - `update()` — payments are immutable in their economic facts
 *     (currency, amount, received_at), enforced at the model level (2B.7
 *     §"Immutability") and by the absence of any service update method
 *     (2B.17). Their only mutable field — `unapplied_amount` — is a
 *     projection updated by AllocationService (2B.18) as a side effect of
 *     recording allocations.
 *
 *   - `destroy()` — financial records are not deletable through HTTP
 *     (Q6, Architecture §17.3).
 *
 * =============================================================================
 * Authorization layering (Step 7 v1.1.0 §C.1, route #6)
 * =============================================================================
 * Two layers cooperate:
 *
 *   - Route middleware (`auth.permission:wallet.payment.record`) runs
 *     before the controller is instantiated. It enforces the required
 *     permission and rejects with a 403 `AUTH_PERMISSION_DENIED` if the
 *     caller lacks it.
 *
 *   - The controller calls `Gate::authorize('view', $wallet)` on the
 *     target wallet. This is the "view-ability gate on the wallet"
 *     mandated by Step 7 v1.1.0 §C.1 for route #6. It ensures that a
 *     caller who holds `wallet.payment.record` cannot record a payment
 *     against a wallet they are not otherwise entitled to view.
 *
 * The two layers are independent: the route middleware gates the
 * permission; the policy gate ensures the caller's relationship to the
 * target wallet is legitimate. Both are required.
 *
 * =============================================================================
 * Tenant scoping (Step 7 v1.1.0 §C.6)
 * =============================================================================
 * The route-bound `{wallet}` parameter is resolved through
 * `WalletRepository::findForTenant($tenantId, $walletId)` (2B.11) — NOT
 * through implicit route-model binding. This mirrors the pattern
 * established by WalletController (2B.26) and ObligationController
 * (2B.27), and honours the architecture §12 mandate.
 *
 * A cross-tenant wallet id yields `null`; the controller translates
 * `null` into the same 404 a non-existent id would produce, via
 * `ModelNotFoundException`. The caller cannot distinguish "the wallet
 * does not exist" from "the wallet exists but belongs to another tenant".
 *
 * Step 7 v1.1.0 §C.3 test method T6 (`test_payment_store_returns_404_for_cross_tenant_wallet`)
 * exercises this vector and relies on the repository's `null` return for
 * its 404.
 *
 * =============================================================================
 * Contrast with ObligationController (2B.27)
 * =============================================================================
 * This controller differs from 2B.27 in three ways, each driven by a
 * substantive difference between the two models (2B.6 vs. 2B.7):
 *
 *   1. No `component` resolution. Payments are not component-tagged
 *      (Architecture §3.4, D109); the request (2B.23) does not accept a
 *      `component` field, and the service (2B.17) passes `null` to the
 *      ledger-write primitive. This controller therefore has no
 *      `resolveComponent()` helper.
 *
 *   2. Only one date field (`received_at`), not two. Payments are
 *      received at a point in time; there is no `due_at` equivalent on
 *      the payment model (2B.7 / 2B.51).
 *
 *   3. Two-argument service call, not eight. `PaymentService::record()`
 *      (2B.17) takes `(wallet, amount, receivedAt, referenceType,
 *      referenceId, description, createdBy)` — no `component` and no
 *      `dueAt`. The controller's argument-passing block is correspondingly
 *      shorter.
 *
 * =============================================================================
 * Response envelope
 * =============================================================================
 * `store()` returns 201 Created with:
 *
 *     { "success": true, "data": <PaymentResource payload> }
 *
 * The created payment is loaded with its `createdBy` relation so the
 * resource can render the actor's display name without a second query.
 * The parent wallet is NOT re-loaded: the wallet is the URL context, not
 * the response subject.
 *
 * The ledger entry that was posted alongside the payment is NOT part of
 * this response. It is visible via the statement endpoint (2B.30) or the
 * wallet's `entries` relation (2B.5). The payment is the resource this
 * endpoint addresses.
 *
 * =============================================================================
 * No overpayment detection
 * =============================================================================
 * A payment may exceed the total outstanding obligations on the wallet.
 * That fact is NOT detected by this controller and is NOT an error. D110
 * requires the unapplied remainder to be recorded explicitly — and it is,
 * as the payment's `unapplied_amount` column. The service (2B.17
 * §"No overpayment handling at record time") handles this; the controller
 * has no role in overpayment.
 *
 * =============================================================================
 * What this controller deliberately does NOT do
 * =============================================================================
 *   - It does not accept or forward `wallet_id`, `currency`,
 *     `unapplied_amount`, or `created_by`. Each is either resolved from
 *     the route, inherited from the wallet, set by the service, or
 *     resolved from `auth()`. The request (2B.23 §"Fields accepted by
 *     this request") excludes them all.
 *
 *   - It does not auto-allocate. A newly-recorded payment is fully
 *     unapplied (`unapplied_amount = amount`); it becomes applied only
 *     when an explicit allocation is recorded (2B.29, route #7).
 *
 *   - It does not evaluate any policy. The wallet's
 *     `allocation_policy_version_id` is a slot; no code in this
 *     controller reads or interprets a policy (D110, Architecture §10).
 *
 *   - It does not write ledger entries directly. The service (2B.17)
 *     owns the transactional write path, calls
 *     `WalletService::recordEntry()` (2B.15), and acquires the wallet
 *     row lock. This controller delegates unconditionally.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D110                         — Payment attribution
 *   Architecture v1.2.0 §3.4    — Payment entity
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §6.2    — Payment projection
 *   Architecture v1.2.0 §6.4    — Overpayment and unapplied amounts
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §13     — RBAC integration
 *   Architecture v1.2.0 §16.2   — Services and their dependencies
 *   Architecture v1.2.0 §19     — Q6 resolution (no financial cascades)
 *   Step 5 (manifest) 2B.28     — This file
 *   Step 7 v1.1.0 §A.1.1        — Permission slugs (wallet.payment.record)
 *   Step 7 v1.1.0 §A.4          — F-2B-LIST-1 (payments embedded)
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (route #6)
 *   Step 7 v1.1.0 §C.3          — Cross-tenant test matrix (T6)
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *   §2.5 (conventions)          — Controller conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers
 * @version 1.0.0
 */
final class PaymentController extends Controller
{
    /**
     * Constructor.
     *
     * Two dependencies:
     *
     *     PaymentService       — the write path for the payment aggregate
     *                            and its ledger entry.
     *     WalletRepository     — tenant-scoped wallet resolution.
     *
     * The service (2B.17) already owns the ledger-write dependency (via
     * `WalletService::recordEntry()`); this controller does not touch
     * `WalletService` directly.
     *
     * @param PaymentService   $paymentService
     * @param WalletRepository $walletRepository
     */
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly WalletRepository $walletRepository,
    ) {
    }

    // =========================================================================
    // store — record a payment
    // =========================================================================

    /**
     * Record a payment received into a wallet.
     *
     * The StorePaymentRequest (2B.23) has already validated the input
     * shape: `amount` (positive decimal, non-zero), `received_at`
     * (optional date), `reference_type` and `reference_id` (optional,
     * all-or-nothing, each ≤ 64 chars), `description` (optional, ≤ 500
     * chars).
     *
     * The route-bound `{wallet}` is resolved through the tenant-scoped
     * repository, then the controller gates on `view` of the resolved
     * wallet, then delegates to `PaymentService::record()` (2B.17),
     * which:
     *
     *   - Creates the Payment row with `currency` inherited from the
     *     wallet (Q8) and `unapplied_amount` initialized to equal `amount`.
     *   - Posts a `wallet_entries` row of type `payment_received`, with
     *     a positive signed quantity (credit-side, per Architecture §4.2)
     *     and the payment as its reference.
     *   - Updates the wallet's `current_balance` inside the same
     *     transaction, under the wallet row lock.
     *   - Dispatches `PaymentReceived` after commit, gated on
     *     `config('wallet.audit.emit_events')`.
     *
     * The actor (`created_by`) is resolved from `auth()->id()` here. The
     * request does not accept it — see 2B.23 §"Fields accepted by this
     * request".
     *
     * @param StorePaymentRequest $request
     * @param string              $wallet    Route parameter — resolved manually.
     * @return JsonResponse  201 Created
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function store(StorePaymentRequest $request, string $wallet): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $walletModel = $this->resolveWalletOrFail($tenantId, $wallet);

        Gate::authorize('view', $walletModel);

        // ---------------------------------------------------------------------
        // Resolve the optional business timestamp and the actor. The date
        // is validated by the request (2B.23) via Laravel's `date` rule; a
        // null input yields null, and the service defaults `received_at`
        // to `now()`.
        //
        // There is no `component` to resolve: payments are not
        // component-tagged (Architecture §3.4, D109). There is no `due_at`
        // to resolve: payments are not owed, they are received. See the
        // class docblock §"Contrast with ObligationController".
        // ---------------------------------------------------------------------
        $receivedAt = $this->resolveDate($request->input('received_at'));

        $actorId = $request->user()?->id;

        $payment = $this->paymentService->record(
            wallet: $walletModel,
            amount: (string) $request->input('amount'),
            receivedAt: $receivedAt,
            referenceType: $request->input('reference_type') !== null
                ? (string) $request->input('reference_type')
                : null,
            referenceId: $request->input('reference_id') !== null
                ? (string) $request->input('reference_id')
                : null,
            description: $request->input('description') !== null
                ? (string) $request->input('description')
                : null,
            createdBy: $actorId !== null ? (int) $actorId : null,
        );

        // ---------------------------------------------------------------------
        // Eager-load the actor relation so the resource can render the
        // display name without a second query. The wallet is not reloaded:
        // it is the URL context, not the response subject.
        // ---------------------------------------------------------------------
        $payment->loadMissing('createdBy');

        return response()->json([
            'success' => true,
            'data'    => (new PaymentResource($payment))->resolve(),
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
     * This helper is identical to the ones in WalletController (2B.26) and
     * ObligationController (2B.27). Duplication across sibling controllers
     * is deliberate: extracting it to a trait or base class would couple
     * the three controllers through a shared abstraction whose only shared
     * content is four lines of code. If a fourth controller repeats the
     * pattern, that calculus changes.
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
     * See the class docblock §"Tenant scoping" for the rationale on
     * resolving through the repository rather than through implicit
     * route-model binding.
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
     * Parse an optional date input to a Carbon instance.
     *
     * The request (2B.23) validates `received_at` with Laravel's `date`
     * rule, which accepts any format `strtotime()` can parse. This helper
     * converts the validated string to a `Carbon` instance for the
     * service.
     *
     * A null input (or an empty string) yields null. The service treats
     * null `received_at` as "now" (2B.17 §"record").
     *
     * `Carbon::parse()` cannot throw on a string that passed the `date`
     * rule; the guard is retained for defensive symmetry with the
     * service's own null-handling.
     *
     * @param mixed $value
     * @return Carbon|null
     */
    private function resolveDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        return Carbon::parse((string) $value);
    }
}