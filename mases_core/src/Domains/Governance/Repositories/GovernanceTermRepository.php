<?php

declare(strict_types=1);


namespace MaaleArna\MASES\Core\Domains\Governance\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;

/**
 * GovernanceTermRepository
 *
 * Read-only query surface for `GovernanceTerm` rows.
 *
 * See the file-level change-verification block for the repository's
 * shape, its tenant and body scoping, its pagination and filter
 * contract, its currentness queries, and its deliberate absence of
 * write methods.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Repositories
 * @version 1.0.0
 */
final class GovernanceTermRepository
{
    /**
     * Default page size when the caller does not specify one.
     */
    private const DEFAULT_PER_PAGE = 25;

    /**
     * Maximum page size the caller may request.
     */
    private const MAX_PER_PAGE = 200;

    // =========================================================================
    // Listing
    // =========================================================================

    /**
     * Paginate terms belonging to a body.
     *
     * The result is scoped to BOTH `$tenantId` and `$bodyId`.
     *
     * Recognised filter keys:
     *
     *     'is_active'   — a bool.
     *     'is_current'  — a bool; applies or negates the current()
     *                     scope.
     *     'search'      — a string searched across `label_am`,
     *                     `label_en`.
     *
     * Order: `effective_from ASC, id ASC`.
     *
     * @param  int                  $tenantId
     * @param  int                  $bodyId
     * @param  array<string, mixed> $filters
     * @param  int                  $perPage
     * @param  array<int, string>   $with
     * @return LengthAwarePaginator
     */
    public function paginateForBody(
        int $tenantId,
        int $bodyId,
        array $filters = [],
        int $perPage = self::DEFAULT_PER_PAGE,
        array $with = []
    ): LengthAwarePaginator {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $query = GovernanceTerm::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId);

        $this->applyFilters($query, $filters);

        if ($with !== []) {
            $query->with($with);
        }

        return $query
            ->orderBy('effective_from', 'asc')
            ->orderBy('id', 'asc')
            ->paginate($perPage);
    }

    // =========================================================================
    // Single-row lookups by primary key (tenant-scoped)
    // =========================================================================

    /**
     * Find a term by primary key, scoped to a tenant.
     *
     * @param  int                $tenantId
     * @param  int                $id
     * @param  array<int, string> $with
     * @return GovernanceTerm|null
     */
    public function findForTenant(int $tenantId, int $id, array $with = []): ?GovernanceTerm
    {
        $query = GovernanceTerm::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id);

        if ($with !== []) {
            $query->with($with);
        }

        return $query->first();
    }

    /**
     * Find a term by primary key, scoped to a tenant, or throw.
     *
     * Throws `ModelNotFoundException` when no row is found in the
     * tenant, INCLUDING the case where the id exists in a different
     * tenant. The exception is constructed directly rather than via
     * a query, so a cross-tenant id cannot accidentally be returned.
     *
     * @param  int                $tenantId
     * @param  int                $id
     * @param  array<int, string> $with
     * @return GovernanceTerm
     *
     * @throws ModelNotFoundException
     */
    public function findForTenantOrFail(int $tenantId, int $id, array $with = []): GovernanceTerm
    {
        $term = $this->findForTenant($tenantId, $id, $with);

        if ($term === null) {
            throw (new ModelNotFoundException())->setModel(
                GovernanceTerm::class,
                [$id],
            );
        }

        return $term;
    }

    // =========================================================================
    // Existence checks
    // =========================================================================

    /**
     * Whether a term with the given primary key exists in the tenant.
     *
     * @param  int  $tenantId
     * @param  int  $id
     * @return bool
     */
    public function existsForTenant(int $tenantId, int $id): bool
    {
        return GovernanceTerm::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->exists();
    }

    // =========================================================================
    // Counts
    // =========================================================================

    /**
     * Count terms for a body in a tenant.
     *
     * When `$activeOnly` is true, only terms with `is_active = true`
     * are counted. Soft-deleted rows are excluded by the model's
     * global scope.
     *
     * @param  int  $tenantId
     * @param  int  $bodyId
     * @param  bool $activeOnly
     * @return int
     */
    public function countForBody(int $tenantId, int $bodyId, bool $activeOnly = false): int
    {
        $query = GovernanceTerm::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->count();
    }

    // =========================================================================
    // Currentness queries
    // =========================================================================

    /**
     * Find the current term of a body, if any.
     *
     * "Current" means the term's date range contains today (null
     * bounds treated as unbounded). See the model's `isCurrent()`
     * accessor (G2b file 14 of 27) and its `current()` scope.
     *
     * When multiple terms of a body are current — which the schema
     * permits, since overlapping terms are not forbidden — the
     * method returns the one with the LATEST `effective_from`.
     * Nulls sort lowest, so a term with a null `effective_from` is
     * only returned when no dated term is current. The tiebreaker
     * is `id DESC` (the most recently created term wins).
     *
     * The method does NOT filter by `is_active`. An inactive term
     * whose dates contain today is still "current" in the temporal
     * sense. A caller that wants "authoritative AND current" tests
     * `isActive()` on the returned model, or uses
     * `findAllCurrentForBody()` and filters.
     *
     * Returns null when no term of the body is current.
     *
     * @param  int                $tenantId
     * @param  int                $bodyId
     * @param  array<int, string> $with
     * @return GovernanceTerm|null
     */
    public function findCurrentForBody(
        int $tenantId,
        int $bodyId,
        array $with = []
    ): ?GovernanceTerm {
        $query = GovernanceTerm::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId)
            ->current();

        if ($with !== []) {
            $query->with($with);
        }

        return $query
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Find all current terms of a body.
     *
     * Returns every term whose date range contains today. When the
     * body's terms do not overlap, the collection has at most one
     * element. When they do overlap — which the schema permits —
     * the collection may have more.
     *
     * The method is provided alongside `findCurrentForBody()` for
     * callers that need to detect and surface the overlap rather
     * than silently pick one. A UI that shows "the current term"
     * uses `findCurrentForBody()`; an administrative view that
     * warns about overlapping terms uses this method.
     *
     * The collection is ordered by `effective_from DESC, id DESC`,
     * matching `findCurrentForBody()`'s selection order.
     *
     * The method does NOT filter by `is_active`.
     *
     * @param  int                $tenantId
     * @param  int                $bodyId
     * @param  array<int, string> $with
     * @return Collection<int, GovernanceTerm>
     */
    public function findAllCurrentForBody(
        int $tenantId,
        int $bodyId,
        array $with = []
    ): Collection {
        $query = GovernanceTerm::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId)
            ->current();

        if ($with !== []) {
            $query->with($with);
        }

        return $query
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Apply the recognised filter keys to the query.
     *
     * @param  Builder              $query
     * @param  array<string, mixed> $filters
     * @return void
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        // Active flag.
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        // Currentness flag.
        if (array_key_exists('is_current', $filters) && $filters['is_current'] !== null) {
            $isCurrent = (bool) $filters['is_current'];

            if ($isCurrent) {
                $query->current();
            } else {
                $query->whereNot(function (Builder $q): void {
                    // Wrap the negation in a grouped WHERE so it does
                    // not interfere with other clauses. The inner
                    // subquery mirrors the current() scope exactly.
                    $today = Carbon::today()->toDateString();

                    $q->where(function (Builder $q2) use ($today): void {
                        $q2->whereNull('effective_from')
                           ->orWhere('effective_from', '<=', $today);
                    })->where(function (Builder $q2) use ($today): void {
                        $q2->whereNull('effective_until')
                           ->orWhere('effective_until', '>=', $today);
                    });
                });
            }
        }

        // Search across the two label columns.
        if (array_key_exists('search', $filters)) {
            $term = is_string($filters['search']) ? trim($filters['search']) : '';

            if ($term !== '') {
                $this->applyLabelSearch($query, $term);
            }
        }
    }

    /**
     * Apply an OR-chain LIKE condition across the two label columns.
     *
     * LIKE wildcards in the user's term are escaped.
     *
     * @param  Builder $query
     * @param  string  $term
     * @return void
     */
    private function applyLabelSearch(Builder $query, string $term): void
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $pattern = '%' . $escaped . '%';

        $query->where(function (Builder $q) use ($pattern): void {
            $q->where('label_am', 'like', $pattern)
              ->orWhere('label_en', 'like', $pattern);
        });
    }
}