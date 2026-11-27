<?php

declare(strict_types=1);



namespace MaaleArna\Arna\Core\Domains\Governance\Policies;

use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Authorization\Services\AuthorizationService;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceTerm;

/**
 * GovernanceTermPolicy
 *
 * Authorization rules for governance term operations.
 *
 * See the file-level change-verification block for the policy's
 * two-layer authorization model, its tenant-context resolution, its
 * deliberate omission of the membership-based grant path (deferred
 * to G2c), and its reasoning for reusing the `governance.body.*`
 * permission slugs.
 *
 * =============================================================================
 * Ability map
 * =============================================================================
 * The policy exposes five abilities, mapped by Laravel to the
 * following controller calls (delivered as G2b file 21 of 27):
 *
 *   viewAny(User, GovernanceBody)   ← GovernanceTermController::index
 *   view(User, GovernanceTerm)      ← GovernanceTermController::show
 *   create(User, GovernanceBody)    ← GovernanceTermController::store
 *   update(User, GovernanceTerm)    ← GovernanceTermController::update
 *   delete(User, GovernanceTerm)    ← (no route in G2b; reserved for
 *                                       a future CR that adds a
 *                                       soft-delete endpoint)
 *
 * The `delete` ability is declared for symmetry with the platform's
 * standard five-ability policy shape.
 *
 * =============================================================================
 * Grant rules
 * =============================================================================
 *   viewAny   grants  if the caller holds `governance.body.view`
 *                     in the resolved tenant, AND the body belongs
 *                     to that tenant.
 *
 *   view      grants  if the caller holds `governance.body.view`
 *                     in the resolved tenant, AND the term belongs
 *                     to that tenant.
 *
 *   create    grants  if the caller holds `governance.body.manage`
 *                     in the resolved tenant, AND the body belongs
 *                     to that tenant.
 *
 *   update    grants  if the caller holds `governance.body.manage`
 *                     in the resolved tenant, AND the term belongs
 *                     to that tenant.
 *
 *   delete    grants  if the caller holds `governance.body.manage`
 *                     in the resolved tenant, AND the term belongs
 *                     to that tenant.
 *
 * Every ability fails closed when the tenant cannot be resolved.
 *
 * =============================================================================
 * @package MaaleArna\Arna\Core\Domains\Governance\Policies
 * @version 1.0.0
 */
final class GovernanceTermPolicy
{
    /**
     * The permission slug required for read operations on terms.
     *
     * Reused from the GovernanceBody permission set.
     */
    private const PERMISSION_VIEW = 'governance.body.view';

    /**
     * The permission slug required for write operations on terms.
     *
     * Reused from the GovernanceBody permission set.
     */
    private const PERMISSION_MANAGE = 'governance.body.manage';

    /**
     * Construct the policy with its authorization dependency.
     *
     * @param AuthorizationService $authorization
     */
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {
    }

    // =========================================================================
    // viewAny — list terms of a body
    // =========================================================================

    /**
     * Determine whether the user may list the terms of a body.
     *
     * @param  User           $user
     * @param  GovernanceBody $body
     * @return bool
     */
    public function viewAny(User $user, GovernanceBody $body): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ((int) $body->tenant_id !== $tenantId) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: self::PERMISSION_VIEW,
            user: $user,
            tenantId: $tenantId,
        );
    }

    // =========================================================================
    // view — read a single term
    // =========================================================================

    /**
     * Determine whether the user may view a specific term.
     *
     * @param  User           $user
     * @param  GovernanceTerm $term
     * @return bool
     */
    public function view(User $user, GovernanceTerm $term): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ((int) $term->tenant_id !== $tenantId) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: self::PERMISSION_VIEW,
            user: $user,
            tenantId: $tenantId,
        );
    }

    // =========================================================================
    // create — register a new term under a body
    // =========================================================================

    /**
     * Determine whether the user may create a term under a body.
     *
     * @param  User           $user
     * @param  GovernanceBody $body
     * @return bool
     */
    public function create(User $user, GovernanceBody $body): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ((int) $body->tenant_id !== $tenantId) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: self::PERMISSION_MANAGE,
            user: $user,
            tenantId: $tenantId,
        );
    }

    // =========================================================================
    // update — modify an existing term
    // =========================================================================

    /**
     * Determine whether the user may modify a specific term.
     *
     * @param  User           $user
     * @param  GovernanceTerm $term
     * @return bool
     */
    public function update(User $user, GovernanceTerm $term): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ((int) $term->tenant_id !== $tenantId) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: self::PERMISSION_MANAGE,
            user: $user,
            tenantId: $tenantId,
        );
    }

    // =========================================================================
    // delete — soft-delete a term
    // =========================================================================

    /**
     * Determine whether the user may soft-delete a specific term.
     *
     * No HTTP endpoint invokes this ability in G2b.
     *
     * @param  User           $user
     * @param  GovernanceTerm $term
     * @return bool
     */
    public function delete(User $user, GovernanceTerm $term): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ((int) $term->tenant_id !== $tenantId) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: self::PERMISSION_MANAGE,
            user: $user,
            tenantId: $tenantId,
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the tenant id from the server-side request context.
     *
     * Returns null when the helper is unavailable or no tenant is
     * resolved. Callers treat null as "fail closed".
     *
     * @return int|null
     */
    private function resolveTenantId(): ?int
    {
        if (!function_exists('arna_tenant_id')) {
            return null;
        }

        $tenantId = arna_tenant_id();

        if ($tenantId === null) {
            return null;
        }

        return (int) $tenantId;
    }
}