<?php

declare(strict_types=1);



namespace MaaleArna\MASES\Core\Domains\Governance\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;

/**
 * GovernanceBodyPolicy
 *
 * Authorization rules for governance body operations.
 *
 * See the file-level change-verification block for the policy's
 * two-layer authorization model, its tenant-context resolution, its
 * deliberate omission of the membership-based grant path (deferred
 * to a phase that introduces `GovernanceMembership`), and its
 * reasoning for the intentionally thin shape relative to
 * `ShareholderPolicy`.
 *
 * =============================================================================
 * Ability map
 * =============================================================================
 * The policy exposes five abilities, mapped by Laravel to the
 * following controller calls (delivered as G2a file 14):
 *
 *   viewAny(User $user)                       ← GovernanceBodyController::index
 *   view(User $user, GovernanceBody $body)    ← GovernanceBodyController::show
 *   create(User $user)                        ← GovernanceBodyController::store
 *   update(User $user, GovernanceBody $body)  ← GovernanceBodyController::update
 *   delete(User $user, GovernanceBody $body)  ← (no route in G2a; reserved for
 *                                                a future CR that adds a
 *                                                soft-delete endpoint)
 *
 * The `delete` ability is declared for symmetry with the platform's
 * standard five-ability policy shape. It is not invoked by any
 * route in G2a. A future CR that exposes a
 * `DELETE /api/governance/bodies/{body}` endpoint will call
 * `Gate::authorize('delete', $body)` in the new controller action.
 *
 * =============================================================================
 * Grant rules
 * =============================================================================
 *   viewAny   grants  if the caller holds `governance.body.view`
 *                     in the resolved tenant.
 *
 *   view      grants  if the caller holds `governance.body.view`
 *                     in the resolved tenant, AND the body belongs
 *                     to that tenant.
 *
 *   create    grants  if the caller holds `governance.body.manage`
 *                     in the resolved tenant.
 *
 *   update    grants  if the caller holds `governance.body.manage`
 *                     in the resolved tenant, AND the body belongs
 *                     to that tenant.
 *
 *   delete    grants  if the caller holds `governance.body.manage`
 *                     in the resolved tenant, AND the body belongs
 *                     to that tenant.
 *
 * Every ability fails closed when the tenant cannot be resolved.
 *
 * =============================================================================
 * The absence of an `update`-for-self-service path
 * =============================================================================
 * Unlike `ShareholderPolicy`, this policy does not grant `update` to
 * a caller who is "the subject of the record". A governance body
 * has no subject in the shareholder sense — no user "owns" a body.
 * The body's structural properties (its mandate, its reporting
 * relationship, its effective range) are administrative data that
 * only a caller with the `manage` permission can modify.
 *
 * A future CR that decides a body's active members should be able
 * to modify certain properties — e.g. an agenda item they drafted,
 * or an attendance note they recorded — will introduce a
 * policy-mediated rule for the SPECIFIC property and the SPECIFIC
 * entity that carries it. It will not grant a general `update`
 * capability to members. The four-eyes principle that underlies
 * §21's "verification must be separate from completion" applies
 * to any such future change.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Policies
 * @version 1.0.0
 */
final class GovernanceBodyPolicy
{
    /**
     * The permission slug required for read operations on governance
     * bodies.
     *
     * Declared as a class constant rather than inlined at every call
     * site, so a future CR that renames the slug touches one line
     * in this file. The slug is seeded by `GovernancePermissionsSeeder`
     * (G1 file 7 of 10).
     */
    private const PERMISSION_VIEW = 'governance.body.view';

    /**
     * The permission slug required for write operations on governance
     * bodies.
     *
     * Declared as a class constant for the same reason as
     * `PERMISSION_VIEW`. The slug is seeded by
     * `GovernancePermissionsSeeder` (G1 file 7 of 10).
     */
    private const PERMISSION_MANAGE = 'governance.body.manage';

    /**
     * Construct the policy with its authorization dependency.
     *
     * `AuthorizationService` is a Phase 1 singleton bound by
     * `CoreServiceProvider`. The container resolves it by reflection;
     * no provider change is required for the dependency itself.
     *
     * @param AuthorizationService $authorization
     */
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {
    }

    // =========================================================================
    // viewAny — list governance bodies
    // =========================================================================

    /**
     * Determine whether the user may list governance bodies.
     *
     * Listing is an administrative operation. It requires the
     * `governance.body.view` permission in the resolved tenant.
     *
     * The tenant context is resolved server-side. If no tenant is
     * available, the check fails closed.
     *
     * There is no model to check the tenant boundary against — the
     * caller is asking to see the list of bodies the tenant owns,
     * and the list itself is tenant-scoped by the repository. The
     * permission check is therefore the only layer this ability
     * applies.
     *
     * @param  User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: self::PERMISSION_VIEW,
            user: $user,
            tenantId: $tenantId,
        );
    }

    // =========================================================================
    // view — read a single governance body
    // =========================================================================

    /**
     * Determine whether the user may view a specific governance body.
     *
     * The ability applies two layers, in this order:
     *
     *   1. Tenant boundary — the body's `tenant_id` must equal the
     *      resolved tenant. A mismatch fails immediately.
     *
     *   2. RBAC permission — the caller must hold
     *      `governance.body.view` in the resolved tenant.
     *
     * Both layers must pass. See the file-level change-verification
     * block §"The two-layer authorization model" for the full
     * reasoning.
     *
     * @param  User             $user
     * @param  GovernanceBody   $body
     * @return bool
     */
    public function view(User $user, GovernanceBody $body): bool
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
    // create — register a new governance body
    // =========================================================================

    /**
     * Determine whether the user may create a governance body.
     *
     * Creation is an administrative operation. It requires the
     * `governance.body.manage` permission in the resolved tenant.
     *
     * There is no model yet — the body has not been created. The
     * tenant scope of the created row is determined by the
     * controller, which passes the resolved tenant to the service.
     * The permission check is therefore the only layer this ability
     * applies.
     *
     * @param  User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: self::PERMISSION_MANAGE,
            user: $user,
            tenantId: $tenantId,
        );
    }

    // =========================================================================
    // update — modify an existing governance body
    // =========================================================================

    /**
     * Determine whether the user may modify a specific governance
     * body.
     *
     * The ability applies two layers, in the same order as `view`:
     *
     *   1. Tenant boundary — the body's `tenant_id` must equal the
     *      resolved tenant.
     *
     *   2. RBAC permission — the caller must hold
     *      `governance.body.manage` in the resolved tenant.
     *
     * See the file-level change-verification block §"The absence of
     * an `update`-for-self-service path" for why there is no
     * alternative grant path on this ability.
     *
     * @param  User             $user
     * @param  GovernanceBody   $body
     * @return bool
     */
    public function update(User $user, GovernanceBody $body): bool
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
    // delete — soft-delete a governance body
    // =========================================================================

    /**
     * Determine whether the user may soft-delete a specific
     * governance body.
     *
     * No HTTP endpoint invokes this ability in G2a. It is declared
     * for symmetry with the platform's standard five-ability policy
     * shape and reserved for a future CR that adds a
     * `DELETE /api/governance/bodies/{body}` endpoint.
     *
     * The grant rule mirrors `update`: the tenant boundary check
     * plus the `governance.body.manage` permission.
     *
     * When the future CR adds the endpoint, the controller action
     * will invoke `Gate::authorize('delete', $body)`, and the
     * `GovernanceBodyService` will gain a `softDelete()` method
     * (currently it has `deactivate()` only — a semantic
     * distinction the future CR must resolve).
     *
     * @param  User             $user
     * @param  GovernanceBody   $body
     * @return bool
     */
    public function delete(User $user, GovernanceBody $body): bool
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
    // Private helpers
    // =========================================================================

    /**
     * Resolve the tenant id from the server-side request context.
     *
     * Returns null when the helper is unavailable or no tenant is
     * resolved. Callers treat null as "fail closed".
     *
     * The tenant is never read from the request body, query string,
     * or a custom header. It comes from `mases_tenant_id()`, the
     * same helper used elsewhere in the platform.
     *
     * Same shape as the identical helper on `ShareholderPolicy`,
     * `ShareHoldingPolicy`, `CertificatePolicy`, and
     * `ShareClassPolicy`. The duplication is intentional: each
     * policy is an independent authorization boundary, and the
     * helper's body is a three-line guard whose extraction into a
     * shared trait would add indirection without removing any
     * invariant.
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