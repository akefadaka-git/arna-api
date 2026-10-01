<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P2.3 · File 3 of 7
 * =============================================================================
 *
 * File:            ShareClassPolicy.php  (NEW)
 * Unit:            P2.3 — wire share_class.manage (decision 3b)
 * Delivery type:   New file
 * Net lines:       +…
 *
 * Before:
 *   There was no ShareClassPolicy. The ShareClassController gated both
 *   its actions with `auth.permission:share_class.view` at the route
 *   level. The `share_class.manage` slug was declared in the RBAC
 *   vocabulary and assigned to super_admin and platform_admin, but no
 *   code path read it. There were no write endpoints on the controller,
 *   so no route existed that a manage-gated action could attach to.
 *
 * After:
 *   A new policy class with two abilities:
 *
 *     viewAny(User)                  — for index (list all share classes)
 *     view(User, ShareClass)         — for show (single share class)
 *
 *   Both abilities grant if the caller holds EITHER:
 *
 *     · share_class.view   — the read permission
 *     · share_class.manage — the manage permission, treated as a
 *                            superset of view
 *
 *   A caller holding only `share_class.manage` (and not `share_class.view`)
 *   is admitted by these abilities. This makes the manage slug effective
 *   without introducing write endpoints, which would expand the unit into
 *   a full ShareClass write-surface CR (deferred; the ShareClassService
 *   docblock explicitly reserves write methods for a future CR).
 *
 * =============================================================================
 * Behavioural change to the routes file — required, delivered in File 5
 * =============================================================================
 * The classes block in `Routes/api.php` currently carries
 * `->middleware('auth.permission:share_class.view')` on the whole group.
 * That middleware denies a manage-only caller before the controller
 * runs, so the policy's new grant would never be reached.
 *
 * File 5 of this sequence removes that middleware and lets the
 * controller's Gate check perform the authorization. This is the pattern
 * already in use for `holdings.index`, `holdings.show`,
 * `certificates.index`, and `certificates.show` — none of those carries
 * route middleware; all delegate to the policy layer.
 *
 * =============================================================================
 * No tenant check on the ShareClass row
 * =============================================================================
 * Share classes are global reference data (D3). The `share_classes`
 * table has no `tenant_id` column; every tenant sees the same two rows.
 * The policy therefore does NOT compare the ShareClass's tenant against
 * the resolved tenant — there is nothing to compare.
 *
 * The policy still resolves the tenant id from the request context,
 * because `AuthorizationService::hasPermission` requires it. If no
 * tenant is resolved, the ability fails closed.
 *
 * =============================================================================
 * Preserved contracts
 * =============================================================================
 *   · Class is `final`.
 *   · Same namespace, same directory as ShareholderPolicy and
 *     ShareHoldingPolicy.
 *   · Constructor dependency: AuthorizationService only (no
 *     ShareholderPolicy — share classes have no per-shareholder access
 *     path).
 *   · Same `resolveTenantId()` helper as the sibling policies.
 *   · No direct database queries.
 *
 * Files modified:            none
 * Files added:               this file only
 * Files deleted:             none
 * Provider registration:     pending — File 4 of this sequence adds
 *                            `Gate::policy(ShareClass::class, ShareClassPolicy::class)`
 * Route middleware touched:  pending — File 5 removes the group-level
 *                            `auth.permission:share_class.view`
 * Seeder changes:            none
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Shareholder\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareClass;

/**
 * ShareClassPolicy
 *
 * Authorization rules for viewing share-class reference data.
 *
 * =============================================================================
 * Introduction and contract
 * =============================================================================
 * This policy is new in Phase 2.3. It does not replace the route-level
 * permission check that previously gated the share-class read routes; it
 * supersedes it, in favour of the same policy-layer pattern used
 * elsewhere in the Shareholder domain.
 *
 * The purpose of the policy is to make the `share_class.manage` slug
 * effective without introducing write endpoints. A caller holding only
 * `share_class.manage` — for example a future role that manages share
 * classes but is not expected to read shareholder records — is admitted
 * to the read routes, on the reasoning that a manage grant implies a
 * read grant.
 *
 * =============================================================================
 * Ability map
 * =============================================================================
 * The policy exposes two abilities, mapped by Laravel to the following
 * controller calls (after File 6 of this sequence updates the
 * controller):
 *
 *   viewAny(User $user)
 *       ← ShareClassController::index
 *
 *   view(User $user, ShareClass $shareClass)
 *       ← ShareClassController::show
 *
 * Both abilities apply the same grant rule. `viewAny` does not receive a
 * model instance; `view` receives the resolved ShareClass but does not
 * inspect it beyond its existence (which is guaranteed by the route
 * model binding before the controller's Gate check runs).
 *
 * No `create`, `update`, `delete`, `restore`, or `forceDelete` ability
 * is defined. Share classes are seeded reference data (D3); the
 * `ShareClassController` has no write methods, and `ShareClassService`
 * is deliberately read-only. A future CR that introduces write endpoints
 * would add the corresponding abilities to this policy alongside the
 * write routes.
 *
 * =============================================================================
 * The manage-as-superset rule
 * =============================================================================
 * The grant check is a disjunction:
 *
 *     share_class.view  OR  share_class.manage
 *
 * The interpretation of `share_class.manage` as a superset of
 * `share_class.view` is a deliberate design decision, recorded here so
 * that a future reader does not mistake it for an oversight.
 *
 * Rationale:
 *
 *   · The natural reading of a "manage" permission is that it includes
 *     read access. A role that can create, update, or delete a share
 *     class can trivially infer the current state of the share-class
 *     table by attempting those operations, so denying read access
 *     while granting write access would produce no security benefit.
 *
 *   · The alternative — treating view and manage as orthogonal — would
 *     require every role that manages share classes to also be granted
 *     `share_class.view`. That is redundant bookkeeping; the disjunction
 *     achieves the same outcome with one grant instead of two.
 *
 *   · The `super_admin` and `platform_admin` roles already hold both
 *     slugs. The disjunction is a no-op for them. It matters only for a
 *     future role composed with `manage` but not `view`.
 *
 * If a future CR decides the two slugs should be orthogonal, the change
 * is confined to this policy: replace the disjunction with a check for
 * `share_class.view` alone, and let the manage grant gate only the
 * write abilities the CR introduces.
 *
 * =============================================================================
 * Tenant context
 * =============================================================================
 * `share_classes` has no `tenant_id` column. Share classes are global
 * reference data — every tenant sees the same rows. The policy therefore
 * does NOT compare the ShareClass (or the request) against a tenant
 * boundary for the specific row being authorized.
 *
 * The policy still resolves the tenant id from the request context,
 * because `AuthorizationService::hasPermission` is a tenant-scoped
 * lookup: the caller's permission set is evaluated against the tenant
 * the request has been resolved into. If no tenant is resolved, the
 * ability fails closed. This is the same fail-closed behaviour used by
 * every other policy in the domain.
 *
 * The consequence is that a caller with `share_class.view` in tenant A
 * and `share_class.view` in tenant B sees the same two rows from either
 * tenant. This is correct for global reference data; if the domain ever
 * introduces tenant-scoped share classes (a future CR), this policy
 * would be extended with a tenant match on the model.
 *
 * =============================================================================
 * No direct database queries
 * =============================================================================
 * This policy performs no database queries. The permission lookups go
 * through `AuthorizationService`, which caches results in the framework
 * cache. The ShareClass model is not touched beyond its existence, which
 * is established by the route model binding before the controller's Gate
 * check runs.
 *
 * =============================================================================
 * Testability
 * =============================================================================
 * The policy is a plain class with one dependency. Unit tests construct
 * it with a fake `AuthorizationService` and assert every branch.
 * Feature tests exercise the whole chain through the Gate facade against
 * real users and permissions.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Policies
 * @version 1.0.0
 */
final class ShareClassPolicy
{
    /**
     * Construct the policy with its dependency.
     *
     * `AuthorizationService` is a Phase 1 singleton bound by
     * CoreServiceProvider. The container resolves it by reflection; no
     * provider change is required for the dependency itself.
     *
     * @param AuthorizationService $authorization
     */
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {
    }

    // =========================================================================
    // viewAny — list share classes
    // =========================================================================

    /**
     * Determine whether the user may list share classes.
     *
     * Grants if the caller holds `share_class.view` OR
     * `share_class.manage`. See the class docblock §"The manage-as-
     * superset rule" for the reasoning.
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $this->canViewShareClasses($user);
    }

    // =========================================================================
    // view — read a single share class
    // =========================================================================

    /**
     * Determine whether the user may view a specific share class.
     *
     * Grants if the caller holds `share_class.view` OR
     * `share_class.manage`. The ShareClass model itself is not inspected;
     * its existence is established by the route model binding before
     * this method runs, and share classes carry no per-row access rule
     * beyond the tenant-agnostic reference-data semantics.
     *
     * @param User       $user
     * @param ShareClass $shareClass
     * @return bool
     */
    public function view(User $user, ShareClass $shareClass): bool
    {
        return $this->canViewShareClasses($user);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * The shared grant check for both abilities.
     *
     * Fails closed when no tenant context is available — the permission
     * lookup is tenant-scoped, and without a tenant the caller's grants
     * cannot be evaluated. When a tenant is present, returns true if the
     * caller holds either the read or the manage permission.
     *
     * @param User $user
     * @return bool
     */
    private function canViewShareClasses(User $user): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ($this->authorization->hasPermission(
            permissionSlug: 'share_class.view',
            user: $user,
            tenantId: $tenantId,
        )) {
            return true;
        }

        return $this->authorization->hasPermission(
            permissionSlug: 'share_class.manage',
            user: $user,
            tenantId: $tenantId,
        );
    }

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