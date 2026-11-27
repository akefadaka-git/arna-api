<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\Arna\Core\Domains\Loan\Models\Loan;
use MaaleArna\Arna\Core\Domains\Loan\Models\LoanInstallment;

/**
 * Dispatched after commit when the first overdue installment moves a loan from active to in_arrears; this is a once-per-loan milestone.
 *
 * @package MaaleArna\Arna\Core\Domains\Loan\Events
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