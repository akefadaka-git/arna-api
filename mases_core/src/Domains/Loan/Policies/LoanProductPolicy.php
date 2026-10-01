<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;

/**
 * LoanProductPolicy
 *
 * The authorization surface for the loan product catalogue. Answers the
 * "may this user view loan products?" questions that the Loan-domain
 * controllers ask through `Gate::authorize(...)`.
 *
 * =============================================================================
 * Scope — the product catalogue
 * =============================================================================
 * This policy covers the `LoanProduct` aggregate (A.17). The catalogue is
 * reference data: every user who can view any loan can view the products
 * under which loans are originated, because the product identity is part
 * of a loan's public representation.
 *
 * The catalogue has NO per-user ownership semantics. There is no
 * "products I originated" view, no "products shared with me" concept.
 * The only meaningful authorization question is "does this user hold
 * permission to read loan-related data in this tenant?".
 *
 * Phase 2C exposes exactly one HTTP endpoint against the catalogue:
 * `GET /loans/products` (A.78). The endpoint is declared BEFORE
 * `/loans/{loan}` in the routes file (A.84) so that the static `products`
 * path is not captured by the dynamic `{loan}` placeholder.
 *
 * =============================================================================
 * The two abilities
 * =============================================================================
 *   viewAny   — may the user enumerate the catalogue? Used by
 *               `GET /loans/products` (A.78).
 *
 *   view      — may the user view a specific product? Not exercised by
 *               any Phase 2C HTTP endpoint; provided for symmetry with
 *               the other policies and for use by a future CR that
 *               introduces a `GET /loans/products/{product}` route.
 *
 * Both abilities delegate to the SAME permission: `loan.view`. The
 * product catalogue is not gated by a distinct `loan_product.*`
 * permission family; the frozen RBAC matrix (B7) declares no such family.
 * A user who can read a loan necessarily needs to read the product that
 * loan was originated under, so `loan.view` is the correct gate.
 *
 * The `loan.view_own` permission does NOT grant product-catalogue access.
 * A borrower who can view their own loans can view their loan's
 * borrower-facing representation, but the CATALOGUE (a list of every
 * product the tenant offers) is an operational view, not a
 * borrower-facing one. Restricting it to `loan.view` holders is
 * deliberate.
 *
 * =============================================================================
 * Why no ownership path
 * =============================================================================
 * Unlike `LoanPolicy::view()` (A.52), which grants view access to a
 * borrower for their own loan, this policy has no ownership fallback.
 * The reasoning:
 *
 *   - A `LoanProduct` is not owned by a user; it is owned by a tenant.
 *     There is no per-user relationship between a user and a product
 *     that could serve as an ownership predicate.
 *
 *   - The catalogue is small, homogeneous, and non-sensitive at the
 *     per-user level. A tenant_admin who can see every loan in the
 *     tenant can trivially derive the product list; withholding
 *     catalogue access from them would produce an inconsistent
 *     authorization surface with no security benefit.
 *
 *   - A borrower who needs product data to understand their own loan
 *     reads it via `LoanPolicy::view()` (A.52) on the loan, which
 *     resolves the product relation as part of the loan's
 *     representation. The catalogue endpoint is not the borrower's
 *     path to product data.
 *
 * The result: no ownership check, no `Shareholder` lookup, no
 * tenant-scoped shareholder query. The policy's implementation is
 * correspondingly simpler than `LoanPolicy`'s — the difference is
 * intentional and reflects the different nature of the two aggregates.
 *
 * =============================================================================
 * What this policy deliberately does NOT do
 * =============================================================================
 *   - It does NOT define a `before()` method. Same posture as `LoanPolicy`
 *     (A.52) and every other Phase 2C policy: no policy-level bypass;
 *     the RBAC matrix is the single source of truth for role access.
 *
 *   - It does NOT return a `Response` instance. Every method returns a
 *     plain `bool`. Denials produce the canonical MASES 403 envelope
 *     through the standard `AuthorizationException` path.
 *
 *   - It does NOT expose `create`, `update`, `delete`, `activate`, or
 *     `deactivate` abilities. The frozen Stage 6 §4.16 HTTP surface
 *     exposes no product-mutation endpoint. Products are seeded or
 *     created by administrative scripts; a future CR that introduces
 *     product management would extend this policy at that time.
 *
 *   - It does NOT trust a caller-supplied tenant id for the permission
 *     check. The tenant scope for `view(product)` is read from the
 *     product's own `tenant_id` column; the tenant scope for
 *     `viewAny()` is resolved from the request context via the
 *     `mases_tenant_id()` helper (the `tenant.membership` middleware's
 *     contract).
 *
 * =============================================================================
 * The `hasPermission` delegation
 * =============================================================================
 * Every permission check flows through
 * `AuthorizationService::hasPermission(permissionSlug:, user:, tenantId:)`,
 * using NAMED ARGUMENTS. This is the same delegation pattern as
 * `LoanPolicy` (A.52), `LoanCollateralPolicy` (A.54), and every Phase 2A
 * and Phase 2B policy. See A.52's class docblock §"The `hasPermission`
 * delegation" for the platform-wide rationale.
 *
 * =============================================================================
 * Registration
 * =============================================================================
 * Registered in `LoanServiceProvider::registerPolicies()` (A.85) against
 * the `LoanProduct` model:
 *
 *     Gate::policy(LoanProduct::class, LoanProductPolicy::class);
 *
 * Once registered, every `Gate::authorize('viewAny', LoanProduct::class)`
 * or `Gate::authorize('view', $product)` call resolves through this
 * policy. The controller uses `Gate::authorize(...)` exclusively; the
 * policy's methods are not called directly by production code.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — B7 (RBAC), D130
 *   Phase 2C Clarification Response, 2026-09-12  — Extra 1 (tenant
 *                                                  scoping)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §4
 *   Phase 2C A.17                                — `LoanProduct`
 *   Phase 2C A.52                                — `LoanPolicy` (the
 *                                                  structural sibling)
 *   Phase 2C A.54                                — `LoanCollateralPolicy`
 *                                                  (the other domain
 *                                                  policy)
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *   Phase 2C A.86                                — `LoanPermissionsSeeder`
 *   Phase 2C A.87                                — `LoanRolesSeeder`
 *   Phase 2A 2A.16                               — `ShareholderPolicy`
 *                                                  (the shape template)
 *   Phase 2B 2B.20                               — `WalletPolicy`
 *                                                  (the direct sibling)
 *   Step 5 (manifest) A.53                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Policies
 * @version 1.0.1
 */
final class LoanProductPolicy
{
    /**
     * Constructor.
     *
     * One dependency: the Core `AuthorizationService`, which answers
     * "does this user hold this permission in this tenant?".
     *
     * @param AuthorizationService $authorizationService
     */
    public function __construct(
        private readonly AuthorizationService $authorizationService,
    ) {
    }

    // =========================================================================
    // Public API — abilities
    // =========================================================================

    /**
     * Determine whether the user may enumerate the product catalogue.
     *
     * Used by `GET /loans/products` (A.78). The ability is granted by
     * the `loan.view` permission in the request's tenant.
     *
     * The tenant is resolved from the request context via
     * `mases_tenant_id()`, populated by the `tenant.membership`
     * middleware. If the helper returns null (a context that bypassed
     * the middleware or a non-HTTP context), the ability returns
     * `false`: a catalogue query without a resolved tenant cannot be
     * safely evaluated, because the catalogue is tenant-scoped (Extra 1).
     *
     * Note that `loan.view_own` does NOT grant this ability. See the
     * class docblock §"The two abilities".
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        $tenantId = $this->resolveTenantIdForListAbility();

        if ($tenantId === null) {
            return false;
        }

        return $this->hasPermission($user, 'loan.view', $tenantId);
    }

    /**
     * Determine whether the user may view a specific product.
     *
     * Not exercised by any Phase 2C HTTP endpoint; provided for symmetry
     * with `LoanPolicy` (A.52) and for use by a future CR that
     * introduces a `GET /loans/products/{product}` route.
     *
     * The ability is granted by the `loan.view` permission in the
     * PRODUCT'S tenant. The tenant is read from the product's own
     * `tenant_id` column, so a cross-tenant product cannot be authorized
     * via a caller-controlled tenant id.
     *
     * There is no ownership path. See the class docblock §"Why no
     * ownership path".
     *
     * @param User         $user
     * @param LoanProduct  $product
     * @return bool
     */
    public function view(User $user, LoanProduct $product): bool
    {
        return $this->hasPermission(
            $user,
            'loan.view',
            (int) $product->tenant_id,
        );
    }

    // =========================================================================
    // Private — permission delegation
    // =========================================================================

    /**
     * Delegate a permission check to the Core `AuthorizationService`.
     *
     * Uses NAMED ARGUMENTS, following the platform-wide convention for
     * this service's call sites.
     *
     * @param User   $user
     * @param string $permissionSlug
     * @param int    $tenantId
     * @return bool
     */
    private function hasPermission(
        User $user,
        string $permissionSlug,
        int $tenantId,
    ): bool {
        return $this->authorizationService->hasPermission(
            permissionSlug: $permissionSlug,
            user:           $user,
            tenantId:       $tenantId,
        );
    }

    // =========================================================================
    // Private — tenant resolution for list abilities
    // =========================================================================

    /**
     * Resolve the tenant id for the list-scoped ability.
     *
     * The `viewAny` ability has no `LoanProduct` instance from which to
     * read a `tenant_id`, so the tenant is resolved from the request
     * context via the `mases_tenant_id()` helper. The helper is
     * populated by the `tenant.membership` middleware (registered in
     * `bootstrap/app.php` and applied to every Loan route via the
     * config's middleware stack, A.1).
     *
     * The implementation mirrors `LoanPolicy::resolveTenantIdForListAbility()`
     * (A.52) byte-for-byte. The duplication is deliberate: the two
     * policies are independent authorization surfaces and neither should
     * depend on the other for a private helper. A future CR that
     * extracts the helper into a shared trait would update both files at
     * once.
     *
     * @return int|null
     */
    private function resolveTenantIdForListAbility(): ?int
    {
        if (!function_exists('mases_tenant_id')) {
            return null;
        }

        /** @var int|string|null $tenantId */
        $tenantId = mases_tenant_id();

        if ($tenantId === null || $tenantId === '') {
            return null;
        }

        return (int) $tenantId;
    }
}