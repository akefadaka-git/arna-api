<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P2.3 · File 1 of 7
 * =============================================================================
 *
 * File:            ShareHoldingPolicy.php
 * Unit:            P2.3 — wire share_holding.view / share_holding.view_own
 * Delivery type:   Amendment
 * Net lines:       +1 constructor dependency; +1 ability method;
 *                  +1 private helper; view() body rewritten
 *
 * Before:
 *   The policy exposed one ability — view(User, ShareHolding) — which
 *   loaded the holding's shareholder and delegated directly to
 *   ShareholderPolicy::view. The two share_holding-domain slugs
 *   (`share_holding.view`, `share_holding.view_own`) were declared in
 *   the RBAC vocabulary and assigned to roles, but no code path read
 *   them. The class docblock described the delegation as the single
 *   rule.
 *
 * After:
 *   Two abilities:
 *
 *     view(User, ShareHolding)              — preserved contract; loads
 *                                             the holding's shareholder
 *                                             and delegates to
 *                                             viewForShareholder.
 *
 *     viewForShareholder(User, Shareholder) — new. Grants through an OR
 *                                             of three paths:
 *
 *       1. caller holds `share_holding.view`                (administrative)
 *       2. caller holds `share_holding.view_own` AND is
 *          the shareholder's direct user_id link            (self-service)
 *       3. `ShareholderPolicy::view` returns true           (delegation:
 *                                                             shareholder.view,
 *                                                             shareholder.view_own
 *                                                             + self-link,
 *                                                             or representative)
 *
 *   The three paths are alternatives; any one grants.
 *
 * Constructor change:
 *   The policy now takes an `AuthorizationService` in addition to the
 *   existing `ShareholderPolicy`. The container resolves both via
 *   reflection; no provider change is required.
 *
 * Preserved:
 *   · The class remains `final`.
 *   · The delegation to `ShareholderPolicy` as path 3.
 *   · Fail-closed on an orphaned holding (shareholder relation null).
 *   · Cross-tenant short-circuit (returns false before any permission
 *     check).
 *   · No direct database queries beyond the existing `loadMissing`.
 *
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Route middleware touched:  none
 * Seeder changes:            none
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Shareholder\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareHolding;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;

/**
 * ShareHoldingPolicy
 *
 * Authorization rules for viewing a share holding, and — via the
 * `viewForShareholder` ability — for viewing a shareholder's holdings
 * as a collection.
 *
 * =============================================================================
 * Ability map
 * =============================================================================
 * The policy exposes two abilities, mapped by Laravel to the following
 * controller calls:
 *
 *   view(User $user, ShareHolding $holding)
 *       ← ShareHoldingController::show
 *
 *   viewForShareholder(User $user, Shareholder $shareholder)
 *       ← ShareHoldingController::index
 *       ← ShareHoldingController::store (target resolution step)
 *
 * No `create`, `update`, or `delete` ability is defined. A holding's
 * existence, its current quantity, and its lifetime are all managed by
 * ShareHoldingService (2A.14), not by controller actions. There is no
 * HTTP surface that mutates a holding directly, so no ability is needed.
 *
 * =============================================================================
 * Three access paths
 * =============================================================================
 * Both abilities grant access through ANY of three paths. The two
 * abilities share the same rule — `view` simply resolves the holding's
 * shareholder and forwards to `viewForShareholder`.
 *
 *   1. Administrative (holding domain). The caller holds
 *      `share_holding.view`. Grants access to any shareholder's holdings
 *      in the resolved tenant.
 *
 *   2. Self-service (holding domain). The caller is the individual
 *      shareholder's direct user link (shareholders.user_id equals the
 *      caller's id) AND holds `share_holding.view_own`. Grants access to
 *      that shareholder's holdings only.
 *
 *   3. Delegation (shareholder domain). `ShareholderPolicy::view` on the
 *      holding's shareholder returns true. This transitively admits:
 *
 *        · administrative callers holding `shareholder.view`;
 *        · self-service callers holding `shareholder.view_own` with a
 *          direct link to the shareholder;
 *        · representatives of a legal entity (path 3 of the shareholder
 *          policy — no explicit permission required).
 *
 * The three paths are alternatives. Access is granted if any one of them
 * authorizes the operation.
 *
 * =============================================================================
 * Why both domains' slugs grant access
 * =============================================================================
 * The `share_holding.*` slugs and the `shareholder.*` slugs describe the
 * same underlying access in two vocabularies:
 *
 *   · `shareholder.view` / `share_holding.view` — the administrative
 *     grant. A user who manages the shareholder register holds both; a
 *     user who only manages holdings can be granted only the latter.
 *
 *   · `shareholder.view_own` / `share_holding.view_own` — the
 *     self-service grant. A user who is a direct-linked individual
 *     shareholder holds both; a user whose role grants only one of them
 *     is admitted by that one.
 *
 * Wiring both sets into the holding policy lets a role be composed with
 * either vocabulary without loss. A role holding only `share_holding.*`
 * works; a role holding only `shareholder.*` works; a role holding both
 * works. There is no OR-drift risk because all three paths are pure
 * disjunctions — none can silently deny a caller who would be admitted
 * by another.
 *
 * =============================================================================
 * Representative semantics (D11)
 * =============================================================================
 * A representative User acts on a legal-entity shareholder's behalf.
 * They obtain access to the entity's holdings exclusively through
 * path 3 (the delegated ShareholderPolicy's representative path), which
 * requires no permission.
 *
 * They do NOT obtain access through path 2 (self-service). Path 2
 * requires `share_holding.view_own` AND a direct user_id link; a
 * representative relationship satisfies neither condition, and D11
 * forbids legal entities from carrying a direct user_id.
 *
 * The consequence is intentional: the `_view_own` slugs are self-service
 * grants, not representative grants. A representative's access is a
 * structural relationship, not a permission.
 *
 * =============================================================================
 * Tenant boundary
 * =============================================================================
 * Every check begins with a tenant match: the shareholder's `tenant_id`
 * must equal the tenant resolved from the request via `mases_tenant_id()`.
 * If the tenant cannot be resolved or does not match, the ability
 * returns false before any permission is consulted.
 *
 * The controller (2A.23) already performs the same tenant check and
 * returns 404 on mismatch. The policy repeats it as defence in depth —
 * a caller whose permissions would otherwise grant access must not be
 * able to cross the tenant boundary through a policy bug.
 *
 * When the check fails, the policy returns false. The canonical renderer
 * converts the resulting AuthorizationException to a 403
 * AUTH_PERMISSION_DENIED response. A cross-tenant holding is thus
 * reported as 403 by the policy layer, but as 404 by the controller
 * layer, because the controller's tenant assertion runs first. The
 * policy's 403 is unreachable in normal flow — it is a safety net.
 *
 * =============================================================================
 * Fail-closed on orphaned holding
 * =============================================================================
 * If the holding's `shareholder` relation resolves to null — meaning the
 * foreign key points at a missing row — the `view` ability returns
 * false. An orphaned ledger entry is a referential-integrity incident,
 * not a case to handle gracefully. Returning false produces an
 * AuthorizationException (403), which is the correct response: the
 * caller cannot be granted access to a holding that has no identifiable
 * owner.
 *
 * In normal operation the FK constraint on `share_holdings.shareholder_id`
 * prevents orphaned rows. The check is defensive.
 *
 * =============================================================================
 * No direct database queries
 * =============================================================================
 * The only database access this policy triggers is the eager load of the
 * `shareholder` relation in `view()`. If the caller has already loaded it
 * (as `ShareHoldingController::show()` does not — but the tenant
 * assertion in the controller does), `loadMissing` is a no-op. If the
 * relation is not loaded, one query resolves it.
 *
 * The permission lookups in `viewForShareholder()` go through
 * `AuthorizationService` (Phase 1), which caches results in the
 * framework cache. The delegated `ShareholderPolicy::view` performs the
 * same lookups; the cache makes the second lookup free.
 *
 * =============================================================================
 * Testability
 * =============================================================================
 * The policy is a plain class with two dependencies. Unit tests
 * construct it with a fake `AuthorizationService` and a fake
 * `ShareholderPolicy` and assert every branch. Feature tests exercise
 * the whole chain through the Gate facade against real users and
 * permissions.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Policies
 * @version 2.0.0
 */
final class ShareHoldingPolicy
{
    /**
     * Construct the policy with its dependencies.
     *
     * `AuthorizationService` is a Phase 1 singleton bound by
     * CoreServiceProvider; `ShareholderPolicy` is instantiated by Laravel
     * when the Gate resolves this policy. Both are resolved from the
     * container.
     *
     * @param ShareholderPolicy    $shareholderPolicy
     * @param AuthorizationService $authorization
     */
    public function __construct(
        private readonly ShareholderPolicy $shareholderPolicy,
        private readonly AuthorizationService $authorization,
    ) {
    }

    // =========================================================================
    // view — read a single holding
    // =========================================================================

    /**
     * Determine whether the user may view a specific share holding.
     *
     * Resolves the holding's owning shareholder and delegates to
     * `viewForShareholder`. See the class docblock for the three paths
     * the delegated method implements.
     *
     * @param User         $user
     * @param ShareHolding $holding
     * @return bool
     */
    public function view(User $user, ShareHolding $holding): bool
    {
        // Ensure the shareholder relation is present without issuing a
        // redundant query when it is already loaded.
        $holding->loadMissing('shareholder');

        $shareholder = $holding->shareholder;

        // Fail closed on an orphaned holding. See the class docblock.
        if ($shareholder === null) {
            return false;
        }

        return $this->viewForShareholder($user, $shareholder);
    }

    // =========================================================================
    // viewForShareholder — read a shareholder's holdings as a collection
    // =========================================================================

    /**
     * Determine whether the user may list the holdings of a specific
     * shareholder.
     *
     * Called by `ShareHoldingController::index()` (after resolving the
     * shareholder from the `shareholder_id` query parameter) and by
     * `ShareHoldingController::store()` (both target modes — the entry
     * being recorded is against the resolved shareholder's holding).
     *
     * Grants access through any of the three paths described in the
     * class docblock. Fails closed on tenant mismatch, missing tenant
     * context, or absence of a matching permission or relationship.
     *
     * @param User        $user
     * @param Shareholder $shareholder
     * @return bool
     */
    public function viewForShareholder(User $user, Shareholder $shareholder): bool
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
        // Path 1 — administrative holding permission.
        //
        // A caller holding `share_holding.view` can see any shareholder's
        // holdings in the resolved tenant.
        // ---------------------------------------------------------------------
        if ($this->authorization->hasPermission(
            permissionSlug: 'share_holding.view',
            user: $user,
            tenantId: $tenantId,
        )) {
            return true;
        }

        // ---------------------------------------------------------------------
        // Path 2 — self-service holding permission.
        //
        // The caller must be the shareholder's direct user link AND hold
        // `share_holding.view_own`. This mirrors `ShareholderPolicy::view`
        // path 2 (which checks `shareholder.view_own`); the two slugs are
        // parallel grants in the two domains' vocabularies.
        //
        // The `isIndividual()` guard is defensive: D11 forbids a direct
        // user_id on a legal entity, and a corrupted row that violates
        // D11 must not receive self-service access through this path.
        // ---------------------------------------------------------------------
        if (
            $shareholder->isIndividual()
            && $shareholder->user_id !== null
            && (int) $shareholder->user_id === (int) $user->id
            && $this->authorization->hasPermission(
                permissionSlug: 'share_holding.view_own',
                user: $user,
                tenantId: $tenantId,
            )
        ) {
            return true;
        }

        // ---------------------------------------------------------------------
        // Path 3 — delegation to the shareholder policy.
        //
        // Grants transitively through the three paths of
        // ShareholderPolicy::view: `shareholder.view`, `shareholder.view_own`
        // + self-link, or representative relationship. The delegate
        // repeats the tenant check; that redundancy is intentional and
        // costs one cache lookup.
        // ---------------------------------------------------------------------
        return $this->shareholderPolicy->view($user, $shareholder);
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