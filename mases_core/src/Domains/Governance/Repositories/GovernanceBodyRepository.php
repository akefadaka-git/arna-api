<?php

declare(strict_types=1);


namespace MaaleArna\MASES\Core\Domains\Governance\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceBodyType;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;

/**
 * GovernanceBodyRepository
 *
 * Read-only query surface for `GovernanceBody` rows.
 *
 * See the file-level change-verification block for the full rationale
 * on the repository's shape, its tenant-scoping contract, its
 * pagination and filter contract, its deliberate absence of write
 * methods, and the defensive correction to the Shareholder
 * precedent's `findForTenantOrFail()` behaviour.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Repositories
 * @version 1.0.0
 */
final class GovernanceBodyRepository
{
    /**
     * Default page size when the caller does not specify one.
     *
     * Chosen to be small enough that a default page is cheap to
     * render, large enough that a typical list of governance bodies
     * fits in one or two pages. Overridable per call.
     */
    private const DEFAULT_PER_PAGE = 25;

    /**
     * Maximum page size the caller may request.
     *
     * Protects against a caller requesting `per_page=10000` and
     * forcing the server to serialise an unbounded result. Enforced
     * inside `paginateForTenant()` by clamping the input.
     */
    private const MAX_PER_PAGE = 200;

    // =========================================================================
    // Listing
    // =========================================================================

    /**
     * Paginate governance bodies belonging to a tenant.
     *
     * The result is always scoped to `$tenantId`. Filters are applied
     * only when the corresponding key is present and non-empty in
     * `$filters`. Unrecognised keys are ignored — the method does not
     * error on a filter it does not understand, because callers may
     * evolve their query parameters independently.
     *
     * Recognised filter keys:
     *
     *     'type'            — a GovernanceBodyType case or its string
     *                         value.
     *     'is_active'       — a bool.
     *     'parent_body_id'  — an int.
     *     'search'          — a string searched across `code`,
     *                         `name_am`, `name_en`.
     *
     * Order: `code ASC`, with `id ASC` as a stable-pagination
     * tiebreaker. See the file-level change-verification block
     * §"Pagination and filters" for the reasoning behind the
     * alphabetical default.
     *
     * @param  int                  $tenantId
     * @param  array<string, mixed> $filters
     * @param  int                  $perPage
     * @param  array<int, string>   $with
     * @return LengthAwarePaginator
     */
    public function paginateForTenant(
        int $tenantId,
        array $filters = [],
        int $perPage = self::DEFAULT_PER_PAGE,
        array $with = []
    ): LengthAwarePaginator {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $query = GovernanceBody::query()
            ->where('tenant_id', $tenantId);

        $this->applyFilters($query, $filters);

        if ($with !== []) {
            $query->with($with);
        }

        return $query
            ->orderBy('code', 'asc')
            ->orderBy('id', 'asc')
            ->paginate($perPage);
    }

    // =========================================================================
    // Single-row lookups by primary key (tenant-scoped)
    // =========================================================================

    /**
     * Find a governance body by primary key, scoped to a tenant.
     *
     * Returns null if no matching row exists or if the row belongs to
     * a different tenant. This is the safe way to load a body for
     * display: a cross-tenant id returns null rather than another
     * organization's data.
     *
     * @param  int                $tenantId
     * @param  int                $id
     * @param  array<int, string> $with
     * @return GovernanceBody|null
     */
    public function findForTenant(int $tenantId, int $id, array $with = []): ?GovernanceBody
    {
        $query = GovernanceBody::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id);

        if ($with !== []) {
            $query->with($with);
        }

        return $query->first();
    }

    /**
     * Find a governance body by primary key, scoped to a tenant, or
     * throw.
     *
     * Throws Eloquent's `ModelNotFoundException` when no row is
     * found in the tenant, INCLUDING the case where the id exists in
     * a different tenant. This is a deliberate correction to the
     * `ShareholderRepository` precedent — see the file-level
     * change-verification block
     * §"`findForTenantOrFail` — a defensive correction to the
     * Shareholder precedent" for the full reasoning.
     *
     * The exception is caught by the canonical error renderer in
     * `bootstrap/app.php` and converted into a 404
     * `RESOURCE_NOT_FOUND` response.
     *
     * @param  int                $tenantId
     * @param  int                $id
     * @param  array<int, string> $with
     * @return GovernanceBody
     *
     * @throws ModelNotFoundException
     */
    public function findForTenantOrFail(int $tenantId, int $id, array $with = []): GovernanceBody
    {
        $body = $this->findForTenant($tenantId, $id, $with);

        if ($body === null) {
            throw (new ModelNotFoundException())->setModel(
                GovernanceBody::class,
                [$id],
            );
        }

        return $body;
    }

    // =========================================================================
    // Single-row lookups by natural key (tenant-scoped)
    // =========================================================================

    /**
     * Find a governance body by its canonical code, scoped to a
     * tenant.
     *
     * The lookup is case-insensitive because (a) the model normalises
     * stored codes to uppercase on write and (b) the column's default
     * collation (`utf8mb4_unicode_ci`) compares case-insensitively.
     * The term is therefore trimmed and uppercased before the query,
     * matching the model's `code` set-mutator contract.
     *
     * Returns null if no matching row exists in the tenant.
     *
     * @param  int                $tenantId
     * @param  string             $code
     * @param  array<int, string> $with
     * @return GovernanceBody|null
     */
    public function findByCode(int $tenantId, string $code, array $with = []): ?GovernanceBody
    {
        $normalised = strtoupper(trim($code));

        $query = GovernanceBody::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $normalised);

        if ($with !== []) {
            $query->with($with);
        }

        return $query->first();
    }

    /**
     * Find a governance body by its canonical code, scoped to a
     * tenant, or throw.
     *
     * Throws Eloquent's `ModelNotFoundException` when no row is
     * found in the tenant.
     *
     * @param  int                $tenantId
     * @param  string             $code
     * @param  array<int, string> $with
     * @return GovernanceBody
     *
     * @throws ModelNotFoundException
     */
    public function findByCodeOrFail(int $tenantId, string $code, array $with = []): GovernanceBody
    {
        $body = $this->findByCode($tenantId, $code, $with);

        if ($body === null) {
            throw (new ModelNotFoundException())->setModel(
                GovernanceBody::class,
                [$code],
            );
        }

        return $body;
    }

    // =========================================================================
    // Existence checks
    // =========================================================================

    /**
     * Whether a governance body with the given primary key exists in
     * the tenant.
     *
     * Returns true for any row in the tenant, regardless of
     * `is_active` or `deleted_at`. Soft-deleted rows are excluded by
     * the model's global scope; a caller that needs to see
     * soft-deleted rows uses the model directly.
     *
     * @param  int  $tenantId
     * @param  int  $id
     * @return bool
     */
    public function existsForTenant(int $tenantId, int $id): bool
    {
        return GovernanceBody::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->exists();
    }

    /**
     * Whether a governance body with the given code exists in the
     * tenant.
     *
     * The lookup is case-insensitive; the term is normalised to
     * uppercase before the query, matching the model's `code`
     * set-mutator contract.
     *
     * The `$exceptId` parameter allows a caller to exclude a specific
     * row from the check — used by `GovernanceBodyService::update()`
     * to verify that a proposed new code does not collide with any
     * OTHER row, while permitting the row's own current code to be
     * "re-used" in a no-op update.
     *
     * @param  int      $tenantId
     * @param  string   $code
     * @param  int|null $exceptId
     * @return bool
     */
    public function existsByCode(int $tenantId, string $code, ?int $exceptId = null): bool
    {
        $normalised = strtoupper(trim($code));

        $query = GovernanceBody::query()
            ->where('tenant_id', $tenantId)
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
     * Count governance bodies in a tenant.
     *
     * When `$activeOnly` is true, only bodies with `is_active = true`
     * are counted. Soft-deleted rows are excluded in every case by
     * the model's global scope.
     *
     * Used by dashboard queries, launcher-tile counts, and any future
     * surface that needs a bounded summary figure without loading the
     * rows.
     *
     * @param  int  $tenantId
     * @param  bool $activeOnly
     * @return int
     */
    public function countForTenant(int $tenantId, bool $activeOnly = false): int
    {
        $query = GovernanceBody::query()
            ->where('tenant_id', $tenantId);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->count();
    }

    /**
     * Count governance bodies of a specific type in a tenant.
     *
     * Accepts either the enum case or its backing string, so callers
     * do not need to normalise before invoking.
     *
     * When `$activeOnly` is true, only bodies with `is_active = true`
     * are counted. Soft-deleted rows are excluded in every case by
     * the model's global scope.
     *
     * @param  int                                  $tenantId
     * @param  GovernanceBodyType|string            $type
     * @param  bool                                 $activeOnly
     * @return int
     */
    public function countByType(
        int $tenantId,
        GovernanceBodyType|string $type,
        bool $activeOnly = false
    ): int {
        $value = $type instanceof GovernanceBodyType ? $type->value : $type;

        $query = GovernanceBody::query()
            ->where('tenant_id', $tenantId)
            ->where('body_type', $value);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->count();
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Apply the recognised filter keys to the query.
     *
     * Unknown keys are silently ignored. Empty or null values are also
     * ignored, so a caller may pass `['type' => null]` and get the
     * unfiltered result without an explicit check.
     *
     * @param  Builder              $query
     * @param  array<string, mixed> $filters
     * @return void
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        // Type filter — accepts an enum case or its string value.
        if (array_key_exists('type', $filters) && $filters['type'] !== null && $filters['type'] !== '') {
            $type = $filters['type'];

            $value = $type instanceof GovernanceBodyType
                ? $type->value
                : (string) $type;

            $query->where('body_type', $value);
        }

        // Active flag filter — only applied when explicitly present.
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        // Parent body filter — returns only direct children of the
        // specified body. Useful for tree-shaped views.
        if (array_key_exists('parent_body_id', $filters) && $filters['parent_body_id'] !== null && $filters['parent_body_id'] !== '') {
            $query->where('parent_body_id', (int) $filters['parent_body_id']);
        }

        // Search filter — delegates to the shared name-search helper.
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
     * The escape character is `\` and is applied to the term's `%`,
     * `_`, and `\` characters so that a literal user input of `50%`
     * matches `"50%"` literally rather than matching everything
     * starting with `50`.
     *
     * Case-insensitivity is provided by the column's default
     * collation (`utf8mb4_unicode_ci`). A search for `board` matches
     * a body whose code is `BOARD` and whose name contains "Board".
     *
     * The `purpose` and `mandate` columns are deliberately NOT
     * searched — see the file-level change-verification block
     * §"Search behaviour".
     *
     * @param  Builder $query
     * @param  string  $term
     * @return void
     */
    private function applyNameSearch(Builder $query, string $term): void
    {
        // Escape LIKE wildcards in the user term.
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