<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;

/**
 * LoanRepository
 *
 * The read-only query surface for the `Loan` aggregate. Every read of a
 * loan through the Loan domain flows through this repository; the
 * repository is the single enforcement point for tenant scoping.
 *
 * =============================================================================
 * Scope — the loan aggregate
 * =============================================================================
 * The repository exposes the seven query methods the Loan-domain
 * controllers and the domain's own read paths require:
 *
 *   findForTenant()          — single-row lookup, tenant-scoped, nullable.
 *   findForTenantOrFail()    — single-row lookup, tenant-scoped, throws
 *                              on absence. Used by controllers that must
 *                              render a 404 for a missing loan.
 *   listForTenant()          — collection lookup, tenant-scoped,
 *                              optionally filtered.
 *   listForBorrower()        — collection lookup, tenant-scoped AND
 *                              borrower-scoped. Used by the borrower-
 *                              facing read paths.
 *   paginateForTenant()      — paginated lookup, tenant-scoped,
 *                              optionally filtered. Used by the
 *                              `GET /loans` index endpoint.
 *   existsForTenant()        — existence check, tenant-scoped.
 *   countActiveForTenant()   — count of non-terminal loans in a tenant.
 *                              Used by administrative dashboards.
 *
 * The repository is READ-ONLY. It performs no writes, opens no
 * transactions, dispatches no events, and mutates no state. Every
 * mutation of the `Loan` aggregate flows through the domain services
 * (`LoanApplicationService`, A.28; `LoanApprovalService`, A.29;
 * `LoanDisbursementService`, A.30; etc.).
 *
 * =============================================================================
 * Tenant scoping — the repository's central invariant
 * =============================================================================
 * Every method takes `$tenantId` as its first parameter and composes the
 * query with `Loan::scopeForTenant()` (A.18). No method returns a loan
 * from another tenant, and no method allows a caller to bypass the scope.
 *
 * The scoping rule is that the repository is the SINGLE place a caller
 * can build a `Loan` query without having to remember the scope. A
 * controller that resolves the route-bound `{loan}` calls
 * `findForTenantOrFail()`; a controller that lists loans calls
 * `paginateForTenant()`. Neither composes the scope itself.
 *
 * The tenant scoping produces the platform-wide isolation property
 * documented in the handoff §3: a non-existent loan id and a loan id
 * that belongs to a DIFFERENT tenant produce IDENTICAL outcomes (a 404
 * from the controller that translates `ModelNotFoundException`). The two
 * cases are indistinguishable from the caller's perspective.
 *
 * =============================================================================
 * The `$with` eager-load parameter
 * =============================================================================
 * Every single-lookup and collection method accepts an optional `$with`
 * array of relation names to eager-load. The parameter is threaded
 * directly into Eloquent's `with()`, so callers pass the standard
 * relation names declared on the `Loan` model (A.18): `'borrower'`,
 * `'product'`, `'policyVersion'`, `'installments'`, `'collaterals'`,
 * `'restructurings'`, `'writeOff'`, `'statusHistories'`,
 * `'interestAccruals'`, `'penaltyAccruals'`, `'approvedBy'`,
 * `'disbursedBy'`, `'createdBy'`, `'tenant'`.
 *
 * The parameter is `list<string>` and defaults to `[]`. It exists so
 * that a controller can compose a resource's eager-load set at the query
 * boundary — the single point at which the N+1 problem is preventable —
 * rather than loading relations lazily inside the resource's `toArray()`.
 *
 * =============================================================================
 * The `$filters` parameter — the supported filter set
 * =============================================================================
 * The two collection-lookup methods (`listForTenant`,
 * `paginateForTenant`) accept an optional `$filters` array. The filters
 * are the intersection of (a) the fields that a Loan-domain read path
 * has a legitimate reason to filter by, and (b) the fields that are
 * indexed. The set is intentionally narrow; a filter that cannot be
 * indexed would produce a full-table scan under the composite indexes
 * declared in the migration A.9.
 *
 * The supported filters are:
 *
 *   'status'                  — a `LoanStatus` case or its string value.
 *                               Composes with the
 *                               `loans_tenant_status_index`.
 *
 *   'borrower_shareholder_id' — an integer shareholder id. Composes with
 *                               the `loans_tenant_borrower_index`.
 *
 *   'loan_product_id'         — an integer product id. Composes with the
 *                               `loans_tenant_product_index`.
 *
 *   'in_arrears'              — a boolean. When true, filters to loans
 *                               in the `in_arrears` status; when false,
 *                               is a no-op (does not exclude loans in
 *                               arrears; it merely does not restrict to
 *                               them). A caller that needs "loans NOT in
 *                               arrears" composes the exclusion via a
 *                               future filter; the current set does not
 *                               include it because no Phase 2C read path
 *                               requires the exclusion.
 *
 *   'active_only'             — a boolean. When true, filters to loans
 *                               NOT in any terminal status (via
 *                               `Loan::scopeActive()`, A.18).
 *
 * Unknown filter keys are IGNORED, not rejected. The choice is
 * deliberate: a repository is a low-level utility; a controller that
 * receives a bad filter key from a request should validate the key at
 * the request layer (a future `IndexLoanRequest`, not in this file's
 * scope). The repository's job is to apply the filters it understands
 * and to ignore the rest. If a future CR requires strict rejection, that
 * CR adds the validation at the caller level.
 *
 * =============================================================================
 * No exceptions from the repository
 * =============================================================================
 * With the sole exception of `findForTenantOrFail()`, no method on this
 * repository throws. Missing rows produce `null` (for the `find*`
 * methods) or an empty collection (for the `list*` methods). The
 * deliberate exception is `findForTenantOrFail()`, whose name documents
 * its behavior: it throws `ModelNotFoundException` on absence, for use
 * by controllers that render 404s directly from the exception.
 *
 * The distinction is preserved so that the two call-site patterns are
 * unambiguous:
 *
 *   - A controller that wants to distinguish "found" from "not found"
 *     and branch on the difference calls `findForTenant()` and inspects
 *     the return.
 *
 *   - A controller that must render 404 on absence calls
 *     `findForTenantOrFail()` and lets the framework's exception
 *     renderer do the work.
 *
 * =============================================================================
 * Ordering
 * =============================================================================
 * The collection-lookup methods (`listForTenant`, `listForBorrower`) and
 * the paginated method (`paginateForTenant`) return rows ordered by
 * `application_date` descending, with an `id` descending tiebreaker. The
 * ordering matches the composite index `loans_tenant_application_index`
 * declared in the migration A.9, so the read is served from the index.
 *
 * The most-recent-first order is the correct default for a loan list:
 * operators triaging the loan book want the newest applications at the
 * top. A caller that needs a different order composes it at the query
 * boundary in a future CR; the current default is not parameterized.
 *
 * =============================================================================
 * What this repository deliberately does NOT do
 * =============================================================================
 *   - It does NOT write. Every mutation flows through a domain service.
 *
 *   - It does NOT open a transaction. Every query is a single, atomic
 *     read; the repository has no multi-statement work that would
 *     require a transaction boundary.
 *
 *   - It does NOT dispatch events. Reads are silent; the Loan domain's
 *     audit trail records lifecycle transitions, not reads.
 *
 *   - It does NOT compose queries beyond the tenant scope and the
 *     documented filters. The repository is not a general-purpose query
 *     builder; a caller that needs a query the repository does not expose
 *     composes it against the `Loan` model directly, accepting the
 *     responsibility of remembering the tenant scope.
 *
 *   - It does NOT validate filter shapes. See §"The `$filters`
 *     parameter".
 *
 *   - It does NOT cache. Every call issues a fresh query. The repository
 *     holds no state across calls; a caller that needs to memoize a
 *     result holds it in its own scope.
 *
 *   - It does NOT expose a `create`, `update`, `delete`, `restore`, or
 *     `forceDelete` method. The repository's method-name surface makes
 *     its read-only nature visible at a glance.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D129, D130, B7
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §4
 *   Phase 2C A.2                                 — `LoanStatus`
 *   Phase 2C A.9                                 — `loans` migration
 *                                                  (the indexed columns
 *                                                  that this repository's
 *                                                  filters compose with)
 *   Phase 2C A.18                                — `Loan` (the model and
 *                                                  its scopes)
 *   Phase 2C A.77                                — `LoanController`
 *                                                  (the primary consumer)
 *   Phase 2A 2A.13                               — `ShareholderRepository`
 *                                                  (the shape precedent)
 *   Phase 2B 2B.19                               — `WalletRepository`
 *                                                  (the direct sibling)
 *   Step 5 (manifest) A.55                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Repositories
 * @version 1.0.0
 */
final class LoanRepository
{
    /**
     * The default number of rows per page for `paginateForTenant()`.
     *
     * The value is a deliberate default, not a hard maximum. A caller
     * that passes an explicit `$perPage` overrides it. The value matches
     * the platform-wide default used by Phase 2A's and Phase 2B's
     * repositories.
     */
    private const DEFAULT_PER_PAGE = 25;

    // =========================================================================
    // Public API — single-row lookups
    // =========================================================================

    /**
     * Find a loan by id within a tenant.
     *
     * Returns the loan when it exists and belongs to the tenant; returns
     * `null` otherwise. A loan that exists in a DIFFERENT tenant returns
     * `null`, indistinguishable from a non-existent id. This is the
     * platform-wide tenant-isolation contract (handoff §3).
     *
     * @param int           $tenantId
     * @param int           $loanId
     * @param list<string>  $with      Relations to eager-load.
     * @return Loan|null
     */
    public function findForTenant(
        int $tenantId,
        int $loanId,
        array $with = [],
    ): ?Loan {
        /** @var Loan|null $loan */
        $loan = Loan::query()
            ->forTenant($tenantId)
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->whereKey($loanId)
            ->first();

        return $loan;
    }

    /**
     * Find a loan by id within a tenant, or throw.
     *
     * Identical to `findForTenant()` in scope, but throws
     * `ModelNotFoundException` when the loan does not exist or belongs
     * to a different tenant. The two failure cases are indistinguishable
     * from the caller's perspective (the exception's model class is
     * `Loan` and its id list contains the requested id).
     *
     * Controllers that must render a 404 for a missing loan use this
     * method; the framework's exception renderer translates the
     * exception into the canonical MASES 404 envelope.
     *
     * @param int           $tenantId
     * @param int           $loanId
     * @param list<string>  $with      Relations to eager-load.
     * @return Loan
     *
     * @throws ModelNotFoundException
     */
    public function findForTenantOrFail(
        int $tenantId,
        int $loanId,
        array $with = [],
    ): Loan {
        $loan = $this->findForTenant($tenantId, $loanId, $with);

        if ($loan === null) {
            throw (new ModelNotFoundException())
                ->setModel(Loan::class, [$loanId]);
        }

        return $loan;
    }

    // =========================================================================
    // Public API — collection lookups
    // =========================================================================

    /**
     * List all loans in a tenant, optionally filtered.
     *
     * Returns an Eloquent `Collection` of `Loan` models ordered by
     * `application_date` descending with an `id` descending tiebreaker.
     * See the class docblock §"Ordering" for the rationale.
     *
     * See the class docblock §"The `$filters` parameter" for the
     * supported filter keys. Unknown keys are ignored.
     *
     * The method returns an EMPTY collection when no rows match; it does
     * not return `null`.
     *
     * @param int                $tenantId
     * @param array<string, mixed> $filters
     * @param list<string>       $with     Relations to eager-load.
     * @return Collection<int, Loan>
     */
    public function listForTenant(
        int $tenantId,
        array $filters = [],
        array $with = [],
    ): Collection {
        $query = Loan::query()
            ->forTenant($tenantId)
            ->when(
                $with !== [],
                static fn ($q) => $q->with($with),
            )
            ->orderByDesc('application_date')
            ->orderByDesc('id');

        $this->applyFilters($query, $filters);

        /** @var Collection<int, Loan> $loans */
        $loans = $query->get();

        return $loans;
    }

    /**
     * List all loans for a specific borrower within a tenant.
     *
     * Returns an Eloquent `Collection` of `Loan` models ordered by
     * `application_date` descending with an `id` descending tiebreaker.
     *
     * The method is the borrower-facing read path: it composes both the
     * tenant scope AND the borrower scope, so a caller cannot list
     * loans for a borrower in a different tenant.
     *
     * The borrower scope is expressed as
     * `borrower_shareholder_id = $shareholderId`, matching the
     * `loans_tenant_borrower_index` composite index declared in the
     * migration A.9.
     *
     * @param int           $tenantId
     * @param int           $shareholderId
     * @param list<string>  $with          Relations to eager-load.
     * @return Collection<int, Loan>
     */
    public function listForBorrower(
        int $tenantId,
        int $shareholderId,
        array $with = [],
    ): Collection {
        /** @var Collection<int, Loan> $loans */
        $loans = Loan::query()
            ->forTenant($tenantId)
            ->forBorrower($shareholderId)
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->orderByDesc('application_date')
            ->orderByDesc('id')
            ->get();

        return $loans;
    }

    // =========================================================================
    // Public API — paginated lookup
    // =========================================================================

    /**
     * Paginate loans in a tenant, optionally filtered.
     *
     * Returns a `LengthAwarePaginator` of `Loan` models ordered by
     * `application_date` descending with an `id` descending tiebreaker.
     *
     * The `$perPage` parameter defaults to `DEFAULT_PER_PAGE` (25). A
     * caller that passes an explicit value overrides it. The method does
     * NOT clamp `$perPage` to a maximum; the caller (typically a
     * controller backed by a request validator) is responsible for the
     * clamp. A future CR that introduces a platform-wide cap would add
     * it here.
     *
     * See the class docblock §"The `$filters` parameter" for the
     * supported filter keys.
     *
     * @param int                $tenantId
     * @param array<string, mixed> $filters
     * @param int                $perPage
     * @param list<string>       $with     Relations to eager-load.
     * @return LengthAwarePaginator<int, Loan>
     */
    public function paginateForTenant(
        int $tenantId,
        array $filters = [],
        int $perPage = self::DEFAULT_PER_PAGE,
        array $with = [],
    ): LengthAwarePaginator {
        $query = Loan::query()
            ->forTenant($tenantId)
            ->when(
                $with !== [],
                static fn ($q) => $q->with($with),
            )
            ->orderByDesc('application_date')
            ->orderByDesc('id');

        $this->applyFilters($query, $filters);

        /** @var LengthAwarePaginator<int, Loan> $paginator */
        $paginator = $query->paginate($perPage);

        return $paginator;
    }

    // =========================================================================
    // Public API — existence and aggregate lookups
    // =========================================================================

    /**
     * Whether a loan exists within a tenant.
     *
     * Returns `true` only when a row with the given id exists in the
     * given tenant. A loan that exists in a DIFFERENT tenant returns
     * `false`, indistinguishable from a non-existent id.
     *
     * The method issues an `EXISTS` query, which does not retrieve the
     * row. A caller that needs the row uses `findForTenant()`.
     *
     * @param int $tenantId
     * @param int $loanId
     * @return bool
     */
    public function existsForTenant(int $tenantId, int $loanId): bool
    {
        return Loan::query()
            ->forTenant($tenantId)
            ->whereKey($loanId)
            ->exists();
    }

    /**
     * Count the non-terminal loans in a tenant.
     *
     * "Non-terminal" is defined by `Loan::scopeActive()` (A.18), which
     * excludes loans in any of the four terminal statuses (`rejected`,
     * `written_off`, `settled`, `closed`). Every other status is
     * counted.
     *
     * The method is the numerator for the administrative "active loans
     * under management" dashboard metric. It issues a single `COUNT`
     * query; the composite index `loans_tenant_status_index` serves it.
     *
     * @param int $tenantId
     * @return int
     */
    public function countActiveForTenant(int $tenantId): int
    {
        return Loan::query()
            ->forTenant($tenantId)
            ->active()
            ->count();
    }

    // =========================================================================
    // Private — filter composition
    // =========================================================================
    /**
     * Apply the supported filters to a `Loan` query.
     *
     * See the class docblock §"The `$filters` parameter" for the
     * supported keys and their semantics. Unknown keys are ignored.
     *
     * The method takes the query by reference? No: Laravel's
     * `Illuminate\Database\Eloquent\Builder` is a mutable object, and
     * every `where*()` call mutates it in place, returning `$this` for
     * chaining. Passing it as a parameter therefore suffices; the caller
     * observes the mutation.
     *
     * @param Builder<Loan> $query
     * @param array<string, mixed>                        $filters
     * @return void
     */
    private function applyFilters(
        Builder $query,
        array $filters,
    ): void {
        if ($filters === []) {
            return;
        }

        // ---------------------------------------------------------------------
        // status — a LoanStatus case or its string value.
        // ---------------------------------------------------------------------
        if (array_key_exists('status', $filters) && $filters['status'] !== null) {
            /** @var LoanStatus|string $status */
            $status = $filters['status'];
            $query->inStatus($status);
        }

        // ---------------------------------------------------------------------
        // borrower_shareholder_id — an integer shareholder id.
        // ---------------------------------------------------------------------
        if (
            array_key_exists('borrower_shareholder_id', $filters)
            && $filters['borrower_shareholder_id'] !== null
        ) {
            $query->where(
                'borrower_shareholder_id',
                (int) $filters['borrower_shareholder_id'],
            );
        }

        // ---------------------------------------------------------------------
        // loan_product_id — an integer product id.
        // ---------------------------------------------------------------------
        if (
            array_key_exists('loan_product_id', $filters)
            && $filters['loan_product_id'] !== null
        ) {
            $query->where(
                'loan_product_id',
                (int) $filters['loan_product_id'],
            );
        }

        // ---------------------------------------------------------------------
        // in_arrears — when true, restrict to `in_arrears` status.
        // ---------------------------------------------------------------------
        if (array_key_exists('in_arrears', $filters) && $filters['in_arrears'] === true) {
            $query->inArrears();
        }

        // ---------------------------------------------------------------------
        // active_only — when true, exclude terminal statuses.
        // ---------------------------------------------------------------------
        if (array_key_exists('active_only', $filters) && $filters['active_only'] === true) {
            $query->active();
        }
    }
}