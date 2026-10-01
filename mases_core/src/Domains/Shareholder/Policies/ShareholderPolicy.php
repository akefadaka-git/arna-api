<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;

/**
 * ShareholderPolicy
 *
 * Authorization rules for shareholder operations.
 *
 * =============================================================================
 * Ability map
 * =============================================================================
 * The policy exposes four abilities, mapped by Laravel to the following
 * controller calls (delivered as 2A.21–2A.24):
 *
 *   viewAny(User $user)                     ← ShareholderController::index
 *   view(User $user, Shareholder $holder)   ← ShareholderController::show
 *                                             ShareHoldingController::show
 *                                             CertificateController::show
 *                                             ShareHoldingController::store
 *                                             CertificateController::store
 *   create(User $user)                      ← ShareholderController::store
 *   update(User $user, Shareholder $holder) ← ShareholderController::update
 *                                             CertificateController::cancel
 *
 * No `delete`, `restore`, or `forceDelete` ability is defined. Phase 2A
 * does not soft-delete shareholders (F-2A12-6). Adding any of those
 * abilities requires a corresponding service path and a business decision
 * recorded in a Change Request.
 *
 * =============================================================================
 * Three access paths
 * =============================================================================
 * The `view` ability grants access through ANY of three paths:
 *
 *   1. Administrative permission. The user holds `shareholder.view`
 *      (see the RBAC plan in the frozen manifest). An administrator with
 *      this permission can view any shareholder in the resolved tenant.
 *
 *   2. Self-service for individual shareholders. The user's id matches
 *      the shareholder's `user_id`, AND the shareholder is an individual,
 *      AND the user holds `shareholder.view_own`. This is the individual
 *      reading their own record.
 *
 *   3. Representative access for legal entities (D11). The user's id
 *      matches the shareholder's `representative_user_id`, AND the
 *      shareholder is a legal entity. This grants VIEW access only — NOT
 *      update. See the class docblock section "Representative semantics".
 *
 * The three paths are alternatives. Access is granted if any one of them
 * authorizes the operation.
 *
 * =============================================================================
 * Representative semantics (D11)
 * =============================================================================
 * A representative User acts on a legal-entity shareholder's behalf for
 * MASES access: they can log in, view the shareholder's record, view its
 * holdings, and view its certificates. They cannot change the shareholder's
 * identity data (name, representative assignment, active state) — those
 * are administrative actions requiring the `shareholder.update` permission.
 *
 * If the business later decides that a representative may also modify
 * identity data, that is a CR. The current conservative posture is
 * deliberate: representation is about access, not ownership.
 *
 * The representative does not need to hold `shareholder.view_own`
 * explicitly. The check for the representative path is a direct id
 * comparison, not a permission lookup, because representation is a
 * structural relationship recorded in the database — not a grant that
 * can be independently revoked from the user's permissions.
 *
 * An individual shareholder may also have a `representative_user_id`
 * set (D11 permits representing individuals too). In that case, the
 * representative relationship grants the same VIEW access to the
 * individual shareholder's record. There is no special-casing by type
 * in the representative path.
 *
 * =============================================================================
 * Tenant boundary
 * =============================================================================
 * Every `view` and `update` check additionally asserts that the
 * shareholder's `tenant_id` matches the tenant resolved from the request
 * via `mases_tenant_id()`. If the tenant cannot be resolved, the check
 * fails closed (returns false).
 *
 * The controller (2A.21, 2A.23, 2A.24) already performs the same check
 * and returns 404 on mismatch. The policy repeats it as defence in
 * depth — a caller whose permissions would otherwise grant access must
 * not be able to cross the tenant boundary through a policy bug.
 *
 * When the check fails, the policy returns false. The canonical renderer
 * converts the resulting AuthorizationException to a 403
 * AUTH_PERMISSION_DENIED response. A cross-tenant shareholder is thus
 * reported as 403 by the policy layer, but as 404 by the controller
 * layer, because the controller's tenant assertion runs first. The
 * policy's 403 is unreachable in normal flow — it is a safety net.
 *
 * =============================================================================
 * Fail-closed defaults
 * =============================================================================
 * Every ability returns false when:
 *
 *   - The shareholder belongs to a different tenant.
 *   - The tenant context is missing.
 *   - The user has no matching permission and no matching relationship.
 *
 * There is no global super-admin bypass. A super admin is a user with
 * the appropriate permission set — the `super_admin` role (per the RBAC
 * plan) grants `shareholder.view`, `shareholder.create`, and
 * `shareholder.update`. Privilege comes from permissions, not from a
 * hard-coded role name.
 *
 * =============================================================================
 * No database queries beyond the injected service
 * =============================================================================
 * The policy performs no direct database queries. Permission lookups go
 * through `AuthorizationService` (Phase 1), which caches results in the
 * framework cache. Relationship checks are id comparisons against
 * already-loaded model attributes.
 *
 * =============================================================================
 * Testability
 * =============================================================================
 * The policy is a plain class with no static state. Unit tests (2A.46)
 * instantiate it with a fake `AuthorizationService` and assert every
 * branch. The method signatures follow Laravel's policy convention, so
 * `Gate::forUser($user)->allows('view', $shareholder)` invokes the
 * correct method.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Policies
 */
final class ShareholderPolicy
{
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
    // viewAny — list shareholders
    // =========================================================================

    /**
     * Determine whether the user may list shareholders.
     *
     * Listing is an administrative operation. It requires the
     * `shareholder.view` permission in the resolved tenant. A user with
     * only `shareholder.view_own` cannot list all shareholders — they can
     * only view their own record through the `view` ability.
     *
     * The tenant context is resolved server-side. If no tenant is
     * available, the check fails closed.
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: 'shareholder.view',
            user: $user,
            tenantId: $tenantId,
        );
    }

    // =========================================================================
    // view — read a single shareholder
    // =========================================================================

    /**
     * Determine whether the user may view a specific shareholder.
     *
     * Grants access through any of three paths: administrative
     * permission, self-service for individual shareholders, or
     * representative access for legal entities. See the class docblock.
     *
     * @param User        $user
     * @param Shareholder $shareholder
     * @return bool
     */
    public function view(User $user, Shareholder $shareholder): bool
    {
        // ---------------------------------------------------------------------
        // Tenant boundary — fail closed if the shareholder is not in the
        // resolved tenant or the tenant cannot be determined.
        // ---------------------------------------------------------------------
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ((int) $shareholder->tenant_id !== $tenantId) {
            return false;
        }

        // ---------------------------------------------------------------------
        // Path 1 — administrative permission.
        // ---------------------------------------------------------------------
        if ($this->authorization->hasPermission(
            permissionSlug: 'shareholder.view',
            user: $user,
            tenantId: $tenantId,
        )) {
            return true;
        }

        // ---------------------------------------------------------------------
        // Path 2 — self-service for individuals.
        //
        // The user is linked directly to the shareholder (user_id) AND
        // the shareholder is an individual AND the user holds the
        // `shareholder.view_own` permission.
        // ---------------------------------------------------------------------
        if (
            $shareholder->isIndividual()
            && $shareholder->user_id !== null
            && (int) $shareholder->user_id === (int) $user->id
            && $this->authorization->hasPermission(
                permissionSlug: 'shareholder.view_own',
                user: $user,
                tenantId: $tenantId,
            )
        ) {
            return true;
        }

        // ---------------------------------------------------------------------
        // Path 3 — representative access (D11).
        //
        // The user is the designated representative of this shareholder.
        // No explicit permission is required: representation is a
        // structural relationship recorded in the database.
        // ---------------------------------------------------------------------
        if (
            $shareholder->representative_user_id !== null
            && (int) $shareholder->representative_user_id === (int) $user->id
        ) {
            return true;
        }

        return false;
    }

    // =========================================================================
    // create — register a new shareholder
    // =========================================================================

    /**
     * Determine whether the user may create a new shareholder.
     *
     * Creation is an administrative operation. It requires the
     * `shareholder.create` permission in the resolved tenant.
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: 'shareholder.create',
            user: $user,
            tenantId: $tenantId,
        );
    }

    // =========================================================================
    // update — modify an existing shareholder
    // =========================================================================

    /**
     * Determine whether the user may modify a specific shareholder.
     *
     * Modification is an administrative operation. It requires the
     * `shareholder.update` permission in the resolved tenant.
     *
     * A representative of a legal-entity shareholder does NOT gain the
     * `update` ability through their representative relationship. See
     * the class docblock section "Representative semantics".
     *
     * An individual shareholder does NOT gain the `update` ability on
     * their own record. Modifying identity data (name, representative,
     * active state) requires the administrative permission, even for the
     * subject of the record. If the business later decides that
     * individuals may update their own profiles, that is a CR.
     *
     * @param User        $user
     * @param Shareholder $shareholder
     * @return bool
     */
    public function update(User $user, Shareholder $shareholder): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ((int) $shareholder->tenant_id !== $tenantId) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: 'shareholder.update',
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
     * The tenant is never read from the request body, query string, or a
     * custom header. It comes from `mases_tenant_id()`, the same helper
     * used elsewhere in the platform.
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