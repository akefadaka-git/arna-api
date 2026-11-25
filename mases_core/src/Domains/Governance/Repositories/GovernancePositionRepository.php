<?php

declare(strict_types=1);


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