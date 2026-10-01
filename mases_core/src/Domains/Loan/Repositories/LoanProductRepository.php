<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Repositories;

use Illuminate\Database\Eloquent\Collection;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;

/**
 * LoanProductRepository
 *
 * The read-only query surface for the loan product catalogue. Every read
 * of a `LoanProduct` through the Loan domain flows through this
 * repository; the repository is the single enforcement point for tenant
 * scoping.
 *
 * =============================================================================
 * Scope — the product catalogue
 * =============================================================================
 * The repository exposes the five query methods the Loan-domain
 * controllers and the domain's own read paths require:
 *
 *   findForTenant()          — single-row lookup by primary key,
 *                              tenant-scoped, nullable.
 *   findByCode()             — single-row lookup by product code within
 *                              a tenant, nullable.
 *   listForTenant()          — collection lookup, tenant-scoped.
 *   listActiveForTenant()    — collection lookup, tenant-scoped,
 *                              restricted to active products.
 *   existsForTenant()        — existence check by primary key,
 *                              tenant-scoped.
 *
 * The repository is READ-ONLY. It performs no writes, opens no
 * transactions, dispatches no events, and mutates no state. Product
 * mutations (creation, deactivation, policy attachment) are performed by
 * an administrative script or a future CR's `LoanProductService`; no
 * Phase 2C service mutates products.
 *
 * =============================================================================
 * Tenant scoping — the repository's central invariant
 * =============================================================================
 * Every method takes `$tenantId` as its first parameter and composes the
 * query with `LoanProduct::scopeForTenant()` (A.17). No method returns a
 * product from another tenant, and no method allows a caller to bypass
 * the scope.
 *
 * The tenant scoping produces the platform-wide isolation property
 * documented in the handoff §3 and in the handoff Extra 1: a
 * non-existent product id and a product id that belongs to a DIFFERENT
 * tenant produce IDENTICAL outcomes. A controller that resolves a
 * route-bound product calls `findForTenant()`; a non-existent or
 * cross-tenant id produces the same `null` return, which the controller
 * translates to a 404.
 *
 * The tenant scoping ALSO applies to the `findByCode()` lookup. A
 * product's code is unique within its tenant (per the
 * `UNIQUE (tenant_id, code)` constraint declared in the migration A.8),
 * but the SAME code may exist in different tenants. A lookup by code
 * without a tenant scope would be ambiguous; the repository therefore
 * requires `$tenantId`.
 *
 * =============================================================================
 * The `$with` eager-load parameter
 * =============================================================================
 * Every lookup method accepts an optional `$with` array of relation names
 * to eager-load. The parameter is threaded directly into Eloquent's
 * `with()`, so callers pass the standard relation names declared on the
 * `LoanProduct` model (A.17): `'tenant'`, `'policyVersion'`, `'loans'`.
 *
 * The parameter is `list<string>` and defaults to `[]`. It exists so that
 * a controller can compose a resource's eager-load set at the query
 * boundary — the single point at which the N+1 problem is preventable —
 * rather than loading relations lazily inside the resource's `toArray()`.
 *
 * =============================================================================
 * The `listActiveForTenant()` convenience method
 * =============================================================================
 * The distinction between `listForTenant()` and `listActiveForTenant()`
 * mirrors the two legitimate read patterns for a product catalogue:
 *
 *   - `listForTenant()` returns the FULL catalogue, including inactive
 *     products. A product is inactive when `is_active = false`; the
 *     product's existing loans continue to be serviced under its frozen
 *     policy version (per the migration A.8's docblock), but the product
 *     is no longer offered for new applications.
 *
 *   - `listActiveForTenant()` returns only the products currently offered
 *     for new applications. This is the correct read for the "which
 *     product should I choose?" selector in a new-application UI, and
 *     for the `LoanApplicationService::resolveActiveProduct()` (A.28)
 *     precondition that a proposed product is active.
 *
 * The two methods share the same ordering and eager-load semantics; they
 * differ only in the `active()` scope they compose.
 *
 * A caller that needs "inactive only" composes the query directly against
 * the `LoanProduct` model. The repository's narrow surface deliberately
 * does not enumerate every possible filter.
 *
 * =============================================================================
 * Ordering
 * =============================================================================
 * The two collection-lookup methods return rows ordered by `code`
 * ascending. The code is the product catalogue's natural human-readable
 * identifier; a code-ordered list is the stable, predictable presentation
 * that operators expect. Unlike the loan list (which is
 * activity-ordered), the product list is not temporal: a product is
 * reference data, not an event.
 *
 * The `code` column carries the `UNIQUE (tenant_id, code)` constraint
 * declared in the migration A.8, so the ordering is deterministic within
 * a tenant without an additional tiebreaker.
 *
 * =============================================================================
 * No exceptions from the repository
 * =============================================================================
 * No method on this repository throws. Missing rows produce `null` (for
 * the `find*` methods) or an empty collection (for the `list*` methods).
 * There is no `findForTenantOrFail()` on this repository: no Phase 2C
 * controller resolves a route-bound `{loanProduct}` by primary key for a
 * 404-rendering code path. The one Phase 2C product endpoint
 * (`GET /loans/products`, A.78) returns the catalogue, not a single
 * product.
 *
 * A future CR that introduces a `GET /loans/products/{product}` route
 * would add a `findForTenantOrFail()` method at that time, matching the
 * shape of `LoanRepository::findForTenantOrFail()` (A.55).
 *
 * =============================================================================
 * What this repository deliberately does NOT do
 * =============================================================================
 *   - It does NOT write. Product mutations are outside Phase 2C's HTTP
 *     surface.
 *
 *   - It does NOT open a transaction. Every query is a single, atomic
 *     read.
 *
 *   - It does NOT dispatch events. Reads are silent.
 *
 *   - It does NOT compose queries beyond the tenant scope and the
 *     documented `active()` filter. The repository is not a
 *     general-purpose query builder.
 *
 *   - It does NOT expose a `findByCodeOrFail()` method. The one internal
 *     caller of `findByCode()` — a future administrative script or a
 *     future product-management service — composes its own failure
 *     handling. Adding the `OrFail` variant to the repository now would
 *     be speculative.
 *
 *   - It does NOT cache. Every call issues a fresh query. The repository
 *     holds no state across calls.
 *
 *   - It does NOT expose a `create`, `update`, `delete`, `restore`, or
 *     `forceDelete` method. The repository's method-name surface makes
 *     its read-only nature visible at a glance.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D130 (product
 *                                                  catalogue), B6
 *                                                  (hybrid storage),
 *                                                  B7 (RBAC)
 *   Phase 2C Clarification Response, 2026-09-12  — Extra 1 (tenant
 *                                                  scoping),
 *                                                  Extra 2 (single
 *                                                  policy type)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §4
 *   Phase 2C A.8                                 — `loan_products`
 *                                                  migration (the
 *                                                  UNIQUE (tenant_id,
 *                                                  code) constraint and
 *                                                  the is_active flag)
 *   Phase 2C A.17                                — `LoanProduct` (the
 *                                                  model and its scopes)
 *   Phase 2C A.28                                — `LoanApplicationService`
 *                                                  (the internal caller
 *                                                  of `findForTenant()`
 *                                                  via
 *                                                  `resolveActiveProduct()`)
 *   Phase 2C A.55                                — `LoanRepository` (the
 *                                                  structural sibling)
 *   Phase 2C A.78                                — `LoanProductController`
 *                                                  (the primary HTTP
 *                                                  consumer)
 *   Phase 2A 2A.13                               — `ShareholderRepository`
 *                                                  (the shape precedent)
 *   Phase 2B 2B.19                               — `WalletRepository`
 *                                                  (the direct sibling)
 *   Step 5 (manifest) A.56                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Repositories
 * @version 1.0.0
 */
final class LoanProductRepository
{
    // =========================================================================
    // Public API — single-row lookups
    // =========================================================================

    /**
     * Find a product by primary key within a tenant.
     *
     * Returns the product when it exists and belongs to the tenant;
     * returns `null` otherwise. A product that exists in a DIFFERENT
     * tenant returns `null`, indistinguishable from a non-existent id.
     * This is the platform-wide tenant-isolation contract (handoff §3,
     * Extra 1).
     *
     * @param int           $tenantId
     * @param int           $productId
     * @param list<string>  $with       Relations to eager-load.
     * @return LoanProduct|null
     */
    public function findForTenant(
        int $tenantId,
        int $productId,
        array $with = [],
    ): ?LoanProduct {
        /** @var LoanProduct|null $product */
        $product = LoanProduct::query()
            ->forTenant($tenantId)
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->whereKey($productId)
            ->first();

        return $product;
    }

    /**
     * Find a product by code within a tenant.
     *
     * The product code is unique within a tenant per the
     * `UNIQUE (tenant_id, code)` constraint (migration A.8). The same
     * code may exist in different tenants, so the lookup requires
     * `$tenantId`.
     *
     * The comparison is case-insensitive: the query is composed with
     * `LoanProduct::scopeOfCode()` (A.17), which normalises the search
     * term to uppercase before the `WHERE`. A caller may pass the code in
     * any case.
     *
     * Returns the product when it exists and belongs to the tenant;
     * returns `null` otherwise. A product whose code matches but whose
     * tenant is different returns `null`, indistinguishable from a
     * non-existent code. This is the platform-wide tenant-isolation
     * contract applied to code-based lookups.
     *
     * @param int           $tenantId
     * @param string        $code
     * @param list<string>  $with       Relations to eager-load.
     * @return LoanProduct|null
     */
    public function findByCode(
        int $tenantId,
        string $code,
        array $with = [],
    ): ?LoanProduct {
        /** @var LoanProduct|null $product */
        $product = LoanProduct::query()
            ->forTenant($tenantId)
            ->ofCode($code)
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->first();

        return $product;
    }

    // =========================================================================
    // Public API — collection lookups
    // =========================================================================

    /**
     * List all products in a tenant, ordered by code.
     *
     * Returns an Eloquent `Collection` of `LoanProduct` models ordered by
     * `code` ascending. The list includes BOTH active and inactive
     * products; a caller that needs only active products uses
     * `listActiveForTenant()`.
     *
     * The method returns an EMPTY collection when the tenant has no
     * products; it does not return `null`.
     *
     * The ordering is deterministic within a tenant, because the
     * `code` column carries the `UNIQUE (tenant_id, code)` constraint
     * declared in the migration A.8. No additional tiebreaker is
     * required.
     *
     * @param int           $tenantId
     * @param list<string>  $with       Relations to eager-load.
     * @return Collection<int, LoanProduct>
     */
    public function listForTenant(
        int $tenantId,
        array $with = [],
    ): Collection {
        /** @var Collection<int, LoanProduct> $products */
        $products = LoanProduct::query()
            ->forTenant($tenantId)
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->orderBy('code')
            ->get();

        return $products;
    }

    /**
     * List active products in a tenant, ordered by code.
     *
     * Identical to `listForTenant()` in scope and ordering, but
     * restricted to products with `is_active = true` via
     * `LoanProduct::scopeActive()` (A.17).
     *
     * This is the correct read for the "which product should I choose?"
     * selector in a new-application UI, and for any caller that needs
     * the products currently offered for new business.
     *
     * The method returns an EMPTY collection when the tenant has no
     * active products; it does not return `null`.
     *
     * @param int           $tenantId
     * @param list<string>  $with       Relations to eager-load.
     * @return Collection<int, LoanProduct>
     */
    public function listActiveForTenant(
        int $tenantId,
        array $with = [],
    ): Collection {
        /** @var Collection<int, LoanProduct> $products */
        $products = LoanProduct::query()
            ->forTenant($tenantId)
            ->active()
            ->when(
                $with !== [],
                static fn ($query) => $query->with($with),
            )
            ->orderBy('code')
            ->get();

        return $products;
    }

    // =========================================================================
    // Public API — existence check
    // =========================================================================

    /**
     * Whether a product exists within a tenant.
     *
     * Returns `true` only when a row with the given primary key exists
     * in the given tenant. A product that exists in a DIFFERENT tenant
     * returns `false`, indistinguishable from a non-existent id.
     *
     * The method issues an `EXISTS` query, which does not retrieve the
     * row. A caller that needs the row uses `findForTenant()`.
     *
     * @param int $tenantId
     * @param int $productId
     * @return bool
     */
    public function existsForTenant(int $tenantId, int $productId): bool
    {
        return LoanProduct::query()
            ->forTenant($tenantId)
            ->whereKey($productId)
            ->exists();
    }
}