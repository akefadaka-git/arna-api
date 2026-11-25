<?php

declare(strict_types=1);



namespace MaaleArna\MASES\Core\Domains\Governance\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceAuthorityGrantService;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;

/**
 * GovernanceMembershipPolicy
 *
 * Authorization rules for governance membership operations.
 *
 * See the file-level change-verification block for the policy's
 * two-layer authorization model, its tenant-context resolution, its
 * deliberate omission of a membership-based grant path, its
 * reasoning for reusing the `governance.body.*` permission slugs
 * (and for leaving the reserved `governance.member.*` slugs
 * unused), and its deliberate non-use of any "own record" concept.
 *
 * =============================================================================
 * Ability map
 * =============================================================================
 * The policy exposes five abilities, mapped by Laravel to the
 * following controller calls (delivered as G2c file 25 of 31):
 *
 *   viewAny(User, GovernanceBody)         ← GovernanceMembershipController::index
 *   view(User, GovernanceMembership)      ← GovernanceMembershipController::show
 *   create(User, GovernanceBody)          ← GovernanceMembershipController::store
 *   update(User, GovernanceMembership)    ← GovernanceMembershipController::update
 *   delete(User, GovernanceMembership)    ← (no route in G2c; reserved for
 *                                            a future CR that adds a
 *                                            soft-delete endpoint)
 *
 * The `delete` ability is declared for symmetry with the platform's
 * standard five-ability policy shape. It is not invoked by any
 * route in G2c.
 *
 * =============================================================================
 * Grant rules
 * =============================================================================
 *   viewAny   grants  if the caller holds `governance.body.view`
 *                     in the resolved tenant, AND the body belongs
 *                     to that tenant.
 *
 *   view      grants  if the caller holds `governance.body.view`
 *                     in the resolved tenant, AND the membership
 *                     belongs to that tenant.
 *
 *   create    grants  if the caller holds `governance.body.manage`
 *                     in the resolved tenant, AND the body belongs
 *                     to that tenant.
 *
 *   update    grants  if the caller holds `governance.body.manage`
 *                     in the resolved tenant, AND the membership
 *                     belongs to that tenant.
 *
 *   delete    grants  if the caller holds `governance.body.manage`
 *                     in the resolved tenant, AND the membership
 *                     belongs to that tenant.
 *
 * Every ability fails closed when the tenant cannot be resolved.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Policies
 * @version 1.0.0
 */
final class GovernanceMembershipPolicy
{
    /**
     * The permission slug required for read operations on
     * memberships.
     *
     * Reused from the GovernanceBody permission set. See the
     * file-level change-verification block §"The permission slugs"
     * for the reasoning and for the reserved `governance.member.*`
     * slugs that this policy does not yet use.
     */
    private const PERMISSION_VIEW = 'governance.body.view';

    /**
     * The permission slug required for write operations on
     * memberships.
     *
     * Reused from the GovernanceBody permission set. See the
     * file-level change-verification block §"The permission slugs".
     */
    private const PERMISSION_MANAGE = 'governance.body.manage';

    /**
     * Construct the policy with its authorization dependency.
     *
     * @param AuthorizationService $authorization
     */
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly GovernanceAuthorityGrantService $authorityGrants,
    ) {
    }

    // =========================================================================
    // viewAny — list memberships of a body
    // =========================================================================

    /**
     * Determine whether the user may list the memberships of a body.
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
    // view — read a single membership
    // =========================================================================

    /**
     * Determine whether the user may view a specific membership.
     *
     * @param  User                 $user
     * @param  GovernanceMembership $membership
     * @return bool
     */
    public function view(User $user, GovernanceMembership $membership): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ((int) $membership->tenant_id !== $tenantId) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: self::PERMISSION_VIEW,
            user: $user,
            tenantId: $tenantId,
        );
    }

    // =========================================================================
    // create — register a new membership under a body
    // =========================================================================

    /**
     * Determine whether the user may create a membership under a
     * body.
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
        ) || $this->authorityGrants->allows(
            $user, $tenantId, (int) $membership->governance_body_id, (int) $membership->governance_position_id, 'governance.membership.manage',
        );
    }

    // =========================================================================
    // update — modify an existing membership
    // =========================================================================

    /**
     * Determine whether the user may modify a specific membership.
     *
     * This ability governs BOTH field updates (through the service's
     * `update()` method) and lifecycle transitions (through the
     * service's `end()`, `suspend()`, `resume()`, `resign()`,
     * `remove()`, and `expire()` methods). The controller that
     * invokes any of those methods calls this ability first; the
     * policy does not distinguish between the field-level and
     * lifecycle cases.
     *
     * A future CR that decides some lifecycle transitions warrant a
     * stricter permission than field edits — for example, that only
     * a caller with a specific role may `remove()` a member — would
     * introduce a separate ability (e.g.
     * `remove(User, GovernanceMembership)`) and the controller
     * would call it instead of `update` for that path. The current
     * design does not anticipate that CR.
     *
     * @param  User                 $user
     * @param  GovernanceMembership $membership
     * @return bool
     */
    public function update(User $user, GovernanceMembership $membership): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ((int) $membership->tenant_id !== $tenantId) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: self::PERMISSION_MANAGE,
            user: $user,
            tenantId: $tenantId,
        ) || ((int) $membership->user_id !== (int) $user->getKey() && $this->authorityGrants->allows(
            $user, $tenantId, (int) $membership->governance_body_id, (int) $membership->governance_position_id, 'governance.membership.manage',
        ));
    }

    // =========================================================================
    // delete — soft-delete a membership
    // =========================================================================

    /**
     * Determine whether the user may soft-delete a specific
     * membership.
     *
     * No HTTP endpoint invokes this ability in G2c. The
     * `GovernanceMembershipService` (G2c file 20 of 31) deliberately
     * does not expose a `delete()` or `softDelete()` method —
     * memberships end through their lifecycle methods, not through
     * deletion. This ability is declared for symmetry with the
     * platform's standard five-ability policy shape.
     *
     * A future CR that decides an administrative-recovery scenario
     * warrants a soft-delete endpoint would add the endpoint, the
     * service method, and the `Gate::authorize('delete', ...)`
     * call in the controller.
     *
     * @param  User                 $user
     * @param  GovernanceMembership $membership
     * @return bool
     */
    public function delete(User $user, GovernanceMembership $membership): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ((int) $membership->tenant_id !== $tenantId) {
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
        if (!function_exists('mases_tenant_id')) {
            return null;
        }

        $tenantId = mases_tenant_id();

        if ($tenantId === null) {
            return null;
        }

        return (int) $tenantId;
    }
}