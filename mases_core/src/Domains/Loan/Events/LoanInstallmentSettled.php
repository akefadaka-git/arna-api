<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;

/**
 * LoanInstallmentSettled
 *
 * Domain event dispatched when a loan installment transitions to the
 * `settled` state — the moment an installment's expected principal and
 * expected interest have been fully repaid and the installment is closed
 * from further servicing.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by `LoanRepaymentService::record()` (A.31) AFTER
 * the transaction that recorded the Wallet payment, applied the
 * allocations, updated the loan's projections, and performed the
 * installment-status transitions has committed.
 *
 * Because the installment settlement is NOT tied to a state transition of
 * the LOAN itself (the loan's status may remain `active` or `in_arrears`
 * even when an installment is settled), the event is NOT routed through
 * `LoanStateMachine::transition()` (A.26). Instead, it is registered
 * directly by the service via `DB::afterCommit()`, gated on
 * `config('loan.audit.emit_events')` (A.1, default `true`).
 *
 * Multiple instances of this event may fire from a SINGLE call to
 * `record()`: under the frozen A.31 flow (§"Installment settlement — the
 * frozen interpretation (A)"), installments settle in a BATCH when the
 * loan's outstanding principal reaches zero. A final repayment that
 * clears the loan's remaining principal therefore produces one
 * `LoanInstallmentSettled` event per unsettled installment on the loan —
 * potentially many events from one repayment.
 *
 * Additionally, a single `record()` call may fire this event for
 * installments that were already overdue at the moment of the repayment:
 * an overdue installment whose interest obligation is fully discharged
 * transitions to `settled`, not to a distinct "recovered" state. D133's
 * "restructuring does not erase the historical arrears ledger" clause
 * means the penalty accrual row remains, but the installment itself is
 * settled. The event carries the transition regardless of the
 * installment's prior state (`pending`, `partial`, or `overdue`).
 *
 * The settlement of one installment does NOT imply the settlement of the
 * loan. The loan-level settlement is a separate event
 * (`LoanSettled`, A.48) that fires only when EVERY non-superseded
 * installment is settled.
 *
 * =============================================================================
 * What the event carries, and the state of the installment at dispatch
 * time
 * =============================================================================
 * Three properties:
 *
 *   $loan         — the loan whose installment settled.
 *
 *   $installment  — the specific installment that transitioned to
 *                   `settled`.
 *
 *   $actorUserId  — the user who recorded the repayment that caused the
 *                   settlement, or null for a system-recorded repayment.
 *
 * Under the frozen A.31 flow, the carried `$installment` reflects the
 * post-settlement state:
 *
 *   - `status` = `InstallmentStatus::Settled`;
 *   - `settled_principal`, `settled_interest`, and `settled_amount` at
 *     their fully-settled values (equal, at scale 4, to the
 *     corresponding `expected_*` values);
 *   - all other columns (identity, due date, expected triple) unchanged
 *     from their schedule-generation values.
 *
 * The carried `$loan` reflects the same post-repayment state the sibling
 * `LoanRepaymentRecorded` (A.42) event carries: updated
 * `settled_amount`, recomputed `outstanding_*` projections, and — if
 * this batch of settlements completed the loan — `status =
 * LoanStatus::Settled`. Otherwise, the loan's status is unchanged.
 *
 * =============================================================================
 * Why the event carries the actor
 * =============================================================================
 * Unlike `LoanApproved` (A.39), `LoanDisbursed` (A.41), and
 * `LoanRepaymentRecorded` (A.42), this event DOES carry an explicit
 * `$actorUserId` property.
 *
 * The reasoning is the same as for `LoanRejected` (A.40) and
 * `LoanEnteredArrears` (A.44):
 *
 *   - The installment row's mutable columns
 *     (`settled_principal`, `settled_interest`, `settled_amount`,
 *     `status`) do NOT record an actor. The migration A.10 declares no
 *     actor column on `loan_installments`; a settlement is a
 *     consequence of a repayment, not an independent business action
 *     with its own actor.
 *
 *   - The Wallet side DOES record the actor (on the Payment and on each
 *     PaymentAllocation), but the installment settlement is a Loan-side
 *     consequence of the Wallet-side action, not the action itself. A
 *     listener that wants to correlate the settlement with the actor
 *     would have to join through the sibling `LoanRepaymentRecorded`
 *     event's payment or allocations — a needless indirection for a
 *     fact that is available at construction time.
 *
 *   - The audit trail's requirement is that every lifecycle transition
 *     has an attributable actor. Carrying `$actorUserId` directly on the
 *     event satisfies this requirement without a join.
 *
 * The property is nullable, mirroring the domain-wide convention:
 * a system-initiated repayment (a future automated direct-debit
 * process, for example) would have no human actor.
 *
 * =============================================================================
 * Why the event carries the Loan model
 * =============================================================================
 * The event exposes the full `Loan` model as a public readonly property,
 * in addition to the installment. This is deliberate, even though the
 * `$installment->loan_id` column technically links back to the loan:
 *
 *   1. A listener that needs the loan's identity (tenant, borrower,
 *      product) reads it directly from the carried instance without a
 *      re-query.
 *
 *   2. A listener that needs the loan's post-settlement projections
 *      (which reflect this repayment's effect) reads them directly.
 *
 *   3. The alternative — carrying only `$installment` and letting the
 *      listener load the loan through the relation — would force every
 *      listener to make an extra query for data the event already has.
 *
 * This follows the pattern established by every sibling Loan event: the
 * primary model is the LOAN, and the installment is a supplementary
 * model-typed parameter, precisely because the loan is the aggregate
 * root and the installment is a child.
 *
 * =============================================================================
 * Why the event carries the installment model
 * =============================================================================
 * The installment is exposed as a full model instance for the same
 * reason: a listener that needs the installment's number, due date,
 * expected triple, settled triple, or any other attribute reads it
 * directly. The alternative would force a re-query keyed by
 * `installment_id`.
 *
 * The specific values a listener is likely to need are:
 *
 *   - `installment_number` — identifies the installment within the
 *     schedule;
 *   - `due_date` — the contractual due date;
 *   - `expected_total` — the total amount that was scheduled to be paid;
 *   - `settled_amount` — the total amount actually paid (equal to
 *     `expected_total` at the moment of settlement).
 *
 * All are available on the model without additional I/O.
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * All three properties are `public readonly`. A listener MUST NOT attempt
 * to mutate any of the carried models; mutations belong to the service
 * layer, and any write from a listener would run outside the service's
 * transaction boundary. The `readonly` modifier enforces this at the
 * language level.
 *
 * The model instances themselves remain mutable by their own API
 * (fill/save), because PHP's `readonly` applies to the property binding,
 * not to the object's internal state. Listeners are expected to observe,
 * not to mutate; if a future requirement demands a post-settlement side
 * effect that writes, the correct pattern is for the listener to call a
 * service, not to edit the event's models.
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
 * other listener queued), the two carried models must be serialized. The
 * convention for that scenario is to use `SerializesModels` so that
 * Eloquent's model serialization survives the queue boundary. Phase 2C
 * does not adopt that trait; a future CR would add it.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * Registered in `LoanServiceProvider::registerEventListeners()` (A.85)
 * against the single `AuditLoanEvent` listener (A.51):
 *
 *     Event::listen(
 *         LoanInstallmentSettled::class,
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
 * `'loan.installment.settled'`.
 *
 * =============================================================================
 * The batching note
 * =============================================================================
 * Because interpretation (A) of the frozen A.31 flow settles installments
 * in a batch when the loan's outstanding principal reaches zero, a single
 * repayment at the end of the loan's life fires ONE
 * `LoanInstallmentSettled` event per unsettled installment. A loan with
 * N installments whose final repayment settles all N therefore fires N
 * such events from one call to `record()`.
 *
 * A listener that treats each event as an independent settlement of an
 * installment — for example, to build a per-installment settlement
 * timeline — is correct. A listener that assumes "one repayment, one
 * settlement event" is not, and must be revised. The class docblock
 * documents this explicitly so that the assumption is not accidentally
 * reintroduced.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9, D133, D135, B1,
 *                                                  B2, B3
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §1.3
 *   Phase 2C A.5                                 — `InstallmentStatus`
 *   Phase 2C A.18                                — `Loan`
 *   Phase 2C A.19                                — `LoanInstallment`
 *   Phase 2C A.31                                — `LoanRepaymentService`
 *                                                  (the sole constructor
 *                                                  caller; §"Installment
 *                                                  settlement — the
 *                                                  frozen interpretation
 *                                                  (A)")
 *   Phase 2C A.42                                — `LoanRepaymentRecorded`
 *                                                  (the sibling event
 *                                                  from the same
 *                                                  service)
 *   Phase 2C A.48                                — `LoanSettled` (the
 *                                                  loan-level
 *                                                  settlement event)
 *   Phase 2C A.51                                — `AuditLoanEvent`
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *   Phase 2A `ShareholderRegistered`             — the event-class template
 *   Phase 2B `WalletOpened`                      — the dispatch pattern
 *   Step 5 (manifest) A.43                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Events
 * @version 1.0.0
 */
final class LoanInstallmentSettled
{
    use Dispatchable;

    /**
     * The loan whose installment settled.
     *
     * The instance was constructed by `LoanRepaymentService::record()`
     * (A.31) after the loan's projections were updated and after the
     * sibling settlement check ran. Its status and projections reflect
     * the post-repayment state.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var Loan
     */
    public readonly Loan $loan;

    /**
     * The installment that transitioned to the `settled` state.
     *
     * The instance was constructed by `LoanRepaymentService::record()`
     * (A.31) after the transition was persisted. Its `status` property
     * is `InstallmentStatus::Settled`; its settled triple reflects the
     * fully-settled state.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var LoanInstallment
     */
    public readonly LoanInstallment $installment;

    /**
     * The user whose repayment caused the settlement, or null for a
     * system-recorded repayment.
     *
     * This is NOT derivable from the installment row (which has no actor
     * column) nor from the loan row (which records only the loan-level
     * approval and disbursement actors). It is carried explicitly. See
     * the class docblock §"Why the event carries the actor".
     *
     * Read-only.
     *
     * @var int|null
     */
    public readonly ?int $actorUserId;

    /**
     * Create a new event instance.
     *
     * @param Loan            $loan         The loan whose installment
     *                                       settled.
     * @param LoanInstallment $installment  The installment that
     *                                       transitioned to `settled`.
     * @param int|null        $actorUserId  The user whose repayment
     *                                       caused the settlement, or
     *                                       null for a system-recorded
     *                                       repayment.
     */
    public function __construct(
        Loan $loan,
        LoanInstallment $installment,
        ?int $actorUserId = null,
    ) {
        $this->loan        = $loan;
        $this->installment = $installment;
        $this->actorUserId = $actorUserId;
    }

    // =========================================================================
    // Convenience accessors — loan
    // =========================================================================

    /**
     * The primary key of the loan whose installment settled.
     *
     * @return int
     */
    public function loanId(): int
    {
        return (int) $this->loan->getKey();
    }

    /**
     * The tenant id under which the loan belongs.
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
     * The id of the borrower (shareholder) whose installment settled.
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

    // =========================================================================
    // Convenience accessors — installment
    // =========================================================================

    /**
     * The primary key of the installment that settled.
     *
     * @return int
     */
    public function installmentId(): int
    {
        return (int) $this->installment->getKey();
    }

    /**
     * The installment's monotonic number within the loan's schedule.
     *
     * Read directly from the installment's `installment_number` column.
     * The number is monotonic across all schedules of the loan (per the
     * migration A.10 numbering discipline), so it uniquely identifies the
     * installment even across restructures.
     *
     * @return int
     */
    public function installmentNumber(): int
    {
        return (int) $this->installment->installment_number;
    }

    /**
     * The installment's contractual due date, in ISO 8601 form.
     *
     * Read directly from the installment's `due_date` column, which the
     * migration A.10 declares NOT NULL.
     *
     * @return string
     */
    public function dueDate(): string
    {
        return $this->installment->due_date->toIso8601String();
    }

    /**
     * The installment's total expected repayment, as a decimal string at
     * scale 4.
     *
     * Read directly from the installment's `expected_total` column.
     * Because the installment is fully settled at the moment this event
     * is dispatched, `settledAmount()` equals this value.
     *
     * @return string
     */
    public function expectedTotal(): string
    {
        return (string) $this->installment->expected_total;
    }

    /**
     * The installment's total settled amount, as a decimal string at
     * scale 4.
     *
     * Read directly from the installment's `settled_amount` column.
     * Because the installment is fully settled at the moment this event
     * is dispatched, this value equals `expectedTotal()`.
     *
     * @return string
     */
    public function settledAmount(): string
    {
        return (string) $this->installment->settled_amount;
    }

    // =========================================================================
    // Convenience accessors — actor
    // =========================================================================

    /**
     * The user whose repayment caused the settlement, or null for a
     * system-recorded repayment.
     *
     * Mirrors the public readonly `$actorUserId` property; provided so
     * that listeners that prefer a method call over a property read have
     * a consistent entry point.
     *
     * @return int|null
     */
    public function actorUserId(): ?int
    {
        return $this->actorUserId;
    }
}