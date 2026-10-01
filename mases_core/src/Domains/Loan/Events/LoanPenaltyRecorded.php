<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanPenaltyAccrual;

/**
 * LoanPenaltyRecorded
 *
 * Domain event dispatched when a penalty obligation is recorded against a
 * borrower's Wallet for a single overdue loan installment — the moment the
 * arrears mechanism recognizes that the installment has crossed its grace
 * threshold without full settlement.
 *
 * =============================================================================
 * When this event fires — the once-per-installment rule
 * =============================================================================
 * The event is dispatched by `LoanArrearsService::detect()` (A.33) AFTER
 * the transaction that inserted the `loan_penalty_accruals` bookkeeping
 * row, wrote the Wallet penalty obligation, updated the installment's
 * status to `overdue`, and updated the loan's `outstanding_penalty` has
 * committed.
 *
 * Because the penalty is a DISCRETE FACT recognized once per overdue
 * installment (D133, "no compounding, no capitalization"), this event
 * fires AT MOST ONCE PER INSTALLMENT across the installment's lifetime.
 * The invariant is enforced at two levels:
 *
 *   - Structurally by the UNIQUE constraint on
 *     `loan_penalty_accruals.accrual_period_key` (migration A.16). The key
 *     is deterministic from `(loan_id, installment_id)`; the constraint
 *     makes duplicate insertions impossible.
 *
 *   - Behaviorally by `LoanArrearsService::detect()` (A.33), whose fast
 *     path checks for an existing accrual row before doing any work, and
 *     whose `try/catch` on the unique-violation race returns null without
 *     constructing this event.
 *
 * Consequence: a listener registered for this event may rely on it being
 * a per-installment milestone. It will not be called twice for the same
 * installment, even if `DetectArrearsCommand` (A.88) is run many times
 * for the same day or restarted after a crash.
 *
 * Unlike `LoanEnteredArrears` (A.44), which is a once-per-LOAN milestone,
 * this event can fire many times per loan — once for each installment
 * that becomes overdue. The distinction is deliberate:
 *
 *   - `LoanEnteredArrears` — the loan leaves good standing.
 *   - `LoanPenaltyRecorded` — a specific installment has been penalized.
 *
 * On the FIRST overdue installment, BOTH events fire from the same
 * `detect()` call. On subsequent overdue installments, only this event
 * fires.
 *
 * =============================================================================
 * What the event carries, and the state of the models at dispatch time
 * =============================================================================
 * Four properties:
 *
 *   $loan         — the loan whose installment was penalized.
 *
 *   $installment  — the specific installment that was penalized.
 *
 *   $accrual      — the `LoanPenaltyAccrual` bookkeeping row that A.33
 *                   just inserted, including its one-time write-back of
 *                   `wallet_obligation_id`.
 *
 *   $actorUserId  — the user whose detection triggered the penalty, or
 *                   null for the system-driven daily command (the normal
 *                   case).
 *
 * Under the frozen A.33 flow, by the time this event is constructed:
 *
 *   - The accrual row has been INSERTED and its `wallet_obligation_id`
 *     has been written back with the id of the Wallet obligation that was
 *     created for this penalty (A.33, steps 7 and 10).
 *
 *   - The installment's `status` has been set to
 *     `InstallmentStatus::Overdue` (A.33, step 11).
 *
 *   - The loan's `outstanding_penalty` has been incremented by the
 *     penalty amount (A.33, step 12).
 *
 *   - If this is the FIRST overdue installment of the loan, the loan's
 *     status is about to be transitioned from `active` to `in_arrears`
 *     (A.33, step 13), and `LoanEnteredArrears` (A.44) fires alongside
 *     this event.
 *
 * The carried `$accrual` reflects the fully-committed state of the
 * accrual row: its `wallet_obligation_id` is non-null (the write-back
 * happened at A.33, step 10, before this event was constructed). A
 * listener may rely on `$event->accrual->hasWalletObligation()` returning
 * `true`.
 *
 * =============================================================================
 * Why the event carries the accrual model
 * =============================================================================
 * The `LoanPenaltyAccrual` instance is exposed as a full model for the
 * same reason the sibling events carry their supplementary models: it is
 * the authoritative Loan-side record of the penalty fact, and a listener
 * that needs any of its attributes reads them without a re-query.
 *
 * The attributes a listener is likely to need are:
 *
 *   - `penalty_amount` — the amount of the penalty the arrears service
 *     computed for this installment;
 *   - `wallet_obligation_id` — the join key to the Wallet-side
 *     `ObligationRecorded` event for the same penalty obligation;
 *   - `recorded_at` — the moment of recognition (which, if the daily
 *     command ran late, is NOT the same as the installment's due date);
 *   - `accrual_period_key` — the deterministic key that enforces the
 *     once-per-installment invariant.
 *
 * All are available on the model without additional I/O.
 *
 * =============================================================================
 * Why the event carries the actor
 * =============================================================================
 * The reasoning is the same as documented on `LoanRejected` (A.40),
 * `LoanInstallmentSettled` (A.43), and `LoanEnteredArrears` (A.44):
 *
 *   - No loan-row column describes the arrears-detection actor. Writing
 *     the actor into `approved_by`, `disbursed_by`, or `created_by` would
 *     misrepresent the column's meaning.
 *
 *   - The installment row (`loan_installments`, A.10) has no actor
 *     column at all.
 *
 *   - The accrual row (`loan_penalty_accruals`, A.16) also has no actor
 *     column.
 *
 *   - The state machine DOES record the actor on the corresponding
 *     `loan_status_histories.actor_user_id` row — but only when this
 *     detection is the FIRST overdue installment of the loan (i.e. when
 *     it causes the `active → in_arrears` transition). For subsequent
 *     overdue installments, NO status-history row is created for this
 *     detection. Carrying the actor on the event is therefore the only
 *     reliable way to attribute the penalty to its detector.
 *
 * The property is nullable. The normal case for `DetectArrearsCommand`
 * (A.88) is a null actor.
 *
 * =============================================================================
 * Why the event carries the Loan model
 * =============================================================================
 * The LOAN is the primary model carried by this event, following the
 * domain-wide convention. A listener that needs the loan's identity
 * (tenant, borrower, product) or its post-detection
 * `outstanding_penalty` reads it directly from the carried instance.
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
 * The model instances themselves remain mutable by their own API
 * (fill/save), because PHP's `readonly` applies to the property binding,
 * not to the object's internal state. Listeners are expected to observe,
 * not to mutate; if a future requirement demands a post-detection side
 * effect that writes, the correct pattern is for the listener to call a
 * service, not to edit the event's models.
 *
 * Note that `LoanPenaltyAccrual` enforces partial mutability at the model
 * level: its `booted()` guard throws on any `updating` event except the
 * one-time `wallet_obligation_id` write-back that A.33 already performed
 * before constructing this event. A listener that attempts to mutate the
 * accrual will receive a `LogicException` from the model guard, not a
 * silent corruption.
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
 * other listener queued), the three carried models must be serialized.
 * The convention for that scenario is to use `SerializesModels` so that
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
 *         LoanPenaltyRecorded::class,
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
 * `'loan.penalty.recorded'`.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D133 (arrears rules),
 *                                                  B1, B2, B3
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §1.3, §4.2,
 *                                                  §4.3
 *   Phase 2C A.5                                 — `InstallmentStatus`
 *   Phase 2C A.16                                — `loan_penalty_accruals`
 *                                                  migration
 *   Phase 2C A.18                                — `Loan`
 *   Phase 2C A.19                                — `LoanInstallment`
 *   Phase 2C A.25                                — `LoanPenaltyAccrual`
 *                                                  (the model)
 *   Phase 2C A.33                                — `LoanArrearsService`
 *                                                  (the sole constructor
 *                                                  caller)
 *   Phase 2C A.43                                — `LoanInstallmentSettled`
 *                                                  (the sibling event
 *                                                  for the inverse
 *                                                  settlement case)
 *   Phase 2C A.44                                — `LoanEnteredArrears`
 *                                                  (the once-per-loan
 *                                                  sibling event)
 *   Phase 2C A.51                                — `AuditLoanEvent`
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *   Phase 2C A.88                                — `DetectArrearsCommand`
 *                                                  (the normal caller of
 *                                                  `detect()`, with a
 *                                                  null actor)
 *   Phase 2A `ShareholderRegistered`             — the event-class template
 *   Phase 2B `WalletOpened`                      — the dispatch pattern
 *   Step 5 (manifest) A.45                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Events
 * @version 1.0.0
 */
final class LoanPenaltyRecorded
{
    use Dispatchable;

    /**
     * The loan whose installment was penalized.
     *
     * The instance was constructed by `LoanArrearsService::detect()`
     * (A.33) after the penalty was written to the loan's
     * `outstanding_penalty` projection. Its `status` property is
     * unchanged from the state the service found it in (typically
     * `LoanStatus::Active` on the first overdue detection, or
     * `LoanStatus::InArrears` on subsequent ones). Its
     * `outstanding_penalty` reflects the penalty added for this
     * installment.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var Loan
     */
    public readonly Loan $loan;

    /**
     * The installment that was penalized.
     *
     * The instance was constructed by `LoanArrearsService::detect()`
     * (A.33) AFTER the installment's status was set to
     * `InstallmentStatus::Overdue` (A.33, step 11). Its `status` property
     * is therefore `InstallmentStatus::Overdue` at dispatch time.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var LoanInstallment
     */
    public readonly LoanInstallment $installment;

    /**
     * The `LoanPenaltyAccrual` bookkeeping row that A.33 inserted.
     *
     * The instance was constructed by `LoanArrearsService::detect()`
     * (A.33) AFTER its `wallet_obligation_id` was written back (A.33,
     * step 10). Its `walletObligationId` is therefore non-null at
     * dispatch time; `$accrual->hasWalletObligation()` returns `true`.
     *
     * Read-only. Listeners MUST treat the model as immutable. Note that
     * the model's own `booted()` guard enforces immutability beyond the
     * one-time write-back that has already happened: an attempt to
     * mutate it throws `LogicException` at the model level.
     *
     * @var LoanPenaltyAccrual
     */
    public readonly LoanPenaltyAccrual $accrual;

    /**
     * The user whose detection triggered the penalty, or null for a
     * system-driven detection.
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
     * @param Loan                 $loan         The loan whose
     *                                            installment was
     *                                            penalized.
     * @param LoanInstallment      $installment  The penalized overdue
     *                                            installment.
     * @param LoanPenaltyAccrual   $accrual      The penalty bookkeeping
     *                                            row that was recorded.
     * @param int|null             $actorUserId  The user whose detection
     *                                            triggered the penalty,
     *                                            or null for a system
     *                                            detection.
     */
    public function __construct(
        Loan $loan,
        LoanInstallment $installment,
        LoanPenaltyAccrual $accrual,
        ?int $actorUserId = null,
    ) {
        $this->loan        = $loan;
        $this->installment = $installment;
        $this->accrual     = $accrual;
        $this->actorUserId = $actorUserId;
    }

    // =========================================================================
    // Convenience accessors — loan
    // =========================================================================

    /**
     * The primary key of the loan whose installment was penalized.
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
     * The id of the borrower (shareholder) whose installment was
     * penalized.
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
     * The primary key of the penalized installment.
     *
     * @return int
     */
    public function installmentId(): int
    {
        return (int) $this->installment->getKey();
    }

    /**
     * The penalized installment's monotonic number within the loan's
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
     * The penalized installment's contractual due date, in ISO 8601 form.
     *
     * Read directly from the installment's `due_date` column. Note that
     * this is the CONTRACTUAL due date, not the moment of detection: if
     * `DetectArrearsCommand` (A.88) ran late (e.g. after a scheduler
     * outage), the penalty was recorded at a later moment. The moment of
     * detection is available via `recordedAt()`.
     *
     * @return string
     */
    public function dueDate(): string
    {
        return $this->installment->due_date->toIso8601String();
    }

    /**
     * The penalized installment's total expected repayment, as a decimal
     * string at scale 4.
     *
     * @return string
     */
    public function expectedTotal(): string
    {
        return (string) $this->installment->expected_total;
    }

    /**
     * The penalized installment's total settled amount at the moment of
     * detection, as a decimal string at scale 4.
     *
     * Read directly from the installment's `settled_amount` column. The
     * difference between `expectedTotal()` and this value is the overdue
     * remainder that was the basis for the penalty computation (per
     * D133's `overdue_installment` basis).
     *
     * @return string
     */
    public function settledAmount(): string
    {
        return (string) $this->installment->settled_amount;
    }

    // =========================================================================
    // Convenience accessors — accrual
    // =========================================================================

    /**
     * The primary key of the penalty accrual row that was recorded.
     *
     * @return int
     */
    public function accrualId(): int
    {
        return (int) $this->accrual->getKey();
    }

    /**
     * The penalty amount recorded, as a decimal string at scale 4.
     *
     * Read directly from the accrual's `penalty_amount` column. This is
     * the amount that was also written as a Wallet obligation and added
     * to the loan's `outstanding_penalty`.
     *
     * @return string
     */
    public function penaltyAmount(): string
    {
        return (string) $this->accrual->penalty_amount;
    }

    /**
     * The primary key of the Wallet obligation created for this penalty.
     *
     * Read directly from the accrual's `wallet_obligation_id` column,
     * which A.33 wrote back (step 10) before constructing this event. The
     * value is guaranteed non-null at dispatch time.
     *
     * The value is the join key between this Loan-domain event and the
     * Wallet domain's own `ObligationRecorded` event, which fires for the
     * same obligation in the same transaction.
     *
     * @return int
     */
    public function walletObligationId(): int
    {
        return (int) $this->accrual->wallet_obligation_id;
    }

    /**
     * The deterministic accrual key that enforces the once-per-installment
     * invariant.
     *
     * Read directly from the accrual's `accrual_period_key` column. The
     * format is `"{loan_id}:{installment_id}"` (per the migration A.16).
     *
     * @return string
     */
    public function accrualPeriodKey(): string
    {
        return (string) $this->accrual->accrual_period_key;
    }

    /**
     * The moment of penalty recognition, in ISO 8601 form.
     *
     * Read directly from the accrual's `recorded_at` column. This is the
     * moment the detection was performed, which may be later than the
     * installment's due date if the daily command ran late.
     *
     * The column is NOT NULL per the migration A.16, so no null branch is
     * required.
     *
     * @return string
     */
    public function recordedAt(): string
    {
        return $this->accrual->recorded_at->toIso8601String();
    }

    // =========================================================================
    // Convenience accessors — loan-side financial state
    // =========================================================================

    /**
     * The loan's `outstanding_penalty` at the moment of the transition, as
     * a decimal string at scale 4.
     *
     * Read directly from the loan's `outstanding_penalty` column. The
     * value already includes the penalty that this detection recorded,
     * because A.33 writes the penalty to the loan's projection (step 12)
     * BEFORE constructing this event (step 14).
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
     * The user whose detection triggered the penalty, or null for a
     * system detection.
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