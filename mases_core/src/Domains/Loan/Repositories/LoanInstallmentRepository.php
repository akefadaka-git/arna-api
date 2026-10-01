<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Repositories;

use Illuminate\Database\Eloquent\Collection;
use MaaleArna\MASES\Core\Domains\Loan\Enums\InstallmentStatus;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;

/**
 * LoanInstallmentRepository
 *
 * The read-only query surface for a loan's installment schedule. Every
 * read of a `LoanInstallment` through the Loan domain flows through this
 * repository.
 *
 * =============================================================================
 * Scope — a loan's installments (a child repository)
 * =============================================================================
 * This repository is a CHILD repository: its aggregate is a loan's
 * installment rows, and its lookup key is the parent loan's primary key.
 * The four methods it exposes cover the read patterns required by the
 * domain's services, resources, and tests:
 *
 *   findForLoan()             — single-row lookup by primary key,
 *                               scoped to a loan, nullable.
 *   listForLoan()             — all installments of a loan, in
 *                               chronological order.
 *   listUnsettledForLoan()    — the subset of a loan's installments
 *                               that still carry an outstanding amount,
 *                               in chronological order.
 *   nextInstallmentNumber()   — the monotonic number the next
 *                               installment should receive for this
 *                               loan. Returns 1 when the loan has no
 *                               installments yet.
 *
 * The repository is READ-ONLY. It performs no writes, opens no
 * transactions, dispatches no events, and mutates no state. Every
 * mutation of the installment table flows through the services that own
 * the corresponding operations: `LoanScheduleService::generate()` (A.27),
 * `LoanRepaymentService::record()` (A.31),
 * `LoanArrearsService::detect()` (A.33), and
 * `LoanRestructuringService::restructure()` (A.34).
 *
 * =============================================================================
 * Tenant-scoping composition — the caller's responsibility
 * =============================================================================
 * Unlike `LoanRepository` (A.55) and `LoanProductRepository` (A.56), which
 * take `$tenantId` as their first parameter, this repository takes
 * `$loanId`. The design follows the handoff §5 rule for child
 * repositories:
 *
 *     "accept `$tenantId` (for loan-scoped ones) or `$loanId` (for child
 *      repositories); ... document the tenant-scoping composition."
 *
 * The rationale: the `loan_installments` table (migration A.10) has no
 * `tenant_id` column of its own. A loan's installments are tenant-scoped
 * TRANSITIVELY, through the loan. Tenant isolation is therefore enforced
 * ONE LEVEL UP: the caller MUST have obtained `$loanId` from a
 * tenant-scoped lookup through `LoanRepository` (A.55) before invoking
 * any method on this repository.
 *
 * The correct composition pattern at a controller is:
 *
 *     // 1. Resolve the loan through the tenant-scoped LoanRepository.
 *     $loan = $this->loanRepository->findForTenantOrFail(
 *         tenantId: $tenantId,
 *         loanId:   $routeLoanId,
 *     );
 *
 *     // 2. Query installments by the resolved loan's primary key.
 *     $installments = $this->installmentRepository->listForLoan($loan->id);
 *
 * The pattern guarantees that a non-existent loan id and a loan id from
 * a DIFFERENT tenant produce identical outcomes at step 1 (both throw
 * `ModelNotFoundException`), so step 2 is never reached for a
 * cross-tenant reference. The isolation contract is preserved without
 * the repository having to re-verify the parent's tenant on every query.
 *
 * A caller that skips step 1 — passing a raw user-supplied `$loanId`
 * directly to this repository — bypasses the tenant isolation. This is
 * a programming defect, not a repository defect: the handoff §3 rule
 * ("Controllers resolve route-bound `{loan}` through tenant-scoped
 * repository lookups") is the load-bearing discipline, and this
 * repository trusts that the discipline has been applied.
 *
 * The trust model is documented here, on every method, and on the class
 * docblock, so that a future contributor reading any method's signature
 * cannot miss the composition rule.
 *
 * =============================================================================
 * The `$with` eager-load parameter
 * =============================================================================
 * The two lookup methods (`findForLoan`, `listForLoan`) accept an optional
 * `$with` array of relation names to eager-load. The parameter is threaded
 * directly into Eloquent's `with()`, so callers pass the standard relation
 * names declared on the `LoanInstallment` model (A.19): `'loan'`,
 * `'interestAccrual'`, `'penaltyAccrual'`.
 *
 * `listUnsettledForLoan()` also accepts `$with`. A caller that lists a
 * loan's outstanding installments frequently needs to eager-load the
 * accrual relations to render per-installment interest and penalty
 * detail; the parameter is provided for that purpose.
 *
 * =============================================================================
 * Ordering
 * =============================================================================
 * Both collection-lookup methods return rows ordered by
 * `installment_number` ascending. The number is MONOTONIC across all
 * schedules of the same loan (per the migration A.10 numbering
 * discipline), so the ascending order is the loan's contractual
 * chronology, spanning every schedule it has ever had:
 *
 *   - Schedule 1 (original):      installments 1..N
 *   - Schedule 2 (after restructure 1): installments (N+1)..(N+M)
 *   - Schedule 3 (after restructure 2): installments (N+M+1)..
 *
 * A caller that needs only the CURRENT schedule's installments filters
 * by status to exclude `superseded` rows (A.5). The
 * `listUnsettledForLoan()` method performs this filter implicitly, since
 * superseded rows are by definition not unsettled.
 *
 * The `installment_number` ordering is served by the composite UNIQUE
 * index on `(loan_id, installment_number)` declared in the migration
 * A.10.
 *
 * =============================================================================
 * `nextInstallmentNumber()` — the monotonic counter
 * =============================================================================
 * The `nextInstallmentNumber()` method returns `MAX(installment_number) + 1`
 * for the loan, or `1` when the loan has no installments. It is the
 * single source of truth for the number that `LoanScheduleService::generate()`
 * (A.27) assigns to the FIRST installment of a newly-generated schedule.
 *
 * The monotonicity is a load-bearing property of the schedule's
 * UNIQUE constraint: a restructure's new schedule continues numbering
 * from where the prior schedule left off, so the constraint
 * `UNIQUE (loan_id, installment_number)` holds across the loan's entire
 * lifetime, not merely within a single schedule.
 *
 * -----------------------------------------------------------------------------
 * Concurrency caveat
 * -----------------------------------------------------------------------------
 * The method's implementation performs a `SELECT MAX(...)` query without
 * any lock. Two concurrent schedule generations for the same loan could
 * read the same `MAX` value and compute the same next number. The
 * unique constraint then rejects the LOSER's INSERT with an SQLSTATE
 * 23000 violation.
 *
 * In practice the race is unreachable through normal code paths: the
 * two callers of `nextInstallmentNumber()` — `LoanScheduleService::generate()`
 * via `LoanDisbursementService::disburse()` (A.30) and via
 * `LoanRestructuringService::restructure()` (A.34) — both run inside a
 * transaction that has ALREADY acquired a lock on the loan row (the
 * disbursement's Wallet → Loan lock ordering and the restructure's
 * state-machine lock). The caller's lock serializes the two generations,
 * so the second caller reads the first caller's committed `MAX` value.
 *
 * The method itself does NOT acquire a lock; it relies on the caller's
 * transaction to provide serialization. A future caller that invokes
 * the method outside such a transaction would be exposed to the race.
 * The docblock on the method records this contract.
 *
 * =============================================================================
 * No exceptions from the repository
 * =============================================================================
 * No method on this repository throws. Missing rows produce `null` (for
 * `findForLoan()`) or an empty collection (for the `list*` methods). The
 * `nextInstallmentNumber()` method always returns an integer, defaulting
 * to `1` for a loan with no installments.
 *
 * There is no `findForLoanOrFail()` variant: no Phase 2C controller
 * resolves a route-bound installment by primary key. The schedule,
 * statement, and reconciliation endpoints all operate on the LOAN; the
 * installments are read as a collection through the loan's relation or
 * through this repository.
 *
 * =============================================================================
 * The `listUnsettledForLoan()` semantics
 * =============================================================================
 * "Unsettled" is defined by `InstallmentStatus::unsettledValues()` (A.5):
 * `pending`, `partial`, `overdue`. Settled and superseded installments
 * are excluded:
 *
 *   - `settled` — the borrower has fully paid the installment. It no
 *     longer contributes to the loan's outstanding balance.
 *
 *   - `superseded` — the installment belonged to a prior schedule that
 *     was replaced by a restructuring. Its corresponding Wallet
 *     obligation was discharged (per the frozen restructure flow), and
 *     it no longer contributes to the loan's outstanding balance.
 *
 * The method's return value therefore answers the question "which of
 * this loan's contractual installments still carry an amount the
 * borrower owes?". It is the set on which the following read patterns
 * operate:
 *
 *   - The schedule endpoint (`GET /loans/{loan}/schedule`, A.81) filters
 *     to the current schedule by status; the unsettled subset is a
 *     refinement.
 *
 *   - A future arrears-detection refinement could iterate only the
 *     unsettled installments rather than the full schedule.
 *
 *   - A future statement-projection refinement could sum the remaining
 *     totals over the unsettled set.
 *
 * The method is delivered ahead of those consumers so that the
 * repository surface is stable when they are introduced.
 *
 * =============================================================================
 * What this repository deliberately does NOT do
 * =============================================================================
 *   - It does NOT write. Every mutation flows through the schedule,
 *     repayment, arrears, and restructuring services.
 *
 *   - It does NOT open a transaction. Every query is a single, atomic
 *     read.
 *
 *   - It does NOT dispatch events. Reads are silent.
 *
 *   - It does NOT join through the parent loan to enforce tenant
 *     isolation. See §"Tenant-scoping composition" for the rationale and
 *     the caller's responsibility.
 *
 *   - It does NOT expose a `create`, `update`, `delete`, `save`,
 *     `restore`, or `forceDelete` method. The repository's method-name
 *     surface makes its read-only nature visible at a glance.
 *
 *   - It does NOT cache. Every call issues a fresh query. The
 *     `nextInstallmentNumber()` method's `SELECT MAX` runs on every
 *     invocation; a caller that needs to avoid the query after having
 *     just generated a schedule knows the value it assigned and does
 *     not need to re-query.
 *
 *   - It does NOT expose a `findLatestForLoan()` or
 *     `findCurrentScheduleForLoan()` method. The current schedule is a
 *     projection of the installment set filtered by status; a caller
 *     composes that projection through the model's scopes (A.19) on the
 *     collection returned by `listForLoan()`.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9, D132, D135
 *   Phase 2C Clarification Response, 2026-09-12  — C4, C5
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §3
 *   Phase 2C A.5                                 — `InstallmentStatus`
 *   Phase 2C A.10                                — `loan_installments`
 *                                                  migration (numbering
 *                                                  and constraints)
 *   Phase 2C A.19                                — `LoanInstallment` (the
 *                                                  model and its scopes)
 *   Phase 2C A.27                                — `LoanScheduleService`
 *                                                  (the primary consumer
 *                                                  of
 *                                                  `nextInstallmentNumber()`)
 *   Phase 2C A.55                                — `LoanRepository` (the
 *                                                  parent repository)
 *   Phase 2A 2A.13                               — `ShareholderRepository`
 *                                                  (the shape precedent)
 *   Phase 2B 2B.19                               — `WalletRepository`
 *                                                  (the direct sibling)
 *   Step 5 (manifest) A.57                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Repositories
 * @version 1.0.0
 */
final class LoanInstallmentRepository
{
    // =========================================================================
    // Public API — single-row lookup
    // =========================================================================

    /**
     * Find an installment by primary key within a loan.
     *
     * Returns the installment when it exists and belongs to the loan;
     * returns `null` otherwise. An installment that exists under a
     * DIFFERENT loan returns `null`, indistinguishable from a
     * non-existent id.
     *
     * COMPOSITION RULE: the caller MUST have resolved `$loanId` through
     * a tenant-scoped lookup (e.g.
     * `LoanRepository::findForTenantOrFail()`, A.55) before invoking
     * this method. The repository does not re-verify the parent loan's
     * tenant. See the class docblock §"Tenant-scoping composition".
     *
     * @param int           $loanId
     * @param int           $installmentId
     * @param list<string>  $with            Relations to eager-load.
     * @return LoanInstallment|null
     */
    public function findForLoan(
        int $loanId,
        int $installmentId,
        array $with = [],
    ): ?LoanInstallment {
        /** @var LoanInstallment|null $installment */
        $installment = LoanInstallment::query()
            ->where('loan_id', $loanId)
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->whereKey($installmentId)
            ->first();

        return $installment;
    }

    // =========================================================================
    // Public API — collection lookups
    // =========================================================================

    /**
     * List every installment of a loan, in chronological order.
     *
     * Returns an Eloquent `Collection` of `LoanInstallment` models
     * ordered by `installment_number` ascending. The list includes
     * installments from EVERY schedule the loan has ever had: original
     * schedule, and every subsequent schedule produced by a
     * restructuring. Superseded installments (from prior schedules) are
     * included; a caller that needs only the current schedule filters
     * them out by status.
     *
     * The method returns an EMPTY collection when the loan has no
     * installments yet (a loan in a pre-disbursement state); it does not
     * return `null`.
     *
     * COMPOSITION RULE: the caller MUST have resolved `$loanId` through
     * a tenant-scoped lookup (e.g.
     * `LoanRepository::findForTenantOrFail()`, A.55) before invoking
     * this method. See the class docblock §"Tenant-scoping composition".
     *
     * @param int           $loanId
     * @param list<string>  $with   Relations to eager-load.
     * @return Collection<int, LoanInstallment>
     */
    public function listForLoan(
        int $loanId,
        array $with = [],
    ): Collection {
        /** @var Collection<int, LoanInstallment> $installments */
        $installments = LoanInstallment::query()
            ->where('loan_id', $loanId)
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->orderBy('installment_number')
            ->get();

        return $installments;
    }

    /**
     * List the unsettled installments of a loan, in chronological order.
     *
     * Returns an Eloquent `Collection` of `LoanInstallment` models whose
     * `status` is one of `pending`, `partial`, or `overdue` (the set
     * defined by `InstallmentStatus::unsettledValues()`, A.5). Settled
     * and superseded installments are excluded.
     *
     * The list spans the loan's CURRENT schedule only: superseded
     * installments (from prior schedules) are excluded by definition,
     * because `superseded` is not in the unsettled set. See the class
     * docblock §"The `listUnsettledForLoan()` semantics" for the
     * interpretation.
     *
     * The method returns an EMPTY collection when the loan has no
     * unsettled installments (either because it has no schedule yet, or
     * because every installment has been settled); it does not return
     * `null`.
     *
     * COMPOSITION RULE: the caller MUST have resolved `$loanId` through
     * a tenant-scoped lookup (e.g.
     * `LoanRepository::findForTenantOrFail()`, A.55) before invoking
     * this method. See the class docblock §"Tenant-scoping composition".
     *
     * @param int           $loanId
     * @param list<string>  $with   Relations to eager-load.
     * @return Collection<int, LoanInstallment>
     */
    public function listUnsettledForLoan(
        int $loanId,
        array $with = [],
    ): Collection {
        /** @var Collection<int, LoanInstallment> $installments */
        $installments = LoanInstallment::query()
            ->where('loan_id', $loanId)
            ->whereIn('status', InstallmentStatus::unsettledValues())
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->orderBy('installment_number')
            ->get();

        return $installments;
    }

    // =========================================================================
    // Public API — monotonic counter
    // =========================================================================

    /**
     * The next installment number that should be assigned for a loan.
     *
     * Returns `MAX(installment_number) + 1` for the loan, or `1` when the
     * loan has no installments. The value is MONOTONIC across the loan's
     * entire lifetime, spanning every schedule the loan has ever had. See
     * the class docblock §"`nextInstallmentNumber()`" for the rationale
     * and §"Concurrency caveat" for the race analysis.
     *
     * COMPOSITION RULE: the caller MUST have resolved `$loanId` through
     * a tenant-scoped lookup (e.g.
     * `LoanRepository::findForTenantOrFail()`, A.55) before invoking
     * this method. See the class docblock §"Tenant-scoping composition".
     *
     * TRANSACTION RULE: the caller SHOULD be inside a transaction that
     * has already acquired a lock on the loan row (e.g. the state
     * machine's `lockForUpdate()`, A.26). The lock serializes concurrent
     * schedule generations for the same loan, so the two callers do not
     * compute the same next number. Without the caller's lock, two
     * concurrent calls could return the same value; the UNIQUE constraint
     * on `(loan_id, installment_number)` (migration A.10) would then
     * reject the loser's INSERT.
     *
     * @param int $loanId
     * @return int  The next number to assign; `1` for a loan with no
     *              installments.
     */
    public function nextInstallmentNumber(int $loanId): int
    {
        /** @var int|string|null $maxNumber */
        $maxNumber = LoanInstallment::query()
            ->where('loan_id', $loanId)
            ->max('installment_number');

        return ((int) ($maxNumber ?? 0)) + 1;
    }
}