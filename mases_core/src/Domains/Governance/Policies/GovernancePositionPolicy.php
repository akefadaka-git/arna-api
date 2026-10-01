<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 5 of 27
 * =============================================================================
 *
 * File:            Policies/GovernancePositionPolicy.php  (NEW)
 * Unit:            G2b — GovernancePosition vertical slice (policy)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No `Gate` policy governed access to
 *   `GovernancePosition` rows. Any caller with a
 *   `governance.body.view` or `governance.body.manage` permission
 *   could in principle read or modify any governance position —
 *   including positions belonging to a body in a tenant other than
 *   the one resolved for their request — because the permission
 *   check does not itself enforce the tenant boundary.
 *
 *   (The model's global scopes and the repository's tenant-scoped
 *   methods make the cross-tenant leak unlikely in practice, but
 *   "unlikely in practice" is not a security guarantee. The policy
 *   is the enforcement point that makes the guarantee structural.)
 *
 * After:
 *   One file that declares the domain's authorization policy for
 *   `GovernancePosition`. The policy enforces two layers — the RBAC
 *   permission and the tenant boundary — and nothing more. The
 *   three additional authorization layers that later phases will
 *   add (active membership of the body during the current term,
 *   position-based authority, confidentiality classification) are
 *   NOT part of this policy; they belong to policies introduced by
 *   the phases that create the entities they govern.
 *
 *   The policy exposes five abilities:
 *
 *     viewAny(User, GovernanceBody):          bool
 *         — list every position of a body.
 *
 *     view(User, GovernancePosition):         bool
 *         — read a specific position.
 *
 *     create(User, GovernanceBody):           bool
 *         — register a new position under a body.
 *
 *     update(User, GovernancePosition):       bool
 *         — modify a specific position.
 *
 *     delete(User, GovernancePosition):       bool
 *         — soft-delete a specific position.
 *
 *   Every ability grants through `AuthorizationService::hasPermission()`,
 *   fails closed on missing tenant context, and enforces the tenant
 *   boundary on every model-bearing argument. The grant rules are:
 *
 *     viewAny  →  `governance.body.view`   in the resolved tenant
 *                    AND  `$body->tenant_id === $tenantId`
 *
 *     view     →  `governance.body.view`   in the resolved tenant
 *                    AND  `$position->tenant_id === $tenantId`
 *
 *     create   →  `governance.body.manage` in the resolved tenant
 *                    AND  `$body->tenant_id === $tenantId`
 *
 *     update   →  `governance.body.manage` in the resolved tenant
 *                    AND  `$position->tenant_id === $tenantId`
 *
 *     delete   →  `governance.body.manage` in the resolved tenant
 *                    AND  `$position->tenant_id === $tenantId`
 *
 *   What the policy deliberately does NOT do:
 *
 *     · It does NOT reference any role name. Authorization is
 *       permission-based, not role-based.
 *
 *     · It does NOT delegate to a sibling policy. A position has no
 *       "own record" concept — no user "owns" a position the way a
 *       shareholder owns their own record. The policy has one grant
 *       rule per ability.
 *
 *     · It does NOT walk the membership table. Whether a user is an
 *       active member of a body, and whether their position grants
 *       them any specific authority, is a decision that depends on
 *       `GovernanceMembership` and `GovernanceTerm` — neither of
 *       which exists yet at G2b. When G2c arrives, a future CR may
 *       extend `view` (and possibly `update`) to grant via an
 *       active membership in addition to the RBAC permission. The
 *       extension will be additive: the existing rule remains, and
 *       the membership rule is added alongside it as an alternative
 *       grant path — the same shape `ShareholderPolicy::view` uses
 *       for its three paths.
 *
 *     · It does NOT enforce confidentiality. Confidentiality is a
 *       property of governance RECORDS (minutes, resolutions,
 *       declarations), not of governance positions. A position's
 *       existence, name, and flags are not confidential.
 *
 *     · It does NOT throw. It returns `bool` on every ability; the
 *       controller is responsible for calling `Gate::authorize()`,
 *       which converts a `false` return into an
 *       `AuthorizationException` that the canonical renderer maps
 *       to a 403 `AUTH_PERMISSION_DENIED` response.
 *
 * =============================================================================
 * Why `create()` and `viewAny()` take a GovernanceBody, not a
 * GovernancePosition
 * =============================================================================
 * Two abilities operate on a collection or on a yet-to-be-created
 * row, not on an existing position:
 *
 *   `viewAny(User, GovernanceBody)` — the caller is asking to see
 *   the list of positions for a specific body. There is no position
 *   instance to authorize against; the body is the relevant scope.
 *
 *   `create(User, GovernanceBody)` — the caller is asking to create
 *   a new position under a specific body. The body is the
 *   authorization scope; the not-yet-existing position has no
 *   tenant_id of its own.
 *
 * Laravel resolves the second argument of these abilities from the
 * route's model binding (the `{governanceBody}` parameter) or from
 * the controller's explicit call. The two abilities check the
 * body's tenant, not the position's.
 *
 * The three model-bearing abilities (`view`, `update`, `delete`)
 * take a `GovernancePosition` and check its `tenant_id` directly.
 *
 * =============================================================================
 * Two-layer authorization model
 * =============================================================================
 * For a model-bearing ability (`view`, `update`, `delete`), the
 * policy enforces TWO layers, in this order:
 *
 *   Layer 1 — the tenant boundary.
 *       The position's `tenant_id` must equal the tenant resolved
 *       from the server-side request context. A mismatch fails
 *       immediately, before any permission is consulted.
 *
 *   Layer 2 — the RBAC permission.
 *       The caller must hold the required permission in the
 *       resolved tenant.
 *
 * For a body-scoped ability (`viewAny`, `create`), the same two
 * layers apply, but Layer 1 checks the BODY's `tenant_id` instead
 * of the position's.
 *
 * =============================================================================
 * The permission slugs
 * =============================================================================
 * As approved in the G2b manifest, positions reuse the two
 * `governance.body.*` slugs seeded by G1's
 * `GovernancePermissionsSeeder`:
 *
 *   Read operations  →  `governance.body.view`
 *   Write operations →  `governance.body.manage`
 *
 * No new slugs are introduced. No `GovernancePermissionsSeeder`
 * amendment is delivered by G2b. The rationale: a position is a
 * structural attribute of a body; a caller authorized to read or
 * manage a body's structure is authorized to read or manage its
 * positions. The `governance.member.*` slugs reserved by G1 remain
 * for G2c's memberships.
 *
 * A future CR that decides positions should have their own slugs
 * (e.g. because a role should be able to edit positions but not
 * edit the body itself) would amend the seeder, the roles seeder,
 * and this policy together.
 *
 * =============================================================================
 * Tenant-context resolution
 * =============================================================================
 * The tenant id is resolved from `mases_tenant_id()` — the same
 * server-side helper every policy in the platform uses. The policy
 * does NOT read the tenant from the request body, a query-string
 * parameter, or any header other than the one the established
 * `RequestTenantResolver` consumes.
 *
 * When the helper is unavailable or returns null, `resolveTenantId()`
 * returns null, and every ability fails closed by returning `false`.
 *
 * =============================================================================
 * No database queries beyond the injected service
 * =============================================================================
 * The policy performs no direct database queries. Permission
 * lookups go through `AuthorizationService`, which caches results
 * in the framework cache. The tenant-boundary checks are integer
 * comparisons against already-loaded model attributes.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Policies/GovernancePositionPolicy.php
 *
 * The directory already exists (created for `GovernanceBodyPolicy`,
 * G2a file 6 of 18).
 *
 * The policy is registered with the `Gate` facade by the amended
 * `GovernanceServiceProvider` (G2b file 26 of 27). Until that
 * amendment is deployed, this policy file is inert — present on
 * disk but not resolvable by `Gate::authorize()`. The amendment
 * adds one line to the provider:
 *
 *     Gate::policy(GovernancePosition::class, GovernancePositionPolicy::class);
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Abilities declared:        5 (viewAny, view, create, update, delete)
 * Services injected:         1 (AuthorizationService)
 * Direct database queries:   none
 * Role references:           none
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;

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
 * @package MaaleArna\MASES\Core\Domains\Governance\Policies
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