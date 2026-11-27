<?php

declare(strict_types=1);



namespace MaaleArna\Arna\Core\Domains\Governance\Policies;

use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Authorization\Services\AuthorizationService;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernancePosition;

/**
 * GovernancePositionPolicy
 *
 * Authorization rules for governance position operations.
 *
 * See the file-level change-verification block for the policy's
 * two-layer authorization model, its tenant-context resolution, its
 * deliberate omission of the membership-based grant path (deferred
 * to G2c), and its reasoning for reusing the `governance.body.*`
 * permission slugs rather than introducing dedicated
 * `governance.position.*` slugs.
 *
 * =============================================================================
 * Ability map
 * =============================================================================
 * The policy exposes five abilities, mapped by Laravel to the
 * following controller calls (delivered as G2b file 9 of 27):
 *
 *   viewAny(User, GovernanceBody)         ← GovernancePositionController::index
 *   view(User, GovernancePosition)        ← GovernancePositionController::show
 *   create(User, GovernanceBody)          ← GovernancePositionController::store
 *   update(User, GovernancePosition)      ← GovernancePositionController::update
 *   delete(User, GovernancePosition)      ← (no route in G2b; reserved for
 *                                            a future CR that adds a
 *                                            soft-delete endpoint)
 *
 * The `delete` ability is declared for symmetry with the platform's
 * standard five-ability policy shape. It is not invoked by any
 * route in G2b. A future CR that exposes a
 * `DELETE /api/governance/positions/{position}` endpoint will call
 * `Gate::authorize('delete', $position)` in the new controller
 * action.
 *
 * =============================================================================
 * Grant rules
 * =============================================================================
 *   viewAny   grants  if the caller holds `governance.body.view`
 *                     in the resolved tenant, AND the body belongs
 *                     to that tenant.
 *
 *   view      grants  if the caller holds `governance.body.view`
 *                     in the resolved tenant, AND the position
 *                     belongs to that tenant.
 *
 *   create    grants  if the caller holds `governance.body.manage`
 *                     in the resolved tenant, AND the body belongs
 *                     to that tenant.
 *
 *   update    grants  if the caller holds `governance.body.manage`
 *                     in the resolved tenant, AND the position
 *                     belongs to that tenant.
 *
 *   delete    grants  if the caller holds `governance.body.manage`
 *                     in the resolved tenant, AND the position
 *                     belongs to that tenant.
 *
 * Every ability fails closed when the tenant cannot be resolved.
 *
 * =============================================================================
 * Why create() and viewAny() take a GovernanceBody
 * =============================================================================
 * See the file-level change-verification block §"Why create() and
 * viewAny() take a GovernanceBody, not a GovernancePosition".
 * Briefly: the two abilities operate on a collection or on a
 * yet-to-be-created row; the position does not exist yet, and the
 * body is the correct authorization scope.
 *
 * =============================================================================
 * @package MaaleArna\Arna\Core\Domains\Governance\Policies
 * @version 1.0.0
 */
final class GovernancePositionPolicy
{
    /**
     * The permission slug required for read operations on positions.
     *
     * Reused from the GovernanceBody permission set. See the
     * file-level change-verification block §"The permission slugs".
     */
    private const PERMISSION_VIEW = 'governance.body.view';

    /**
     * The permission slug required for write operations on positions.
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
    ) {
    }

    // =========================================================================
    // viewAny — list positions of a body
    // =========================================================================

    /**
     * Determine whether the user may list the positions of a body.
     *
     * The ability applies two layers:
     *
     *   1. Tenant boundary — the body's `tenant_id` must equal the
     *      resolved tenant.
     *
     *   2. RBAC permission — the caller must hold
     *      `governance.body.view` in the resolved tenant.
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
    // view — read a single position
    // =========================================================================

    /**
     * Determine whether the user may view a specific position.
     *
     * The ability applies two layers:
     *
     *   1. Tenant boundary — the position's `tenant_id` must equal
     *      the resolved tenant.
     *
     *   2. RBAC permission — the caller must hold
     *      `governance.body.view` in the resolved tenant.
     *
     * @param  User               $user
     * @param  GovernancePosition $position
     * @return bool
     */
    public function view(User $user, GovernancePosition $position): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ((int) $position->tenant_id !== $tenantId) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: self::PERMISSION_VIEW,
            user: $user,
            tenantId: $tenantId,
        );
    }

    // =========================================================================
    // create — register a new position under a body
    // =========================================================================

    /**
     * Determine whether the user may create a position under a body.
     *
     * The ability applies two layers:
     *
     *   1. Tenant boundary — the body's `tenant_id` must equal the
     *      resolved tenant.
     *
     *   2. RBAC permission — the caller must hold
     *      `governance.body.manage` in the resolved tenant.
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
            $user, $tenantId, (int) $position->governance_body_id, (int) $position->id, 'governance.position.manage',
        );
    }

    // =========================================================================
    // update — modify an existing position
    // =========================================================================

    /**
     * Determine whether the user may modify a specific position.
     *
     * The ability applies two layers:
     *
     *   1. Tenant boundary — the position's `tenant_id` must equal
     *      the resolved tenant.
     *
     *   2. RBAC permission — the caller must hold
     *      `governance.body.manage` in the resolved tenant.
     *
     * @param  User               $user
     * @param  GovernancePosition $position
     * @return bool
     */
    public function update(User $user, GovernancePosition $position): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ((int) $position->tenant_id !== $tenantId) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: self::PERMISSION_MANAGE,
            user: $user,
            tenantId: $tenantId,
        );
    }

    // =========================================================================
    // delete — soft-delete a position
    // =========================================================================

    /**
     * Determine whether the user may soft-delete a specific position.
     *
     * No HTTP endpoint invokes this ability in G2b. It is declared
     * for symmetry with the platform's standard five-ability policy
     * shape and reserved for a future CR that adds a
     * `DELETE /api/governance/positions/{position}` endpoint.
     *
     * The grant rule mirrors `update`: the tenant boundary check
     * plus the `governance.body.manage` permission.
     *
     * @param  User               $user
     * @param  GovernancePosition $position
     * @return bool
     */
    public function delete(User $user, GovernancePosition $position): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ((int) $position->tenant_id !== $tenantId) {
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