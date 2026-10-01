<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 3 of 27
 * =============================================================================
 *
 * File:            Repositories/GovernancePositionRepository.php  (NEW)
 * Unit:            G2b — GovernancePosition vertical slice (repository)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `GovernancePosition` model (G2b
 *   file 2 of 27) could be queried from any caller via Eloquent's
 *   own static API, but the domain had no centralised, tenant-scoped
 *   query surface for positions. Every controller, service, or
 *   future consumer that needed to load a position would have had
 *   to construct its own query — with the risk that a caller would
 *   forget the tenant scope, that two callers would implement the
 *   same filter differently, or that a future schema change would
 *   require edits scattered across the domain.
 *
 * After:
 *   One file that declares the domain's read-only query surface for
 *   `GovernancePosition`. Every read path in the domain — the
 *   service (G2b file 4), the policy (G2b file 5), the controller
 *   (G2b file 9), and every future caller — goes through this class.
 *
 *   The repository is:
 *
 *     · Read-only. It declares no `create()`, no `update()`, no
 *       `delete()`, no `restore()`, and no `forceDelete()`. Every
 *       mutation is performed by `GovernancePositionService` (G2b
 *       file 4), which validates invariants, wraps the write in a
 *       transaction, and dispatches an event after commit.
 *
 *     · Tenant-scoped by construction. Every public method takes
 *       `int $tenantId` as its first parameter and applies it as a
 *       WHERE clause. A caller cannot accidentally return a
 *       position from another tenant.
 *
 *     · Body-scoped for its primary list. `paginateForBody()` takes
 *       a `bodyId` in addition to the `tenantId`, because the
 *       natural list query for positions is "positions of this
 *       body" — a body-scoped query that happens to be
 *       tenant-scoped as a consequence.
 *
 *   The methods it declares:
 *
 *     paginateForBody($tenantId, $bodyId, $filters, $perPage, $with)
 *         — Paginated list of a body's positions, with optional
 *           filters.
 *
 *     findForTenant($tenantId, $id, $with)
 *         — Single row by primary key, tenant-scoped.
 *
 *     findForTenantOrFail($tenantId, $id, $with)
 *         — Same, but throws ModelNotFoundException on miss.
 *
 *     findByCode($tenantId, $bodyId, $code, $with)
 *         — Single row by natural key within a body, tenant-scoped.
 *
 *     findByCodeOrFail($tenantId, $bodyId, $code, $with)
 *         — Same, but throws on miss.
 *
 *     existsForTenant($tenantId, $id)
 *         — Existence check by primary key.
 *
 *     existsByCode($tenantId, $bodyId, $code, $exceptId)
 *         — Natural-key uniqueness check within a body.
 *
 *     countForBody($tenantId, $bodyId, $activeOnly)
 *         — Total count of positions for a body.
 *
 *     countVotingForBody($tenantId, $bodyId)
 *         — Count of voting positions for a body. A convenience
 *           accessor for a future quorum computation in G5.
 *
 *   What this repository deliberately does NOT do:
 *
 *     · It does NOT resolve foreign relations automatically. A
 *       caller that needs `body` or `tenant` passes them via the
 *       `$with` parameter.
 *
 *     · It does NOT declare a `search()` method separate from
 *       `paginateForBody()`. The `search` filter is applied inside
 *       the paginated list path, so filter and paginate share one
 *       implementation.
 *
 *     · It does NOT return typed DTOs. It returns
 *       `GovernancePosition` models and standard Laravel
 *       collections / paginators.
 *
 *     · It does NOT implement cross-body uniqueness. Uniqueness is
 *       always scoped to a single body; a `findByCode` across all
 *       bodies would be a category error.
 *
 * =============================================================================
 * Why this class exists
 * =============================================================================
 * Position lookups are performed from several places:
 *
 *   1. The HTTP controller layer (G2b file 9) — for listing and
 *      viewing.
 *   2. The service layer (G2b file 4) — for existence and
 *      uniqueness checks before writes.
 *   3. The policy layer (G2b file 5) — for tenant ownership
 *      resolution during authorization.
 *   4. Future cross-cutting callers — a membership validator in
 *      G2c that checks whether a proposed position exists and is
 *      active; a dashboard count.
 *
 * Centralising the queries serves the same purposes the equivalent
 * repository does for `GovernanceBody`:
 *
 *   · Every query is tenant-scoped by construction.
 *
 *   · The name-search behaviour is implemented once.
 *
 *   · Tests can construct a repository against a known fixture and
 *     assert behaviour without going through HTTP.
 *
 * This structure mirrors `GovernanceBodyRepository`,
 * `ShareholderRepository`, and `WalletRepository`.
 *
 * =============================================================================
 * Tenant scoping — the platform's contract
 * =============================================================================
 * Every public method takes `int $tenantId` as its FIRST parameter,
 * before any other argument. The caller resolves this value from the
 * established MASES tenant context and passes it in.
 *
 * This class does NOT read `mases_tenant_id()` or any request
 * header. Requiring the caller to supply the value makes the trust
 * boundary explicit.
 *
 * The body-scoped methods (`paginateForBody`, `findByCode`,
 * `existsByCode`, `countForBody`) take both `$tenantId` and
 * `$bodyId`. The tenant scope is a WHERE clause on `tenant_id`; the
 * body scope is a WHERE clause on `governance_body_id`. Both are
 * applied. A caller that supplies a `$bodyId` belonging to a
 * different tenant receives an empty result — the query filters on
 * both, so the cross-tenant body's positions are never returned.
 *
 * =============================================================================
 * Pagination and filters
 * =============================================================================
 * `paginateForBody()` recognises four filter keys:
 *
 *     'is_active'   — a bool; filters on `is_active`.
 *     'is_voting'   — a bool; filters on `is_voting`.
 *     'is_officer'  — a bool; filters on `is_officer`.
 *     'search'      — a string; case-insensitive LIKE across
 *                     `code`, `name_am`, `name_en`.
 *
 * Unrecognised keys are ignored. A null or empty-string value for
 * any filter is treated as "filter not present".
 *
 * The pagination page size is clamped to `[1, MAX_PER_PAGE]`.
 *
 * The default order is `sort_order ASC, id ASC`. This is the
 * position's natural display order: positions with lower
 * `sort_order` values appear first; ties are broken by insertion
 * order (ascending id). This differs from
 * `GovernanceBodyRepository`'s `code ASC` ordering because a
 * position's display order is a first-class concept the schema
 * models (the `sort_order` column), whereas a body's display order
 * is derived from its code.
 *
 * =============================================================================
 * Search behaviour
 * =============================================================================
 * The `search` filter matches the term against three columns:
 *
 *     code     — the canonical uppercase identifier
 *     name_am  — the Amharic display name
 *     name_en  — the English display name
 *
 * The match is a case-insensitive LIKE `%term%`, with LIKE
 * wildcards in the user's term escaped. The database's default
 * collation (`utf8mb4_unicode_ci`) handles the case-insensitivity.
 *
 * The `description_am` and `description_en` columns are
 * deliberately NOT searched. Both are free-text fields; including
 * them would produce too many false-positive matches in a search
 * intended to locate a specific position by its identity. A future
 * CR that needs full-text search across descriptions is a separate
 * feature.
 *
 * =============================================================================
 * No writes
 * =============================================================================
 * This repository is READ-ONLY by design. It exposes no `create`,
 * `update`, `delete`, `restore`, or `forceDelete` methods.
 * Persistence for positions is performed exclusively by
 * `GovernancePositionService` (G2b file 4), which:
 *
 *   - Validates the code-uniqueness rule within the body.
 *   - Validates that the body exists in the same tenant.
 *   - Validates that at least one bilingual name is non-empty.
 *   - Wraps the operation in a database transaction.
 *   - Dispatches the domain event after commit.
 *
 * =============================================================================
 * Eager-loading
 * =============================================================================
 * Methods accept an optional `array $with` parameter listing
 * relations to eager-load. Callers that render the result to JSON
 * typically pass `['body']` to avoid N+1 on the resource's optional
 * embedded body summary. Callers that only need counts pass an
 * empty array. The default is empty — the repository never
 * eager-loads relations the caller did not request.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Repositories
 * @version 1.0.0
 */
namespace MaaleArna\MASES\Core\Domains\Governance\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;

/**
 * GovernancePositionRepository
 *
 * Read-only query surface for `GovernancePosition` rows.
 *
 * See the file-level change-verification block for the repository's
 * shape, its tenant and body scoping, its pagination and filter
 * contract, and its deliberate absence of write methods.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Repositories
 * @version 1.0.0
 */
final class GovernancePositionRepository
{
    /**
     * Default page size when the caller does not specify one.
     */
    private const DEFAULT_PER_PAGE = 25;

    /**
     * Maximum page size the caller may request.
     *
     * Protects against a caller requesting `per_page=10000`. Enforced
     * inside `paginateForBody()` by clamping the input.
     */
    private const MAX_PER_PAGE = 200;

    // =========================================================================
    // Listing
    // =========================================================================

    /**
     * Paginate positions belonging to a body.
     *
     * The result is scoped to BOTH `$tenantId` and `$bodyId`. A
     * `$bodyId` that belongs to a different tenant produces an empty
     * result — the tenant filter excludes the cross-tenant body's
     * positions.
     *
     * Recognised filter keys:
     *
     *     'is_active'   — a bool.
     *     'is_voting'   — a bool.
     *     'is_officer'  — a bool.
     *     'search'      — a string searched across `code`,
     *                     `name_am`, `name_en`.
     *
     * Order: `sort_order ASC, id ASC`. See the file-level
     * change-verification block §"Pagination and filters".
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

        $query = GovernancePosition::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId);

        $this->applyFilters($query, $filters);

        if ($with !== []) {
            $query->with($with);
        }

        return $query
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->paginate($perPage);
    }

    // =========================================================================
    // Single-row lookups by primary key (tenant-scoped)
    // =========================================================================

    /**
     * Find a position by primary key, scoped to a tenant.
     *
     * Returns null if no matching row exists or if the row belongs to
     * a different tenant.
     *
     * @param  int                $tenantId
     * @param  int                $id
     * @param  array<int, string> $with
     * @return GovernancePosition|null
     */
    public function findForTenant(int $tenantId, int $id, array $with = []): ?GovernancePosition
    {
        $query = GovernancePosition::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id);

        if ($with !== []) {
            $query->with($with);
        }

        return $query->first();
    }

    /**
     * Find a position by primary key, scoped to a tenant, or throw.
     *
     * Throws `ModelNotFoundException` when no row is found in the
     * tenant, INCLUDING the case where the id exists in a different
     * tenant. The exception is constructed directly rather than via
     * a query, so a cross-tenant id cannot accidentally be returned
     * — the same defensive correction the
     * `GovernanceBodyRepository::findForTenantOrFail()` method
     * documents for its Shareholder predecessor.
     *
     * @param  int                $tenantId
     * @param  int                $id
     * @param  array<int, string> $with
     * @return GovernancePosition
     *
     * @throws ModelNotFoundException
     */
    public function findForTenantOrFail(int $tenantId, int $id, array $with = []): GovernancePosition
    {
        $position = $this->findForTenant($tenantId, $id, $with);

        if ($position === null) {
            throw (new ModelNotFoundException())->setModel(
                GovernancePosition::class,
                [$id],
            );
        }

        return $position;
    }

    // =========================================================================
    // Single-row lookups by natural key (tenant + body scoped)
    // =========================================================================

    /**
     * Find a position by its canonical code within a body, scoped to
     * a tenant.
     *
     * The lookup is case-insensitive because (a) the model normalises
     * stored codes to uppercase on write and (b) the column's default
     * collation compares case-insensitively. The term is trimmed and
     * uppercased before the query, matching the model's `code`
     * set-mutator contract.
     *
     * Returns null if no matching row exists in the tenant and body.
     *
     * @param  int                $tenantId
     * @param  int                $bodyId
     * @param  string             $code
     * @param  array<int, string> $with
     * @return GovernancePosition|null
     */
    public function findByCode(
        int $tenantId,
        int $bodyId,
        string $code,
        array $with = []
    ): ?GovernancePosition {
        $normalised = strtoupper(trim($code));

        $query = GovernancePosition::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId)
            ->where('code', $normalised);

        if ($with !== []) {
            $query->with($with);
        }

        return $query->first();
    }

    /**
     * Find a position by its canonical code within a body, scoped to
     * a tenant, or throw.
     *
     * @param  int                $tenantId
     * @param  int                $bodyId
     * @param  string             $code
     * @param  array<int, string> $with
     * @return GovernancePosition
     *
     * @throws ModelNotFoundException
     */
    public function findByCodeOrFail(
        int $tenantId,
        int $bodyId,
        string $code,
        array $with = []
    ): GovernancePosition {
        $position = $this->findByCode($tenantId, $bodyId, $code, $with);

        if ($position === null) {
            throw (new ModelNotFoundException())->setModel(
                GovernancePosition::class,
                [$code],
            );
        }

        return $position;
    }

    // =========================================================================
    // Existence checks
    // =========================================================================

    /**
     * Whether a position with the given primary key exists in the
     * tenant.
     *
     * Returns true for any row in the tenant, regardless of
     * `is_active` or `deleted_at`. Soft-deleted rows are excluded by
     * the model's global scope.
     *
     * @param  int  $tenantId
     * @param  int  $id
     * @return bool
     */
    public function existsForTenant(int $tenantId, int $id): bool
    {
        return GovernancePosition::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->exists();
    }

    /**
     * Whether a position with the given code exists within a body.
     *
     * The lookup is case-insensitive; the term is normalised to
     * uppercase before the query.
     *
     * The `$exceptId` parameter allows a caller to exclude a specific
     * row from the check — used by
     * `GovernancePositionService::update()` to verify that a proposed
     * new code does not collide with any OTHER position in the same
     * body, while permitting the row's own current code to be
     * "re-used" in a no-op update.
     *
     * @param  int      $tenantId
     * @param  int      $bodyId
     * @param  string   $code
     * @param  int|null $exceptId
     * @return bool
     */
    public function existsByCode(
        int $tenantId,
        int $bodyId,
        string $code,
        ?int $exceptId = null
    ): bool {
        $normalised = strtoupper(trim($code));

        $query = GovernancePosition::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId)
            ->where('code', $normalised);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->exists();
    }

    // =========================================================================
    // Counts
    // =========================================================================

    /**
     * Count positions for a body in a tenant.
     *
     * When `$activeOnly` is true, only positions with
     * `is_active = true` are counted. Soft-deleted rows are excluded
     * in every case by the model's global scope.
     *
     * @param  int  $tenantId
     * @param  int  $bodyId
     * @param  bool $activeOnly
     * @return int
     */
    public function countForBody(int $tenantId, int $bodyId, bool $activeOnly = false): int
    {
        $query = GovernancePosition::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->count();
    }

    /**
     * Count voting positions for a body.
     *
     * A convenience accessor for a future quorum computation in G5.
     * Counts positions with `is_voting = true` AND `is_active = true`;
     * a deactivated voting position does not contribute to a body's
     * voting-slot count.
     *
     * G2b does not consume this value. It exists so that G5's
     * quorum logic, when written, has a ready query. If G5's design
     * does not need it, the method is unused and harmless.
     *
     * @param  int  $tenantId
     * @param  int  $bodyId
     * @return int
     */
    public function countVotingForBody(int $tenantId, int $bodyId): int
    {
        return GovernancePosition::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId)
            ->where('is_active', true)
            ->where('is_voting', true)
            ->count();
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Apply the recognised filter keys to the query.
     *
     * Unknown keys are silently ignored. Empty or null values are
     * also ignored.
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

        // Voting flag.
        if (array_key_exists('is_voting', $filters) && $filters['is_voting'] !== null) {
            $query->where('is_voting', (bool) $filters['is_voting']);
        }

        // Officer flag.
        if (array_key_exists('is_officer', $filters) && $filters['is_officer'] !== null) {
            $query->where('is_officer', (bool) $filters['is_officer']);
        }

        // Search across code + bilingual names.
        if (array_key_exists('search', $filters)) {
            $term = is_string($filters['search']) ? trim($filters['search']) : '';

            if ($term !== '') {
                $this->applyNameSearch($query, $term);
            }
        }
    }

    /**
     * Apply an OR-chain LIKE condition across the three identity
     * columns: `code`, `name_am`, `name_en`.
     *
     * LIKE wildcards in the user's term are escaped. Case-
     * insensitivity is provided by the column's default collation.
     *
     * @param  Builder $query
     * @param  string  $term
     * @return void
     */
    private function applyNameSearch(Builder $query, string $term): void
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $pattern = '%' . $escaped . '%';

        $columns = [
            'code',
            'name_am',
            'name_en',
        ];

        $query->where(function (Builder $q) use ($columns, $pattern): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $q->{$method}($column, 'like', $pattern);
            }
        });
    }
}