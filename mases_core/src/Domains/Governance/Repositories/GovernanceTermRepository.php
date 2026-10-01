<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 15 of 27
 * =============================================================================
 *
 * File:            Repositories/GovernanceTermRepository.php  (NEW)
 * Unit:            G2b — GovernanceTerm vertical slice (repository)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `GovernanceTerm` model (G2b file 14
 *   of 27) could be queried from any caller via Eloquent's own
 *   static API, but the domain had no centralised, tenant-scoped
 *   query surface for terms. Every controller, service, or future
 *   consumer that needed to load a term would have had to construct
 *   its own query — with the risk that a caller would forget the
 *   tenant scope, that two callers would implement the same filter
 *   differently, or that a future schema change would require edits
 *   scattered across the domain.
 *
 * After:
 *   One file that declares the domain's read-only query surface for
 *   `GovernanceTerm`. Every read path in the domain — the service
 *   (G2b file 16), the policy (G2b file 17), the controller (G2b
 *   file 21), and every future caller — goes through this class.
 *
 *   The repository is:
 *
 *     · Read-only. It declares no write methods. Every mutation is
 *       performed by `GovernanceTermService` (G2b file 16), which
 *       validates invariants, wraps the write in a transaction, and
 *       dispatches an event after commit.
 *
 *     · Tenant-scoped by construction. Every public method takes
 *       `int $tenantId` as its first parameter and applies it as a
 *       WHERE clause.
 *
 *     · Body-scoped for its primary list. `paginateForBody()` takes
 *       a `bodyId` in addition to the `tenantId`, because the
 *       natural list query for terms is "terms of this body".
 *
 *   The methods it declares:
 *
 *     paginateForBody($tenantId, $bodyId, $filters, $perPage, $with)
 *         — Paginated list of a body's terms, with optional filters.
 *
 *     findForTenant($tenantId, $id, $with)
 *         — Single row by primary key, tenant-scoped.
 *
 *     findForTenantOrFail($tenantId, $id, $with)
 *         — Same, but throws ModelNotFoundException on miss.
 *
 *     existsForTenant($tenantId, $id)
 *         — Existence check by primary key.
 *
 *     countForBody($tenantId, $bodyId, $activeOnly)
 *         — Total count of terms for a body.
 *
 *     findCurrentForBody($tenantId, $bodyId, $with)
 *         — The term of a body whose date range contains today, or
 *           null if no term is current.
 *
 *     findAllCurrentForBody($tenantId, $bodyId, $with)
 *         — All current terms of a body, as a collection. Returns
 *           more than one only when the body's term data has
 *           overlapping ranges, which the schema permits.
 *
 *   What this repository deliberately does NOT do:
 *
 *     · It does NOT resolve foreign relations automatically. A
 *       caller that needs `body` or `tenant` passes them via the
 *       `$with` parameter.
 *
 *     · It does NOT declare a `search()` method separate from
 *       `paginateForBody()`. The `search` filter is applied inside
 *       the paginated list path.
 *
 *     · It does NOT return typed DTOs.
 *
 *     · It does NOT enforce non-overlap of terms. Overlapping terms
 *       are permitted by design; the `findAllCurrentForBody()`
 *       method surfaces the possibility by returning a collection
 *       rather than a single model.
 *
 *     · It does NOT filter by `is_active` by default. Active and
 *       current are orthogonal concepts — see the model docblock
 *       (G2b file 14 of 27).
 *
 * =============================================================================
 * Why this class exists
 * =============================================================================
 * Term lookups are performed from several places:
 *
 *   1. The HTTP controller layer (G2b file 21) — for listing and
 *      viewing.
 *   2. The service layer (G2b file 16) — for existence checks
 *      before writes.
 *   3. The policy layer (G2b file 17) — for tenant ownership
 *      resolution during authorization.
 *   4. Future cross-cutting callers — a membership validator in G2c
 *      that checks whether a term is current; a dashboard that
 *      shows the current term of each body.
 *
 * Centralising the queries serves the same purposes as the
 * equivalent repositories for `GovernanceBody` and
 * `GovernancePosition`.
 *
 * =============================================================================
 * Tenant scoping — the platform's contract
 * =============================================================================
 * Every public method takes `int $tenantId` as its FIRST parameter.
 * The caller resolves this value from the established MASES tenant
 * context and passes it in.
 *
 * The body-scoped methods (`paginateForBody`, `countForBody`,
 * `findCurrentForBody`, `findAllCurrentForBody`) take both
 * `$tenantId` and `$bodyId`. Both scopes are applied. A caller that
 * supplies a `$bodyId` belonging to a different tenant receives an
 * empty result.
 *
 * =============================================================================
 * Pagination and filters
 * =============================================================================
 * `paginateForBody()` recognises three filter keys:
 *
 *     'is_active'   — a bool; filters on `is_active`.
 *     'is_current'  — a bool; when true, applies the model's
 *                     `current()` scope; when false, applies its
 *                     negation (not current).
 *     'search'      — a string; case-insensitive LIKE across
 *                     `label_am`, `label_en`.
 *
 * Unrecognised keys are ignored. A null or empty-string value for
 * any filter is treated as "filter not present".
 *
 * The pagination page size is clamped to `[1, MAX_PER_PAGE]`.
 *
 * The default order is `effective_from ASC, id ASC`. This is the
 * term's natural chronological order: earlier terms first; ties are
 * broken by insertion order. This differs from
 * `GovernancePositionRepository`'s `sort_order ASC, id ASC`
 * ordering because a term has no explicit sort column — its
 * chronological position IS its display order.
 *
 * NULL `effective_from` sorts first in MySQL's default (nulls sort
 * lowest in ascending order). A term with an unknown start date
 * therefore appears before terms with a known start date. This is
 * the correct posture: an unknown-start term is the most
 * indefinite, and placing it first signals "this term's start is
 * not established".
 *
 * =============================================================================
 * Search behaviour
 * =============================================================================
 * The `search` filter matches the term against two columns:
 *
 *     label_am  — the Amharic label
 *     label_en  — the English label
 *
 * The match is a case-insensitive LIKE `%term%`, with LIKE
 * wildcards in the user's term escaped.
 *
 * The `notes` column is deliberately NOT searched. It is a free-
 * text field; including it would produce too many false-positive
 * matches in a search intended to locate a specific term by its
 * identity. A future CR that needs full-text search across notes is
 * a separate feature.
 *
 * =============================================================================
 * No writes
 * =============================================================================
 * This repository is READ-ONLY by design. Persistence for terms is
 * performed exclusively by `GovernanceTermService` (G2b file 16),
 * which validates the domain invariants before persisting.
 *
 * =============================================================================
 * Eager-loading
 * =============================================================================
 * Methods accept an optional `array $with` parameter listing
 * relations to eager-load. Callers that render the result to JSON
 * typically pass `['body']` to avoid N+1 on the resource's optional
 * embedded body summary.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Repositories
 * @version 1.0.0
 */
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