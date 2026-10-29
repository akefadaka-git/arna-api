<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Repositories;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use MaaleArna\MASES\Core\Domains\Shareholder\Enums\ShareholderType;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;

/**
 * ShareholderRepository
 *
 * Read-only query surface for Shareholder rows.
 *
 * =============================================================================
 * Why this class exists
 * =============================================================================
 * Shareholder lookups are performed from three places:
 *
 *   1. The HTTP controller layer (2A.21) — for listing and viewing.
 *   2. The service layer (2A.12) — for existence checks before write.
 *   3. The policy layer (2A.29) — for ownership resolution during
 *      authorization.
 *
 * Centralising the queries here serves three purposes:
 *
 *   - Every query is tenant-scoped by construction. The repository cannot
 *     return a shareholder from another tenant, because every method takes
 *     a tenant id and applies it as a WHERE clause. A controller that
 *     forgets to scope cannot accidentally leak cross-tenant data — it
 *     would need to construct its own query, which the code review process
 *     is designed to catch.
 *
 *   - The bilingual name search is implemented once. If the Amharic-first
 *     policy changes how names are matched, one method updates.
 *
 *   - The tests (2A.46) can construct a repository against a known fixture
 *     and assert behaviour without going through HTTP.
 *
 * =============================================================================
 * Tenant scoping (Master Prompt Section 36)
 * =============================================================================
 * Every public method in this class takes `int $tenantId` as its FIRST
 * parameter, before any other argument. The caller resolves this value from
 * the established MASES tenant context (via `TenantContextService` or the
 * equivalent mechanism in the current request) and passes it in.
 *
 * This class does NOT read `mases_tenant_id()` or any request header. If it
 * did, a compromised request could supply a different tenant and read
 * another organisation's shareholder register. Requiring the caller to
 * supply the value makes the trust boundary explicit: the caller is
 * responsible for having resolved it from an authoritative source.
 *
 * Read methods therefore cannot be called without a tenant. There is no
 * `all()`, no `find($id)` without a tenant, and no `search()` across all
 * tenants. This is deliberate.
 *
 * =============================================================================
 * No writes
 * =============================================================================
 * This repository is READ-ONLY by design. It exposes no `create`, `update`,
 * `delete`, `restore`, or `forceDelete` methods. Persistence for the
 * Shareholder domain is performed exclusively by ShareholderService (2A.12),
 * which:
 *
 *   - Validates the D11 representative rule before persist.
 *   - Validates the D8 identity rule (individual has a user_id, legal entity
 *     has a representative_user_id) before persist.
 *   - Wraps the operation in a database transaction.
 *   - Dispatches the domain event (2A.31 / 2A.32) after commit.
 *
 * Placing writes in a repository and business rules in a service would
 * fragment the invariants across two classes. The current split keeps the
 * repository a thin query layer and the service a coherent domain
 * implementation.
 *
 * =============================================================================
 * Amharic-first search
 * =============================================================================
 * `searchByName()` matches the search term against all eight name columns —
 * the six individual-name columns
 * (`first_name_am`, `first_name_en`, `father_name_am`, `father_name_en`,
 * `grandfather_name_am`, `grandfather_name_en`) and the two entity-name
 * columns (`entity_name_am`, `entity_name_en`) — with an OR between them.
 * The columns are treated as equals: a search in Amharic and a search in
 * English both match, and no column is preferred over another for the
 * purpose of the query.
 *
 * Ordering is by `created_at desc` (newest first), matching the pattern
 * used elsewhere in the platform for listing endpoints.
 *
 * =============================================================================
 * Eager-loading
 * =============================================================================
 * Methods accept an optional `array $with` parameter listing relations to
 * eager-load. Callers that render the result to JSON typically pass
 * `['user', 'representative']` to avoid N+1. Callers that only need counts
 * pass an empty array. The default is empty — the repository never
 * eager-loads relations the caller did not request, because that would
 * impose a hidden cost on simple queries.
 *
 * =============================================================================
 * Pagination
 * =============================================================================
 * `paginateForTenant()` returns Laravel's `LengthAwarePaginator`, which
 * carries the page count and total so that resources (2A.25) can include
 * pagination metadata in the response envelope. Other methods return
 * `Collection` or a single model — they are intended for bounded lookups
 * (one representative's shareholders, one specific shareholder) where
 * pagination is not meaningful.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Repositories
 */
final class ShareholderRepository
{
    /**
     * Default page size when the caller does not specify one.
     *
     * Chosen to be small enough that a default page is cheap to render,
     * large enough that a typical register view fits in two or three pages.
     * Overridable per call.
     */
    private const DEFAULT_PER_PAGE = 25;

    /**
     * Maximum page size the caller may request.
     *
     * Protects against a caller requesting `perPage=10000` and forcing the
     * server to serialise an unbounded result. Enforced inside
     * `paginateForTenant()` by clamping the input.
     */
    private const MAX_PER_PAGE = 200;

    // =========================================================================
    // Listing
    // =========================================================================

    /**
     * Paginate shareholders belonging to a tenant.
     *
     * The result is always scoped to `$tenantId`. Filters are applied only
     * when the corresponding key is present and non-empty in `$filters`.
     * Unrecognised keys are ignored — the method does not error on a filter
     * it does not understand, because callers may evolve their query
     * parameters independently.
     *
     * Recognised filter keys:
     *
     *   'type'       — a ShareholderType case or its string value.
     *   'is_active'  — a bool.
     *   'search'     — a string searched across all name columns.
     *
     * @param int                  $tenantId
     * @param array<string, mixed> $filters
     * @param int                  $perPage
     * @param array<int, string>   $with
     * @return LengthAwarePaginator
     */
    public function paginateForTenant(
        int $tenantId,
        array $filters = [],
        int $perPage = self::DEFAULT_PER_PAGE,
        array $with = []
    ): LengthAwarePaginator {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $query = Shareholder::query()
            ->where('tenant_id', $tenantId);

        $this->applyFilters($query, $filters);

        if ($with !== []) {
            $query->with($with);
        }

        return $query
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }

    // =========================================================================
    // Single-row lookups (tenant-scoped)
    // =========================================================================

    /**
     * Find a shareholder by primary key, scoped to a tenant.
     *
     * Returns null if no matching row exists or if the row belongs to a
     * different tenant. This is the safe way to load a shareholder for
     * display: a cross-tenant id returns null rather than another
     * organisation's data.
     *
     * @param int                $tenantId
     * @param int                $id
     * @param array<int, string> $with
     * @return Shareholder|null
     */
    public function findForTenant(int $tenantId, int $id, array $with = []): ?Shareholder
    {
        $query = Shareholder::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id);

        if ($with !== []) {
            $query->with($with);
        }

        return $query->first();
    }

    /**
     * Find a shareholder by primary key, scoped to a tenant, or throw.
     *
     * Throws Eloquent's ModelNotFoundException when no row is found. The
     * canonical error renderer in `bootstrap/app.php` converts that to a
     * 404 RESOURCE_NOT_FOUND response.
     *
     * @param int                $tenantId
     * @param int                $id
     * @param array<int, string> $with
     * @return Shareholder
     *
     * @throws ModelNotFoundException
     */
    public function findForTenantOrFail(int $tenantId, int $id, array $with = []): Shareholder
    {
        $shareholder = $this->findForTenant($tenantId, $id, $with);

        if ($shareholder === null) {
            throw (new ModelNotFoundException())->setModel(Shareholder::class, [$id]);
        }

        return $shareholder;
    }

    // =========================================================================
    // Lookups by relationship
    // =========================================================================

    /**
     * Find the shareholder directly linked to a given User.
     *
     * Used for the self-service path: an authenticated user with a linked
     * shareholder row can view their own holdings without an admin
     * permission check.
     *
     * Returns null if the user is not linked to a shareholder in the given
     * tenant. A user linked in tenant A does not resolve in tenant B, by
     * construction.
     *
     * @param int                $tenantId
     * @param int                $userId
     * @param array<int, string> $with
     * @return Shareholder|null
     */
    public function findByUser(int $tenantId, int $userId, array $with = []): ?Shareholder
    {
        $query = Shareholder::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId);

        if ($with !== []) {
            $query->with($with);
        }

        return $query->first();
    }

    /**
     * Find all shareholders for which the given user is the representative.
     *
     * A single user may represent multiple legal-entity shareholders, so
     * this method returns a Collection. The list is bounded by the number
     * of entities the user represents, which is expected to be small; no
     * pagination is applied.
     *
     * @param int                $tenantId
     * @param int                $userId
     * @param array<int, string> $with
     * @return Collection<int, Shareholder>
     */
    public function findByRepresentative(
        int $tenantId,
        int $userId,
        array $with = []
    ): Collection {
        $query = Shareholder::query()
            ->where('tenant_id', $tenantId)
            ->where('representative_user_id', $userId);

        if ($with !== []) {
            $query->with($with);
        }

        return $query
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();
    }

    // =========================================================================
    // Search
    // =========================================================================

    /**
     * Search shareholders by name across all bilingual name columns.
     *
     * The search term is matched against every one of the eight name columns
     * with a LIKE `%term%` comparison. Amharic and English columns are
     * treated identically; no column is preferred. The search is
     * case-insensitive on both sides on MySQL's default collation.
     *
     * The result is bounded to the given tenant. The order is newest-first
     * by created_at, with id as a tiebreaker for stable pagination.
     *
     * This method does NOT paginate. Callers that need pagination should use
     * `paginateForTenant()` with a `search` filter, which applies the same
     * matching logic. `searchByName()` exists for bounded lookups — e.g. a
     * typeahead over a representative's own entities — where the caller
     * knows the result set is small.
     *
     * @param int                $tenantId
     * @param string             $term
     * @param array<int, string> $with
     * @param int                $limit
     * @return Collection<int, Shareholder>
     */
    public function searchByName(
        int $tenantId,
        string $term,
        array $with = [],
        int $limit = 50
    ): Collection {
        $term = trim($term);

        if ($term === '') {
            return new Collection();
        }

        $query = Shareholder::query()->where('tenant_id', $tenantId);

        $this->applyNameSearch($query, $term);

        if ($with !== []) {
            $query->with($with);
        }

        return $query
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(max(1, $limit))
            ->get();
    }

    // =========================================================================
    // Existence checks
    // =========================================================================

    /**
     * Whether a shareholder with the given primary key exists in the tenant.
     *
     * @param int $tenantId
     * @param int $id
     * @return bool
     */
    public function existsForTenant(int $tenantId, int $id): bool
    {
        return Shareholder::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->exists();
    }

    /**
     * Whether the given user is already linked directly to a shareholder in
     * the tenant.
     *
     * The `users` table has a UNIQUE constraint on `email` but not on the
     * link to a shareholder; the equivalent UNIQUE on `shareholders` is
     * `(tenant_id, user_id)`. This method allows the service to check
     * before persist and provide a friendly error rather than relying on a
     * database exception.
     *
     * @param int      $tenantId
     * @param int      $userId
     * @param int|null $excludeShareholderId  Skip this row when checking (for updates).
     * @return bool
     */
    public function isUserLinked(
        int $tenantId,
        int $userId,
        ?int $excludeShareholderId = null
    ): bool {
        $query = Shareholder::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId);

        if ($excludeShareholderId !== null) {
            $query->where('id', '!=', $excludeShareholderId);
        }

        return $query->exists();
    }

    // =========================================================================
    // Counts
    // =========================================================================

    /**
     * Count active shareholders in a tenant.
     *
     * "Active" means `is_active = true` AND not soft-deleted (the latter is
     * implicit — soft-deleted rows are excluded from the default query
     * scope).
     *
     * @param int $tenantId
     * @return int
     */
    public function countActiveForTenant(int $tenantId): int
    {
        return Shareholder::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();
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
     * @param Builder              $query
     * @param array<string, mixed> $filters
     * @return void
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        // Type filter — accepts an enum case or its string value.
        if (array_key_exists('type', $filters) && $filters['type'] !== null && $filters['type'] !== '') {
            $type = $filters['type'];

            $value = $type instanceof ShareholderType
                ? $type->value
                : (string) $type;

            $query->where('shareholder_type', $value);
        }

        // Active flag filter — only applied when explicitly present.
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
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
     * Apply an OR-chain LIKE condition across every bilingual name column.
     *
     * The escape character is `\` and is applied to the term's `%`, `_`, and
     * `\` characters so that a literal user input of `50%` matches
     * `"50%"` literally rather than matching everything starting with `50`.
     *
     * @param Builder $query
     * @param string  $term
     * @return void
     */
    private function applyNameSearch(Builder $query, string $term): void
    {
        // Escape LIKE wildcards in the user term.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $pattern = '%' . $escaped . '%';

        $columns = [
            'first_name_am',
            'first_name_en',
            'father_name_am',
            'father_name_en',
            'grandfather_name_am',
            'grandfather_name_en',
            'entity_name_am',
            'entity_name_en',
        ];

        $query->where(function (Builder $q) use ($columns, $pattern): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $q->{$method}($column, 'like', $pattern);
            }
        });
    }
}