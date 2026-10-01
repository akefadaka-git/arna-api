<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2a · File 4 of 17
 * =============================================================================
 *
 * File:            Repositories/GovernanceBodyRepository.php  (NEW)
 * Unit:            G2a — GovernanceBody vertical slice (repository)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `GovernanceBody` model (G2a file 3
 *   of 17) could be queried from any caller via Eloquent's own
 *   static API, but the domain had no centralised, tenant-scoped
 *   query surface. Every controller, service, or future consumer
 *   that needed to load a governance body would have had to
 *   construct its own query — with the risk that a caller would
 *   forget the tenant scope, that two callers would implement the
 *   same filter differently, or that a future schema change would
 *   require edits scattered across the domain.
 *
 * After:
 *   One file that declares the domain's read-only query surface for
 *   `GovernanceBody`. Every read path in the domain — the service
 *   (G2a file 5), the policy (G2a file 6), the controller (G2a
 *   file 14), and every future caller — goes through this class.
 *
 *   The repository is:
 *
 *     · Read-only. It declares no `create()`, no `update()`, no
 *       `delete()`, no `restore()`, and no `forceDelete()`. Every
 *       mutation is performed by `GovernanceBodyService` (G2a file
 *       5), which validates invariants, wraps the write in a
 *       transaction, and dispatches an event after commit. Placing
 *       any write here would fragment those concerns across two
 *       classes.
 *
 *     · Tenant-scoped by construction. Every public method takes
 *       `int $tenantId` as its first parameter and applies it as a
 *       WHERE clause. A caller cannot accidentally return a body
 *       from another tenant — it would need to construct its own
 *       query, which code review is designed to catch.
 *
 *     · Query-construction only. It performs no caching, no
 *       authorization, no validation, no event dispatch. Those are
 *       concerns of the layer above it.
 *
 *   The methods it declares:
 *
 *     paginateForTenant($tenantId, $filters, $perPage, $with)
 *         — Paginated list, with optional filters.
 *
 *     findForTenant($tenantId, $id, $with)
 *         — Single row by primary key, tenant-scoped.
 *
 *     findForTenantOrFail($tenantId, $id, $with)
 *         — Same, but throws ModelNotFoundException on miss.
 *
 *     findByCode($tenantId, $code, $with)
 *         — Single row by natural key, tenant-scoped.
 *
 *     findByCodeOrFail($tenantId, $code, $with)
 *         — Same, but throws on miss.
 *
 *     existsForTenant($tenantId, $id)
 *         — Existence check by primary key.
 *
 *     existsByCode($tenantId, $code, $exceptId)
 *         — Natural-key uniqueness check.
 *
 *     countForTenant($tenantId, $activeOnly)
 *         — Total count for a tenant.
 *
 *     countByType($tenantId, $type, $activeOnly)
 *         — Count of bodies of a given type.
 *
 *   What this repository deliberately does NOT do:
 *
 *     · It does NOT resolve foreign relations automatically. A
 *       caller that needs `parent`, `children`, or `reportingBody`
 *       passes them via the `$with` parameter; the repository does
 *       not impose an eager-load list on every caller.
 *
 *     · It does NOT declare a `search()` method separate from
 *       `paginateForTenant()`. The `search` filter is applied
 *       inside the paginated list path, so the two operations —
 *       filter and paginate — share one implementation. A caller
 *       that needs an unpaginated search is out of scope; the
 *       platform's convention is that unbounded name searches are
 *       a query-shape anti-pattern.
 *
 *     · It does NOT return typed DTOs. It returns `GovernanceBody`
 *       models and standard Laravel collections / paginators.
 *       Introducing a DTO layer would be a separate architectural
 *       decision; no MASES domain currently uses one.
 *
 *     · It does NOT implement cycle-detection for the parent
 *       relationship. That is a service-layer concern; the
 *       repository returns whatever the schema holds.
 *
 * =============================================================================
 * Why this class exists
 * =============================================================================
 * Governance body lookups are performed from several places:
 *
 *   1. The HTTP controller layer (G2a file 14) — for listing and
 *      viewing.
 *   2. The service layer (G2a file 5) — for existence and
 *      uniqueness checks before writes.
 *   3. The policy layer (G2a file 6) — for tenant ownership
 *      resolution during authorization.
 *   4. Future cross-cutting callers — dashboards, reports, the
 *      launcher tile's count, ARNA integration.
 *
 * Centralising the queries serves three purposes:
 *
 *   · Every query is tenant-scoped by construction. A controller
 *     that forgets to scope cannot accidentally leak cross-tenant
 *     data — it would need to construct its own query, which the
 *     code review process is designed to catch.
 *
 *   · The name-search behaviour is implemented once. If the
 *     Amharic-first policy changes how names are matched, one
 *     method updates.
 *
 *   · Tests (G2a file 17) can construct a repository against a
 *     known fixture and assert behaviour without going through
 *     HTTP.
 *
 * This structure mirrors `ShareholderRepository` and
 * `WalletRepository` exactly. The three repositories share the same
 * shape because they serve the same purpose in three domains.
 *
 * =============================================================================
 * Tenant scoping — the platform's contract
 * =============================================================================
 * Every public method in this class takes `int $tenantId` as its
 * FIRST parameter, before any other argument. The caller resolves
 * this value from the established MASES tenant context (via
 * `TenantContextService` or the `mases_tenant_id()` helper) and
 * passes it in.
 *
 * This class does NOT read `mases_tenant_id()` or any request
 * header. If it did, a compromised request could supply a different
 * tenant and read another organization's governance records.
 * Requiring the caller to supply the value makes the trust boundary
 * explicit: the caller is responsible for having resolved it from an
 * authoritative source.
 *
 * Read methods therefore cannot be called without a tenant. There is
 * no `all()`, no `find($id)` without a tenant, and no `search()`
 * across all tenants. This is deliberate.
 *
 * =============================================================================
 * `findForTenantOrFail` — a defensive correction to the Shareholder
 * precedent
 * =============================================================================
 * The `ShareholderRepository` (Phase 2A) implements its
 * `findForTenantOrFail()` method as:
 *
 *     $shareholder = $this->findForTenant($tenantId, $id, $with);
 *
 *     if ($shareholder === null) {
 *         throw (new Shareholder())->newModelQuery()->findOrFail($id, ['*']);
 *     }
 *
 *     return $shareholder;
 *
 * The `findOrFail($id, ['*'])` call inside the guard has an
 * unintended consequence: if the id exists in a DIFFERENT tenant,
 * `findOrFail` finds the row and returns it, and the method returns
 * a cross-tenant record. The intent — "throw when the row is not in
 * this tenant" — is not honoured for the cross-tenant case.
 *
 * This repository implements the same-shaped method but with the
 * correct behaviour:
 *
 *     $body = $this->findForTenant($tenantId, $id, $with);
 *
 *     if ($body === null) {
 *         throw (new ModelNotFoundException())->setModel(
 *             GovernanceBody::class,
 *             [$id],
 *         );
 *     }
 *
 *     return $body;
 *
 * The exception is constructed directly rather than via a query.
 * The caller receives the same `ModelNotFoundException` that the
 * canonical error renderer maps to a 404 `RESOURCE_NOT_FOUND`
 * response, whether the id does not exist at all or exists in
 * another tenant. There is no code path by which a cross-tenant row
 * is returned.
 *
 * A future Change Request may correct the Shareholder pattern with
 * the same fix. That CR is outside this delivery's scope and outside
 * the Governance domain.
 *
 * =============================================================================
 * Pagination and filters
 * =============================================================================
 * `paginateForTenant()` recognises four filter keys:
 *
 *     'type'            — a GovernanceBodyType case or its string
 *                         value; filters on `body_type`.
 *
 *     'is_active'       — a bool; filters on `is_active`.
 *
 *     'parent_body_id'  — an int; returns only bodies whose
 *                         `parent_body_id` matches. Useful for
 *                         "children of X" queries.
 *
 *     'search'          — a string; case-insensitive LIKE across
 *                         `code`, `name_am`, `name_en`.
 *
 * Unrecognised keys are ignored. A null or empty-string value for
 * any filter is treated as "filter not present", so a caller may
 * pass `['type' => null]` and receive the unfiltered result without
 * an explicit check.
 *
 * The pagination page size is clamped to `[1, MAX_PER_PAGE]` inside
 * the method, so a caller cannot force the server to serialise an
 * unbounded result by requesting `per_page=10000`.
 *
 * The default order is `code ASC`, with `id ASC` as a tiebreaker for
 * stable pagination. This differs from `ShareholderRepository`'s
 * `created_at DESC`, because the two models serve different
 * purposes:
 *
 *   · A shareholder is a RECORD — a registration event. Its natural
 *     list order is newest-first, matching the register metaphor.
 *
 *   · A governance body is REFERENCE DATA — a named entity that
 *     persists across many registrations. Its natural list order is
 *     alphabetical by its canonical code, matching the reference-
 *     data convention `ShareClassService::all()` follows.
 *
 * =============================================================================
 * Search behaviour
 * =============================================================================
 * The `search` filter matches the term against three columns:
 *
 *     code         — the canonical uppercase identifier
 *     name_am      — the Amharic display name
 *     name_en      — the English display name
 *
 * The match is a case-insensitive LIKE `%term%`, with LIKE wildcards
 * in the user's term escaped. The database's default collation
 * (`utf8mb4_unicode_ci`) handles the case-insensitivity, so a search
 * for `board` matches a body whose code is `BOARD`.
 *
 * The `purpose` and `mandate` columns are deliberately NOT searched.
 * Both are long free-text fields; including them would produce too
 * many false-positive matches in a search that is intended to locate
 * a specific body by its identity. A future CR that needs
 * full-text search across mandate text is a separate feature.
 *
 * The search is applied INSIDE the paginated query, not as a
 * separate step. A caller receives paginated results whose
 * `total()` reflects the filtered count.
 *
 * =============================================================================
 * No writes
 * =============================================================================
 * This repository is READ-ONLY by design. It exposes no `create`,
 * `update`, `delete`, `restore`, or `forceDelete` methods.
 * Persistence for the Governance domain is performed exclusively by
 * `GovernanceBodyService` (G2a file 5), which:
 *
 *   - Validates the code-uniqueness rule within the tenant.
 *   - Validates that a `parent_body_id` reference is in the same
 *     tenant and does not form a cycle.
 *   - Validates that `effective_from <= effective_until` when both
 *     are non-null.
 *   - Validates that at least one bilingual name is non-empty.
 *   - Wraps the operation in a database transaction.
 *   - Dispatches the domain event (`GovernanceBodyRegistered` or
 *     `GovernanceBodyUpdated`) after commit.
 *
 * Placing writes in a repository and business rules in a service
 * would fragment the invariants across two classes. The current
 * split keeps the repository a thin query layer and the service a
 * coherent domain implementation.
 *
 * =============================================================================
 * Eager-loading
 * =============================================================================
 * Methods accept an optional `array $with` parameter listing
 * relations to eager-load. Callers that render the result to JSON
 * typically pass `['parent', 'reportingBody']` to avoid N+1.
 * Callers that only need counts pass an empty array. The default is
 * empty — the repository never eager-loads relations the caller did
 * not request, because that would impose a hidden cost on simple
 * queries.
 *
 * This mirrors `ShareholderRepository`'s contract exactly.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Repositories
 * @version 1.0.0
 */
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