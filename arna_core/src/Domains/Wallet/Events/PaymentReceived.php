<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Wallet\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\Arna\Core\Domains\Wallet\Models\Payment;

/**
 * Dispatched after payment recording commits; disabling event emission never suppresses the payment or ledger write.
 *
 * @package MaaleArna\Arna\Core\Domains\Wallet\Events
 * @version 1.0.0
 */
final class PaymentReceived
{
    use Dispatchable;

    /**
     * The payment that was received.
     *
     * Hydrated and persisted at the time the event is constructed — the
     * service has already run `$payment->refresh()` before this event is
     * instantiated, so every attribute reflects the row as committed.
     *
     * @var Payment
     */
    public readonly Payment $payment;

    /**
     * Construct the event.
     *
     * The constructor takes the persisted `Payment` instance. It performs
     * no validation and no side effects; the service has already
     * established every invariant that governs receipt before reaching
     * this point.
     *
     * @param Payment $payment  The newly-received payment.
     */
    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the received payment.
     *
     * Convenience accessor for listeners that need only the identifier.
     * Equivalent to `$event->payment->id`, but expresses intent at the
     * call site.
     *
     * @return int
     */
    public function paymentId(): int
    {
        return (int) $this->payment->getKey();
    }

    /**
     * The id of the wallet the payment was received into.
     *
     * Read directly from the payment's `wallet_id` column, which is an
     * immutable fact of the row (2B.7 §"Immutability"). No query is
     * required.
     *
     * @return int
     */
    public function walletId(): int
    {
        return (int) $this->payment->wallet_id;
    }

    /**
     * The tenant id under which the payment was received.
     *
     * Resolved transitively through the payment's parent wallet:
     * `payment.wallet_id → wallet.tenant_id`. The `Payment` model does
     * not carry a `tenant_id` column of its own (2B.7 §"What this model
     * deliberately does NOT do" — tenant scope is inherited through the
     * wallet).
     *
     * Returns `null` if the wallet relation is not loaded and cannot be
     * resolved. In every legitimate Phase 2B dispatch, the relation is
     * reachable: `PaymentService::record()` holds the `Wallet` instance
     * in hand when it constructs this event, and the payment's
     * `wallet_id` points at it. A listener that needs a non-null tenant
     * should load the relation first
     * (`$event->payment->loadMissing('wallet')`) or handle the null
     * case, mirroring the pattern on the sibling `ObligationRecorded`
     * (2B.41 §"tenantId") and on the 2A `CertificateIssued` event.
     *
     * Contrast with `WalletOpened::tenantId()` (2B.40), which returns
     * `int` unconditionally because the `Wallet` model carries
     * `tenant_id` directly. The nullability here reflects the payment's
     * transitive scope, not a data anomaly.
     *
     * @return int|null
     */
    public function tenantId(): ?int
    {
        $wallet = $this->payment->wallet;

        if ($wallet === null) {
            return null;
        }

        return (int) $wallet->tenant_id;
    }

    /**
     * The amount of the received payment.
     *
     * Read directly from the payment's `amount` column, which is an
     * immutable fact of the row and is set at creation time (2B.17
     * §"record"). The value is a positive decimal string at scale 4.
     *
     * Provided as a convenience for listeners that audit payments by
     * amount without inspecting the model. Equivalent to
     * `$event->payment->amount`.
     *
     * Unlike the sibling `ObligationRecorded::component()` accessor
     * (2B.41), which returns a domain enum, this accessor returns a
     * scalar string — a payment has no component to expose (see the
     * class docblock §"No component, no due date").
     *
     * @return string
     */
    public function amount(): string
    {
        return (string) $this->payment->amount;
    }
}