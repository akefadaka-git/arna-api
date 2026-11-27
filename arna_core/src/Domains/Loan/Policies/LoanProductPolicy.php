<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Policies;

use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Authorization\Services\AuthorizationService;
use MaaleArna\Arna\Core\Domains\Loan\Models\LoanProduct;

/**
 * Read-only loan-product catalogue authorization follows tenant loan-view access; products have no per-user ownership or mutation surface.
 *
 * @package MaaleArna\Arna\Core\Domains\Loan\Policies
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
     * `arna_tenant_id()`, populated by the `tenant.membership`
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
     * context via the `arna_tenant_id()` helper. The helper is
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
        if (!function_exists('arna_tenant_id')) {
            return null;
        }

        /** @var int|string|null $tenantId */
        $tenantId = arna_tenant_id();

        if ($tenantId === null || $tenantId === '') {
            return null;
        }

        return (int) $tenantId;
    }
}