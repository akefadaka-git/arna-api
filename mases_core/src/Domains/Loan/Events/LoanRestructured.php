<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanRestructuring;

/**
 * LoanRestructured
 *
 * Domain event dispatched when a loan's contractual terms are renegotiated
 * — the moment the prior schedule is discharged, a new schedule is
 * generated in its place, and the loan returns to good standing under the
 * new terms.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by `LoanRestructuringService::restructure()`
 * (A.34) AFTER the transaction that inserted the `loan_restructurings`
 * row, discharged the prior schedule's outstanding Wallet obligations,
 * generated the new schedule, recomputed the loan's projections, and
 * performed the `in_arrears → restructured → active` state transitions
 * has committed.
 *
 * Because the restructuring is the semantically meaningful MILESTONE and
 * the `restructured → active` step is a bookkeeping settle-into-working
 * state, the event is attached to the FIRST transition
 * (`in_arrears → restructured`) via `LoanStateMachine::transition()`
 * (A.26)'s `$afterCommitEvent` parameter — following the exact pattern of
 * `LoanDisbursementService` (A.30) for the `approved → disbursed →
 * active` sequence.
 *
 * Consequence: the event fires once, and only once, per successful
 * restructuring. If the surrounding transaction rolls back — including a
 * failure during the second transition (`restructured → active`) — the
 * event is never dispatched, because `DB::afterCommit()` fires only after
 * the OUTERMOST transaction commits.
 *
 * Multiple restructurings of the same loan are permitted by the frozen
 * C4 matrix: `in_arrears → restructured → active` can repeat across the
 * loan's lifetime. Each successful restructure dispatches exactly one
 * `LoanRestructured` event with a distinct `LoanRestructuring` row.
 *
 * =============================================================================
 * The transition path — `in_arrears → restructured` is the only path
 * =============================================================================
 * The frozen state machine (C4, A.2) permits exactly one transition INTO
 * `restructured`:
 *
 *     in_arrears → restructured
 *
 * No other status can reach `restructured`. In particular, a loan that is
 * `active` (in good standing) cannot be restructured under the frozen
 * matrix; it must first enter `in_arrears` (via `LoanArrearsService`,
 * A.33) before a restructure can be applied. A future Change Request that
 * permits voluntary restructures of `active` loans would widen the
 * matrix and adjust this event's documented inbound state.
 *
 * `restructured` is a TRANSIENT state: the `restructured → active`
 * transition fires in the SAME transaction that writes the restructuring
 * row, so no external reader observes the loan in `restructured` state.
 * By the time the after-commit dispatch fires, the loan in the database
 * is `active`.
 *
 * =============================================================================
 * What the event carries, and the state of the loan at dispatch time
 * =============================================================================
 * Three properties:
 *
 *   $loan           — the loan that was restructured.
 *
 *   $restructuring  — the `LoanRestructuring` row that A.34 inserted,
 *                     recording the previous terms and the new terms.
 *
 *   $actorUserId    — the user who performed the restructure, or null for
 *                     a system-initiated restructure (unusual, but
 *                     permitted by the design).
 *
 * Under the frozen A.34 flow (mirroring A.35's write-off flow, which was
 * delivered in the same structural family), by the time this event is
 * constructed:
 *
 *   - The `loan_restructurings` row has been INSERTED, and its primary
 *     key is available to the service as `$restructuring->getKey()`.
 *     The row's `previous_*` columns capture the loan's state at the
 *     moment of the restructure; its `new_*` columns capture the terms
 *     that govern the restructured loan; its `reason` column carries the
 *     operator's justification.
 *
 *   - The prior schedule's outstanding Wallet obligations have been
 *     DISCHARGED via `ObligationService::discharge()` with
 *     `reference_type = 'LoanRestructure'` and
 *     `reference_id = (string) $restructuring->getKey()`. This is the
 *     reference-id ordering fix (Phase 2C Final Reconciliation §1.2):
 *     the restructuring row is inserted BEFORE the discharges so that
 *     each discharge entry has a valid reference id to point at.
 *
 *   - The prior schedule's unsettled installments have been SUPERSEDED
 *     (`InstallmentStatus::Superseded`, A.5).
 *
 *   - A new installment schedule has been generated by
 *     `LoanScheduleService::generate()` (A.27) under the new terms.
 *
 *   - The loan's three outstanding projections have been RECOMPUTED from
 *     the Wallet state post-discharge.
 *
 * The carried `$loan` reflects the projection updates and the superseded
 * installments, but its `status` property is still `LoanStatus::InArrears`
 * at event-construction time (the pre-transition value). The
 * `in_arrears → restructured` transition is performed by the state
 * machine AFTER the event is constructed, on a fresh row-locked instance
 * returned by the transition; the event holds the pre-transition instance.
 *
 * This mirrors the pattern of `LoanApplicationSubmitted` (A.38),
 * `LoanEnteredArrears` (A.44), and `LoanPenaltyRecorded` (A.45): the
 * carried loan retains its pre-transition status. A listener that needs
 * the loan's post-transition state reads the loan by id (which will be
 * `active` by the time the after-commit dispatch fires), or relies on the
 * `loan_status_histories` rows that the state machine appended for the
 * two transitions.
 *
 * =============================================================================
 * Why the event carries the LoanRestructuring model
 * =============================================================================
 * The `LoanRestructuring` instance is exposed as a full model so that a
 * listener can read the complete before-and-after terms of the
 * restructure without re-querying. The attributes a listener is likely
 * to need are:
 *
 *   - `previous_outstanding_principal`,
 *   - `previous_outstanding_interest`,
 *   - `previous_outstanding_penalty` — the loan's outstanding at the
 *     moment of the restructure;
 *   - `previous_installment_count`, `previous_maturity_date`,
 *     `previous_policy_version_id` — the prior schedule's summary;
 *   - `new_principal_amount`, `new_term_months`, `new_maturity_date`,
 *     `new_total_scheduled_repayment`, `new_installment_count`,
 *     `new_policy_version_id` — the new schedule's summary;
 *   - `reason` — the operator's justification (required by A.26's
 *     `REASON_REQUIRED_TRANSITIONS` for the `in_arrears → restructured`
 *     transition);
 *   - `restructured_at`, `restructured_by` — the moment and actor of the
 *     restructuring.
 *
 * All are available on the model without additional I/O. The model also
 * exposes read-only helpers (`previousOutstandingTotal()`,
 * `hasPolicyChanged()`, `isPrincipalIncrease()`,
 * `isPrincipalDecrease()`, `isPrincipalUnchanged()`) that a listener can
 * use to classify the restructure.
 *
 * =============================================================================
 * Why the event carries the actor
 * =============================================================================
 * The reasoning follows the pattern of `LoanRejected` (A.40),
 * `LoanInstallmentSettled` (A.43), `LoanEnteredArrears` (A.44),
 * `LoanPenaltyRecorded` (A.45), and `LoanWrittenOff` (A.47):
 *
 *   - The loan row's actor columns (`approved_by`, `disbursed_by`,
 *     `created_by`) do not describe the restructure actor. Writing the
 *     restructure actor into any of them would misrepresent the column's
 *     meaning.
 *
 *   - The `LoanRestructuring` model DOES carry a `restructured_by` column
 *     and a `restructuredBy()` relation (per A.21). The actor is
 *     therefore recoverable from the carried `$restructuring` model.
 *
 *   - The state machine ALSO records the actor on the corresponding
 *     `loan_status_histories.actor_user_id` row for the
 *     `in_arrears → restructured` transition.
 *
 *   - Nonetheless, the actor is carried EXPLICITLY as `$actorUserId` so
 *     that listeners do not have to reach through the `LoanRestructuring`
 *     model or the history table. This is the same redundancy that A.35
 *     (write-off) adopted: the `LoanWriteOff` model carries
 *     `written_off_by`, and the sibling `LoanWrittenOff` event carries
 *     `$actorUserId` separately. Uniformity across the event family is
 *     worth the small duplication.
 *
 * The property is nullable, mirroring the domain-wide convention. The
 * common case is a human operator performing the restructure; the null
 * case would arise from a future CR that introduces an automated
 * restructure workflow.
 *
 * =============================================================================
 * The reference-id ordering fix (Phase 2C Final Reconciliation §1.2)
 * =============================================================================
 * A note for listeners and for the audit trail: the `LoanRestructuring`
 * row's primary key is the FIRST value produced in the transaction, and
 * it is used as the `reference_id` on every Wallet discharge entry. A
 * listener that correlates the Wallet-side `ObligationCancelled` events
 * with this Loan-side event uses the restructuring row's primary key as
 * the join key:
 *
 *     Loan-side:    $event->restructuringId()
 *     Wallet-side:  $cancelledEvent->referenceId()
 *
 * The two MUST match. This is a property of the frozen reference-id
 * ordering, not an incidental correlation.
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
 * not to mutate; if a future requirement demands a post-restructure side
 * effect that writes, the correct pattern is for the listener to call a
 * service, not to edit the event's models.
 *
 * Note that `LoanRestructuring` enforces strict immutability at the model
 * level: its `booted()` guard throws `LogicException` on any `updating`
 * or `deleting` event. A listener that attempts to mutate the carried
 * restructuring receives a loud failure, not silent corruption.
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
 *         LoanRestructured::class,
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
 * `'loan.restructured'`.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D130, B2, B6
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2 (reference-id
 *                                                  ordering), §1.3,
 *                                                  §3
 *   Phase 2C A.2                                 — `LoanStatus`
 *   Phase 2C A.5                                 — `InstallmentStatus`
 *   Phase 2C A.12                                — `loan_restructurings`
 *                                                  migration
 *   Phase 2C A.18                                — `Loan`
 *   Phase 2C A.21                                — `LoanRestructuring`
 *                                                  (the model)
 *   Phase 2C A.26                                — `LoanStateMachine`
 *   Phase 2C A.27                                — `LoanScheduleService`
 *                                                  (called during the
 *                                                  restructure)
 *   Phase 2C A.34                                — `LoanRestructuringService`
 *                                                  (the sole constructor
 *                                                  caller)
 *   Phase 2C A.44                                — `LoanEnteredArrears`
 *                                                  (the event that
 *                                                  precedes this one in
 *                                                  the common case)
 *   Phase 2C A.47                                — `LoanWrittenOff` (the
 *                                                  structurally
 *                                                  analogous terminal
 *                                                  event)
 *   Phase 2C A.51                                — `AuditLoanEvent`
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *   CR-2C-WALLET-REFTYPE-001                     — Wallet ref-type map
 *   CR-2C-WALLET-OBLIGATION-LIFECYCLE-001        — adds `discharge()`
 *   Phase 2A `ShareholderRegistered`             — the event-class template
 *   Phase 2B `WalletOpened`                      — the dispatch pattern
 *   Step 5 (manifest) A.46                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Events
 * @version 1.0.0
 */
final class LoanRestructured
{
    use Dispatchable;

    /**
     * The loan that was restructured.
     *
     * The instance was constructed by
     * `LoanRestructuringService::restructure()` (A.34) BEFORE the state
     * machine performed the `in_arrears → restructured` transition. Its
     * `status` property therefore carries `LoanStatus::InArrears` at
     * dispatch time, not `LoanStatus::Restructured` or
     * `LoanStatus::Active`. Its three outstanding projections reflect the
     * post-discharge, post-recompute state. See the class docblock
     * §"What the event carries" for the full sequence.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var Loan
     */
    public readonly Loan $loan;

    /**
     * The `LoanRestructuring` row that A.34 inserted, recording the
     * previous terms and the new terms of the loan.
     *
     * The row is immutable per the `LoanRestructuring` model's `booted()`
     * guard (A.21). Its primary key is the reference id used on every
     * Wallet discharge entry created by the same transaction
     * (`reference_type = 'LoanRestructure'`).
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var LoanRestructuring
     */
    public readonly LoanRestructuring $restructuring;

    /**
     * The user who performed the restructure, or null for a
     * system-initiated restructure.
     *
     * The actor is also preserved on the `restructuring.restructured_by`
     * column and on the corresponding `loan_status_histories` row; the
     * event carries it explicitly so that listeners do not have to reach
     * through either. See the class docblock §"Why the event carries the
     * actor".
     *
     * Read-only.
     *
     * @var int|null
     */
    public readonly ?int $actorUserId;

    /**
     * Create a new event instance.
     *
     * @param Loan                $loan           The loan that was
     *                                             restructured.
     * @param LoanRestructuring   $restructuring  The restructuring row
     *                                             that was recorded.
     * @param int|null            $actorUserId    The user who performed
     *                                             the restructure, or
     *                                             null for a system
     *                                             restructure.
     */
    public function __construct(
        Loan $loan,
        LoanRestructuring $restructuring,
        ?int $actorUserId = null,
    ) {
        $this->loan          = $loan;
        $this->restructuring = $restructuring;
        $this->actorUserId   = $actorUserId;
    }

    // =========================================================================
    // Convenience accessors — loan
    // =========================================================================

    /**
     * The primary key of the restructured loan.
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
     * The id of the borrower (shareholder) whose loan was restructured.
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
    // Convenience accessors — restructuring
    // =========================================================================

    /**
     * The primary key of the `LoanRestructuring` row.
     *
     * This is the JOIN KEY between this Loan-domain event and every
     * Wallet-side `ObligationCancelled` event that fired from the same
     * transaction. Each cancelled event's `reference_id` equals this
     * value.
     *
     * @return int
     */
    public function restructuringId(): int
    {
        return (int) $this->restructuring->getKey();
    }

    /**
     * The operator's reason for the restructure, as recorded on the
     * restructuring row.
     *
     * Read directly from the restructuring's `reason` column. The value
     * is guaranteed non-empty: the `in_arrears → restructured` transition
     * is one of A.26's `REASON_REQUIRED_TRANSITIONS`, and
     * `LoanRestructuringService::restructure()` (A.34) enforces the
     * requirement before persisting.
     *
     * @return string
     */
    public function reason(): string
    {
        return (string) $this->restructuring->reason;
    }

    /**
     * The moment the restructure was recorded, in ISO 8601 form.
     *
     * Read directly from the restructuring's `restructured_at` column,
     * which the migration A.12 declares NOT NULL.
     *
     * @return string
     */
    public function restructuredAt(): string
    {
        return $this->restructuring->restructured_at->toIso8601String();
    }

    /**
     * The sum of the three `previous_outstanding_*` columns of the
     * restructuring row, as a decimal string at scale 4.
     *
     * This is the loan's total outstanding at the moment the restructure
     * was performed. Delegates to
     * `LoanRestructuring::previousOutstandingTotal()` (A.21) so that the
     * helper's implementation stays the single source of truth.
     *
     * @return string
     */
    public function previousOutstandingTotal(): string
    {
        return $this->restructuring->previousOutstandingTotal();
    }

    /**
     * The new principal amount under the restructured terms, as a decimal
     * string at scale 4.
     *
     * Read directly from the restructuring's `new_principal_amount`
     * column.
     *
     * @return string
     */
    public function newPrincipalAmount(): string
    {
        return (string) $this->restructuring->new_principal_amount;
    }

    /**
     * The new term in months under the restructured terms.
     *
     * Read directly from the restructuring's `new_term_months` column.
     *
     * @return int
     */
    public function newTermMonths(): int
    {
        return (int) $this->restructuring->new_term_months;
    }

    /**
     * Whether the restructure changed the loan's policy version.
     *
     * Delegates to `LoanRestructuring::hasPolicyChanged()` (A.21).
     *
     * @return bool
     */
    public function hasPolicyChanged(): bool
    {
        return $this->restructuring->hasPolicyChanged();
    }

    /**
     * Whether the restructure increased the loan's principal.
     *
     * Delegates to `LoanRestructuring::isPrincipalIncrease()` (A.21).
     *
     * @return bool
     */
    public function isPrincipalIncrease(): bool
    {
        return $this->restructuring->isPrincipalIncrease();
    }

    /**
     * Whether the restructure decreased the loan's principal.
     *
     * Delegates to `LoanRestructuring::isPrincipalDecrease()` (A.21).
     *
     * @return bool
     */
    public function isPrincipalDecrease(): bool
    {
        return $this->restructuring->isPrincipalDecrease();
    }

    /**
     * Whether the restructure left the loan's principal unchanged — a
     * "term-only" restructure.
     *
     * Delegates to `LoanRestructuring::isPrincipalUnchanged()` (A.21).
     *
     * @return bool
     */
    public function isPrincipalUnchanged(): bool
    {
        return $this->restructuring->isPrincipalUnchanged();
    }

    // =========================================================================
    // Convenience accessors — actor
    // =========================================================================

    /**
     * The user who performed the restructure, or null for a system
     * restructure.
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