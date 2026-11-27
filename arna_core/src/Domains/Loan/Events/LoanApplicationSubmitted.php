<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\Arna\Core\Domains\Loan\Models\Loan;

/**
 * Dispatched after commit when a loan transitions from draft to submitted.
 *
 * @package MaaleArna\Arna\Core\Domains\Loan\Events
 * @version 1.0.0
 */
final class LoanApplicationSubmitted
{
    use Dispatchable;

    /**
     * The loan whose draft-to-submitted transition produced this event.
     *
     * The instance was constructed by `LoanApplicationService::submit()`
     * (A.28) BEFORE the state machine performed the transition. Its
     * `status` property therefore carries `LoanStatus::Draft`, not
     * `LoanStatus::Submitted`. See the class docblock §"What the event
     * carries" for the rationale and for the correct way to read the
     * loan's post-transition state.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var Loan
     */
    public readonly Loan $loan;

    /**
     * The user who submitted the application, or null for a
     * system-initiated submission.
     *
     * This is NOT the same user as the loan's `created_by` column, which
     * records the user who created the draft. See the class docblock
     * §"Why the actor is a separate property" for the rationale.
     *
     * Read-only.
     *
     * @var int|null
     */
    public readonly ?int $actorUserId;

    /**
     * Create a new event instance.
     *
     * @param Loan     $loan         The loan that entered the `submitted`
     *                               state.
     * @param int|null $actorUserId  The submitting user, or null.
     */
    public function __construct(Loan $loan, ?int $actorUserId = null)
    {
        $this->loan        = $loan;
        $this->actorUserId = $actorUserId;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the submitted loan.
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
     * The tenant id under which the loan was submitted.
     *
     * Read directly from the loan's `tenant_id` column — no relation
     * traversal is required, because the `loans` table carries the
     * tenant scope directly (unlike the `wallet_*` tables from Phase 2B,
     * whose tenant scope is inherited through the wallet).
     *
     * @return int
     */
    public function tenantId(): int
    {
        return (int) $this->loan->tenant_id;
    }

    /**
     * The id of the borrower (shareholder) the loan was submitted for.
     *
     * Read directly from the loan's `borrower_shareholder_id` column.
     * Provided as a convenience for listeners that audit submissions at
     * the borrower level without loading the loan's `borrower` relation.
     *
     * @return int
     */
    public function borrowerId(): int
    {
        return (int) $this->loan->borrower_shareholder_id;
    }

    /**
     * The id of the loan product the application was submitted against.
     *
     * Read directly from the loan's `loan_product_id` column. Provided
     * as a convenience for listeners that audit submissions at the
     * product level without loading the loan's `product` relation.
     *
     * @return int
     */
    public function loanProductId(): int
    {
        return (int) $this->loan->loan_product_id;
    }

    /**
     * The user who submitted the application, or null for a
     * system-initiated submission.
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