<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Wallet\Policies;

use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Domains\Wallet\Models\Payment;

/**
 * Per-payment authorization delegates to WalletPolicy on the parent wallet; retain parent-wallet tenant checks.
 *
 * @package MaaleArna\Arna\Core\Domains\Wallet\Policies
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