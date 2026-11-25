<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;

/**
 * Dispatched after the approval transaction commits; carries the approved loan and frozen policy reference.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Events
 * @version 1.0.0
 */
final class LoanApproved
{
    use Dispatchable;

    /**
     * The loan that entered the `approved` state.
     *
     * Under the frozen flow, this instance was constructed by
     * `LoanApprovalService::approve()` (A.29) after the approval
     * metadata was written and after the state machine performed the
     * transition; the service then refreshed the instance, so the
     * carried loan reflects the post-transition state. See the class
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
     * The constructor takes only the loan; the approving actor is read
     * from the loan's `approved_by` column by any listener that needs
     * it. See the class docblock §"Where the approving actor is found".
     *
     * @param Loan $loan  The loan that entered the `approved` state.
     */
    public function __construct(Loan $loan)
    {
        $this->loan = $loan;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the approved loan.
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
     * The tenant id under which the loan was approved.
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
     * The id of the borrower (shareholder) whose loan was approved.
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
     * The id of the loan product the approved loan belongs to.
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
     * The id of the versioned `loan_product_rules` policy frozen onto the
     * loan at approval.
     *
     * Read directly from the loan's `frozen_policy_version_id` column. The
     * value is guaranteed to be non-null by the time this event is
     * dispatched, because `LoanApprovalService::approve()` (A.29) refuses
     * to approve a loan whose product has no policy version.
     *
     * @return int
     */
    public function frozenPolicyVersionId(): int
    {
        return (int) $this->loan->frozen_policy_version_id;
    }

    /**
     * The user who approved the loan, or null for a system approval.
     *
     * Read directly from the loan's `approved_by` column, which
     * `LoanApprovalService::approve()` (A.29) writes before constructing
     * this event. See the class docblock §"Where the approving actor is
     * found".
     *
     * @return int|null
     */
    public function approvedByUserId(): ?int
    {
        $approvedBy = $this->loan->approved_by;

        return $approvedBy !== null ? (int) $approvedBy : null;
    }

    /**
     * The moment the approval was recorded, in ISO 8601 form, or null if
     * the decision date is unset.
     *
     * Read directly from the loan's `decision_date` column. The value is
     * guaranteed to be non-null by the time this event is dispatched,
     * because `LoanApprovalService::approve()` (A.29) writes it before
     * constructing this event. The null branch is defensive.
     *
     * @return string|null
     */
    public function decisionDate(): ?string
    {
        return $this->loan->decision_date?->toIso8601String();
    }
}