<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;

/**
 * Dispatched after commit when a loan transitions from under_review to rejected; carries the decision reason.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Events
 * @version 1.0.0
 */
final class LoanRejected
{
    use Dispatchable;

    /**
     * The loan that entered the `rejected` state.
     *
     * Under the frozen flow, this instance was constructed by
     * `LoanApprovalService::reject()` (A.29) after `decision_date` was
     * written and after the state machine performed the transition; the
     * service then refreshed the instance, so the carried loan reflects
     * the post-transition state. See the class docblock §"What the event
     * carries" for the full sequence.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var Loan
     */
    public readonly Loan $loan;

    /**
     * The operator's reason for rejecting the loan.
     *
     * Non-empty by construction: `LoanApprovalService::reject()` (A.29)
     * refuses to reject a loan without a reason, and the state machine's
     * `REASON_REQUIRED_TRANSITIONS` set (A.26) enforces the same rule
     * structurally. A listener may rely on this property being non-empty
     * without a `trim()`-based guard of its own.
     *
     * The same value is written to the corresponding
     * `loan_status_histories.transition_reason` row, so the reason is
     * reconstructible from the loan's lifecycle log independent of this
     * event.
     *
     * Read-only.
     *
     * @var string
     */
    public readonly string $reason;

    /**
     * Create a new event instance.
     *
     * The constructor takes the loan and the rejection reason. The
     * rejecting actor is intentionally NOT a parameter — see the class
     * docblock §"Where the rejecting actor is found" for the rationale
     * and for how to recover the actor from the loan's status history.
     *
     * @param Loan   $loan    The loan that entered the `rejected` state.
     * @param string $reason  The operator's non-empty rejection reason.
     */
    public function __construct(Loan $loan, string $reason)
    {
        $this->loan   = $loan;
        $this->reason = $reason;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the rejected loan.
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
     * The tenant id under which the loan was rejected.
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
     * The id of the borrower (shareholder) whose loan was rejected.
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
     * The id of the loan product under which the rejected application was
     * filed.
     *
     * Read directly from the loan's `loan_product_id` column. Note that a
     * rejected loan has NO frozen policy version — the freeze happens
     * only on approval, and a rejection leaves
     * `frozen_policy_version_id` null.
     *
     * @return int
     */
    public function loanProductId(): int
    {
        return (int) $this->loan->loan_product_id;
    }

    /**
     * The operator's rejection reason.
     *
     * Mirrors the public readonly `$reason` property; provided so that
     * listeners that prefer a method call over a property read have a
     * consistent entry point.
     *
     * @return string
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * The moment the rejection decision was recorded, in ISO 8601 form,
     * or null if the decision date is unset.
     *
     * Read directly from the loan's `decision_date` column, which
     * `LoanApprovalService::reject()` (A.29) writes before constructing
     * this event. The value is guaranteed to be non-null by the time the
     * event is dispatched; the null branch is defensive.
     *
     * @return string|null
     */
    public function decisionDate(): ?string
    {
        return $this->loan->decision_date?->toIso8601String();
    }
}