<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2a · File 6 of 17
 * =============================================================================
 *
 * File:            Policies/GovernanceBodyPolicy.php  (NEW)
 * Unit:            G2a — GovernanceBody vertical slice (policy)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No `Gate` policy governed access to
 *   `GovernanceBody` rows. Any caller with a `governance.body.view`
 *   or `governance.body.manage` permission could in principle read
 *   or modify any governance body — including bodies in a tenant
 *   other than the one resolved for their request — because the
 *   permission check does not itself enforce the tenant boundary.
 *
 *   (The model's global scopes and the repository's tenant-scoped
 *   methods make the cross-tenant leak unlikely in practice, but
 *   "unlikely in practice" is not a security guarantee. The policy
 *   is the enforcement point that makes the guarantee structural.)
 *
 * After:
 *   One file that declares the domain's authorization policy for
 *   `GovernanceBody`. The policy is deliberately simple at G2a: it
 *   enforces two layers — the RBAC permission and the tenant
 *   boundary — and nothing more. The three additional authorization
 *   layers that later phases will add (active membership of the
 *   body during the current term, position-based authority, and
 *   confidentiality classification) are NOT part of this policy;
 *   they belong to policies introduced by the phases that create
 *   the entities they govern.
 *
 *   The policy exposes five abilities:
 *
 *     viewAny(User):                       bool
 *         — list every governance body in the resolved tenant.
 *
 *     view(User, GovernanceBody):          bool
 *         — read a specific governance body.
 *
 *     create(User):                        bool
 *         — register a new governance body.
 *
 *     update(User, GovernanceBody):        bool
 *         — modify a specific governance body.
 *
 *     delete(User, GovernanceBody):        bool
 *         — soft-delete a specific governance body.
 *
 *   Every ability grants through `AuthorizationService::hasPermission()`,
 *   fails closed on missing tenant context, and enforces the tenant
 *   boundary on every model-bearing argument. The grant rules are:
 *
 *     viewAny  →  `governance.body.view`   in the resolved tenant
 *     view     →  `governance.body.view`   in the resolved tenant
 *                    AND  `$body->tenant_id === $tenantId`
 *     create   →  `governance.body.manage` in the resolved tenant
 *     update   →  `governance.body.manage` in the resolved tenant
 *                    AND  `$body->tenant_id === $tenantId`
 *     delete   →  `governance.body.manage` in the resolved tenant
 *                    AND  `$body->tenant_id === $tenantId`
 *
 *   What the policy deliberately does NOT do:
 *
 *     · It does NOT reference any role name. Authorization is
 *       permission-based, not role-based. A caller's role is
 *       irrelevant; what matters is whether the permission is
 *       granted through any of the caller's roles or directly.
 *       This mirrors every other policy in the platform.
 *
 *     · It does NOT delegate to a sibling policy. Unlike
 *       `ShareholderPolicy`, which grants `view` through three
 *       alternative paths (administrative permission, self-service
 *       with a direct user link, or representative relationship),
 *       a governance body has no self-service or representative
 *       ownership concept. A user does not "own" a governance
 *       body the way a shareholder owns their own record. The
 *       policy therefore has one grant rule per ability, not three.
 *
 *     · It does NOT walk the membership table. Whether a user is
 *       an active member of a body is a decision that depends on
 *       `GovernanceMembership`, `GovernanceTerm`, and the current
 *       date. Those entities do not exist yet at G2a; they will
 *       arrive in G2c. When they do, a future CR extends this
 *       policy's `view` ability (and possibly others) to grant via
 *       an active membership in addition to the RBAC permission.
 *       The extension is additive: the existing rule remains, and
 *       the membership rule is added alongside it as an alternative
 *       grant path — the same shape `ShareholderPolicy::view`
 *       uses for its three paths.
 *
 *     · It does NOT enforce confidentiality. Confidentiality is a
 *       property of governance RECORDS (minutes, resolutions,
 *       declarations), not of governance BODIES. A body's
 *       existence and name are not confidential; its agenda items
 *       and its minutes may be. Confidentiality will be enforced
 *       by the policies of the entities that carry a
 *       confidentiality classification, from G6 onward.
 *
 *     · It does NOT compare the caller's tenant against the
 *       request's tenant. It compares against the tenant resolved
 *       by the platform's `mases_tenant_id()` helper — the same
 *       helper every other policy in the platform uses. The
 *       caller cannot influence this value.
 *
 *     · It does NOT throw. It returns `bool` on every ability; the
 *       controller is responsible for calling `Gate::authorize()`,
 *       which converts a `false` return into an
 *       `AuthorizationException` that the canonical renderer maps
 *       to a 403 `AUTH_PERMISSION_DENIED` response. The policy
 *       itself never raises.
 *
 * =============================================================================
 * The two-layer authorization model
 * =============================================================================
 * For a model-bearing ability (`view`, `update`, `delete`), the
 * policy enforces TWO layers, in this order:
 *
 *   Layer 1 — the tenant boundary.
 *       The body's `tenant_id` must equal the tenant resolved from
 *       the server-side request context. A mismatch fails
 *       immediately, before any permission is consulted. This is
 *       defence in depth: the controller (G2a file 14) also
 *       enforces the tenant boundary before calling the policy, so
 *       a cross-tenant id normally produces a 404 from the
 *       controller rather than a 403 from the policy. But the
 *       policy's own check means a future caller that reaches the
 *       policy without the controller's guard still cannot cross
 *       tenants.
 *
 *   Layer 2 — the RBAC permission.
 *       The caller must hold the required permission in the
 *       resolved tenant. The permission check goes through
 *       `AuthorizationService::hasPermission()`, which caches
 *       results and is tenant-scoped.
 *
 * For a model-agnostic ability (`viewAny`, `create`), only Layer 2
 * applies — there is no model to check the tenant boundary against.
 * The permission check is itself tenant-scoped, so the ability
 * still cannot leak across tenants.
 *
 * =============================================================================
 * Why the policy is intentionally thin
 * =============================================================================
 * A reader comparing this file to `ShareholderPolicy` will notice
 * that it is much shorter. The difference is deliberate and reflects
 * a difference between the two domains:
 *
 *   · A shareholder record is simultaneously (a) an administrative
 *     dataset, (b) a self-service identity for the individual
 *     shareholder, and (c) a representative relationship for legal
 *     entities. The ShareholderPolicy must express all three paths
 *     in one `view` ability.
 *
 *   · A governance body is (a) an administrative dataset — full
 *     stop. There is no self-service "my governance body" surface,
 *     and there is no representative relationship in which one
 *     user acts on another user's behalf toward a body. The body
 *     is a shared institutional entity whose access is granted
 *     purely by permission, plus — in a later phase — by active
 *     membership.
 *
 * The thin policy is therefore the correct shape for G2a. A future
 * CR that adds the membership-based grant path will extend the
 * `view` ability (and possibly `update`), and the file will grow
 * to reflect the additional layer. Growth by the addition of a
 * specific new grant path is expected; growth by the addition of
 * unrelated concerns is not.
 *
 * =============================================================================
 * Tenant-context resolution
 * =============================================================================
 * The tenant id is resolved from `mases_tenant_id()` — the same
 * server-side helper every policy in the platform uses. The helper
 * reads the tenant that `TenantContextMiddleware` established
 * earlier in the request pipeline. The policy does NOT read the
 * tenant from:
 *
 *   · the request body,
 *   · a query-string parameter,
 *   · a custom header other than the one the established
 *     `RequestTenantResolver` consumes (`X-Tenant-ID`), which is
 *     handled earlier in the pipeline.
 *
 * When the helper is unavailable (rare — the helper is autoloaded
 * by `composer.json`) or returns null (no tenant context resolved),
 * `resolveTenantId()` returns null, and every ability fails closed
 * by returning `false`. This is the platform's convention; a
 * missing tenant is a deployment or routing defect that must not
 * be masked by a permissive default.
 *
 * =============================================================================
 * Caching — none at this layer
 * =============================================================================
 * The policy does not cache. Caching of permission checks is
 * `AuthorizationService`'s responsibility, and the service already
 * caches with a per-user key registry (see its class docblock for
 * the full mechanism). The policy's own work — a tenant comparison
 * and a cache-backed permission lookup — is cheap. Adding a cache
 * here would duplicate the service's cache and introduce a second
 * invalidation surface.
 *
 * =============================================================================
 * No database queries beyond the injected service
 * =============================================================================
 * The policy performs no direct database queries. Permission
 * lookups go through `AuthorizationService`, which caches results
 * in the framework cache. The tenant-boundary check is an integer
 * comparison against already-loaded model attributes. No relation
 * is traversed, no row is loaded.
 *
 * This matches `ShareClassPolicy` and `ShareholderPolicy`'s
 * contracts, both of which document the same property.
 *
 * =============================================================================
 * Testability
 * =============================================================================
 * The policy is a plain class with one dependency. Unit tests
 * construct it with a fake `AuthorizationService` and assert every
 * branch (permission granted in-tenant, permission denied,
 * cross-tenant mismatch, missing tenant context). Feature tests
 * exercise the whole chain through the `Gate` facade against real
 * users and permissions.
 *
 * The policy has no static state, no constructor side effects, and
 * no reliance on the request lifecycle beyond the
 * `mases_tenant_id()` helper — which is a global function whose
 * return value is easy to control in tests.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Policies/GovernanceBodyPolicy.php
 *
 * The directory `mases_core/src/Domains/Governance/Policies/` must
 * exist. If it does not, create it before uploading — the file's
 * path is the full path.
 *
 * The policy is registered with the `Gate` facade by the amended
 * `GovernanceServiceProvider` (G2a file 15 of 17). Until that
 * amendment is deployed, this policy file is inert — present on
 * disk but not resolvable by `Gate::authorize()`, which would fall
 * through to Laravel's default deny. The amendment adds one line
 * to the provider:
 *
 *     Gate::policy(GovernanceBody::class, GovernanceBodyPolicy::class);
 *
 * No `composer dump-autoload` is required if the class map is
 * already current; the amendment to the provider is what makes the
 * policy active, and the provider is loaded on every request.
 *
 * Verification (after G2a file 15 is also deployed):
 *
 *     php artisan tinker
 *     >>> use Illuminate\Support\Facades\Gate;
 *     >>> use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
 *     >>> Gate::getPolicyFor(GovernanceBody::class)
 *     => MaaleArna\MASES\Core\Domains\Governance\Policies\GovernanceBodyPolicy {#...}
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