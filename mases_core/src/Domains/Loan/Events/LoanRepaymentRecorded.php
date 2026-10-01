<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use MaaleArna\MASES\Core\Domains\Wallet\Models\PaymentAllocation;

/**
 * LoanRepaymentRecorded
 *
 * Domain event dispatched when a repayment is recorded against a loan —
 * the moment the borrower's payment is accepted into the Wallet, applied
 * to specific loan-linked obligations, and reflected in the loan's
 * persisted projections.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by `LoanRepaymentService::record()` (A.31) AFTER
 * the transaction that recorded the Wallet payment, applied the
 * allocations, updated the loan's `settled_amount`, recomputed its three
 * outstanding projections, settled any installments that became fully
 * settled, and — when applicable — transitioned the loan to `settled` has
 * committed.
 *
 * Because the repayment is not tied to a STATE TRANSITION of the loan
 * itself (the loan may or may not change status depending on whether the
 * repayment triggers settlement), the event is NOT routed through
 * `LoanStateMachine::transition()` (A.26). Instead it is registered
 * directly by the service via `DB::afterCommit()`, gated on
 * `config('loan.audit.emit_events')` (A.1, default `true`).
 *
 * Consequence: the event fires once, and only once, per successful call
 * to `record()`. If the surrounding transaction rolls back — for any
 * reason, including a failure in the sibling settlement transition that
 * runs AFTER the projections are recomputed but BEFORE this event's
 * after-commit callback fires — the event is never dispatched, because
 * `DB::afterCommit()` fires only after the outermost transaction commits.
 *
 * A note on the sibling events that may fire from the SAME transaction:
 *
 *   - `LoanInstallmentSettled` (A.43) — one event per installment that
 *     became fully settled as a result of this repayment.
 *   - `LoanSettled` (A.48) — fires only if this repayment was the one
 *     that fully settled the loan, dispatched via the state machine's
 *     after-commit path for the `active → settled` (or `in_arrears →
 *     settled`, or `restructured → settled`) transition.
 *
 * The three events are complementary, not redundant: this event records
 * the REPAYMENT FACT itself; A.43 records the SETTLEMENT CONSEQUENCE at
 * the installment granularity; A.48 records the LOAN-LEVEL SETTLEMENT
 * milestone when the loan is fully repaid.
 *
 * =============================================================================
 * What the event carries, and the state of the loan at dispatch time
 * =============================================================================
 * Four properties:
 *
 *   $loan         — the loan instance held by
 *                   `LoanRepaymentService::record()` at the moment the
 *                   event was constructed.
 *
 *   $payment      — the Wallet `Payment` instance that
 *                   `PaymentService::record()` created for this repayment.
 *
 *   $allocations  — the list of Wallet `PaymentAllocation` instances that
 *                   `AllocationService::allocate()` created, in the order
 *                   the service applied them.
 *
 *   $amount       — the repayment amount, as a decimal string at scale 4.
 *
 * Under the frozen A.31 flow, the carried `$loan` reflects the post-
 * repayment state that the service wrote BEFORE constructing the event:
 *
 *   - `settled_amount` — increased by `$amount`.
 *   - `outstanding_principal`,
 *   - `outstanding_interest`,
 *   - `outstanding_penalty` — recomputed from the authoritative Wallet
 *     obligation state by
 *     `LoanRepaymentService::recomputeOutstandingProjections()`.
 *   - `status` — reflects the outcome of the sibling settlement check:
 *     if this repayment fully settled the loan, `status` is
 *     `LoanStatus::Settled`; otherwise it is unchanged from its
 *     pre-repayment value (typically `Active` or `InArrears`).
 *
 * The carried `$loan` therefore exposes the post-repayment projections
 * that a listener can use to answer "what is the loan's outstanding
 * balance after this repayment?" without a re-query.
 *
 * =============================================================================
 * The repayment fact versus the settlement consequences
 * =============================================================================
 * The event's semantics are intentionally narrow: it records THAT a
 * repayment happened, and the concrete Wallet-side and Loan-side facts
 * that the repayment produced. It does NOT carry per-installment
 * settlement flags or a "loan was settled" boolean.
 *
 * The rationale: a repayment can have MANY downstream consequences, and
 * every consequence has its own dedicated event with its own precise
 * semantics. Bundling them into a single "repayment" event would:
 *
 *   1. Duplicate information that `LoanInstallmentSettled` (A.43) and
 *      `LoanSettled` (A.48) already carry authoritatively.
 *
 *   2. Force every listener that only cares about the repayment fact to
 *      filter out consequence data it does not need.
 *
 *   3. Blur the audit trail: the audit stream should show, in order, the
 *      repayment, the settlement of each affected installment, and the
 *      loan-level settlement (if any). A listener reconstructing "what
 *      happened when" reads the events in the order they were
 *      dispatched, without having to interpret a nested structure.
 *
 * A listener that needs the settlement consequences subscribes to A.43
 * and A.48 separately. A listener that needs only the repayment fact
 * subscribes to this event alone.
 *
 * =============================================================================
 * Why the event carries the Wallet Payment and Allocation models
 * =============================================================================
 * The Wallet-side `Payment` and `PaymentAllocation` instances are exposed
 * as public readonly properties, alongside the Loan model.
 *
 * The `Payment` instance is the authoritative record of the monetary fact
 * that this repayment created on the Wallet: its `amount`, its
 * `received_at`, its `reference_type` and `reference_id`, and its
 * `unapplied_amount` (the amount not yet allocated). A listener that
 * audits the cash-in side of the repayment reads it directly.
 *
 * The `$allocations` list is the authoritative record of HOW the payment
 * was applied: which obligations were reduced, in what order, and by how
 * much. Each `PaymentAllocation` carries the obligation id, the amount,
 * the resulting obligation state, and the moment of the allocation. A
 * listener that audits the obligation-reduction side of the repayment
 * reads the list.
 *
 * Carrying these models directly (rather than re-querying them by id)
 * follows the same pattern as carrying the Loan model itself: the event
 * is the CORRELATION POINT between the Loan-domain and Wallet-domain
 * facts of the same business operation. A listener that reconstructs the
 * full picture from a single event has everything it needs.
 *
 * =============================================================================
 * Why the event does NOT carry the actor
 * =============================================================================
 * Unlike `LoanInstallmentSettled` (A.43), `LoanEnteredArrears` (A.44),
 * `LoanPenaltyRecorded` (A.45), and `LoanWrittenOff` (A.47), this event
 * does NOT carry an `$actorUserId` property.
 *
 * The repayment actor is preserved in the Wallet's audit trail:
 * `LoanRepaymentService::record()` (A.31) passes `$actorUserId` to
 * `PaymentService::record(..., createdBy: $actorUserId)` and to
 * `AllocationService::allocate(..., createdBy: $actorUserId)`. Both the
 * Wallet `Payment` instance and every `PaymentAllocation` instance carry
 * the actor on their own `created_by` column. A listener that needs the
 * actor reads it from `$event->payment->created_by` or from any entry in
 * `$event->allocations`.
 *
 * This mirrors the pattern established by `LoanApproved` (A.39) and
 * `LoanDisbursed` (A.41), whose actors are preserved on the loan row and
 * on the Wallet obligation respectively, and are therefore not carried
 * separately.
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * All four properties are `public readonly`. A listener MUST NOT attempt
 * to mutate any of the carried models; mutations belong to the service
 * layer, and any write from a listener would run outside the service's
 * transaction boundary. The `readonly` modifier enforces this at the
 * language level.
 *
 * The `$allocations` array itself is `readonly` as a property binding, and
 * the array is passed by value at construction. A listener cannot mutate
 * the array's contents; it cannot append, remove, or replace elements.
 * The individual `PaymentAllocation` instances are mutable by their own
 * API (fill/save), but a listener MUST NOT use that mutability.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry point
 * and integrates with Laravel's queued-event machinery. Phase 2C
 * dispatches this event synchronously through the synchronous Event
 * dispatcher; the event is not queued.
 *
 * Because the event carries not one but THREE model instances (the Loan,
 * the Payment, and the array of PaymentAllocations), the serialization
 * posture deserves an explicit note: if a future Change Request makes any
 * listener queued, that CR must handle the serialization of all three
 * model types and of the array. `SerializesModels` handles the scalar
 * model-typed properties; the array-of-models requires either a custom
 * `__serialize` implementation or a refactor of the event to carry
 * allocation ids rather than allocation instances.
 *
 * Phase 2C does not adopt `SerializesModels`; a future CR that queues a
 * listener would need to address this explicitly.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * Registered in `LoanServiceProvider::registerEventListeners()` (A.85)
 * against the single `AuditLoanEvent` listener (A.51):
 *
 *     Event::listen(
 *         LoanRepaymentRecorded::class,
 *         AuditLoanEvent::class,
 *     );
 *
 * The listener branches on the event class via `match (true)` and writes
 * an audit row through the Core `AuditService`. A listener MUST NOT
 * propagate exceptions to the caller; per the convention established by
 * `AuditShareholderEvent` (2A.35) and `AuditWalletEvent` (2B.47), the
 * listener catches `Throwable`, logs, and returns.
 *
 * The event's audit event-type string, frozen by the manifest, is
 * `'loan.repayment.recorded'`.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9, D110, D133, B1,
 *                                                  B2, B3
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §1.3
 *   Phase 2C A.18                                — `Loan`
 *   Phase 2C A.26                                — `LoanStateMachine`
 *                                                  (for the sibling
 *                                                  settlement transition)
 *   Phase 2C A.31                                — `LoanRepaymentService`
 *                                                  (the sole constructor
 *                                                  caller)
 *   Phase 2C A.41                                — `LoanDisbursed` (the
 *                                                  preceding event in
 *                                                  the lifecycle)
 *   Phase 2C A.43                                — `LoanInstallmentSettled`
 *                                                  (the sibling event
 *                                                  for settlement
 *                                                  consequences)
 *   Phase 2C A.48                                — `LoanSettled` (the
 *                                                  loan-level settlement
 *                                                  event)
 *   Phase 2C A.51                                — `AuditLoanEvent`
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *   Phase 2B 2B.17                               — `PaymentService`
 *   Phase 2B 2B.18                               — `AllocationService`
 *   Phase 2B `Payment`                           — the Wallet payment
 *                                                  model
 *   Phase 2B `PaymentAllocation`                 — the Wallet allocation
 *                                                  model
 *   Phase 2A `ShareholderRegistered`             — the event-class template
 *   Phase 2B `WalletOpened`                      — the dispatch pattern
 *   Step 5 (manifest) A.42                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Events
 * @version 1.0.0
 */
final class LoanRepaymentRecorded
{
    use Dispatchable;

    /**
     * The loan against which the repayment was recorded.
     *
     * The instance was constructed by `LoanRepaymentService::record()`
     * (A.31) AFTER the loan's projections were updated and after the
     * sibling settlement check ran. Its `status` property reflects the
     * outcome: `LoanStatus::Settled` if this repayment fully settled the
     * loan, otherwise the status the loan had before the repayment
     * (typically `LoanStatus::Active` or `LoanStatus::InArrears`). Its
     * three outstanding projections reflect the state immediately after
     * the payment and allocations were applied.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var Loan
     */
    public readonly Loan $loan;

    /**
     * The Wallet Payment instance created for this repayment.
     *
     * Created by `PaymentService::record()` (Phase 2B, 2B.17) earlier in
     * the same transaction. Carries the payment's `amount`,
     * `received_at`, `reference_type = 'LoanRepayment'`, `reference_id =
     * (string) $loan->id`, `unapplied_amount`, and `created_by`.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var Payment
     */
    public readonly Payment $payment;

    /**
     * The Wallet PaymentAllocation instances created for this repayment,
     * in the order the service applied them.
     *
     * Each entry is a `PaymentAllocation` (Phase 2B, 2B.18) representing
     * one application of a portion of `$payment` to one specific Wallet
     * obligation. The sum of the entries' amounts equals the allocated
     * portion of `$payment->amount` (i.e. `$payment->amount` minus
     * `$payment->unapplied_amount`).
     *
     * The array is a proper list (`array<int, PaymentAllocation>` with
     * keys `0..N-1`) in the order the service recorded the allocations,
     * which is the order of the caller-supplied allocation instructions.
     *
     * Read-only as a property binding and passed by value; the array
     * itself cannot be mutated by a listener. The individual
     * `PaymentAllocation` instances are mutable by their own API, but a
     * listener MUST NOT use that mutability.
     *
     * @var array<int, PaymentAllocation>
     */
    public readonly array $allocations;

    /**
     * The repayment amount, as a decimal string at scale 4.
     *
     * This is the same value passed by the caller to
     * `LoanRepaymentService::record()` (A.31), and it equals
     * `$payment->amount`. It is carried as a separate scalar so that a
     * listener that needs only the amount does not have to reach through
     * the Payment model.
     *
     * Read-only.
     *
     * @var string
     */
    public readonly string $amount;

    /**
     * Create a new event instance.
     *
     * The constructor takes the loan, the Wallet Payment that was
     * recorded, the list of Wallet PaymentAllocations that were applied,
     * and the repayment amount. The recording actor is intentionally NOT
     * a parameter — see the class docblock §"Why the event does NOT carry
     * the actor".
     *
     * @param Loan                      $loan         The loan that
     *                                                received the
     *                                                repayment.
     * @param Payment                   $payment      The Wallet payment
     *                                                created for the
     *                                                repayment.
     * @param array<int, PaymentAllocation> $allocations The Wallet
     *                                                allocations
     *                                                applied, in order.
     * @param string                    $amount       The repayment
     *                                                amount, as a
     *                                                decimal string at
     *                                                scale 4.
     */
    public function __construct(
        Loan $loan,
        Payment $payment,
        array $allocations,
        string $amount,
    ) {
        $this->loan        = $loan;
        $this->payment     = $payment;
        $this->allocations = $allocations;
        $this->amount      = $amount;
    }

    // =========================================================================
    // Convenience accessors — loan
    // =========================================================================

    /**
     * The primary key of the loan that received the repayment.
     *
     * @return int
     */
    public function loanId(): int
    {
        return (int) $this->loan->getKey();
    }

    /**
     * The tenant id under which the loan was repaid.
     *
     * Read directly from the loan's `tenant_id` column.
     *
     * @return int
     */
    public function tenantId(): int
    {
        return (int) $this->loan->tenant_id;
    }

    /**
     * The id of the borrower (shareholder) who made the repayment.
     *
     * Read directly from the loan's `borrower_shareholder_id` column.
     * Provided as a convenience for listeners that audit repayments at
     * the borrower level without loading the loan's `borrower` relation.
     *
     * @return int
     */
    public function borrowerId(): int
    {
        return (int) $this->loan->borrower_shareholder_id;
    }

    /**
     * The id of the loan product under which the repaid loan was
     * originated.
     *
     * Read directly from the loan's `loan_product_id` column.
     *
     * @return int
     */
    public function loanProductId(): int
    {
        return (int) $this->loan->loan_product_id;
    }

    // =========================================================================
    // Convenience accessors — Wallet payment
    // =========================================================================

    /**
     * The primary key of the Wallet Payment created for this repayment.
     *
     * @return int
     */
    public function paymentId(): int
    {
        return (int) $this->payment->getKey();
    }

    /**
     * The primary key of the Wallet that received the payment.
     *
     * Read directly from the Payment's `wallet_id` column. Note that this
     * is also the borrower's Wallet; `LoanRepaymentService::record()`
     * (A.31) resolves it via the borrower.
     *
     * @return int
     */
    public function walletId(): int
    {
        return (int) $this->payment->wallet_id;
    }

    /**
     * The amount of the payment not yet allocated to any obligation.
     *
     * Read directly from the Payment's `unapplied_amount` column. For a
     * well-formed repayment whose allocation instructions sum exactly to
     * the payment amount (which A.31 enforces), this is `"0.0000"`.
     *
     * @return string
     */
    public function unappliedAmount(): string
    {
        return (string) $this->payment->unapplied_amount;
    }

    /**
     * The user who recorded the repayment, or null for a system-recorded
     * repayment.
     *
     * Read from the Payment's `created_by` column. This is the recovery
     * path for the actor, since the event does not carry `$actorUserId`
     * directly. See the class docblock §"Why the event does NOT carry the
     * actor".
     *
     * @return int|null
     */
    public function recordedByUserId(): ?int
    {
        $createdBy = $this->payment->created_by;

        return $createdBy !== null ? (int) $createdBy : null;
    }

    /**
     * The moment the payment was received, in ISO 8601 form, or null if
     * the received-at timestamp is unset.
     *
     * Read directly from the Payment's `received_at` column. The value is
     * guaranteed to be non-null by the time the event is dispatched
     * (`PaymentService::record()` sets it), but the null branch is
     * defensive.
     *
     * @return string|null
     */
    public function receivedAt(): ?string
    {
        return $this->payment->received_at?->toIso8601String();
    }

    // =========================================================================
    // Convenience accessors — amount and allocations
    // =========================================================================

    /**
     * The repayment amount, as a decimal string at scale 4.
     *
     * Mirrors the public readonly `$amount` property; provided so that
     * listeners that prefer a method call over a property read have a
     * consistent entry point.
     *
     * @return string
     */
    public function amount(): string
    {
        return $this->amount;
    }

    /**
     * The number of Wallet allocations applied by this repayment.
     *
     * Equivalent to `count($event->allocations)`. Provided for listeners
     * that need only the count (for a summary audit row) and would
     * otherwise call `count()` themselves.
     *
     * @return int
     */
    public function allocationCount(): int
    {
        return count($this->allocations);
    }

    /**
     * The primary keys of the Wallet PaymentAllocations created by this
     * repayment, in the order the service recorded them.
     *
     * Convenience accessor for listeners that need the allocation ids
     * without iterating the allocation instances. The order matches the
     * order of `$event->allocations`.
     *
     * @return list<int>
     */
    public function allocationIds(): array
    {
        return array_map(
            static fn (PaymentAllocation $a): int => (int) $a->getKey(),
            $this->allocations,
        );
    }

    /**
     * The primary keys of the Wallet obligations that this repayment
     * reduced, in the order the service applied the allocations.
     *
     * Convenience accessor for listeners that need the affected
     * obligation ids without iterating the allocation instances. A
     * listener that needs the amounts must iterate `$event->allocations`
     * instead.
     *
     * @return list<int>
     */
    public function allocatedObligationIds(): array
    {
        return array_map(
            static fn (PaymentAllocation $a): int => (int) $a->obligation_id,
            $this->allocations,
        );
    }
}