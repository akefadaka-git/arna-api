<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;

/**
 * PaymentReceived
 *
 * Domain event dispatched when a payment is received into a wallet.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * `PaymentService::record()` (2B.17) dispatches this event after the
 * transaction that created the payment has committed, via
 * `DB::afterCommit()`. The single call site is the `store()` action of
 * `PaymentController` (2B.28), which handles route #6 of Step 7 v1.1.0
 * §C.1 (`POST /wallets/{wallet}/payments`).
 *
 * The event is gated on `config('wallet.audit.emit_events')` (2B.1). When
 * the config is false, the payment and its ledger entry are still
 * written; only the event dispatch is suppressed. See the class docblock
 * of `PaymentService` §"Transaction and event discipline".
 *
 * =============================================================================
 * What the event carries
 * =============================================================================
 * One property:
 *
 *     $payment  — the newly-created Payment model.
 *
 * The payment is hydrated and persisted at the time the event is
 * constructed. `PaymentService::record()` calls `$payment->refresh()`
 * before instantiating this event, so every attribute reflects the row
 * as committed — including the database-assigned `id`, the
 * `unapplied_amount` initialized to equal `amount`, and the
 * `received_at` timestamp written by the service.
 *
 * =============================================================================
 * The event is about the payment, not the ledger entry
 * =============================================================================
 * Recording a payment produces TWO rows inside one transaction:
 *
 *   1. A `Payment` row (2B.7) — the scoped business aggregate.
 *   2. A `WalletEntry` row (2B.10) of type `payment_received`, written
 *      by `WalletService::recordEntry()` (2B.15) via the service's
 *      pre-flight call.
 *
 * This event carries the PAYMENT, not the ledger entry. The distinction
 * is deliberate and mirrors the sibling `ObligationRecorded` (2B.41
 * §"The event is about the obligation, not the ledger entry"):
 *
 *   - The payment is the business fact the caller asked for; the ledger
 *     entry is the wallet-domain's internal record of the monetary
 *     movement. A listener that audits "what payments were received?"
 *     wants the payment.
 *
 *   - The ledger entry is reachable from the payment through the
 *     `wallet_entries.reference_type = 'Payment'` + `reference_id =
 *     payment.id` pair (Architecture §5.3). A listener that needs the
 *     ledger entry queries for it by reference.
 *
 *   - If a future CR requires a dedicated event for the ledger movement
 *     (e.g. an `EntryPosted` event dispatched by `recordEntry()` itself),
 *     it is added without changing this event's shape.
 *
 * =============================================================================
 * No component, no due date
 * =============================================================================
 * A payment is not component-tagged and has no due date (Architecture
 * §3.4, D109). Consequently:
 *
 *   - This event exposes NO `component()` accessor. Unlike
 *     `ObligationRecorded` (2B.41), whose event carries the component of
 *     the obligation it announced, a payment's economic meaning is
 *     established LATER by the allocations that settle specific
 *     obligations. At the moment of receipt, the payment has no
 *     component.
 *
 *   - This event exposes NO `dueAt()` accessor. Payments are received,
 *     not owed; the `wallet_payments` table has no `due_at` column
 *     (2B.51).
 *
 * The domain-specific accessor this event provides is `amount()` — the
 * single monetary fact of a payment at the moment of receipt.
 *
 * =============================================================================
 * Why the event carries the model, not an id
 * =============================================================================
 * The event exposes the full `Payment` model as a public readonly
 * property. A listener that needs only the id reads `$event->payment->id`;
 * a listener that needs the amount, the currency, or any other attribute
 * reads the corresponding property. The alternative — carrying only
 * scalar primitives — would force every listener to re-query the row,
 * defeating the purpose of dispatching the event after commit.
 *
 * Per the Event convention (§2.9), constructor parameters are models or
 * scalar primitives, never arrays of arrays; the model is the natural
 * unit of interest here.
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * `$payment` is `public readonly`. A listener MUST NOT attempt to mutate
 * the payment through this reference; mutations belong to the service
 * layer, and the model's `booted()` guard (2B.7 §"Immutability") rejects
 * mutations of the four immutable facts (currency, amount, received_at,
 * wallet_id) anyway. The `readonly` modifier enforces the binding-level
 * constraint at the language level.
 *
 * A listener that needs to correct a payment writes a compensating
 * operation (a reversal of the ledger entry, or a new payment) — it does
 * not edit this one. See Architecture §4.3.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry point
 * and integrates with Laravel's queued-event machinery. Phase 2B
 * dispatches this event synchronously through the synchronous Event
 * dispatcher; the event is not queued. If a future CR makes the audit
 * listener queued, the trait's `SerializesModels` behaviour applies
 * automatically to the `$payment` property.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * Registered in `WalletServiceProvider` (2B.2):
 *
 *     Event::listen(
 *         PaymentReceived::class,
 *         AuditWalletEvent::class,
 *     );
 *
 * The listener, `AuditWalletEvent` (2B.47), branches on the event class
 * via `match (true)` and writes an audit row through the Core
 * `AuditService`. A listener MUST NOT propagate exceptions to the
 * caller; the listener catches `Throwable` and logs, per the Listener
 * convention (§2.10).
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D110                         — Payment attribution
 *   Architecture v1.2.0 §3.4    — Payment entity
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §5.1    — Entry-type vocabulary
 *   Architecture v1.2.0 §5.3    — Permitted reference_type combinations
 *   Architecture v1.2.0 §6.2    — Payment projection
 *   Architecture v1.2.0 §6.4    — Overpayment and unapplied amounts
 *   Architecture v1.2.0 §14.1   — Event inventory (PaymentReceived)
 *   Step 5 (manifest) 2B.42     — This file
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (route #6)
 *   §2.9 (conventions)          — Event conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Events
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