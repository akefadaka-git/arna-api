<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Repositories;

use Illuminate\Database\Eloquent\Collection;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInterestAccrual;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanPenaltyAccrual;

/**
 * LoanAccrualRepository
 *
 * The read-only query surface for a loan's interest and penalty accrual
 * bookkeeping. Every read of a `LoanInterestAccrual` or a
 * `LoanPenaltyAccrual` through the Loan domain flows through this
 * repository.
 *
 * =============================================================================
 * Scope — the two accrual bookkeeping tables (a child repository)
 * =============================================================================
 * This repository is a CHILD repository: its aggregates are a loan's
 * interest-accrual and penalty-accrual bookkeeping rows, and its lookup
 * key is the parent loan's primary key (or a set of installment ids
 * belonging to a loan, or a set of Wallet obligation ids).
 *
 * The two accrual tables (migrations A.15 and A.16) have parallel
 * structures:
 *
 *   loan_interest_accruals   — one row per installment whose interest
 *                              obligation has been recognized on the
 *                              Wallet. UNIQUE on `accrual_period_key`.
 *   loan_penalty_accruals    — one row per overdue installment whose
 *                              penalty obligation has been recognized
 *                              on the Wallet. UNIQUE on
 *                              `accrual_period_key`.
 *
 * Both tables carry the same shape: `loan_id`, `installment_id`,
 * `accrual_period_key`, an amount column, `wallet_obligation_id`,
 * `recorded_at`, `created_at`. The repository exposes a symmetric
 * method set for the two tables.
 *
 * The repository is READ-ONLY. Every mutation of either accrual table
 * flows through `LoanInterestAccrualService::accrue()` (A.32) or
 * `LoanArrearsService::detect()` (A.33), both of which are the sole
 * writers of their respective tables.
 *
 * =============================================================================
 * The eleven methods
 * =============================================================================
 * The repository exposes eleven methods, grouped as follows:
 *
 *   INTEREST (five):
 *     findInterestForLoan()              — single-row by loan + installment
 *     findInterestByObligation()         — single-row by Wallet obligation id
 *     listInterestForLoan()              — all interest accruals of a loan
 *     listInterestByInstallmentIds()     — interest accruals for a set of
 *                                          installments
 *     interestExistsForKey()             — fast idempotency check
 *
 *   PENALTY (five):
 *     findPenaltyForLoan()               — single-row by loan + installment
 *     findPenaltyByObligation()          — single-row by Wallet obligation id
 *     listPenaltyForLoan()               — all penalty accruals of a loan
 *     listPenaltyByInstallmentIds()      — penalty accruals for a set of
 *                                          installments
 *     penaltyExistsForKey()              — fast idempotency check
 *
 *   CROSS-TABLE (one):
 *     installmentIdsByObligationIds()    — the union of interest-accrual
 *                                          and penalty-accrual
 *                                          installment ids for a set of
 *                                          Wallet obligation ids
 *
 * Each method's consumer is documented on the method itself. The
 * consumers are:
 *
 *   - `LoanInterestAccrualService::accrue()` (A.32) — the
 *     `interestExistsForKey()` fast-path check.
 *   - `LoanArrearsService::detect()` (A.33) — the
 *     `penaltyExistsForKey()` fast-path check.
 *   - `LoanRepaymentService::record()` (A.31) — the
 *     `installmentIdsByObligationIds()` call inside
 *     `affectedInstallmentIds()`.
 *   - `LoanReconciliationService::reconcile()` (A.36) — the
 *     `listInterestByInstallmentIds()` and
 *     `listPenaltyByInstallmentIds()` calls inside
 *     `collectPerInstallmentBreakdown()`.
 *
 * The remaining methods are provided for the read patterns the loan
 * detail resource (A.69) and a future CR's audit/reporting surfaces
 * will require.
 *
 * =============================================================================
 * Tenant-scoping composition — the caller's responsibility
 * =============================================================================
 * As with `LoanInstallmentRepository` (A.57) and
 * `LoanCollateralRepository` (A.58), this repository keys on `$loanId`,
 * `$installmentId`, or `$walletObligationId` — never on `$tenantId`.
 * The two accrual tables (migrations A.15 and A.16) have no `tenant_id`
 * column of their own; accruals are tenant-scoped TRANSITIVELY through
 * the loan.
 *
 * The composition rule is identical to A.57's and A.58's:
 *
 *   1. The caller MUST resolve the parent loan through
 *      `LoanRepository::findForTenantOrFail()` (A.55) before invoking
 *      any loan-scoped method on this repository.
 *
 *   2. For the Wallet-obligation-keyed methods
 *      (`findInterestByObligation`, `findPenaltyByObligation`,
 *      `installmentIdsByObligationIds`), the caller MUST have obtained
 *      the obligation ids from a Wallet-side read that was itself
 *      tenant-scoped (the Wallet domain's own repository methods
 *      enforce this).
 *
 * See A.57's class docblock §"Tenant-scoping composition" for the full
 * rationale; this repository follows the identical trust model.
 *
 * =============================================================================
 * The `interestExistsForKey()` / `penaltyExistsForKey()` fast-path check
 * =============================================================================
 * Both methods answer the question "has an accrual with this
 * `accrual_period_key` already been inserted?". They are the FAST-PATH
 * check that `LoanInterestAccrualService::accrue()` (A.32) and
 * `LoanArrearsService::detect()` (A.33) perform BEFORE attempting their
 * respective INSERTs, to avoid the exception overhead of relying on the
 * unique constraint to enforce idempotency.
 *
 * The methods do NOT replace the unique constraint. The constraint
 * remains the authoritative enforcer under concurrency (see A.32's and
 * A.33's try/catch on the unique-violation SQLSTATE); the fast-path
 * check is an optimization for the common case where the row already
 * exists from a prior command run.
 *
 * The `accrual_period_key` is deterministic from `(loan_id,
 * installment_id)` — its format is `"{loan_id}:{installment_id}"` per
 * the migrations A.15 and A.16. The repository does not compute the
 * key; the caller supplies it. This keeps the key-format knowledge in
 * one place (the two services that own the INSERTs).
 *
 * =============================================================================
 * The `installmentIdsByObligationIds()` batch helper
 * =============================================================================
 * This method answers the question "which installments were the
 * beneficiaries of these Wallet obligations?" — the question
 * `LoanRepaymentService::record()` (A.31) asks inside its
 * `affectedInstallmentIds()` helper, which is called during a
 * repayment to determine which installments to consider for settlement.
 *
 * A single Wallet obligation may be referenced by:
 *
 *   - one interest accrual row (the obligation for that installment's
 *     interest), OR
 *   - one penalty accrual row (the obligation for that installment's
 *     penalty), OR
 *   - neither (a principal obligation, or a fee obligation, or an
 *     obligation from outside the Loan domain).
 *
 * The method queries BOTH tables, unions the results, and returns the
 * deduplicated set of installment ids. An obligation that appears in
 * both tables (which cannot happen in a well-formed system — a single
 * Wallet obligation is linked to at most one accrual row of either
 * table) would appear once in the returned set.
 *
 * The method returns an EMPTY list when no accrual references any of
 * the supplied obligations. The caller (A.31) interprets an empty
 * result as "no installments affected" and short-circuits its
 * settlement check.
 *
 * =============================================================================
 * Ordering
 * =============================================================================
 * Both collection-lookup methods return rows ordered by `recorded_at`
 * ascending with an `id` ascending tiebreaker. This is the order in
 * which the accruals were recognized, which is the natural reading
 * order for an audit of a loan's accrual history. The ordering matches
 * the composite index `(loan_id, recorded_at)` declared in the
 * migrations A.15 and A.16.
 *
 * The set-based `listInterestByInstallmentIds()` and
 * `listPenaltyByInstallmentIds()` methods return rows in the same
 * order. A caller that needs a different order (e.g. keyed by
 * `installment_id` for a lookup table) re-keys the collection after
 * receiving it.
 *
 * The `find*` methods return a single row or null; no ordering
 * applies.
 *
 * =============================================================================
 * No exceptions from the repository
 * =============================================================================
 * No method on this repository throws. Missing rows produce `null` (for
 * the `find*` methods) or an empty collection (for the `list*`
 * methods). The `exists*` methods return a boolean. The
 * `installmentIdsByObligationIds()` method returns an empty list when
 * no match is found.
 *
 * =============================================================================
 * What this repository deliberately does NOT do
 * =============================================================================
 *   - It does NOT write. Every mutation flows through
 *     `LoanInterestAccrualService` (A.32) or `LoanArrearsService`
 *     (A.33).
 *
 *   - It does NOT open a transaction. Every query is a single, atomic
 *     read.
 *
 *   - It does NOT dispatch events. Reads are silent.
 *
 *   - It does NOT join through the parent loan to enforce tenant
 *     isolation. See §"Tenant-scoping composition".
 *
 *   - It does NOT compute the `accrual_period_key`. The two services
 *     that own the INSERTs compute it; the repository accepts it as a
 *     string parameter.
 *
 *   - It does NOT expose a `create`, `update`, `delete`, `save`,
 *     `restore`, or `forceDelete` method. The repository's method-name
 *     surface makes its read-only nature visible at a glance.
 *
 *   - It does NOT cache. Every call issues a fresh query.
 *
 *   - It does NOT expose methods that count accruals by component or
 *     aggregate their amounts. A caller that needs a sum composes the
 *     aggregate against the model directly, or reads the sum from the
 *     loan's `outstanding_interest` / `outstanding_penalty` projections
 *     (which are the maintained authoritative aggregates).
 *
 *   - It does NOT expose an `interestExistsForInstallment()` or
 *     `penaltyExistsForInstallment()` variant. The `findInterestForLoan()`
 *     and `findPenaltyForLoan()` methods already answer that question
 *     via a null-check on their return; a dedicated existence method
 *     would be redundant.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9 (interest method),
 *                                                  D133 (arrears rules),
 *                                                  B3 (interest recording)
 *   Phase 2C Clarification Response, 2026-09-12  — C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §4.2, §4.3, §4.4
 *   Phase 2C A.15                                — `loan_interest_accruals`
 *                                                  migration
 *   Phase 2C A.16                                — `loan_penalty_accruals`
 *                                                  migration
 *   Phase 2C A.24                                — `LoanInterestAccrual`
 *   Phase 2C A.25                                — `LoanPenaltyAccrual`
 *   Phase 2C A.31                                — `LoanRepaymentService`
 *                                                  (the consumer of
 *                                                  `installmentIdsByObligationIds()`)
 *   Phase 2C A.32                                — `LoanInterestAccrualService`
 *                                                  (the consumer of
 *                                                  `interestExistsForKey()`)
 *   Phase 2C A.33                                — `LoanArrearsService`
 *                                                  (the consumer of
 *                                                  `penaltyExistsForKey()`)
 *   Phase 2C A.36                                — `LoanReconciliationService`
 *                                                  (the consumer of the
 *                                                  set-based lookups)
 *   Phase 2C A.55                                — `LoanRepository` (the
 *                                                  parent repository)
 *   Phase 2C A.57                                — `LoanInstallmentRepository`
 *                                                  (the structural
 *                                                  sibling)
 *   Phase 2C A.58                                — `LoanCollateralRepository`
 *                                                  (the structural
 *                                                  sibling)
 *   Phase 2A 2A.13                               — `ShareholderRepository`
 *                                                  (the shape precedent)
 *   Phase 2B 2B.19                               — `WalletRepository`
 *                                                  (the direct sibling)
 *   Step 5 (manifest) A.59                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Repositories
 * @version 1.0.0
 */
final class LoanAccrualRepository
{
    // =========================================================================
    // Public API — interest accruals
    // =========================================================================

    /**
     * Find the interest accrual for a loan's installment.
     *
     * Because the migration A.15's UNIQUE constraint on
     * `accrual_period_key` permits at most one interest accrual per
     * installment, this method returns AT MOST one row. A null return
     * means the installment's interest has not been accrued yet (its due
     * date has not arrived, or the daily accrual command has not run
     * since the due date).
     *
     * COMPOSITION RULE: the caller MUST have resolved `$loanId` through
     * a tenant-scoped lookup (e.g.
     * `LoanRepository::findForTenantOrFail()`, A.55) before invoking
     * this method. See the class docblock §"Tenant-scoping composition".
     *
     * @param int           $loanId
     * @param int           $installmentId
     * @param list<string>  $with            Relations to eager-load.
     * @return LoanInterestAccrual|null
     */
    public function findInterestForLoan(
        int $loanId,
        int $installmentId,
        array $with = [],
    ): ?LoanInterestAccrual {
        /** @var LoanInterestAccrual|null $accrual */
        $accrual = LoanInterestAccrual::query()
            ->where('loan_id', $loanId)
            ->where('installment_id', $installmentId)
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->first();

        return $accrual;
    }

    /**
     * Find the interest accrual linked to a Wallet obligation.
     *
     * Because the migration A.15's `wallet_obligation_id` column is a
     * unique FK (each obligation is referenced by at most one interest
     * accrual), this method returns AT MOST one row. A null return means
     * the obligation is not an interest accrual's obligation (it may be
     * a principal or penalty or fee obligation, or an obligation from
     * outside the Loan domain).
     *
     * COMPOSITION RULE: the caller MUST have obtained
     * `$walletObligationId` from a tenant-scoped Wallet-side read (the
     * Wallet domain's repository methods enforce tenant scoping on their
     * side). See the class docblock §"Tenant-scoping composition".
     *
     * @param int           $walletObligationId
     * @param list<string>  $with                Relations to eager-load.
     * @return LoanInterestAccrual|null
     */
    public function findInterestByObligation(
        int $walletObligationId,
        array $with = [],
    ): ?LoanInterestAccrual {
        /** @var LoanInterestAccrual|null $accrual */
        $accrual = LoanInterestAccrual::query()
            ->where('wallet_obligation_id', $walletObligationId)
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->first();

        return $accrual;
    }

    /**
     * List every interest accrual of a loan, in recognition order.
     *
     * Returns an Eloquent `Collection` of `LoanInterestAccrual` models
     * ordered by `recorded_at` ascending with an `id` ascending
     * tiebreaker. The list spans the loan's entire lifetime: accruals
     * for installments from superseded schedules remain in the table
     * (they are not removed by a restructuring), because the historical
     * fact that the interest was once recognized is preserved.
     *
     * Returns an EMPTY collection when the loan has no interest
     * accruals; it does not return `null`.
     *
     * COMPOSITION RULE: the caller MUST have resolved `$loanId` through
     * a tenant-scoped lookup (e.g.
     * `LoanRepository::findForTenantOrFail()`, A.55) before invoking
     * this method. See the class docblock §"Tenant-scoping composition".
     *
     * @param int           $loanId
     * @param list<string>  $with   Relations to eager-load.
     * @return Collection<int, LoanInterestAccrual>
     */
    public function listInterestForLoan(
        int $loanId,
        array $with = [],
    ): Collection {
        /** @var Collection<int, LoanInterestAccrual> $accruals */
        $accruals = LoanInterestAccrual::query()
            ->where('loan_id', $loanId)
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        return $accruals;
    }

    /**
     * List the interest accruals for a set of installment ids.
     *
     * Returns an Eloquent `Collection` of `LoanInterestAccrual` models
     * ordered by `recorded_at` ascending with an `id` ascending
     * tiebreaker. Each installment contributes at most one row (per the
     * migration A.15's UNIQUE constraint on `accrual_period_key`), but
     * an installment that has not yet been accrued is simply absent from
     * the result.
     *
     * The method is the batch-lookup primitive used by
     * `LoanReconciliationService::collectPerInstallmentBreakdown()`
     * (A.36), which eager-loads each accrual's `walletObligation`
     * relation to render the per-installment monetary view.
     *
     * Returns an EMPTY collection when no installment in the input set
     * has an interest accrual.
     *
     * COMPOSITION RULE: the caller MUST have obtained `$installmentIds`
     * from a tenant-scoped read (typically from a loan resolved through
     * `LoanRepository::findForTenantOrFail()`, A.55). See the class
     * docblock §"Tenant-scoping composition".
     *
     * @param list<int>     $installmentIds
     * @param list<string>  $with            Relations to eager-load.
     * @return Collection<int, LoanInterestAccrual>
     */
    public function listInterestByInstallmentIds(
        array $installmentIds,
        array $with = [],
    ): Collection {
        if ($installmentIds === []) {
            return new Collection();
        }

        /** @var Collection<int, LoanInterestAccrual> $accruals */
        $accruals = LoanInterestAccrual::query()
            ->whereIn('installment_id', $installmentIds)
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        return $accruals;
    }

    /**
     * Whether an interest accrual exists for a given accrual-period key.
     *
     * The fast-path idempotency check performed by
     * `LoanInterestAccrualService::accrue()` (A.32) BEFORE attempting
     * its INSERT. The key format is `"{loan_id}:{installment_id}"` (per
     * the migration A.15); the repository accepts it as an opaque
     * string.
     *
     * Returns `true` when a row with the key exists; `false` otherwise.
     * The unique constraint remains the authoritative enforcer under
     * concurrency; this method is the optimization for the common case.
     *
     * COMPOSITION RULE: the caller MUST have derived the key from a
     * tenant-scoped loan + installment pair. See the class docblock
     * §"Tenant-scoping composition".
     *
     * @param string $accrualPeriodKey
     * @return bool
     */
    public function interestExistsForKey(string $accrualPeriodKey): bool
    {
        return LoanInterestAccrual::query()
            ->where('accrual_period_key', $accrualPeriodKey)
            ->exists();
    }

    // =========================================================================
    // Public API — penalty accruals
    // =========================================================================

    /**
     * Find the penalty accrual for a loan's installment.
     *
     * Because the migration A.16's UNIQUE constraint on
     * `accrual_period_key` permits at most one penalty accrual per
     * installment, this method returns AT MOST one row. A null return
     * means the installment has not been penalized (it has not crossed
     * its grace threshold without full settlement, or the daily arrears
     * command has not run since the crossing).
     *
     * COMPOSITION RULE: the caller MUST have resolved `$loanId` through
     * a tenant-scoped lookup (e.g.
     * `LoanRepository::findForTenantOrFail()`, A.55) before invoking
     * this method. See the class docblock §"Tenant-scoping composition".
     *
     * @param int           $loanId
     * @param int           $installmentId
     * @param list<string>  $with            Relations to eager-load.
     * @return LoanPenaltyAccrual|null
     */
    public function findPenaltyForLoan(
        int $loanId,
        int $installmentId,
        array $with = [],
    ): ?LoanPenaltyAccrual {
        /** @var LoanPenaltyAccrual|null $accrual */
        $accrual = LoanPenaltyAccrual::query()
            ->where('loan_id', $loanId)
            ->where('installment_id', $installmentId)
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->first();

        return $accrual;
    }

    /**
     * Find the penalty accrual linked to a Wallet obligation.
     *
     * Because the migration A.16's `wallet_obligation_id` column is a
     * unique FK (each obligation is referenced by at most one penalty
     * accrual), this method returns AT MOST one row. A null return means
     * the obligation is not a penalty accrual's obligation.
     *
     * COMPOSITION RULE: the caller MUST have obtained
     * `$walletObligationId` from a tenant-scoped Wallet-side read. See
     * the class docblock §"Tenant-scoping composition".
     *
     * @param int           $walletObligationId
     * @param list<string>  $with                Relations to eager-load.
     * @return LoanPenaltyAccrual|null
     */
    public function findPenaltyByObligation(
        int $walletObligationId,
        array $with = [],
    ): ?LoanPenaltyAccrual {
        /** @var LoanPenaltyAccrual|null $accrual */
        $accrual = LoanPenaltyAccrual::query()
            ->where('wallet_obligation_id', $walletObligationId)
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->first();

        return $accrual;
    }

    /**
     * List every penalty accrual of a loan, in recognition order.
     *
     * Returns an Eloquent `Collection` of `LoanPenaltyAccrual` models
     * ordered by `recorded_at` ascending with an `id` ascending
     * tiebreaker. The list spans the loan's entire lifetime: penalty
     * accruals recorded before a restructuring or a write-off remain in
     * the table, per D133's "restructuring does not erase the historical
     * arrears ledger" and "write-off does not erase the historical
     * arrears ledger" clauses.
     *
     * Returns an EMPTY collection when the loan has no penalty accruals;
     * it does not return `null`.
     *
     * COMPOSITION RULE: the caller MUST have resolved `$loanId` through
     * a tenant-scoped lookup (e.g.
     * `LoanRepository::findForTenantOrFail()`, A.55) before invoking
     * this method. See the class docblock §"Tenant-scoping composition".
     *
     * @param int           $loanId
     * @param list<string>  $with   Relations to eager-load.
     * @return Collection<int, LoanPenaltyAccrual>
     */
    public function listPenaltyForLoan(
        int $loanId,
        array $with = [],
    ): Collection {
        /** @var Collection<int, LoanPenaltyAccrual> $accruals */
        $accruals = LoanPenaltyAccrual::query()
            ->where('loan_id', $loanId)
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        return $accruals;
    }

    /**
     * List the penalty accruals for a set of installment ids.
     *
     * Returns an Eloquent `Collection` of `LoanPenaltyAccrual` models
     * ordered by `recorded_at` ascending with an `id` ascending
     * tiebreaker. Each installment contributes at most one row (per the
     * migration A.16's UNIQUE constraint on `accrual_period_key`), but
     * an installment that has not been penalized is simply absent from
     * the result.
     *
     * The method is the batch-lookup primitive used by
     * `LoanReconciliationService::collectPerInstallmentBreakdown()`
     * (A.36), which eager-loads each accrual's `walletObligation`
     * relation to render the per-installment monetary view.
     *
     * Returns an EMPTY collection when no installment in the input set
     * has a penalty accrual.
     *
     * COMPOSITION RULE: same as `listInterestByInstallmentIds()`.
     *
     * @param list<int>     $installmentIds
     * @param list<string>  $with            Relations to eager-load.
     * @return Collection<int, LoanPenaltyAccrual>
     */
    public function listPenaltyByInstallmentIds(
        array $installmentIds,
        array $with = [],
    ): Collection {
        if ($installmentIds === []) {
            return new Collection();
        }

        /** @var Collection<int, LoanPenaltyAccrual> $accruals */
        $accruals = LoanPenaltyAccrual::query()
            ->whereIn('installment_id', $installmentIds)
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        return $accruals;
    }

    /**
     * Whether a penalty accrual exists for a given accrual-period key.
     *
     * The fast-path idempotency check performed by
     * `LoanArrearsService::detect()` (A.33) BEFORE attempting its
     * INSERT. The key format is `"{loan_id}:{installment_id}"` (per the
     * migration A.16); the repository accepts it as an opaque string.
     *
     * Returns `true` when a row with the key exists; `false` otherwise.
     * The unique constraint remains the authoritative enforcer under
     * concurrency.
     *
     * COMPOSITION RULE: the caller MUST have derived the key from a
     * tenant-scoped loan + installment pair. See the class docblock
     * §"Tenant-scoping composition".
     *
     * @param string $accrualPeriodKey
     * @return bool
     */
    public function penaltyExistsForKey(string $accrualPeriodKey): bool
    {
        return LoanPenaltyAccrual::query()
            ->where('accrual_period_key', $accrualPeriodKey)
            ->exists();
    }

    // =========================================================================
    // Public API — cross-table batch lookup
    // =========================================================================

    /**
     * The set of installment ids whose interest or penalty accruals
     * reference any of the given Wallet obligation ids.
     *
     * Queries BOTH the `loan_interest_accruals` and
     * `loan_penalty_accruals` tables (migrations A.15 and A.16), unions
     * the matching `installment_id` values, and returns the deduplicated
     * set as a proper list of integers. The order is unspecified; a
     * caller that needs a specific order sorts the result itself.
     *
     * The method is the batch-lookup primitive used by
     * `LoanRepaymentService::record()` (A.31) inside its
     * `affectedInstallmentIds()` helper, which determines which
     * installments to consider for settlement after a repayment's
     * allocations are applied.
     *
     * Returns an EMPTY list when none of the supplied obligation ids is
     * referenced by any accrual row — the case in which the repayment
     * touched only non-loan-linked obligations, or only principal-linked
     * obligations (which have no corresponding accrual row).
     *
     * COMPOSITION RULE: the caller MUST have obtained
     * `$walletObligationIds` from a tenant-scoped Wallet-side read. See
     * the class docblock §"Tenant-scoping composition".
     *
     * @param list<int> $walletObligationIds
     * @return list<int>
     */
    public function installmentIdsByObligationIds(array $walletObligationIds): array
    {
        if ($walletObligationIds === []) {
            return [];
        }

        /** @var list<int|string> $interestIds */
        $interestIds = LoanInterestAccrual::query()
            ->whereIn('wallet_obligation_id', $walletObligationIds)
            ->pluck('installment_id')
            ->all();

        /** @var list<int|string> $penaltyIds */
        $penaltyIds = LoanPenaltyAccrual::query()
            ->whereIn('wallet_obligation_id', $walletObligationIds)
            ->pluck('installment_id')
            ->all();

        $merged = array_merge($interestIds, $penaltyIds);

        /** @var list<int> $result */
        $result = array_values(array_unique(array_map('intval', $merged)));

        return $result;
    }
}