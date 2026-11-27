<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Repositories;

use Illuminate\Database\Eloquent\Collection;
use MaaleArna\Arna\Core\Domains\Loan\Enums\CollateralStatus;
use MaaleArna\Arna\Core\Domains\Loan\Models\LoanCollateral;

/**
 * Read-only child repository; every collateral query is scoped to its parent loan and tenant.
 *
 * @package MaaleArna\Arna\Core\Domains\Loan\Repositories
 * @version 1.0.0
 */
final class LoanCollateralRepository
{
    // =========================================================================
    // Public API — single-row lookup
    // =========================================================================

    /**
     * Find a collateral by primary key within a loan.
     *
     * Returns the collateral when it exists and belongs to the loan;
     * returns `null` otherwise. A collateral that exists under a
     * DIFFERENT loan returns `null`, indistinguishable from a
     * non-existent id.
     *
     * The method does NOT filter by status: a released collateral is
     * returned as readily as a registered one. The release endpoint's
     * pre-flight may need to confirm that a collateral has already been
     * released (returning 409 rather than re-releasing); the status
     * check belongs to the service (A.37), not to this repository.
     *
     * COMPOSITION RULE: the caller MUST have resolved `$loanId` through
     * a tenant-scoped lookup (e.g.
     * `LoanRepository::findForTenantOrFail()`, A.55) before invoking
     * this method. The repository does not re-verify the parent loan's
     * tenant. See the class docblock §"Tenant-scoping composition".
     *
     * @param int           $loanId
     * @param int           $collateralId
     * @param list<string>  $with            Relations to eager-load.
     * @return LoanCollateral|null
     */
    public function findForLoan(
        int $loanId,
        int $collateralId,
        array $with = [],
    ): ?LoanCollateral {
        /** @var LoanCollateral|null $collateral */
        $collateral = LoanCollateral::query()
            ->where('loan_id', $loanId)
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->whereKey($collateralId)
            ->first();

        return $collateral;
    }

    // =========================================================================
    // Public API — collection lookups
    // =========================================================================

    /**
     * List every collateral of a loan, in registration order.
     *
     * Returns an Eloquent `Collection` of `LoanCollateral` models ordered
     * by `registered_at` ascending with an `id` ascending tiebreaker. The
     * list includes BOTH `registered` and `released` collaterals: it is
     * the loan's complete security history.
     *
     * A caller that needs only the currently-held collaterals uses
     * `listRegisteredForLoan()`.
     *
     * The method returns an EMPTY collection when the loan has no
     * collaterals; it does not return `null`.
     *
     * COMPOSITION RULE: the caller MUST have resolved `$loanId` through
     * a tenant-scoped lookup (e.g.
     * `LoanRepository::findForTenantOrFail()`, A.55) before invoking
     * this method. See the class docblock §"Tenant-scoping composition".
     *
     * @param int           $loanId
     * @param list<string>  $with   Relations to eager-load.
     * @return Collection<int, LoanCollateral>
     */
    public function listForLoan(
        int $loanId,
        array $with = [],
    ): Collection {
        /** @var Collection<int, LoanCollateral> $collaterals */
        $collaterals = LoanCollateral::query()
            ->where('loan_id', $loanId)
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->orderBy('registered_at')
            ->orderBy('id')
            ->get();

        return $collaterals;
    }

    /**
     * List the currently-registered collaterals of a loan, in
     * registration order.
     *
     * Returns an Eloquent `Collection` of `LoanCollateral` models whose
     * `status` is `CollateralStatus::Registered` (A.3). Released
     * collaterals are excluded.
     *
     * This is the correct read for the "what security does this loan
     * currently hold?" question. A caller that needs the full collateral
     * history uses `listForLoan()`.
     *
     * The ordering matches `listForLoan()`. The status filter is composed
     * with the composite index `(loan_id, status)` declared in the
     * migration A.11, so the read is served from the index.
     *
     * The method returns an EMPTY collection when the loan has no
     * currently-held collaterals (either because it has none, or because
     * every collateral has been released); it does not return `null`.
     *
     * COMPOSITION RULE: the caller MUST have resolved `$loanId` through
     * a tenant-scoped lookup (e.g.
     * `LoanRepository::findForTenantOrFail()`, A.55) before invoking
     * this method. See the class docblock §"Tenant-scoping composition".
     *
     * @param int           $loanId
     * @param list<string>  $with   Relations to eager-load.
     * @return Collection<int, LoanCollateral>
     */
    public function listRegisteredForLoan(
        int $loanId,
        array $with = [],
    ): Collection {
        /** @var Collection<int, LoanCollateral> $collaterals */
        $collaterals = LoanCollateral::query()
            ->where('loan_id', $loanId)
            ->where('status', CollateralStatus::Registered->value)
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->orderBy('registered_at')
            ->orderBy('id')
            ->get();

        return $collaterals;
    }

    // =========================================================================
    // Public API — existence check
    // =========================================================================

    /**
     * Whether a loan has at least one collateral row registered against
     * it.
     *
     * The answer is based on the loan's entire collateral population,
     * regardless of status: a loan whose only collateral has been
     * released returns `true`. See the class docblock
     * §"The `existsForLoan()` semantics" for the rationale and for the
     * distinct question answered by `listRegisteredForLoan()`.
     *
     * The method issues an `EXISTS` query, which does not retrieve any
     * rows. A caller that needs the rows uses `listForLoan()` or
     * `listRegisteredForLoan()`.
     *
     * COMPOSITION RULE: the caller MUST have resolved `$loanId` through
     * a tenant-scoped lookup (e.g.
     * `LoanRepository::findForTenantOrFail()`, A.55) before invoking
     * this method. See the class docblock §"Tenant-scoping composition".
     *
     * @param int $loanId
     * @return bool
     */
    public function existsForLoan(int $loanId): bool
    {
        return LoanCollateral::query()
            ->where('loan_id', $loanId)
            ->exists();
    }
}