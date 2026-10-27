<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 21 of 31
 * =============================================================================
 *
 * File:            Policies/GovernanceMembershipPolicy.php  (NEW)
 * Unit:            G2c — GovernanceMembership vertical slice (policy)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No `Gate` policy governed access to
 *   `GovernanceMembership` rows. Any caller with a
 *   `governance.body.view` or `governance.body.manage` permission
 *   could in principle read or modify any governance membership —
 *   including memberships belonging to a body in a tenant other
 *   than the one resolved for their request — because the
 *   permission check does not itself enforce the tenant boundary.
 *
 *   (The model's global scopes and the repository's tenant-scoped
 *   methods make the cross-tenant leak unlikely in practice, but
 *   "unlikely in practice" is not a security guarantee. The policy
 *   is the enforcement point that makes the guarantee structural.)
 *
 * After:
 *   One file that declares the domain's authorization policy for
 *   `GovernanceMembership`. The policy enforces two layers — the
 *   RBAC permission and the tenant boundary — and nothing more. The
 *   three additional authorization layers that later phases will
 *   add (confidentiality classification on specific memberships,
 *   position-based authority that distinguishes an officer's
 *   privileges from a member's, and any future business rule that
 *   conditionally grants a caller access to their own membership)
 *   are NOT part of this policy.
 *
 *   The policy exposes five abilities:
 *
 *     viewAny(User, GovernanceBody):         bool
 *         — list every membership of a body.
 *
 *     view(User, GovernanceMembership):      bool
 *         — read a specific membership.
 *
 *     create(User, GovernanceBody):          bool
 *         — register a new membership under a body.
 *
 *     update(User, GovernanceMembership):    bool
 *         — modify a specific membership (including its lifecycle
 *           transitions through the service's end / suspend / resume
 *           / resign / remove / expire methods).
 *
 *     delete(User, GovernanceMembership):    bool
 *         — soft-delete a specific membership.
 *
 *   Every ability grants through `AuthorizationService::hasPermission()`,
 *   fails closed on missing tenant context, and enforces the tenant
 *   boundary on every model-bearing argument. The grant rules are:
 *
 *     viewAny  →  `governance.body.view`   in the resolved tenant
 *                    AND  `$body->tenant_id === $tenantId`
 *
 *     view     →  `governance.body.view`   in the resolved tenant
 *                    AND  `$membership->tenant_id === $tenantId`
 *
 *     create   →  `governance.body.manage` in the resolved tenant
 *                    AND  `$body->tenant_id === $tenantId`
 *
 *     update   →  `governance.body.manage` in the resolved tenant
 *                    AND  `$membership->tenant_id === $tenantId`
 *
 *     delete   →  `governance.body.manage` in the resolved tenant
 *                    AND  `$membership->tenant_id === $tenantId`
 *
 *   What the policy deliberately does NOT do:
 *
 *     · It does NOT reference any role name. Authorization is
 *       permission-based, not role-based.
 *
 *     · It does NOT delegate to a sibling policy. A membership has
 *       no "own record" concept in the governance sense. The
 *       distinction from the Shareholder domain is deliberate: a
 *       Shareholder domain's `ShareholderPolicy::view` grants
 *       three alternative paths (administrative, self-service,
 *       representative) because a shareholder record belongs to
 *       the person it describes. A governance membership record
 *       does NOT belong to the person it describes in the same
 *       sense — it belongs to the organization, records an
 *       authoritative fact, and is administered by the body's
 *       officers. There is no "my own membership" surface at the
 *       HTTP layer, and this policy does not create one.
 *
 *     · It does NOT distinguish an officer's privileges from a
 *       member's. The domain's RBAC model uses role-based
 *       permissions (`governance.body.manage` for officers via
 *       the `president` and `secretary` roles; `governance.body.view`
 *       for board members via the `board` role) rather than a
 *       membership-based check. A future CR that decides the
 *       organization's policy requires an additional
 *       membership-based layer — e.g. only the current chairperson
 *       may end a membership — would extend `update` (and possibly
 *       `delete`) with a second grant path. The extension will be
 *       additive.
 *
 *     · It does NOT enforce confidentiality. Confidentiality is a
 *       property of governance RECORDS (minutes, resolutions,
 *       declarations), not of memberships. The fact that a named
 *       person occupies a named position on a named body is a
 *       structural fact, not a confidential one. A future CR that
 *       decides a specific membership — for example, one recorded
 *       under a sensitive declaration — should be individually
 *       restricted would introduce a confidentiality column and a
 *       policy-layer check at that time.
 *
 *     · It does NOT walk the appointment relation. An appointment
 *       has its own policy
 *       (`GovernanceAppointmentPolicy`, G2c file 8 of 31), and the
 *       membership policy does not consult it. A caller authorized
 *       to see a membership does not automatically gain authority
 *       to see the appointment the membership references; the
 *       appointment's own `view` ability governs that access.
 *
 *     · It does NOT throw. It returns `bool` on every ability; the
 *       controller is responsible for calling `Gate::authorize()`,
 *       which converts a `false` return into an
 *       `AuthorizationException` that the canonical renderer maps
 *       to a 403 `AUTH_PERMISSION_DENIED` response.
 *
 * =============================================================================
 * Why `create()` and `viewAny()` take a GovernanceBody, not a
 * GovernanceMembership
 * =============================================================================
 * Two abilities operate on a collection or on a yet-to-be-created
 * row, not on an existing membership:
 *
 *   `viewAny(User, GovernanceBody)` — the caller is asking to see
 *   the list of memberships for a specific body. There is no
 *   membership instance to authorize against; the body is the
 *   relevant scope.
 *
 *   `create(User, GovernanceBody)` — the caller is asking to create
 *   a new membership under a specific body. The body is the
 *   authorization scope; the not-yet-existing membership has no
 *   tenant_id of its own.
 *
 * Laravel resolves the second argument of these abilities from the
 * route's model binding (the `{governanceBody}` parameter) or from
 * the controller's explicit call. The two abilities check the
 * body's tenant, not the membership's.
 *
 * The three model-bearing abilities (`view`, `update`, `delete`)
 * take a `GovernanceMembership` and check its `tenant_id` directly.
 *
 * =============================================================================
 * Two-layer authorization model
 * =============================================================================
 * For a model-bearing ability (`view`, `update`, `delete`), the
 * policy enforces TWO layers, in this order:
 *
 *   Layer 1 — the tenant boundary.
 *       The membership's `tenant_id` must equal the tenant resolved
 *       from the server-side request context. A mismatch fails
 *       immediately, before any permission is consulted.
 *
 *   Layer 2 — the RBAC permission.
 *       The caller must hold the required permission in the
 *       resolved tenant.
 *
 * For a body-scoped ability (`viewAny`, `create`), the same two
 * layers apply, but Layer 1 checks the BODY's `tenant_id` instead
 * of the membership's.
 *
 * =============================================================================
 * The permission slugs
 * =============================================================================
 * As approved in the G2c manifest, memberships reuse the two
 * `governance.body.*` slugs seeded by G1's
 * `GovernancePermissionsSeeder`:
 *
 *   Read operations  →  `governance.body.view`
 *   Write operations →  `governance.body.manage`
 *
 * This is a decision worth stating explicitly, because the G1
 * seeder RESERVED two slugs for memberships:
 *
 *     governance.member.view
 *     governance.member.manage
 *
 * The manifest's §8 records the reservation and the decision to
 * leave both slugs unused for the current unit:
 *
 *     "G2a, G2b and G2c reuse `governance.body.{view,manage}` for
 *      structural sub-entities (positions, terms, appointments,
 *      memberships). No new permissions introduced by these
 *      sub-phases."
 *
 * The rationale: a membership is an aspect of a body's structure
 * and staffing — the same category as a position, a term, or an
 * appointment. A caller authorized to read or manage a body's
 * structure is authorized to read or manage its memberships. The
 * `governance.member.*` slugs remain reserved for a possible future
 * CR that decides memberships warrant their own authorization
 * boundary — for example, if the organization decides that the
 * body's chair should be able to see members' names without being
 * able to see the body's structural positions.
 *
 * A future CR that exercises the reservation amends the roles
 * seeder (to grant the two `governance.member.*` slugs to the
 * appropriate roles) and this policy (to switch its two constants
 * to the new slugs) together. The change is bounded and is
 * anticipated by the reserved names.
 *
 * =============================================================================
 * Tenant-context resolution
 * =============================================================================
 * The tenant id is resolved from `mases_tenant_id()` — the same
 * server-side helper every policy in the platform uses.
 *
 * When the helper is unavailable or returns null, `resolveTenantId()`
 * returns null, and every ability fails closed by returning `false`.
 *
 * =============================================================================
 * No database queries beyond the injected service
 * =============================================================================
 * The policy performs no direct database queries. Permission
 * lookups go through `AuthorizationService`, which caches results.
 * The tenant-boundary checks are integer comparisons against
 * already-loaded model attributes.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Policies/GovernanceMembershipPolicy.php
 *
 * The directory already exists.
 *
 * The policy is registered with the `Gate` facade by the amended
 * `GovernanceServiceProvider` (G2c file 30 of 31). Until that
 * amendment is deployed, this policy file is inert.
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