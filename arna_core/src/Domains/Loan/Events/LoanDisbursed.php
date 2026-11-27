<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\Arna\Core\Domains\Loan\Models\Loan;

/**
 * Dispatched after disbursement commits, when the principal obligation and repayment schedule have been recorded.
 *
 * @package MaaleArna\Arna\Core\Domains\Loan\Events
 * @version 1.0.0
 */
final class LoanDisbursed
{
    use Dispatchable;

    /**
     * The loan whose disbursement produced this event.
     *
     * The instance was constructed by
     * `LoanDisbursementService::disburse()` (A.30) BEFORE the state
     * machine performed the `approved → disbursed` transition. Its
     * `status` property therefore carries `LoanStatus::Approved`, not
     * `LoanStatus::Disbursed` or `LoanStatus::Active`. See the class
     * docblock §"What the event carries" for the rationale and for the
     * correct way to read the loan's post-transition state.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var Loan
     */
    public readonly Loan $loan;

    /**
     * The primary key of the borrower's Wallet — the target of the
     * principal obligation recorded by the disbursement.
     *
     * Passed explicitly by `LoanDisbursementService::disburse()` (A.30)
     * because it has just resolved the Wallet and holds its id in the
     * local `$wallet` variable. The event carries it so that listeners
     * can correlate the Loan-domain event with the Wallet-domain facts
     * without a re-query.
     *
     * Read-only.
     *
     * @var int
     */
    public readonly int $walletId;

    /**
     * The primary key of the Wallet obligation recorded for the loan's
     * principal.
     *
     * Passed explicitly by `LoanDisbursementService::disburse()` (A.30)
     * because it holds the obligation instance returned by
     * `ObligationService::record()` in the local `$obligation` variable.
     * The event carries it as the join key between this Loan-domain event
     * and the Wallet domain's own `ObligationRecorded` event, which fires
     * for the same obligation in the same transaction.
     *
     * Read-only.
     *
     * @var int
     */
    public readonly int $walletObligationId;

    /**
     * Create a new event instance.
     *
     * The constructor takes the loan and the two Wallet-side identifiers
     * that the disbursement produced. The disbursing actor is
     * intentionally NOT a parameter — see the class docblock §"Why the
     * event does NOT carry the actor".
     *
     * @param Loan $loan                The loan that entered the
     *                                  `disbursed` state.
     * @param int  $walletId            The borrower's Wallet primary key.
     * @param int  $walletObligationId  The Wallet obligation's primary
     *                                  key.
     */
    public function __construct(
        Loan $loan,
        int $walletId,
        int $walletObligationId,
    ) {
        $this->loan               = $loan;
        $this->walletId           = $walletId;
        $this->walletObligationId = $walletObligationId;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the disbursed loan.
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
     * The tenant id under which the loan was disbursed.
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
     * The id of the borrower (shareholder) whose loan was disbursed.
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
     * The id of the loan product under which the disbursed loan was
     * originated.
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
     * The primary key of the borrower's Wallet.
     *
     * Mirrors the public readonly `$walletId` property; provided so that
     * listeners that prefer a method call over a property read have a
     * consistent entry point.
     *
     * @return int
     */
    public function walletId(): int
    {
        return $this->walletId;
    }

    /**
     * The primary key of the Wallet obligation recorded by the
     * disbursement.
     *
     * Mirrors the public readonly `$walletObligationId` property. The
     * value is the join key between this Loan-domain event and the Wallet
     * domain's `ObligationRecorded` event for the same obligation.
     *
     * @return int
     */
    public function walletObligationId(): int
    {
        return $this->walletObligationId;
    }

    /**
     * The moment the disbursement was recorded, in ISO 8601 form, or null
     * if the disbursement date is unset.
     *
     * Read directly from the loan's `disbursement_date` column, which
     * `LoanDisbursementService::disburse()` (A.30) writes before
     * constructing this event. The value is guaranteed to be non-null by
     * the time the event is dispatched; the null branch is defensive.
     *
     * @return string|null
     */
    public function disbursementDate(): ?string
    {
        return $this->loan->disbursement_date?->toIso8601String();
    }
}