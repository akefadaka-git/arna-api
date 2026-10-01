<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P2.3 · File 2 of 7
 * =============================================================================
 *
 * File:            CertificatePolicy.php  (NEW)
 * Unit:            P2.3 — wire certificate.view / certificate.view_own
 * Delivery type:   New file
 * Net lines:       +…
 *
 * Before:
 *   There was no CertificatePolicy. The CertificateController
 *   authorized its four actions against the shareholder via
 *   ShareholderPolicy — `view` on the shareholder for index, show, and
 *   store, and `update` on the shareholder for cancel. The two
 *   certificate-domain slugs (`certificate.view`, `certificate.view_own`)
 *   were declared in the RBAC vocabulary and assigned to roles, but no
 *   code path read them.
 *
 *   The CertificateController's own class docblock recorded the absence
 *   deliberately: "There is no ShareCertificatePolicy in the frozen
 *   manifest. Authorization for certificate operations is mediated
 *   through the Shareholder policy."
 *
 * After:
 *   A new policy class with two abilities, structurally identical to
 *   ShareHoldingPolicy (File 1 of this sequence):
 *
 *     view(User, ShareCertificate)           — for show() and cancel().
 *                                              Resolves the certificate's
 *                                              shareholder and delegates
 *                                              to viewForShareholder().
 *
 *     viewForShareholder(User, Shareholder)  — for index() and store().
 *                                              Applies the three-path OR
 *                                              on the shareholder.
 *
 *   The three paths, identical in shape to ShareHoldingPolicy:
 *
 *     1. caller holds `certificate.view`                (administrative)
 *     2. caller holds `certificate.view_own` AND is
 *        the shareholder's direct user_id link          (self-service)
 *     3. `ShareholderPolicy::view` returns true         (delegation)
 *
 * =============================================================================
 * Deviation from the frozen manifest — declared
 * =============================================================================
 * The CertificateController's class docblock asserts that no
 * ShareCertificatePolicy exists in the frozen manifest and that
 * certificate authorization is mediated entirely through the
 * Shareholder policy. This file introduces a CertificatePolicy,
 * departing from that statement.
 *
 * The departure is the direct consequence of the P2.3 directive: the
 * `certificate.view` and `certificate.view_own` slugs were declared by
 * the manifest and must be honoured by some code path. Introducing a
 * policy is the mechanism by which the two slugs become effective
 * without violating the Shareholder policy's own responsibilities.
 *
 * The delegation path (path 3) preserves every existing behaviour: a
 * caller authorized only through ShareholderPolicy continues to be
 * authorized. The new policy is a superset of the current grant set,
 * not a replacement. The CertificateController's class docblock is
 * corrected in File 6 to reflect the new state.
 *
 * =============================================================================
 * Preserved contracts
 * =============================================================================
 *   · Class is `final`.
 *   · Same namespace, same directory as ShareHoldingPolicy.
 *   · Same constructor dependency pattern (ShareholderPolicy +
 *     AuthorizationService).
 *   · Same three-path OR, same order of checks, same fail-closed
 *     conditions.
 *   · Same `resolveTenantId()` helper.
 *   · Same "no direct database queries beyond one `loadMissing`" rule.
 *
 * Files modified:            none
 * Files added:               this file only
 * Files deleted:             none
 * Provider registration:     pending — File 4 of this sequence adds
 *                            `Gate::policy(ShareCertificate::class, CertificatePolicy::class)`
 * Route middleware touched:  none
 * Seeder changes:            none
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Shareholder\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareCertificate;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;

/**
 * CertificatePolicy
 *
 * Authorization rules for viewing a share certificate, and — via the
 * `viewForShareholder` ability — for viewing a shareholder's
 * certificates as a collection.
 *
 * =============================================================================
 * Introduction and contract
 * =============================================================================
 * This policy is new in Phase 2.3. It does not replace the shareholder-
 * delegation model that governs the rest of the Shareholder domain; it
 * adds a parallel set of grants expressed in the certificate-domain
 * vocabulary so that the `certificate.view` and `certificate.view_own`
 * slugs, previously declared but unread, become effective.
 *
 * The delegation path (path 3, below) preserves the previous behaviour
 * exactly: a caller whose only authorization comes from
 * `ShareholderPolicy` continues to be admitted. The new paths 1 and 2
 * are strict additions.
 *
 * =============================================================================
 * Ability map
 * =============================================================================
 * The policy exposes two abilities, mapped by Laravel to the following
 * controller calls (after File 6 of this sequence updates the
 * controller):
 *
 *   view(User $user, ShareCertificate $certificate)
 *       ← CertificateController::show
 *       ← CertificateController::cancel
 *
 *   viewForShareholder(User $user, Shareholder $shareholder)
 *       ← CertificateController::index
 *       ← CertificateController::store
 *
 * No `create`, `update`, or `delete` ability is defined. A certificate's
 * lifecycle transitions are performed by `CertificateService` and gated
 * by the combination of route middleware (`certificate.issue`,
 * `certificate.cancel`) and the caller's `view` on the certificate.
 * Introducing separate abilities for `issue` and `cancel` would
 * duplicate the route middleware's enforcement and would contradict the
 * frozen manifest's policy map, which does not name those abilities.
 *
 * =============================================================================
 * Three access paths
 * =============================================================================
 * Both abilities grant access through ANY of three paths. The two
 * abilities share the same rule — `view` simply resolves the
 * certificate's shareholder and forwards to `viewForShareholder`.
 *
 *   1. Administrative (certificate domain). The caller holds
 *      `certificate.view`. Grants access to any shareholder's
 *      certificates in the resolved tenant.
 *
 *   2. Self-service (certificate domain). The caller is the individual
 *      shareholder's direct user link (shareholders.user_id equals the
 *      caller's id) AND holds `certificate.view_own`. Grants access to
 *      that shareholder's certificates only.
 *
 *   3. Delegation (shareholder domain). `ShareholderPolicy::view` on the
 *      certificate's shareholder returns true. This transitively admits:
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
 * The `certificate.*` slugs and the `shareholder.*` slugs describe the
 * same underlying access in two vocabularies:
 *
 *   · `shareholder.view` / `certificate.view` — the administrative
 *     grant. A user who manages the shareholder register holds both; a
 *     user who only manages certificates can be granted only the latter.
 *
 *   · `shareholder.view_own` / `certificate.view_own` — the self-service
 *     grant. A user who is a direct-linked individual shareholder holds
 *     both; a user whose role grants only one of them is admitted by
 *     that one.
 *
 * Wiring both sets into the certificate policy lets a role be composed
 * with either vocabulary without loss. A role holding only
 * `certificate.*` works; a role holding only `shareholder.*` works; a
 * role holding both works. There is no OR-drift risk because all three
 * paths are pure disjunctions — none can silently deny a caller who
 * would be admitted by another.
 *
 * =============================================================================
 * Representative semantics (D11)
 * =============================================================================
 * A representative User acts on a legal-entity shareholder's behalf.
 * They obtain access to the entity's certificates exclusively through
 * path 3 (the delegated ShareholderPolicy's representative path), which
 * requires no permission.
 *
 * They do NOT obtain access through path 2 (self-service). Path 2
 * requires `certificate.view_own` AND a direct user_id link; a
 * representative relationship satisfies neither condition, and D11
 * forbids legal entities from carrying a direct user_id.
 *
 * The consequence is intentional: the `_view_own` slugs are
 * self-service grants, not representative grants. A representative's
 * access is a structural relationship, not a permission.
 *
 * =============================================================================
 * Tenant boundary
 * =============================================================================
 * Every check begins with a tenant match: the shareholder's `tenant_id`
 * must equal the tenant resolved from the request via `mases_tenant_id()`.
 * If the tenant cannot be resolved or does not match, the ability
 * returns false before any permission is consulted.
 *
 * The controller (2A.24) already performs the same tenant check and
 * returns 404 on mismatch. The policy repeats it as defence in depth —
 * a caller whose permissions would otherwise grant access must not be
 * able to cross the tenant boundary through a policy bug.
 *
 * =============================================================================
 * Fail-closed on orphaned certificate
 * =============================================================================
 * If the certificate's `shareholder` relation resolves to null — meaning
 * the foreign key points at a missing row — the `view` ability returns
 * false. An orphaned certificate is a referential-integrity incident,
 * not a case to handle gracefully. Returning false produces an
 * AuthorizationException (403), which is the correct response.
 *
 * In normal operation the FK constraint on `share_certificates.shareholder_id`
 * prevents orphaned rows. The check is defensive.
 *
 * =============================================================================
 * No direct database queries
 * =============================================================================
 * The only database access this policy triggers is the eager load of the
 * `shareholder` relation in `view()`. If the caller has already loaded
 * it, `loadMissing` is a no-op. If the relation is not loaded, one query
 * resolves it.
 *
 * The permission lookups in `viewForShareholder()` go through
 * `AuthorizationService`, which caches results in the framework cache.
 * The delegated `ShareholderPolicy::view` performs the same lookups; the
 * cache makes the second lookup free.
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
 * @version 1.0.0
 */
final class CertificatePolicy
{
    /**
     * Construct the policy with its dependencies.
     *
     * `AuthorizationService` is a Phase 1 singleton bound by
     * CoreServiceProvider; `ShareholderPolicy` is instantiated by
     * Laravel when the Gate resolves this policy. Both are resolved
     * from the container.
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
    // view — read a single certificate
    // =========================================================================

    /**
     * Determine whether the user may view a specific share certificate.
     *
     * Resolves the certificate's owning shareholder and delegates to
     * `viewForShareholder`. See the class docblock for the three paths
     * the delegated method implements.
     *
     * The ability is also used by `CertificateController::cancel()` as
     * the per-object authorization step; the action's own permission
     * requirement (`certificate.cancel` at the route level) remains in
     * force independently.
     *
     * @param User             $user
     * @param ShareCertificate $certificate
     * @return bool
     */
    public function view(User $user, ShareCertificate $certificate): bool
    {
        // Ensure the shareholder relation is present without issuing a
        // redundant query when it is already loaded.
        $certificate->loadMissing('shareholder');

        $shareholder = $certificate->shareholder;

        // Fail closed on an orphaned certificate. See the class docblock.
        if ($shareholder === null) {
            return false;
        }

        return $this->viewForShareholder($user, $shareholder);
    }

    // =========================================================================
    // viewForShareholder — read a shareholder's certificates as a collection
    // =========================================================================

    /**
     * Determine whether the user may list the certificates of a specific
     * shareholder.
     *
     * Called by `CertificateController::index()` (after resolving the
     * shareholder from the `shareholder_id` query parameter) and by
     * `CertificateController::store()` (the certificate being issued is
     * against the resolved shareholder).
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
        // Path 1 — administrative certificate permission.
        //
        // A caller holding `certificate.view` can see any shareholder's
        // certificates in the resolved tenant.
        // ---------------------------------------------------------------------
        if ($this->authorization->hasPermission(
            permissionSlug: 'certificate.view',
            user: $user,
            tenantId: $tenantId,
        )) {
            return true;
        }

        // ---------------------------------------------------------------------
        // Path 2 — self-service certificate permission.
        //
        // The caller must be the shareholder's direct user link AND hold
        // `certificate.view_own`. This mirrors `ShareholderPolicy::view`
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
                permissionSlug: 'certificate.view_own',
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