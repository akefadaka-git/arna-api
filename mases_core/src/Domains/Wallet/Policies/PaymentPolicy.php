<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;

/**
 * PaymentPolicy
 *
 * Authorization policy for the `Payment` scoped business aggregate.
 *
 * =============================================================================
 * What this policy is
 * =============================================================================
 * A Laravel policy that decides whether a caller may perform a per-row
 * ability on a `Payment`. It is registered against the `Payment` model
 * by `WalletServiceProvider::registerPolicies()` (2B.2) and, when
 * invoked, resolves the ability by DELEGATING to `WalletPolicy` (2B.37)
 * on the payment's parent wallet.
 *
 * This policy is a structural SIBLING of `ObligationPolicy` (2B.38):
 * same delegation pattern, same ability surface, same "no current call
 * sites" status, same posture on missing parent relations. The two are
 * deliberately parallel so that a reader of one understands the other
 * immediately. The only differences are the model class, the relation
 * name, and the docblock's citation set.
 *
 * =============================================================================
 * Current call sites — NONE in Phase 2B
 * =============================================================================
 * This policy has NO current call sites in the Phase 2B controllers.
 * The reason is structural and mirrors the obligation case exactly:
 *
 *   - The single payment endpoint in 2B is
 *     `POST /wallets/{wallet}/payments` (Step 7 v1.1.0 §C.1 route #6),
 *     handled by `PaymentController::store()` (2B.28). The payment does
 *     not exist until the service creates it, so there is no payment
 *     instance to gate against at request time. The controller gates on
 *     the parent wallet instead:
 *
 *         Gate::authorize('view', $walletModel);
 *
 *   - There is NO `show`, `index`, `update`, or `destroy` endpoint for
 *     payments in the 2B manifest (2B.28 §"Scope" documents the
 *     rationale). No endpoint addresses an existing payment by id.
 *
 * The policy is nonetheless REGISTERED by the provider (2B.2), and this
 * file is in the manifest (Step 5, 2B.39). Its purpose is to be the
 * correct decision point the moment a future CR adds a payment-
 * addressing endpoint — a `GET /wallets/{wallet}/payments/{payment}`
 * show action, or a `GET .../allocations` list, or a state-transition
 * action — without requiring the caller to duplicate the wallet's
 * authorization logic.
 *
 * Registering an uninvoked policy is deliberate and matches the 2A
 * precedent: `ShareHoldingPolicy` (2A.30) is registered even on routes
 * where the caller gates on the shareholder. The registration is a
 * structural commitment; the invocations grow with the API surface.
 *
 * =============================================================================
 * Delegation to WalletPolicy (not reimplementation)
 * =============================================================================
 * The policy is a delegator. It does not re-derive the caller's right to
 * access the payment's parent wallet; it asks `WalletPolicy::view()`
 * directly. This is the "delegation preferred over reimplementation"
 * rule from the conventions (§2.8), applied identically to the
 * obligation case (2B.38 §"Delegation to WalletPolicy"):
 *
 *   - The two policies would otherwise duplicate the same OR of
 *     (broad permission, OR own-only permission + ownership).
 *
 *   - The ownership determination — the check that the caller is either
 *     `shareholder.user_id` or `shareholder.representative_user_id` — is
 *     non-trivial (D11). Duplicating it would create three places that
 *     must be kept in sync (WalletPolicy, ObligationPolicy,
 *     PaymentPolicy).
 *
 *   - Delegating through `WalletPolicy` means that when the wallet-level
 *     rules change, both the obligation-level and payment-level rules
 *     change with them.
 *
 * The policy injects `WalletPolicy` by constructor. As with
 * `ObligationPolicy` (2B.38), this is unconventional in the sense that
 * policies normally do not depend on other policies — but it is the
 * direct expression of the delegation rule and the cleanest available
 * option.
 *
 * =============================================================================
 * Abilities provided
 * =============================================================================
 * One ability:
 *
 *   view(User $user, Payment $payment): bool
 *       — whether the caller may view (and transitively act on) the
 *         payment. Delegates to `WalletPolicy::view()` on the payment's
 *         parent wallet.
 *
 * No other abilities are provided:
 *
 *   - There is no `viewAny` ability. The 2B manifest defines no payment-
 *     index endpoint; payments are embedded under the parent wallet's
 *     `show` response via `whenLoaded()` (Step 7 v1.1.0 §A.4,
 *     F-2B-LIST-1). A future CR that adds an index would introduce
 *     `viewAny` at that time.
 *
 *   - There is no `update` or `delete` ability. Payments are immutable
 *     in their economic facts (2B.7 §"Immutability") and not deletable
 *     through HTTP (Q6). No operation would use those abilities.
 *
 *   - There is no `create` ability. The 2B creation path is the wallet-
 *     scoped store action, which gates on the wallet before the payment
 *     exists. A `create` ability would have no payment instance to
 *     receive as a parameter; Laravel's per-row policy model requires
 *     an instance. Should a future CR need a class-level `create` check
 *     (called via `Gate::authorize('create', Payment::class)`), it would
 *     be added at that time.
 *
 * =============================================================================
 * What this policy deliberately does NOT do
 * =============================================================================
 *   - It does not re-derive the parent wallet's authorization. It
 *     delegates. See §"Delegation to WalletPolicy".
 *
 *   - It does not add a payment-specific restriction on top of the
 *     wallet's authorization. Phase 2B has no business rule that says
 *     "a caller who can view the wallet may nevertheless not view its
 *     payments". If such a rule is introduced — for example, a future
 *     CR that marks certain payments as confidential, or that gates
 *     payment visibility on a KYC policy (A7) or an AML/CFT rule — the
 *     restriction is added here.
 *
 *   - It does not access the payment's `currency`, `amount`,
 *     `unapplied_amount`, `received_at`, `reference_type`,
 *     `reference_id`, or `created_by` fields. The policy's sole input
 *     is the payment's parent wallet — the delegation target.
 *
 *   - It does not evaluate the wallet's `allocation_policy_version_id`
 *     or any other policy-version reference. Policy evaluation is a
 *     future capability (A7, A8, D110); no 2B code path reads a policy
 *     payload. See Architecture §10.
 *
 *   - It does not provide a `before()` global bypass. Any user that can
 *     view the wallet can view the payment through the SAME wallet-level
 *     paths. This matches the 2A precedent (2A.29, 2A.30) and the
 *     sibling policy (2B.38).
 *
 *   - It does not throw. A missing parent wallet results in `false`, not
 *     an exception. A wallet-less payment is a data anomaly that the
 *     policy treats as a denial.
 *
 *   - It does not use `Response::deny()` or `Response::allow()`. The
 *     ability returns a plain `bool` per §2.8.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D11                          — Legal-entity representatives
 *   Architecture v1.2.0 §3.4     — Payment entity
 *   Architecture v1.2.0 §6.2     — Payment projection
 *   Architecture v1.2.0 §6.4     — Overpayment and unapplied amounts
 *   Architecture v1.2.0 §10      — Allocation-policy contract boundary
 *   Architecture v1.2.0 §12      — Tenant isolation
 *   Architecture v1.2.0 §13      — RBAC integration
 *   Architecture v1.2.0 §13.2    — Policy layer (three policies registered)
 *   Architecture v1.2.0 §19      — Q6 resolution (no financial cascades)
 *   Step 5 (manifest) 2B.39      — This file
 *   Step 5 (manifest) 2B.2       — WalletServiceProvider registers this policy
 *   Step 7 v1.1.0 §A.1.1         — Permission slugs
 *   Step 7 v1.1.0 §C.1           — Nine-route API structure (no payment
 *                                  endpoint addresses an existing payment)
 *   Step 7 v1.1.0 §C.6           — Cross-tenant child-validation invariant
 *   §2.8 (conventions)           — Policy conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Policies
 * @version 1.0.0
 */
final class PaymentPolicy
{
    /**
     * Constructor.
     *
     * One dependency: `WalletPolicy`, the target of the delegation. See
     * the class docblock §"Delegation to WalletPolicy" for the rationale.
     *
     * The container resolves both policies through Laravel's auto-wiring:
     * `WalletPolicy` needs only `AuthorizationService` (a Core singleton,
     * bound by `CoreServiceProvider`). No explicit binding is required
     * for either policy.
     *
     * @param WalletPolicy $walletPolicy
     */
    public function __construct(
        private readonly WalletPolicy $walletPolicy,
    ) {
    }

    // =========================================================================
    // view — access a single payment
    // =========================================================================

    /**
     * Whether the caller may view the payment.
     *
     * Delegates to `WalletPolicy::view()` on the payment's parent wallet.
     * The caller can view the payment if and only if the caller can view
     * the wallet the payment belongs to.
     *
     * The parent wallet is read from the `wallet` relation on the payment
     * model (2B.7). When the caller has eager-loaded the relation, no
     * additional query fires; when it has not, one query fires. The policy
     * does not force a load: the payment-addressing endpoints that will
     * eventually call this ability (see the class docblock §"Current call
     * sites") pass a payment that was already resolved through the
     * wallet-scoped repository (`PaymentRepository::findForWallet()`,
     * 2B.13), and those repositories return a model whose wallet is
     * reachable in one query.
     *
     * A missing parent wallet — a payment with a null `wallet` relation,
     * indicating a broken referential invariant — is treated as a denial,
     * not an exception. See the class docblock §"What this policy
     * deliberately does NOT do".
     *
     * @param User    $user
     * @param Payment $payment
     * @return bool
     */
    public function view(User $user, Payment $payment): bool
    {
        $wallet = $payment->wallet;

        if ($wallet === null) {
            return false;
        }

        return $this->walletPolicy->view($user, $wallet);
    }
}