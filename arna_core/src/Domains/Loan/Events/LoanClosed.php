<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\Arna\Core\Domains\Loan\Models\Loan;

/**
 * Reserved event for settled → closed; no Phase 2C code path dispatches it.
 *
 * @package MaaleArna\Arna\Core\Domains\Loan\Events
 * @version 1.0.0
 */
final class LoanClosed
{
    use Dispatchable;

    /**
     * The loan that entered the `closed` state.
     *
     * When the event eventually fires (post-CR), this instance will be
     * the loan as held by the future closing service immediately BEFORE
     * the state machine performs the `settled → closed` transition. Its
     * `status` property will therefore carry `LoanStatus::Settled`, not
     * `LoanStatus::Closed`. See the class docblock §"What the event
     * carries" for the rationale.
     *
     * Under Phase 2C, the event never fires; the property is documented
     * for the future CR's benefit.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var Loan
     */
    public readonly Loan $loan;

    /**
     * Create a new event instance.
     *
     * The constructor takes only the loan; the closure actor is not
     * carried. See the class docblock §"Why the event does NOT carry the
     * actor" for the recovery path.
     *
     * Under Phase 2C, no caller constructs this event. The constructor is
     * defined for the future closure CR.
     *
     * @param Loan $loan  The loan that entered the `closed` state.
     */
    public function __construct(Loan $loan)
    {
        $this->loan = $loan;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the closed loan.
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
     * The tenant id under which the loan was closed.
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
     * The id of the borrower (shareholder) whose loan was closed.
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
     * The total amount the borrower repaid over the life of the loan, as
     * a decimal string at scale 4.
     *
     * Read directly from the loan's `settled_amount` column. By the time
     * the loan reaches `closed`, this value equals
     * `total_scheduled_repayment` (modulo any penalties accrued during
     * the loan's life).
     *
     * @return string
     */
    public function settledAmount(): string
    {
        return (string) $this->loan->settled_amount;
    }

    /**
     * The moment the loan was closed, in ISO 8601 form, or null if the
     * `closed_at` timestamp is unset.
     *
     * Read directly from the loan's `closed_at` column, which the
     * migration A.9 declares nullable and which the state machine (A.26)
     * is the sole writer of. The value is guaranteed to be non-null once
     * the `settled → closed` transition has been recorded; the null
     * branch is defensive for the case where this event is read from a
     * context that constructs it before the transition completes.
     *
     * @return string|null
     */
    public function closedAt(): ?string
    {
        return $this->loan->closed_at?->toIso8601String();
    }
}