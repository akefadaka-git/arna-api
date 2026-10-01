<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;

/**
 * ObligationPolicy
 *
 * Authorization policy for the `Obligation` scoped business aggregate.
 *
 * =============================================================================
 * What this policy is
 * =============================================================================
 * A Laravel policy that decides whether a caller may perform a per-row
 * ability on an `Obligation`. It is registered against the `Obligation`
 * model by `WalletServiceProvider::registerPolicies()` (2B.2) and, when
 * invoked, resolves the ability by DELEGATING to `WalletPolicy` (2B.37)
 * on the obligation's parent wallet.
 *
 * =============================================================================
 * Current call sites — NONE in Phase 2B
 * =============================================================================
 * This policy has NO current call sites in the Phase 2B controllers.
 * The reason is structural:
 *
 *   - The single obligation endpoint in 2B is
 *     `POST /wallets/{wallet}/obligations` (Step 7 v1.1.0 §C.1 route #5),
 *     handled by `ObligationController::store()` (2B.27). The obligation
 *     does not exist until the service creates it, so there is no
 *     obligation instance to gate against at request time. The
 *     controller gates on the parent wallet instead:
 *
 *         Gate::authorize('view', $walletModel);
 *
 *   - There is NO `show`, `index`, `update`, or `destroy` endpoint for
 *     obligations in the 2B manifest (2B.27 §"Scope" documents the
 *     rationale). No endpoint addresses an existing obligation by id.
 *
 * The policy is nonetheless REGISTERED by the provider (2B.2), and this
 * file is in the manifest (Step 5, 2B.38). Its purpose is to be the
 * correct decision point the moment a future CR adds an obligation-
 * addressing endpoint — a `GET /wallets/{wallet}/obligations/{obligation}`
 * show action, or a `POST .../reverse` action, or a `GET .../allocations`
 * list — without requiring the caller to duplicate the wallet's
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
 * access the obligation's parent wallet; it asks `WalletPolicy::view()`
 * directly. This is the "delegation preferred over reimplementation"
 * rule from the conventions (§2.8):
 *
 *   - The two policies would otherwise duplicate the same OR of
 *     (broad permission, OR own-only permission + ownership).
 *
 *   - The ownership determination — the check that the caller is either
 *     `shareholder.user_id` or `shareholder.representative_user_id` — is
 *     non-trivial (D11). Duplicating it would create two places that
 *     must be kept in sync.
 *
 *   - Delegating through `WalletPolicy` means that when the wallet-level
 *     rules change (a new own-only path, a new permission, a new
 *     ownership predicate), the obligation-level rules change with them.
 *
 * The policy injects `WalletPolicy` by constructor. This is
 * unconventional in the sense that policies normally do not depend on
 * other policies — but it is the direct expression of the delegation
 * rule and the cleanest available option. The alternative — injecting
 * `AuthorizationService` and re-implementing the ownership check — is
 * exactly the reimplementation the convention forbids.
 *
 * =============================================================================
 * Abilities provided
 * =============================================================================
 * One ability:
 *
 *   view(User $user, Obligation $obligation): bool
 *       — whether the caller may view (and transitively act on) the
 *         obligation. Delegates to `WalletPolicy::view()` on the
 *         obligation's parent wallet.
 *
 * No other abilities are provided:
 *
 *   - There is no `viewAny` ability. The 2B manifest defines no
 *     obligation-index endpoint; obligations are embedded under the
 *     parent wallet's `show` response via `whenLoaded()` (Step 7 v1.1.0
 *     §A.4, F-2B-LIST-1). A future CR that adds an index would introduce
 *     `viewAny` at that time.
 *
 *   - There is no `update` or `delete` ability. Obligations are
 *     immutable in their economic facts (2B.6 §"Immutability") and not
 *     deletable through HTTP (Q6). No operation would use those
 *     abilities.
 *
 *   - There is no `create` ability. The 2B creation path is the wallet-
 *     scoped store action, which gates on the wallet before the
 *     obligation exists. A `create` ability would have no obligation
 *     instance to receive as a parameter — Laravel's policy model
 *     requires a model instance for per-row abilities and no instance
 *     exists before creation. Should a future CR need a class-level
 *     `create` check (called via `Gate::authorize('create', Obligation::class)`),
 *     it would be added at that time.
 *
 * =============================================================================
 * What this policy deliberately does NOT do
 * =============================================================================
 *   - It does not re-derive the parent wallet's authorization. It
 *     delegates. See §"Delegation to WalletPolicy".
 *
 *   - It does not add an obligation-specific restriction on top of the
 *     wallet's authorization. Phase 2B has no business rule that says
 *     "a caller who can view the wallet may nevertheless not view its
 *     obligations". If such a rule is introduced (e.g. an obligation
 *     becomes confidential to a subset of wallet-viewers), a future CR
 *     adds the restriction here.
 *
 *   - It does not access the obligation's `component`, `currency`,
 *     `original_amount`, `outstanding_amount`, `occurred_at`, `due_at`,
 *     `reference_type`, `reference_id`, or `created_by` fields. The
 *     policy's sole input is the obligation's parent wallet — the
 *     delegation target.
 *
 *   - It does not provide a `before()` global bypass. Any user that can
 *     view the wallet can view the obligation through the SAME wallet-
 *     level paths. This matches the 2A precedent (2A.29, 2A.30).
 *
 *   - It does not throw. A missing parent wallet results in `false`, not
 *     an exception. A wallet-less obligation is a data anomaly that the
 *     policy treats as a denial.
 *
 *   - It does not use `Response::deny()` or `Response::allow()`. The
 *     ability returns a plain `bool` per §2.8.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D11                          — Legal-entity representatives
 *   Architecture v1.2.0 §3.3     — Obligation entity
 *   Architecture v1.2.0 §12      — Tenant isolation
 *   Architecture v1.2.0 §13      — RBAC integration
 *   Architecture v1.2.0 §13.2    — Policy layer (three policies registered)
 *   Architecture v1.2.0 §19      — Q6 resolution (no financial cascades)
 *   Step 5 (manifest) 2B.38      — This file
 *   Step 5 (manifest) 2B.2       — WalletServiceProvider registers this policy
 *   Step 7 v1.1.0 §A.1.1         — Permission slugs
 *   Step 7 v1.1.0 §C.1           — Nine-route API structure (no obligation
 *                                  endpoint addresses an existing obligation)
 *   Step 7 v1.1.0 §C.6           — Cross-tenant child-validation invariant
 *   §2.8 (conventions)           — Policy conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Policies
 * @version 1.0.0
 */
final class ObligationPolicy
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
    // view — access a single obligation
    // =========================================================================

    /**
     * Whether the caller may view the obligation.
     *
     * Delegates to `WalletPolicy::view()` on the obligation's parent
     * wallet. The caller can view the obligation if and only if the
     * caller can view the wallet the obligation belongs to.
     *
     * The parent wallet is read from the `wallet` relation on the
     * obligation model (2B.6). When the caller has eager-loaded the
     * relation, no additional query fires; when it has not, one query
     * fires. The policy does not force a load: the obligation-addressing
     * endpoints that will eventually call this ability (see the class
     * docblock §"Current call sites") pass an obligation that was
     * already resolved through the wallet-scoped repository
     * (`ObligationRepository::findForWallet()`, 2B.12), and those
     * repositories return a model whose wallet is reachable in one query.
     *
     * A missing parent wallet — an obligation with a null `wallet`
     * relation, indicating a broken referential invariant — is treated
     * as a denial, not an exception. See the class docblock §"What this
     * policy deliberately does NOT do".
     *
     * @param User       $user
     * @param Obligation $obligation
     * @return bool
     */
    public function view(User $user, Obligation $obligation): bool
    {
        $wallet = $obligation->wallet;

        if ($wallet === null) {
            return false;
        }

        return $this->walletPolicy->view($user, $wallet);
    }
}