<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;

/**
 * Dispatched after commit when all non-superseded installments are settled and the loan enters its terminal settled state.
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