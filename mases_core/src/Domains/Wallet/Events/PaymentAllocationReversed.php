<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Wallet\Models\PaymentAllocationReversal;

/**
 * PaymentAllocationReversed
 *
 * Domain event dispatched when a previously-recorded payment allocation
 * is reversed.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * `AllocationService::reverse()` (2B.18) dispatches this event after the
 * transaction that created the reversal has committed, via
 * `DB::afterCommit()`. The single call site is the `reverse()` action of
 * `AllocationController` (2B.29), which handles route #8 of Step 7
 * v1.1.0 §C.1
 * (`POST /wallets/{wallet}/allocations/{allocation}/reverse`).
 *
 * The event is gated on `config('wallet.audit.emit_events')` (2B.1). When
 * the config is false, the reversal row and the two projection updates
 * (payment.unapplied_amount, obligation.outstanding_amount) are still
 * written; only the event dispatch is suppressed. See the class docblock
 * of `AllocationService` §"Transaction and event discipline".
 *
 * =============================================================================
 * Relationship to the sibling PaymentAllocated (2B.43)
 * =============================================================================
 * This event is the SYMMETRIC COUNTERPART of `PaymentAllocated` (2B.43).
 * The two form an audit pair over the allocation lifecycle:
 *
 *     PaymentAllocated              — an allocation was recorded.
 *     PaymentAllocationReversed     — a portion of an allocation was undone.
 *
 * Both events reference the same underlying `PaymentAllocation` row: the
 * first through its own id (`allocation_id` = the row it announced), the
 * second through its parent's id (`allocation_id` = the parent allocation
 * being corrected). A listener that reconstructs the full attribution
 * history of a payment joins the two event streams on their respective
 * `payment_id` and `allocation_id` accessors.
 *
 * The two events carry DIFFERENT models:
 *
 *   - `PaymentAllocated` carries a `PaymentAllocation`.
 *   - `PaymentAllocationReversed` carries a `PaymentAllocationReversal`.
 *
 * The distinction is deliberate. A reversal is a distinct, immutable,
 * permanent record with its own schema (2B.9); collapsing it into the
 * parent allocation's event would lose the reversal's `reason`,
 * `reversed_at`, `reversed_by`, and `reversed_amount` — the metadata
 * that the reversal entity was introduced to preserve (Architecture §3.6,
 * amendment A1).
 *
 * =============================================================================
 * No ledger entry produced
 * =============================================================================
 * Like the allocation it corrects, this event is dispatched by an
 * operation that produces NO ledger entry:
 *
 *   - Allocations and their reversals are ATTRIBUTION records, not
 *     MONETARY movements (Architecture §5.1). Reversing an allocation
 *     does not move money; it changes which obligation the payment is
 *     considered to have settled.
 *
 *   - Consequence: the wallet's `current_balance` is NOT touched by the
 *     reversal (2B.18 §"No ledger entries"). The two projection columns
 *     that DO change — `wallet_payments.unapplied_amount` and
 *     `wallet_obligations.outstanding_amount` — are incremented, the
 *     opposite direction of the decrements performed when the original
 *     allocation was recorded.
 *
 * =============================================================================
 * What the event carries
 * =============================================================================
 * One property:
 *
 *     $reversal  — the newly-created PaymentAllocationReversal model.
 *
 * The reversal is hydrated and persisted at the time the event is
 * constructed. `AllocationService::reverse()` calls
 * `$reversal->refresh()` before instantiating this event, so every
 * attribute reflects the row as committed — including the
 * database-assigned `id`, the `reversed_amount` as supplied, the
 * `reason`, the `reversed_at` timestamp written by the service, and the
 * two foreign keys (`wallet_id`, `payment_allocation_id`).
 *
 * =============================================================================
 * Why the event carries the model, not an id
 * =============================================================================
 * The event exposes the full `PaymentAllocationReversal` model as a
 * public readonly property. A listener that needs only the id reads
 * `$event->reversal->id`; a listener that needs the reason, the actor,
 * or the amount reads the corresponding property. The alternative —
 * carrying only scalar primitives — would force every listener to
 * re-query the row, defeating the purpose of dispatching the event after
 * commit.
 *
 * Per the Event convention (§2.9), constructor parameters are models or
 * scalar primitives, never arrays of arrays; the model is the natural
 * unit of interest here.
 *
 * =============================================================================
 * Immutability
 * =============================================================================
 * `$reversal` is `public readonly`. A listener MUST NOT attempt to
 * mutate the reversal through this reference; mutations belong to the
 * service layer, and the model's `booted()` guard (2B.9
 * §"Full immutability") rejects ANY update attempt — a
 * `PaymentAllocationReversal` has no projection column, no mutable
 * metadata, and no lifecycle beyond creation.
 *
 * There is NO "reversal of a reversal" operation in Phase 2B
 * (Architecture §6.5 invariant 4, enforced by the absence of any method
 * that would accept a `PaymentAllocationReversal` as a target). A
 * listener that needs to correct a subsequent attribution error writes a
 * NEW allocation with a NEW allocation record — it does not reverse this
 * one.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry point
 * and integrates with Laravel's queued-event machinery. Phase 2B
 * dispatches this event synchronously through the synchronous Event
 * dispatcher; the event is not queued. If a future CR makes the audit
 * listener queued, the trait's `SerializesModels` behaviour applies
 * automatically to the `$reversal` property.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * Registered in `WalletServiceProvider` (2B.2):
 *
 *     Event::listen(
 *         PaymentAllocationReversed::class,
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
 *   Architecture v1.2.0 §3.6    — PaymentAllocationReversal entity (A1)
 *   Architecture v1.2.0 §5.1    — Allocations and reversals do not
 *                                 produce ledger entries
 *   Architecture v1.2.0 §6.1    — Obligation projection
 *   Architecture v1.2.0 §6.2    — Payment projection
 *   Architecture v1.2.0 §6.5    — Allocation-reversal invariants
 *   Architecture v1.2.0 §14.1   — Event inventory (PaymentAllocationReversed,
 *                                 amendment A9)
 *   Step 5 (manifest) 2B.44     — This file
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (route #8)
 *   §2.9 (conventions)          — Event conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Events
 * @version 1.0.0
 */
final class PaymentAllocationReversed
{
    use Dispatchable;

    /**
     * The reversal that was recorded.
     *
     * Hydrated and persisted at the time the event is constructed — the
     * service has already run `$reversal->refresh()` before this event is
     * instantiated, so every attribute reflects the row as committed.
     *
     * @var PaymentAllocationReversal
     */
    public readonly PaymentAllocationReversal $reversal;

    /**
     * Construct the event.
     *
     * The constructor takes the persisted `PaymentAllocationReversal`
     * instance. It performs no validation and no side effects; the
     * service has already established every invariant that governs
     * reversal before reaching this point.
     *
     * @param PaymentAllocationReversal $reversal  The newly-recorded reversal.
     */
    public function __construct(PaymentAllocationReversal $reversal)
    {
        $this->reversal = $reversal;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the recorded reversal.
     *
     * Convenience accessor for listeners that need only the identifier.
     * Equivalent to `$event->reversal->id`, but expresses intent at the
     * call site.
     *
     * @return int
     */
    public function reversalId(): int
    {
        return (int) $this->reversal->getKey();
    }

    /**
     * The id of the parent allocation being reversed.
     *
     * Read directly from the reversal's `payment_allocation_id` column,
     * which is an immutable fact of the row (2B.9 §"Full immutability").
     * No query is required.
     *
     * This id joins the reversal to the sibling `PaymentAllocated` event
     * (2B.43) for the same parent allocation: an audit listener that
     * reconstructs the history of a single attribution reads both events
     * keyed on this id. See the class docblock
     * §"Relationship to the sibling PaymentAllocated".
     *
     * @return int
     */
    public function allocationId(): int
    {
        return (int) $this->reversal->payment_allocation_id;
    }

    /**
     * The id of the wallet the reversal belongs to.
     *
     * Read directly from the reversal's `wallet_id` column, which is a
     * denormalized column populated at insert time (2B.9 §"Relations").
     * No query is required.
     *
     * The `wallet_id` column on `PaymentAllocationReversal` is
     * denormalized from the parent allocation (which itself denormalizes
     * from the payment/obligation). It enables direct wallet-scoped
     * queries and gives listeners a single, unambiguous wallet
     * reference.
     *
     * @return int
     */
    public function walletId(): int
    {
        return (int) $this->reversal->wallet_id;
    }

    /**
     * The tenant id under which the reversal was recorded.
     *
     * Resolved transitively through the reversal's parent wallet:
     * `reversal.wallet_id → wallet.tenant_id`. The
     * `PaymentAllocationReversal` model does not carry a `tenant_id`
     * column of its own (2B.9 §"No currency column" and the model's
     * general dependency on its ancestry for scope).
     *
     * Returns `null` if the wallet relation is not loaded and cannot be
     * resolved. In every legitimate Phase 2B dispatch, the relation is
     * reachable: `AllocationService::reverse()` holds the `Wallet`
     * instance in hand when it constructs this event, and the reversal's
     * `wallet_id` points at it. A listener that needs a non-null tenant
     * should load the relation first
     * (`$event->reversal->loadMissing('wallet')`) or handle the null
     * case, mirroring the pattern on the sibling events
     * `ObligationRecorded` (2B.41), `PaymentReceived` (2B.42), and
     * `PaymentAllocated` (2B.43), and on the 2A `CertificateIssued`
     * event.
     *
     * @return int|null
     */
    public function tenantId(): ?int
    {
        $wallet = $this->reversal->wallet;

        if ($wallet === null) {
            return null;
        }

        return (int) $wallet->tenant_id;
    }

    /**
     * The amount that was reversed.
     *
     * Read directly from the reversal's `reversed_amount` column, which
     * is an immutable fact of the row and is set at creation time
     * (2B.18 §"reverse"). The value is a positive decimal string at
     * scale 4.
     *
     * Provided as a convenience for listeners that audit reversals by
     * amount without inspecting the model. Equivalent to
     * `$event->reversal->reversed_amount`.
     *
     * Note the difference from the parent allocation's `amount`: a
     * reversal records a specific reversed portion, which may be less
     * than the parent allocation's total. A parent allocation may have
     * multiple partial reversals over its lifetime; their sum cannot
     * exceed the parent's amount (Architecture §6.5 invariant 2).
     *
     * @return string
     */
    public function reversedAmount(): string
    {
        return (string) $this->reversal->reversed_amount;
    }

    /**
     * The reason the reversal was recorded.
     *
     * Read directly from the reversal's `reason` column, which is
     * NOT NULL in the schema and is required by the service (2B.18
     * §"assertReasonIsProvided"). It is the metadata that distinguishes
     * a reversal from a mere mutation (Architecture §3.6); a listener
     * that audits corrections typically records this value alongside the
     * event.
     *
     * Provided as a convenience for listeners that audit reversals
     * without inspecting the model. Equivalent to
     * `$event->reversal->reason`.
     *
     * @return string
     */
    public function reason(): string
    {
        return (string) $this->reversal->reason;
    }
}