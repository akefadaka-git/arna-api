<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;

/**
 * LoanSettled
 *
 * Domain event dispatched when a loan's every non-superseded installment
 * has been fully settled and the loan transitions to the `settled` state —
 * the moment the borrower has discharged the entire scheduled repayment
 * and the loan becomes functionally complete.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by `LoanRepaymentService::maybeSettleLoan()`
 * (A.31) AFTER the transaction that recorded a repayment, applied its
 * allocations, updated the loan's projections, and settled every
 * installment whose remaining obligations were fully discharged has
 * committed.
 *
 * Because the settlement IS tied to a state transition
 * (`active → settled`, `in_arrears → settled`, or
 * `restructured → settled`), the event is registered via
 * `LoanStateMachine::transition()` (A.26)'s `$afterCommitEvent`
 * parameter. The state machine defers the `Event::dispatch()` call until
 * the outermost transaction commits, gated on
 * `config('loan.audit.emit_events')` (A.1, default `true`).
 *
 * Consequence: the event fires once, and only once, per loan. If the
 * surrounding transaction rolls back, the event is never dispatched.
 *
 * Because `settled` has exactly ONE outgoing transition (to `closed`, per
 * the frozen C4 matrix in A.2), a loan cannot re-enter `settled` after
 * leaving it. The event is therefore a ONCE-PER-LOAN milestone.
 *
 * =============================================================================
 * The three inbound paths
 * =============================================================================
 * The frozen state machine (C4, A.2) permits three transitions INTO
 * `settled`:
 *
 *     active        → settled
 *     in_arrears    → settled
 *     restructured  → settled
 *
 * The first two are the practically common cases: a loan in good standing
 * reaches `settled` when its final installment is fully paid; a loan in
 * arrears reaches `settled` if the borrower makes good on the entire
 * outstanding balance (including overdue installments) before the loan is
 * restructured or written off.
 *
 * The third path — `restructured → settled` — is technically reachable
 * but rarely observed in practice: the restructure service (A.34)
 * transitions the loan from `restructured` to `active` in the same
 * transaction that writes the restructure, so no external reader observes
 * a loan in `restructured` state. If a loan is restructured and then
 * fully repaid, it will reach `settled` from `active`, not from
 * `restructured`.
 *
 * None of the three transitions requires a `transition_reason` per A.26's
 * `REASON_REQUIRED_TRANSITIONS`: the transition's meaning is fully
 * conveyed by its endpoints. `LoanRepaymentService::maybeSettleLoan()`
 * (A.31) passes `reason: null` to the state machine.
 *
 * =============================================================================
 * The `settled → closed` follow-up
 * =============================================================================
 * `settled` is TERMINAL but not FINAL, per the frozen C4 freeze (A.2). It
 * has exactly one outgoing transition: `settled → closed`. A loan that
 * reaches `settled` remains in that state until an operator or an
 * automatic post-settlement step performs the closure.
 *
 * Phase 2C does NOT implement the closure operation. No service, command,
 * or endpoint in the frozen manifest performs a `settled → closed`
 * transition. The `LoanClosed` event (A.49) and its dispatcher are
 * therefore DEFERRED to a future Change Request; the event class is
 * delivered in A.49 as a reserved artefact, but no current code path
 * constructs it.
 *
 * The consequence for a listener registered for `LoanSettled` is: after
 * this event, the loan will remain in `settled` state indefinitely under
 * Phase 2C. A future CR that introduces closure would extend the lifecycle
 * with a subsequent `LoanClosed` event; the `LoanSettled` event's
 * semantics would not change.
 *
 * =============================================================================
 * What the event carries, and the state of the loan at dispatch time
 * =============================================================================
 * One property:
 *
 *   $loan  — the loan instance held by
 *            `LoanRepaymentService::maybeSettleLoan()` (A.31) at the
 *            moment the event was constructed.
 *
 * Under the frozen A.31 flow, the event is constructed with the `$loan`
 * instance that the service has been operating on throughout
 * `record()`. By the time the event is constructed:
 *
 *   - The loan's `settled_amount` reflects the total of all repayments.
 *   - The loan's three outstanding projections have been recomputed from
 *     the authoritative Wallet obligation state and are all `"0.0000"`.
 *   - Every non-superseded installment has `status =
 *     InstallmentStatus::Settled`.
 *
 * The carried `$loan`'s `status` property is STILL the pre-transition
 * value at event-construction time (typically `LoanStatus::Active` or
 * `LoanStatus::InArrears`). The `→ settled` transition is performed by
 * the state machine AFTER the event is constructed, on a fresh row-locked
 * instance returned by the transition; the event holds the
 * pre-transition instance.
 *
 * This mirrors the pattern of every prior event tied to a state
 * transition (A.38, A.39, A.40, A.41, A.44, A.46, A.47): the carried
 * loan retains its pre-transition status. A listener that needs the
 * loan's post-transition state reads the loan by id (which will be
 * `settled` by the time the after-commit dispatch fires), or relies on
 * the `loan_status_histories` row that the state machine appended.
 *
 * =============================================================================
 * Why the event does NOT carry the actor
 * =============================================================================
 * Unlike `LoanRejected` (A.40), `LoanInstallmentSettled` (A.43),
 * `LoanEnteredArrears` (A.44), `LoanPenaltyRecorded` (A.45),
 * `LoanRestructured` (A.46), and `LoanWrittenOff` (A.47), this event does
 * NOT carry an `$actorUserId` property.
 *
 * The reasoning follows the pattern established by `LoanApproved` (A.39)
 * and `LoanDisbursed` (A.41):
 *
 *   - The actor of the repayment that triggered the settlement is
 *     preserved on the Wallet side: `LoanRepaymentService::record()`
 *     (A.31) passes `$actorUserId` to `PaymentService::record(...,
 *     createdBy: $actorUserId)` and to
 *     `AllocationService::allocate(..., createdBy: $actorUserId)`. Both
 *     the Wallet `Payment` and every `PaymentAllocation` carry the actor
 *     on their own `created_by` column.
 *
 *   - The sibling `LoanRepaymentRecorded` (A.42) event, which fires from
 *     the same `record()` call, ALSO does not carry the actor; it
 *     exposes it via `$event->payment->created_by`. A listener that
 *     needs the settlement actor reads it from the corresponding
 *     `LoanRepaymentRecorded` event's Payment.
 *
 *   - The state machine records the actor on the corresponding
 *     `loan_status_histories.actor_user_id` row for the
 *     `→ settled` transition. A listener that needs a guaranteed actor
 *     reads it from the history table.
 *
 *   - The `Loan` model itself does NOT carry an actor column for
 *     settlement. Unlike `approved_by` (which A.39 relies on), there is
 *     no `settled_by` column in the migration (A.9).
 *
 * The event therefore carries the bare minimum: the loan whose settlement
 * is the milestone. A listener that needs an actor must reach through one
 * of the three available paths (Wallet Payment, Wallet Allocations, or
 * `loan_status_histories`).
 *
 * =============================================================================
 * Why the event carries the Loan model
 * =============================================================================
 * The event exposes the full `Loan` model as a public readonly property.
 * A listener that needs only the id reads `$event->loanId()`; a listener
 * that needs the borrower, the product, the principal, the settled
 * amount, the maturity date, or any other attribute reads the
 * corresponding property on `$event->loan` — or calls `loadMissing()` to
 * resolve a relation.
 *
 * The alternative — carrying only scalar primitives — would force every
 * listener to re-query the row, defeating the purpose of dispatching the
 * event. Per the Event convention (established by Phase 2A's
 * `ShareholderRegistered` and followed by Phase 2B's `WalletOpened` and
 * by the sibling events A.38–A.47), the constructor's primary parameter
 * is the model.
 *
 * =============================================================================
 * The once-per-loan invariant
 * =============================================================================
 * Because `settled` has only one outgoing transition (to `closed`) and no
 * transition back to a working state, the loan cannot re-enter `settled`
 * after leaving it. Two follow-up properties are worth stating:
 *
 *   1. A listener registered for `LoanSettled` will receive exactly ONE
 *      event per loan across the loan's lifetime.
 *
 *   2. The event marks the "the borrower has paid everything" milestone,
 *      not the "the loan is administratively done" milestone. The latter
 *      would be marked by a `LoanClosed` event (A.49), which is not
 *      dispatched by any Phase 2C code path. A future Change Request
 *      that introduces closure would add that event's dispatcher.
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * `$loan` is `public readonly`. A listener MUST NOT attempt to mutate the
 * loan through this reference; mutations belong to the service layer, and
 * any write from a listener would run outside the service's transaction
 * boundary. The `readonly` modifier enforces this at the language level.
 *
 * The model instance itself remains mutable by its own API (fill/save),
 * because PHP's `readonly` applies to the property binding, not to the
 * object's internal state. Listeners are expected to observe, not to
 * mutate; if a future requirement demands a post-settlement side effect
 * that writes, the correct pattern is for the listener to call a service,
 * not to edit the event's model.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry point
 * and integrates with Laravel's queued-event machinery. Phase 2C
 * dispatches this event synchronously through the synchronous Event
 * dispatcher; the event is not queued.
 *
 * If a future Change Request makes the audit listener queued (or any
 * other listener queued), the model must be serialized. The convention
 * for that scenario is to use `SerializesModels` so that Eloquent's model
 * serialization survives the queue boundary. Phase 2C does not adopt that
 * trait; a future CR would add it.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * Registered in `LoanServiceProvider::registerEventListeners()` (A.85)
 * against the single `AuditLoanEvent` listener (A.51):
 *
 *     Event::listen(
 *         LoanSettled::class,
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
 * `'loan.settled'`.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9, D133, D135, B1,
 *                                                  B2, B3
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §1.3
 *   Phase 2C A.2                                 — `LoanStatus`
 *   Phase 2C A.5                                 — `InstallmentStatus`
 *   Phase 2C A.18                                — `Loan`
 *   Phase 2C A.26                                — `LoanStateMachine`
 *   Phase 2C A.31                                — `LoanRepaymentService`
 *                                                  (the sole constructor
 *                                                  caller, via
 *                                                  `maybeSettleLoan()`)
 *   Phase 2C A.42                                — `LoanRepaymentRecorded`
 *                                                  (the sibling event
 *                                                  from the same
 *                                                  `record()` call)
 *   Phase 2C A.43                                — `LoanInstallmentSettled`
 *                                                  (the per-installment
 *                                                  sibling)
 *   Phase 2C A.49                                — `LoanClosed` (the
 *                                                  terminal follow-up
 *                                                  event, reserved but
 *                                                  not dispatched in
 *                                                  Phase 2C)
 *   Phase 2C A.51                                — `AuditLoanEvent`
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *   Phase 2A `ShareholderRegistered`             — the event-class template
 *   Phase 2B `WalletOpened`                      — the dispatch pattern
 *   Step 5 (manifest) A.48                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Events
 * @version 1.0.0
 */
final class LoanSettled
{
    use Dispatchable;

    /**
     * The loan that entered the `settled` state.
     *
     * The instance was constructed by
     * `LoanRepaymentService::maybeSettleLoan()` (A.31) BEFORE the state
     * machine performed the `→ settled` transition. Its `status` property
     * therefore carries the pre-transition value (typically
     * `LoanStatus::Active` or `LoanStatus::InArrears`), not
     * `LoanStatus::Settled`. Its three outstanding projections are all
     * `"0.0000"`, reflecting the fully-repaid state. See the class
     * docblock §"What the event carries" for the full sequence.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var Loan
     */
    public readonly Loan $loan;

    /**
     * Create a new event instance.
     *
     * The constructor takes only the loan; the settlement actor is not
     * carried. See the class docblock §"Why the event does NOT carry the
     * actor" for the recovery paths.
     *
     * @param Loan $loan  The loan that entered the `settled` state.
     */
    public function __construct(Loan $loan)
    {
        $this->loan = $loan;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the settled loan.
     *
     * Convenience accessor for listeners that need only the identifier.
     * Equivalent to `$event->loan->getKey()`.
     *
     * @return int
     */
    public function loanId(): int
    {
        return (int) $this->loan->getKey();
    }

    /**
     * The tenant id under which the loan was settled.
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
     * The id of the borrower (shareholder) whose loan was settled.
     *
     * Read directly from the loan's `borrower_shareholder_id` column.
     *
     * @return int
     */
    public function borrowerId(): int
    {
        return (int) $this->loan->borrower_shareholder_id;
    }

    /**
     * The id of the loan product under which the loan was originated.
     *
     * Read directly from the loan's `loan_product_id` column.
     *
     * @return int
     */
    public function loanProductId(): int
    {
        return (int) $this->loan->loan_product_id;
    }

    /**
     * The total amount the borrower repaid over the life of the loan, as
     * a decimal string at scale 4.
     *
     * Read directly from the loan's `settled_amount` column. Because the
     * loan is fully settled at the moment this event is dispatched, this
     * value equals `total_scheduled_repayment` (the sum of every
     * installment's expected total), modulo the extra amounts attributable
     * to penalties accrued during the loan's life.
     *
     * @return string
     */
    public function settledAmount(): string
    {
        return (string) $this->loan->settled_amount;
    }

    /**
     * The loan's scheduled total repayment, as a decimal string at scale
     * 4.
     *
     * Read directly from the loan's `total_scheduled_repayment` column,
     * which the schedule generation (A.27) wrote at disbursement time.
     *
     * @return string
     */
    public function totalScheduledRepayment(): string
    {
        return (string) $this->loan->total_scheduled_repayment;
    }

    /**
     * The loan's principal amount, as a decimal string at scale 4.
     *
     * Read directly from the loan's `principal_amount` column.
     *
     * @return string
     */
    public function principalAmount(): string
    {
        return (string) $this->loan->principal_amount;
    }

    /**
     * The loan's maturity date, in ISO 8601 form, or null if the maturity
     * date is unset.
     *
     * Read directly from the loan's `maturity_date` column, which the
     * schedule generation (A.27) set at disbursement time. The value is
     * guaranteed to be non-null for any loan that reached `settled`; the
     * null branch is defensive.
     *
     * @return string|null
     */
    public function maturityDate(): ?string
    {
        return $this->loan->maturity_date?->toIso8601String();
    }
}