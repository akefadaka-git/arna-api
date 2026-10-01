<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;

/**
 * LoanEnteredArrears
 *
 * Domain event dispatched when a loan transitions from `active` to
 * `in_arrears` — the moment the FIRST installment on the loan crosses its
 * grace threshold without full settlement and the loan leaves good
 * standing.
 *
 * =============================================================================
 * When this event fires — the once-per-loan rule
 * =============================================================================
 * The event is dispatched by `LoanArrearsService::detect()` (A.33) AFTER
 * the transaction that recorded the penalty obligation for the triggering
 * installment, transitioned the installment to `overdue`, updated the
 * loan's `outstanding_penalty`, and performed the `active → in_arrears`
 * transition has committed.
 *
 * Because the loan-level `in_arrears` status is a MILESTONE — the loan
 * crosses it once and remains in it until it is settled, restructured, or
 * written off (per the frozen C4 transition matrix, A.2) — this event
 * fires AT MOST ONCE PER LOAN across the loan's lifetime.
 *
 * The rule is enforced structurally by `LoanArrearsService::detect()`
 * (A.33): the event is constructed ONLY when the loan is currently in
 * `LoanStatus::Active`. On subsequent overdue installments, the loan is
 * already in `in_arrears`, the `if ($loan->status === LoanStatus::Active)`
 * branch does not fire, and only the sibling `LoanPenaltyRecorded` event
 * (A.45) is dispatched for the newly-detected installment.
 *
 * A consequence worth documenting explicitly: a listener registered for
 * this event may rely on it being a milestone. It will not be called
 * again for the same loan, even if the loan has many overdue installments
 * detected across many daily command runs.
 *
 * =============================================================================
 * The transition path — `active → in_arrears` is the only path
 * =============================================================================
 * The frozen state machine (C4, A.2) permits exactly one transition INTO
 * `in_arrears`:
 *
 *     active → in_arrears
 *
 * No other status can reach `in_arrears`. In particular, `submitted`,
 * `under_review`, `approved`, and `disbursed` loans cannot be in arrears
 * (the loan is not yet financially live); `restructured` is a transient
 * state that settles into `active` in the same transaction; and the
 * terminal statuses have no outgoing transitions.
 *
 * `LoanArrearsService::detect()` (A.33) enforces the caller-side
 * precondition (`$loan->status` must be `active` or `in_arrears`); the
 * state machine enforces the structural matrix inside the transition.
 *
 * =============================================================================
 * What the event carries, and the state of the loan at dispatch time
 * =============================================================================
 * Three properties:
 *
 *   $loan         — the loan that entered `in_arrears`.
 *
 *   $installment  — the specific overdue installment whose detection
 *                   triggered the loan-level transition.
 *
 *   $actorUserId  — the user whose detection triggered the transition, or
 *                   null for the system-driven daily command (the normal
 *                   case — `DetectArrearsCommand`, A.88, passes a null
 *                   actor).
 *
 * The carried `$loan` is the instance the service held at the moment the
 * event was constructed. Under the frozen A.33 flow, that instance has
 * had the following writes performed on it before event construction:
 *
 *   - `outstanding_penalty` — increased by the penalty amount just
 *     computed for the triggering installment (A.33, step 12).
 *
 * The instance's `status` is still `LoanStatus::Active` at event
 * construction. The `active → in_arrears` transition is performed BY the
 * state machine AFTER the event is constructed, on a fresh row-locked
 * instance returned by the transition; the event holds the pre-transition
 * instance. This mirrors the pattern of `LoanApplicationSubmitted`
 * (A.38), whose carried loan retains `status = Draft` after its
 * `draft → submitted` transition.
 *
 * A listener that needs the loan's post-transition state
 * (`status = in_arrears`) reads the loan by id, or relies on the
 * `loan_status_histories` row that the state machine appended for the
 * transition.
 *
 * The carried `$installment` reflects the POST-overdue state: A.33 sets
 * `$installment->status = InstallmentStatus::Overdue` (step 11) BEFORE
 * constructing this event. So `$installment->status` is
 * `InstallmentStatus::Overdue` at dispatch time. This is a deliberate
 * asymmetry with the loan: the installment's status transition is
 * performed directly by A.33, while the loan's status transition is
 * delegated to the state machine.
 *
 * =============================================================================
 * Why the event carries the actor
 * =============================================================================
 * Unlike `LoanApproved` (A.39), `LoanDisbursed` (A.41), and
 * `LoanRepaymentRecorded` (A.42), this event DOES carry an explicit
 * `$actorUserId` property, for the same reasons documented on
 * `LoanRejected` (A.40) and `LoanInstallmentSettled` (A.43):
 *
 *   - The loan row's only actor columns are `approved_by`,
 *     `disbursed_by`, and `created_by`. None of them describes the actor
 *     of an arrears-detection event. Writing the arrears actor into any
 *     of those columns would misrepresent the column's meaning.
 *
 *   - The installment row (`loan_installments`, A.10) has NO actor
 *     column at all. A.33's detection is not attributed to any user on
 *     the installment.
 *
 *   - The state machine DOES record the actor on the corresponding
 *     `loan_status_histories.actor_user_id` row for the
 *     `active → in_arrears` transition. But the actor is available at
 *     event-construction time; carrying it directly on the event spares
 *     listeners a join through the history table for a fact that is
 *     already in hand.
 *
 * The property is nullable. The normal case for `DetectArrearsCommand`
 * (A.88) is a null actor, because the daily command has no interactive
 * user. The convention still applies: the actor is carried as a separate
 * nullable scalar so that a future CR that introduces an interactive
 * arrears-detection endpoint would populate it with the caller's id
 * without an event-contract change.
 *
 * =============================================================================
 * Why the event carries the Loan model
 * =============================================================================
 * The event exposes the full `Loan` model as a public readonly property,
 * in addition to the installment. This follows the domain-wide convention
 * established by every sibling Loan event: the LOAN is the aggregate
 * root and the primary model carried; the installment is a supplementary
 * model-typed parameter.
 *
 * A listener that needs the loan's identity (tenant, borrower, product)
 * reads it directly from the carried instance without a re-query. A
 * listener that needs the loan's post-detection `outstanding_penalty`
 * reads it directly, since the value was written before event
 * construction.
 *
 * =============================================================================
 * Why the event carries the installment model
 * =============================================================================
 * The triggering installment is exposed as a full model instance so that
 * a listener can read the specific installment that caused the loan to
 * enter arrears, without a re-query. The attributes a listener is likely
 * to need are:
 *
 *   - `installment_number` — identifies the installment within the
 *     schedule;
 *   - `due_date` — the contractual due date that was missed;
 *   - `expected_total`, `settled_amount` — the amount the borrower owed
 *     versus the amount they paid (which together produced the overdue
 *     remainder);
 *   - `status` — `InstallmentStatus::Overdue` at dispatch time.
 *
 * All are available on the model without additional I/O.
 *
 * =============================================================================
 * The relationship to `LoanPenaltyRecorded` (A.45)
 * =============================================================================
 * The SAME `LoanArrearsService::detect()` call that produces this event
 * ALSO produces a `LoanPenaltyRecorded` event (A.45) in the same
 * transaction. The two are complementary:
 *
 *   - `LoanEnteredArrears` marks the LOAN-LEVEL MILESTONE. It fires at
 *     most once per loan, regardless of how many installments become
 *     overdue.
 *
 *   - `LoanPenaltyRecorded` marks the PER-INSTALLMENT FACT. It fires once
 *     per overdue installment, including the installment that triggered
 *     this event.
 *
 * A listener that needs "loans that entered arrears" subscribes to this
 * event alone. A listener that needs "every penalty recorded" subscribes
 * to A.45. A listener that needs both subscribes to both.
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
 * not to mutate; if a future requirement demands a post-detection side
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
 *         LoanEnteredArrears::class,
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
 * `'loan.entered_arrears'`.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D133 (arrears rules),
 *                                                  B1, B2, B3
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §1.3
 *   Phase 2C A.2                                 — `LoanStatus`
 *   Phase 2C A.5                                 — `InstallmentStatus`
 *   Phase 2C A.18                                — `Loan`
 *   Phase 2C A.19                                — `LoanInstallment`
 *   Phase 2C A.26                                — `LoanStateMachine`
 *   Phase 2C A.33                                — `LoanArrearsService`
 *                                                  (the sole constructor
 *                                                  caller)
 *   Phase 2C A.43                                — `LoanInstallmentSettled`
 *                                                  (the sibling event
 *                                                  for the inverse
 *                                                  transition)
 *   Phase 2C A.45                                — `LoanPenaltyRecorded`
 *                                                  (the sibling event
 *                                                  from the same
 *                                                  service)
 *   Phase 2C A.51                                — `AuditLoanEvent`
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *   Phase 2C A.88                                — `DetectArrearsCommand`
 *                                                  (the normal caller of
 *                                                  `detect()`, with a
 *                                                  null actor)
 *   Phase 2A `ShareholderRegistered`             — the event-class template
 *   Phase 2B `WalletOpened`                      — the dispatch pattern
 *   Step 5 (manifest) A.44                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Events
 * @version 1.0.0
 */
final class LoanEnteredArrears
{
    use Dispatchable;

    /**
     * The loan that entered the `in_arrears` state.
     *
     * The instance was constructed by `LoanArrearsService::detect()`
     * (A.33) BEFORE the state machine performed the
     * `active → in_arrears` transition. Its `status` property therefore
     * carries `LoanStatus::Active`, not `LoanStatus::InArrears`. Its
     * `outstanding_penalty` reflects the penalty added for the
     * triggering installment. See the class docblock §"What the event
     * carries" for the full sequence.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var Loan
     */
    public readonly Loan $loan;

    /**
     * The installment whose overdue detection triggered the loan-level
     * transition.
     *
     * The instance was constructed by `LoanArrearsService::detect()`
     * (A.33) AFTER the installment's own status was set to
     * `InstallmentStatus::Overdue` (step 11 of A.33) but BEFORE the loan
     * transition. Its `status` property is therefore
     * `InstallmentStatus::Overdue` at dispatch time.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var LoanInstallment
     */
    public readonly LoanInstallment $installment;

    /**
     * The user whose detection triggered the loan-level transition, or
     * null for a system-driven detection.
     *
     * The normal case is null: the daily `DetectArrearsCommand` (A.88)
     * has no interactive user. See the class docblock §"Why the event
     * carries the actor".
     *
     * Read-only.
     *
     * @var int|null
     */
    public readonly ?int $actorUserId;

    /**
     * Create a new event instance.
     *
     * @param Loan            $loan         The loan that entered
     *                                       `in_arrears`.
     * @param LoanInstallment $installment  The triggering overdue
     *                                       installment.
     * @param int|null        $actorUserId  The user whose detection
     *                                       triggered the transition,
     *                                       or null for a system
     *                                       detection.
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
     * The primary key of the loan that entered arrears.
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
     * The id of the borrower (shareholder) whose loan entered arrears.
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
     * The primary key of the triggering installment.
     *
     * @return int
     */
    public function installmentId(): int
    {
        return (int) $this->installment->getKey();
    }

    /**
     * The triggering installment's monotonic number within the loan's
     * schedule.
     *
     * Read directly from the installment's `installment_number` column.
     * Because the number is monotonic across all schedules of the loan
     * (per the migration A.10 numbering discipline), it identifies the
     * installment uniquely even across restructures.
     *
     * @return int
     */
    public function installmentNumber(): int
    {
        return (int) $this->installment->installment_number;
    }

    /**
     * The triggering installment's contractual due date, in ISO 8601
     * form.
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
     * The triggering installment's total expected repayment, as a
     * decimal string at scale 4.
     *
     * Read directly from the installment's `expected_total` column. This
     * is the amount the schedule required the borrower to pay at this
     * installment; the amount actually paid is `settledAmount()`.
     *
     * @return string
     */
    public function expectedTotal(): string
    {
        return (string) $this->installment->expected_total;
    }

    /**
     * The triggering installment's total settled amount, as a decimal
     * string at scale 4.
     *
     * Read directly from the installment's `settled_amount` column. When
     * the installment became overdue, this value was strictly less than
     * `expectedTotal()`; the difference is the overdue remainder that
     * triggered the penalty.
     *
     * @return string
     */
    public function settledAmount(): string
    {
        return (string) $this->installment->settled_amount;
    }

    // =========================================================================
    // Convenience accessors — loan-side financial state
    // =========================================================================

    /**
     * The loan's `outstanding_penalty` at the moment of the transition, as
     * a decimal string at scale 4.
     *
     * Read directly from the loan's `outstanding_penalty` column. The
     * value already includes the penalty that the detection recorded for
     * the triggering installment, because A.33 writes the penalty to the
     * loan's projection BEFORE constructing this event.
     *
     * @return string
     */
    public function outstandingPenalty(): string
    {
        return (string) $this->loan->outstanding_penalty;
    }

    // =========================================================================
    // Convenience accessors — actor
    // =========================================================================

    /**
     * The user whose detection triggered the loan-level transition, or
     * null for a system detection.
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