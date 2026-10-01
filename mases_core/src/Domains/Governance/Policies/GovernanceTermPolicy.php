<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 17 of 27
 * =============================================================================
 *
 * File:            Policies/GovernanceTermPolicy.php  (NEW)
 * Unit:            G2b — GovernanceTerm vertical slice (policy)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No `Gate` policy governed access to
 *   `GovernanceTerm` rows. Any caller with a `governance.body.view`
 *   or `governance.body.manage` permission could in principle read
 *   or modify any governance term — including terms belonging to a
 *   body in a tenant other than the one resolved for their request —
 *   because the permission check does not itself enforce the tenant
 *   boundary.
 *
 *   (The model's global scopes and the repository's tenant-scoped
 *   methods make the cross-tenant leak unlikely in practice, but
 *   "unlikely in practice" is not a security guarantee. The policy
 *   is the enforcement point that makes the guarantee structural.)
 *
 * After:
 *   One file that declares the domain's authorization policy for
 *   `GovernanceTerm`. The policy enforces two layers — the RBAC
 *   permission and the tenant boundary — and nothing more. The
 *   three additional authorization layers that later phases will
 *   add (active membership of the body during the current term,
 *   position-based authority, confidentiality classification) are
 *   NOT part of this policy; they belong to policies introduced by
 *   the phases that create the entities they govern.
 *
 *   The policy exposes five abilities:
 *
 *     viewAny(User, GovernanceBody):     bool
 *         — list every term of a body.
 *
 *     view(User, GovernanceTerm):        bool
 *         — read a specific term.
 *
 *     create(User, GovernanceBody):      bool
 *         — register a new term under a body.
 *
 *     update(User, GovernanceTerm):      bool
 *         — modify a specific term.
 *
 *     delete(User, GovernanceTerm):      bool
 *         — soft-delete a specific term.
 *
 *   Every ability grants through `AuthorizationService::hasPermission()`,
 *   fails closed on missing tenant context, and enforces the tenant
 *   boundary on every model-bearing argument. The grant rules are:
 *
 *     viewAny  →  `governance.body.view`   in the resolved tenant
 *                    AND  `$body->tenant_id === $tenantId`
 *
 *     view     →  `governance.body.view`   in the resolved tenant
 *                    AND  `$term->tenant_id === $tenantId`
 *
 *     create   →  `governance.body.manage` in the resolved tenant
 *                    AND  `$body->tenant_id === $tenantId`
 *
 *     update   →  `governance.body.manage` in the resolved tenant
 *                    AND  `$term->tenant_id === $tenantId`
 *
 *     delete   →  `governance.body.manage` in the resolved tenant
 *                    AND  `$term->tenant_id === $tenantId`
 *
 *   What the policy deliberately does NOT do:
 *
 *     · It does NOT reference any role name. Authorization is
 *       permission-based, not role-based.
 *
 *     · It does NOT delegate to a sibling policy. A term has no
 *       "own record" concept. The policy has one grant rule per
 *       ability.
 *
 *     · It does NOT walk the membership table. Whether a user is an
 *       active member of a body during a specific term is a decision
 *       that depends on `GovernanceMembership` — which does not
 *       exist yet at G2b. When G2c arrives, a future CR may extend
 *       `view` (and possibly `update`) to grant via an active
 *       membership in addition to the RBAC permission. The
 *       extension will be additive.
 *
 *     · It does NOT enforce confidentiality. Confidentiality is a
 *       property of governance RECORDS, not of governance terms.
 *
 *     · It does NOT throw. It returns `bool` on every ability.
 *
 * =============================================================================
 * Why `create()` and `viewAny()` take a GovernanceBody, not a
 * GovernanceTerm
 * =============================================================================
 * Two abilities operate on a collection or on a yet-to-be-created
 * row, not on an existing term:
 *
 *   `viewAny(User, GovernanceBody)` — the caller is asking to see
 *   the list of terms for a specific body. There is no term instance
 *   to authorize against; the body is the relevant scope.
 *
 *   `create(User, GovernanceBody)` — the caller is asking to create
 *   a new term under a specific body. The body is the authorization
 *   scope; the not-yet-existing term has no tenant_id of its own.
 *
 * Laravel resolves the second argument of these abilities from the
 * route's model binding (the `{governanceBody}` parameter) or from
 * the controller's explicit call. The two abilities check the body's
 * tenant, not the term's.
 *
 * The three model-bearing abilities (`view`, `update`, `delete`)
 * take a `GovernanceTerm` and check its `tenant_id` directly.
 *
 * =============================================================================
 * Two-layer authorization model
 * =============================================================================
 * For a model-bearing ability (`view`, `update`, `delete`), the
 * policy enforces TWO layers, in this order:
 *
 *   Layer 1 — the tenant boundary.
 *       The term's `tenant_id` must equal the tenant resolved from
 *       the server-side request context. A mismatch fails
 *       immediately, before any permission is consulted.
 *
 *   Layer 2 — the RBAC permission.
 *       The caller must hold the required permission in the resolved
 *       tenant.
 *
 * For a body-scoped ability (`viewAny`, `create`), the same two
 * layers apply, but Layer 1 checks the BODY's `tenant_id` instead of
 * the term's.
 *
 * =============================================================================
 * The permission slugs
 * =============================================================================
 * As approved in the G2b manifest, terms reuse the two
 * `governance.body.*` slugs seeded by G1's
 * `GovernancePermissionsSeeder`:
 *
 *   Read operations  →  `governance.body.view`
 *   Write operations →  `governance.body.manage`
 *
 * No new slugs are introduced. A term is a structural attribute of a
 * body; a caller authorized to read or manage a body's structure is
 * authorized to read or manage its terms. The `governance.member.*`
 * slugs reserved by G1 remain for G2c's memberships.
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
 * The policy performs no direct database queries. Permission lookups
 * go through `AuthorizationService`, which caches results. The
 * tenant-boundary checks are integer comparisons against
 * already-loaded model attributes.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Policies/GovernanceTermPolicy.php
 *
 * The directory already exists.
 *
 * The policy is registered with the `Gate` facade by the amended
 * `GovernanceServiceProvider` (G2b file 26 of 27). Until that
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
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;

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
 * @package MaaleArna\MASES\Core\Domains\Governance\Policies
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