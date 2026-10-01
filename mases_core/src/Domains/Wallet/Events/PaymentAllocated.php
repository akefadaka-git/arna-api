<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Wallet\Models\PaymentAllocation;

/**
 * PaymentAllocated
 *
 * Domain event dispatched when a payment is allocated to an obligation.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * `AllocationService::allocate()` (2B.18) dispatches this event after the
 * transaction that created the allocation has committed, via
 * `DB::afterCommit()`. The single call site is the `store()` action of
 * `AllocationController` (2B.29), which handles route #7 of Step 7
 * v1.1.0 §C.1 (`POST /wallets/{wallet}/allocations`).
 *
 * The event is gated on `config('wallet.audit.emit_events')` (2B.1). When
 * the config is false, the allocation row and the two projection updates
 * (payment.unapplied_amount, obligation.outstanding_amount) are still
 * written; only the event dispatch is suppressed. See the class docblock
 * of `AllocationService` §"Transaction and event discipline".
 *
 * =============================================================================
 * No ledger entry produced
 * =============================================================================
 * Unlike the sibling events `ObligationRecorded` (2B.41) and
 * `PaymentReceived` (2B.42), this event is dispatched from an operation
 * that produces NO ledger entry:
 *
 *   - Allocations are ATTRIBUTION records, not MONETARY movements
 *     (Architecture §5.1). They reclassify an existing payment against
 *     an existing obligation; they do not move money.
 *
 *   - Consequence: the wallet's `current_balance` is NOT touched by the
 *     allocation (2B.18 §"No ledger entries"). The two projection columns
 *     that DO change — `wallet_payments.unapplied_amount` and
 *     `wallet_obligations.outstanding_amount` — are updated on the
 *     parent rows, not on the wallet ledger.
 *
 * A listener that audits "what money moved?" looks at
 * `ObligationRecorded` and `PaymentReceived`; a listener that audits
 * "how was the money attributed?" looks here. The two audit streams
 * answer different questions and can be joined through the payment id
 * and obligation id both events carry.
 *
 * =============================================================================
 * What the event carries
 * =============================================================================
 * One property:
 *
 *     $allocation  — the newly-created PaymentAllocation model.
 *
 * The allocation is hydrated and persisted at the time the event is
 * constructed. `AllocationService::allocate()` calls
 * `$allocation->refresh()` before instantiating this event, so every
 * attribute reflects the row as committed — including the
 * database-assigned `id`, the `amount` as supplied, the `allocated_at`
 * timestamp written by the service, and the three foreign keys.
 *
 * =============================================================================
 * Why the event carries the model, not an id
 * =============================================================================
 * The event exposes the full `PaymentAllocation` model as a public
 * readonly property. A listener that needs only the id reads
 * `$event->allocation->id`; a listener that needs the amount, the parent
 * payment, or the parent obligation reads the corresponding property.
 * The alternative — carrying only scalar primitives — would force every
 * listener to re-query the row, defeating the purpose of dispatching the
 * event after commit.
 *
 * Per the Event convention (§2.9), constructor parameters are models or
 * scalar primitives, never arrays of arrays; the model is the natural
 * unit of interest here.
 *
 * =============================================================================
 * Immutability
 * =============================================================================
 * `$allocation` is `public readonly`. A listener MUST NOT attempt to
 * mutate the allocation through this reference; mutations belong to the
 * service layer, and the model's `booted()` guard (2B.8
 * §"Immutability") rejects ANY update attempt — `PaymentAllocation` is
 * fully immutable once recorded, with no projection column and no
 * mutable field.
 *
 * A listener that needs to correct an allocation records a
 * `PaymentAllocationReversal` (2B.9) against it. That correction is
 * announced by the sibling event `PaymentAllocationReversed` (2B.44),
 * not by re-dispatching this one.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry point
 * and integrates with Laravel's queued-event machinery. Phase 2B
 * dispatches this event synchronously through the synchronous Event
 * dispatcher; the event is not queued. If a future CR makes the audit
 * listener queued, the trait's `SerializesModels` behaviour applies
 * automatically to the `$allocation` property.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * Registered in `WalletServiceProvider` (2B.2):
 *
 *     Event::listen(
 *         PaymentAllocated::class,
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
 *   Architecture v1.2.0 §3.5    — PaymentAllocation entity
 *   Architecture v1.2.0 §5.1    — Allocations do not produce ledger entries
 *   Architecture v1.2.0 §6.1    — Obligation projection
 *   Architecture v1.2.0 §6.2    — Payment projection
 *   Architecture v1.2.0 §6.3    — Allocation invariants
 *   Architecture v1.2.0 §14.1   — Event inventory (PaymentAllocated)
 *   Step 5 (manifest) 2B.43     — This file
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (route #7)
 *   §2.9 (conventions)          — Event conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Events
 * @version 1.0.0
 */
final class PaymentAllocated
{
    use Dispatchable;

    /**
     * The allocation that was recorded.
     *
     * Hydrated and persisted at the time the event is constructed — the
     * service has already run `$allocation->refresh()` before this event
     * is instantiated, so every attribute reflects the row as committed.
     *
     * @var PaymentAllocation
     */
    public readonly PaymentAllocation $allocation;

    /**
     * Construct the event.
     *
     * The constructor takes the persisted `PaymentAllocation` instance.
     * It performs no validation and no side effects; the service has
     * already established every invariant that governs allocation before
     * reaching this point.
     *
     * @param PaymentAllocation $allocation  The newly-recorded allocation.
     */
    public function __construct(PaymentAllocation $allocation)
    {
        $this->allocation = $allocation;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the recorded allocation.
     *
     * Convenience accessor for listeners that need only the identifier.
     * Equivalent to `$event->allocation->id`, but expresses intent at the
     * call site.
     *
     * @return int
     */
    public function allocationId(): int
    {
        return (int) $this->allocation->getKey();
    }

    /**
     * The id of the wallet the allocation belongs to.
     *
     * Read directly from the allocation's `wallet_id` column, which is a
     * denormalized column populated at insert time (2B.8 §"Relations").
     * No query is required.
     *
     * The `wallet_id` column on `PaymentAllocation` is denormalized from
     * the parent payment (which itself equals the obligation's wallet by
     * the §6.3 invariant). It enables direct wallet-scoped queries and
     * gives listeners a single, unambiguous wallet reference.
     *
     * @return int
     */
    public function walletId(): int
    {
        return (int) $this->allocation->wallet_id;
    }

    /**
     * The id of the payment being applied.
     *
     * Read directly from the allocation's `payment_id` column, which is
     * an immutable fact of the row (2B.8 §"Immutability"). No query is
     * required.
     *
     * The payment id joins this event to the sibling `PaymentReceived`
     * event (2B.42) for the same payment, allowing an audit listener to
     * correlate "the payment arrived" with "the payment was applied to
     * obligation X".
     *
     * @return int
     */
    public function paymentId(): int
    {
        return (int) $this->allocation->payment_id;
    }

    /**
     * The id of the obligation being settled.
     *
     * Read directly from the allocation's `obligation_id` column, which
     * is an immutable fact of the row (2B.8 §"Immutability"). No query
     * is required.
     *
     * The obligation id joins this event to the sibling
     * `ObligationRecorded` event (2B.41) for the same obligation,
     * allowing an audit listener to correlate "the obligation was
     * recorded" with "the obligation was settled by payment Y".
     *
     * @return int
     */
    public function obligationId(): int
    {
        return (int) $this->allocation->obligation_id;
    }

    /**
     * The tenant id under which the allocation was recorded.
     *
     * Resolved transitively through the allocation's parent wallet:
     * `allocation.wallet_id → wallet.tenant_id`. The `PaymentAllocation`
     * model does not carry a `tenant_id` column of its own (2B.8
     * §"No currency column" and the model's general dependency on its
     * parents for scope).
     *
     * Returns `null` if the wallet relation is not loaded and cannot be
     * resolved. In every legitimate Phase 2B dispatch, the relation is
     * reachable: `AllocationService::allocate()` holds the `Wallet`
     * instance in hand when it constructs this event, and the
     * allocation's `wallet_id` points at it. A listener that needs a
     * non-null tenant should load the relation first
     * (`$event->allocation->loadMissing('wallet')`) or handle the null
     * case, mirroring the pattern on the sibling events
     * `ObligationRecorded` (2B.41) and `PaymentReceived` (2B.42), and on
     * the 2A `CertificateIssued` event.
     *
     * @return int|null
     */
    public function tenantId(): ?int
    {
        $wallet = $this->allocation->wallet;

        if ($wallet === null) {
            return null;
        }

        return (int) $wallet->tenant_id;
    }

    /**
     * The amount that was allocated.
     *
     * Read directly from the allocation's `amount` column, which is an
     * immutable fact of the row and is set at creation time (2B.18
     * §"allocate"). The value is a positive decimal string at scale 4.
     *
     * Provided as a convenience for listeners that audit allocations by
     * amount without inspecting the model. Equivalent to
     * `$event->allocation->amount`.
     *
     * @return string
     */
    public function amount(): string
    {
        return (string) $this->allocation->amount;
    }
}