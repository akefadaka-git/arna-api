<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Wallet\Policies;

use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Domains\Wallet\Models\Obligation;

/**
 * Per-obligation authorization delegates to WalletPolicy on the parent wallet; retain parent-wallet tenant checks.
 *
 * @package MaaleArna\Arna\Core\Domains\Wallet\Policies
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