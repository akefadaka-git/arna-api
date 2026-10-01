<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Repositories;

use Illuminate\Database\Eloquent\Collection;
use MaaleArna\MASES\Core\Domains\Loan\Enums\CollateralStatus;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanCollateral;

/**
 * LoanCollateralRepository
 *
 * The read-only query surface for a loan's collateral records. Every read
 * of a `LoanCollateral` through the Loan domain flows through this
 * repository.
 *
 * =============================================================================
 * Scope — a loan's collaterals (a child repository)
 * =============================================================================
 * This repository is a CHILD repository: its aggregate is a loan's
 * collateral rows, and its lookup key is the parent loan's primary key.
 * The four methods it exposes cover the read patterns required by the
 * domain's services, resources, and tests:
 *
 *   findForLoan()               — single-row lookup by primary key,
 *                                 scoped to a loan, nullable.
 *   listForLoan()               — every collateral of a loan, in
 *                                 registration order.
 *   listRegisteredForLoan()     — the subset of a loan's collaterals that
 *                                 are currently held (status =
 *                                 `registered`), in registration order.
 *   existsForLoan()             — whether a loan has at least one
 *                                 collateral registered against it.
 *
 * The repository is READ-ONLY. It performs no writes, opens no
 * transactions, dispatches no events, and mutates no state. Every
 * mutation of the collateral table flows through
 * `LoanCollateralService::register()` (A.37) and
 * `LoanCollateralService::release()` (A.37).
 *
 * =============================================================================
 * Tenant-scoping composition — the caller's responsibility
 * =============================================================================
 * As with `LoanInstallmentRepository` (A.57), this repository takes
 * `$loanId` rather than `$tenantId`. The `loan_collaterals` table
 * (migration A.11) has no `tenant_id` column of its own; a loan's
 * collaterals are tenant-scoped TRANSITIVELY, through the loan.
 *
 * The composition pattern at a controller is identical to the one
 * documented on A.57:
 *
 *     // 1. Resolve the loan through the tenant-scoped LoanRepository.
 *     $loan = $this->loanRepository->findForTenantOrFail(
 *         tenantId: $tenantId,
 *         loanId:   $routeLoanId,
 *     );
 *
 *     // 2. Query collaterals by the resolved loan's primary key.
 *     $collaterals = $this->collateralRepository->listForLoan($loan->id);
 *
 * A non-existent loan id and a loan id from a DIFFERENT tenant both
 * produce a `ModelNotFoundException` at step 1, so step 2 is never
 * reached for a cross-tenant reference. The isolation contract is
 * preserved without the repository having to re-verify the parent's
 * tenant on every query.
 *
 * See A.57's class docblock §"Tenant-scoping composition" for the full
 * rationale; this repository follows the identical model.
 *
 * =============================================================================
 * The two collection-lookup methods — full vs. registered
 * =============================================================================
 * The distinction between `listForLoan()` and `listRegisteredForLoan()`
 * mirrors the two legitimate read patterns for a loan's collateral:
 *
 *   - `listForLoan()` returns the FULL collateral history of the loan,
 *     including both `registered` and `released` rows. It is the correct
 *     read for the loan's detail view (which presents the full security
 *     history, including previously-released collaterals), and for
 *     administrative reports that audit collateral releases.
 *
 *   - `listRegisteredForLoan()` returns only the collaterals currently
 *     held as security (status = `registered`, per A.3). It is the
 *     correct read for the "what security does this loan currently have?"
 *     question — the question that a pre-approval or pre-disbursement
 *     workflow (a future CR's collateral-coverage check) or a risk
 *     report needs answered.
 *
 * Both methods return rows ordered by `registered_at` ascending, with an
 * `id` ascending tiebreaker for same-instant registrations. See
 * §"Ordering" below.
 *
 * The two methods share the same ordering and eager-load semantics; they
 * differ only in the status filter that `listRegisteredForLoan()`
 * applies.
 *
 * =============================================================================
 * The `existsForLoan()` semantics
 * =============================================================================
 * The `existsForLoan()` method answers a deliberately precise question:
 * "does this loan have AT LEAST ONE collateral registered against it?".
 *
 * The answer is based on the loan's entire collateral population,
 * regardless of status. A loan whose only collateral has been released
 * returns `true` from this method, because the historical fact that a
 * collateral was once registered is preserved on the row (per the A.3
 * freeze: `released` is terminal, and the row remains in the table).
 *
 * A caller that needs the different question — "does this loan currently
 * hold any collateral as security?" — composes the query through
 * `listRegisteredForLoan()` and checks the returned collection's
 * emptiness. The two questions are distinct and are answered by two
 * distinct methods; conflating them into a single `existsForLoan()`
 * would produce the wrong answer for one of the two callers.
 *
 * No Phase 2C consumer requires either form of the existence check: the
 * frozen approval flow (A.29) does not enforce a collateral requirement
 * (the product-level `collateral.required` flag is read by
 * `LoanCollateralService`, not by the approval service), and the frozen
 * release flow (A.37) does not gate on the loan's remaining collateral
 * population. The method is delivered ahead of a future CR — a
 * collateral-coverage precondition on approval or disbursement — that
 * will consume it.
 *
 * =============================================================================
 * The `$with` eager-load parameter
 * =============================================================================
 * The three lookup methods (`findForLoan`, `listForLoan`,
 * `listRegisteredForLoan`) accept an optional `$with` array of relation
 * names to eager-load. The parameter is threaded directly into
 * Eloquent's `with()`, so callers pass the standard relation names
 * declared on the `LoanCollateral` model (A.20): `'loan'`,
 * `'pledgedShareHolding'`, `'registeredBy'`, `'releasedBy'`.
 *
 * The parameter is `list<string>` and defaults to `[]`. It exists so
 * that a controller can compose a resource's eager-load set at the query
 * boundary — the single point at which the N+1 problem is preventable —
 * rather than loading relations lazily inside the resource's `toArray()`.
 *
 * A typical call site for the release endpoint's pre-flight would be:
 *
 *     $collateral = $this->collateralRepository->findForLoan(
 *         loanId:        $loan->id,
 *         collateralId:  $routeCollateralId,
 *         with:          ['registeredBy', 'releasedBy'],
 *     );
 *
 * The release flow itself loads the collateral without eager-loading
 * (the service does not need the relations); the resource that renders
 * the response composes the eager-load set at the controller.
 *
 * =============================================================================
 * Ordering
 * =============================================================================
 * The two collection-lookup methods return rows ordered by
 * `registered_at` ascending, with an `id` ascending tiebreaker. The
 * ordering matches the `Loan::collaterals()` relation declared in A.18,
 * so a caller that reads the collection through the relation and a
 * caller that reads it through this repository observe the SAME order.
 *
 * `registered_at` is set by the migration (A.11) at row insert time; the
 * `id` tiebreaker resolves same-instant registrations, which are rare
 * but possible (a batch import of historical collateral records, or two
 * registrations within the same second). The combination is
 * deterministic.
 *
 * A caller that needs a different order (e.g. `released_at` descending
 * for a release-audit report) composes the query directly against the
 * `LoanCollateral` model. The repository's narrow surface deliberately
 * does not enumerate every possible order.
 *
 * =============================================================================
 * No exceptions from the repository
 * =============================================================================
 * No method on this repository throws. Missing rows produce `null` (for
 * `findForLoan()`) or an empty collection (for the `list*` methods). The
 * `existsForLoan()` method always returns a boolean.
 *
 * There is no `findForLoanOrFail()` variant. The one Phase 2C route that
 * resolves a collateral by primary key — the release endpoint
 * (`POST /loans/{loan}/collateral/{collateral}/release`, A.79) — will
 * use `findForLoan()` and translate the null return to a 404 via the
 * controller's own idiom (a `ModelNotFoundException` constructed from
 * `LoanCollateral::class`). The repository's `OrFail` variant is not
 * required; the controller's translation is unambiguous and keeps the
 * repository's surface uniform with A.57.
 *
 * =============================================================================
 * What this repository deliberately does NOT do
 * =============================================================================
 *   - It does NOT write. Every mutation flows through
 *     `LoanCollateralService` (A.37).
 *
 *   - It does NOT open a transaction. Every query is a single, atomic
 *     read.
 *
 *   - It does NOT dispatch events. Reads are silent.
 *
 *   - It does NOT join through the parent loan to enforce tenant
 *     isolation. See §"Tenant-scoping composition".
 *
 *   - It does NOT expose a `create`, `update`, `delete`, `save`,
 *     `restore`, or `forceDelete` method. The repository's method-name
 *     surface makes its read-only nature visible at a glance.
 *
 *   - It does NOT cache. Every call issues a fresh query.
 *
 *   - It does NOT expose a `listExternalForLoan()` or
 *     `listShareholdingForLoan()` method. The type distinction is a
 *     projection of the collateral set filtered by
 *     `collateral_type`; a caller composes that projection through the
 *     model's scopes (A.20) on the collection returned by
 *     `listForLoan()`.
 *
 *   - It does NOT expose a `listPledgeBackedForLoan()` method.
 *     `CollateralType::pledgeBackedTypes()` (A.4) currently returns a
 *     single-element list (`['shareholding']`), so a pledge-backed
 *     filter is equivalent to a shareholding-type filter. A future CR
 *     that adds a second pledge-backed type would introduce a dedicated
 *     method at that time.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A10 (collateral rules),
 *                                                  B4 (collateral
 *                                                  integration)
 *   Phase 2C Clarification Response, 2026-09-12  — C2 (release endpoint),
 *                                                  C3 (Loan-side pledge
 *                                                  sequencing)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §3
 *   Phase 2C A.3                                 — `CollateralStatus`
 *   Phase 2C A.4                                 — `CollateralType`
 *   Phase 2C A.11                                — `loan_collaterals`
 *                                                  migration (the
 *                                                  registered_at column
 *                                                  and the composite
 *                                                  indexes)
 *   Phase 2C A.20                                — `LoanCollateral` (the
 *                                                  model and its scopes)
 *   Phase 2C A.37                                — `LoanCollateralService`
 *                                                  (the sole writer)
 *   Phase 2C A.55                                — `LoanRepository` (the
 *                                                  parent repository)
 *   Phase 2C A.57                                — `LoanInstallmentRepository`
 *                                                  (the structural
 *                                                  sibling)
 *   Phase 2C A.79                                — `LoanCollateralController`
 *                                                  (the primary HTTP
 *                                                  consumer)
 *   Phase 2A 2A.13                               — `ShareholderRepository`
 *                                                  (the shape precedent)
 *   Phase 2B 2B.19                               — `WalletRepository`
 *                                                  (the direct sibling)
 *   Step 5 (manifest) A.58                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Repositories
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