<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;

/**
 * WalletPolicy
 *
 * Authorization policy for the `Wallet` aggregate root.
 *
 * =============================================================================
 * What this policy is
 * =============================================================================
 * A Laravel policy that decides whether a caller may perform a per-row
 * ability on a `Wallet`. It is registered against the `Wallet` model by
 * `WalletServiceProvider::registerPolicies()` (2B.2) and invoked by the
 * Wallet domain's controllers via `Gate::authorize(...)`.
 *
 * The policy is the SECOND of two authorization layers in the Wallet
 * domain (Step 7 v1.1.0 §C.1):
 *
 *   1. Route middleware (`auth.permission:wallet.*`) runs before the
 *      controller is instantiated and enforces a single unambiguous
 *      permission. It is the PRIMARY gate for a route that maps to
 *      exactly one permission.
 *
 *   2. This policy is invoked by the controller for abilities that may
 *      be granted through MULTIPLE paths. It is the OR-resolver.
 *
 * =============================================================================
 * Abilities provided
 * =============================================================================
 * Three abilities, matching the three `Gate::authorize(...)` call sites
 * across the Wallet domain's controllers:
 *
 *   viewAny(User $user): bool
 *       — invoked by `WalletController::index()` (2B.26) via
 *         `Gate::authorize('viewAny', Wallet::class)`.
 *
 *   view(User $user, Wallet $wallet): bool
 *       — invoked by:
 *           `WalletController::show()` (2B.26, route #3)
 *           `WalletController::adjust()` (2B.26, route #4)
 *           `ObligationController::store()` (2B.27, route #5)
 *           `PaymentController::store()` (2B.28, route #6)
 *           `AllocationController::store()` (2B.29, route #7)
 *           `AllocationController::reverse()` (2B.29, route #8)
 *         The six call sites share a single ability because all six
 *         require the same precondition: the caller must be entitled to
 *         view the target wallet before acting on it.
 *
 *   viewStatement(User $user, Wallet $wallet): bool
 *       — invoked by `StatementController::show()` (2B.30, route #9).
 *         A separate ability from `view` because the statement endpoint
 *         has a DISTINCT permission pair (`wallet.statement.view` /
 *         `wallet.statement.view_own`) that does not imply wallet-detail
 *         access, nor vice versa. See the class docblock §"Authorization
 *         paths" below.
 *
 * The policy provides NO ability for `open`, `adjust`, or
 * `allocation.reverse` as standalone abilities. Those operations are
 * gated entirely by route middleware (Step 7 v1.1.0 §C.1):
 *
 *   - `open` has no target wallet to gate against (it creates one);
 *     its permission `wallet.open` is enforced at the route.
 *
 *   - `adjust` and `allocation.reverse` carry privileged route
 *     middleware (`wallet.adjust`, `wallet.allocation.reverse`) that
 *     restricts them to `super_admin` / `platform_admin` (Step 7
 *     v1.1.0 §A.2.1). The controllers additionally invoke `view` on the
 *     target wallet as a second layer; see 2B.26 §"Authorization
 *     layering" and 2B.29 §"Authorization layering".
 *
 * =============================================================================
 * Authorization paths
 * =============================================================================
 * Each ability grants through an OR of paths.
 *
 * viewAny:
 *
 *     wallet.view          — the only path. There is no `_view_own`
 *                            variant for the collection endpoint: the
 *                            index lists the tenant's wallets and is
 *                            inherently administrative. A manager with
 *                            only own-record access uses the `show`
 *                            endpoint on a known wallet id, not the
 *                            index.
 *
 * view:
 *
 *     wallet.view          — the caller holds the broad permission for
 *                            the wallet's tenant.
 *         OR
 *     wallet.view_own      — the caller holds the own-only permission
 *         AND                for the wallet's tenant,
 *                            AND the wallet's shareholder is the caller
 *                            (direct link OR representative).
 *
 * viewStatement:
 *
 *     wallet.statement.view      — broad permission for the tenant.
 *         OR
 *     wallet.statement.view_own  — own-only permission for the tenant,
 *         AND                        AND the caller owns the wallet.
 *
 * The `view` and `viewStatement` paths are INDEPENDENT: a user may hold
 * `wallet.view` without `wallet.statement.view`, and vice versa. Neither
 * implies the other. The two abilities are kept separate for this reason
 * (Step 7 v1.1.0 §C.1.1 note: "Do not collapse the two abilities into
 * one. A future self-service role may legitimately need statement access
 * without full wallet-detail access, or the reverse.").
 *
 * =============================================================================
 * Ownership determination
 * =============================================================================
 * The `userOwnsWallet()` helper decides whether a user is the owner of a
 * wallet's shareholder. A user owns a wallet's shareholder when EITHER
 * of the following holds:
 *
 *   1. `shareholder.user_id === user.id`
 *      The shareholder is an individual whose direct self-service link
 *      (2A.7 §"user") points at the user.
 *
 *   2. `shareholder.representative_user_id === user.id`
 *      The shareholder is a legal entity whose designated representative
 *      (2A.7 §"representative") is the user. D11 requires every legal
 *      entity to have a representative; the representative is the
 *      authorized human being for the entity.
 *
 * Both checks are performed. An individual shareholder that ALSO has a
 * representative will match either path; a legal entity without a
 * direct user link (the model's invariant) matches the representative
 * path only.
 *
 * The helper does NOT verify that the shareholder is persisted or
 * belongs to a specific tenant. Tenant scope is enforced upstream: the
 * controller resolves the wallet through
 * `WalletRepository::findForTenant()` (2B.11) before this policy runs,
 * so a cross-tenant wallet never reaches the policy.
 *
 * =============================================================================
 * Delegation to AuthorizationService
 * =============================================================================
 * The policy delegates every PERMISSION check to the platform's
 * `AuthorizationService` (Core). It does not read the `permissions`
 * pivot directly and does not reimplement any part of the permission
 * resolution algorithm. The service is the single source of truth for:
 *
 *   - Direct permission assignment.
 *   - Role-derived permissions.
 *   - Tenant scoping of permission grants.
 *   - Global permissions.
 *
 * The policy adds ONLY the ownership decision on top of the service's
 * answer, and only for the own-only paths. This mirrors the 2A
 * precedent (ShareholderPolicy, 2A.29; ShareHoldingPolicy, 2A.30).
 *
 * Method signature used: `AuthorizationService::hasPermission(string
 * $permissionSlug, ?User $user = null, int|string|null $tenantId = null,
 * ?string $applicationSlug = null): bool`. Every call site below uses
 * NAMED arguments to match this signature and to remain independent of
 * any future parameter reordering of the service method. The same
 * pattern is used by `ShareholderPolicy` (2A.29).
 *
 * =============================================================================
 * No before() global bypass
 * =============================================================================
 * This policy provides NO `before()` method. Every ability is decided
 * by an explicit check against the caller's permissions or ownership.
 * A caller with a universal "super_admin" flag is granted access through
 * the SAME paths as any other user: the super_admin role's permission
 * set (seeded by `WalletRolesSeeder`, 2B.56) grants every relevant
 * permission, so the `wallet.view` / `wallet.statement.view` checks
 * succeed for that role.
 *
 * This is deliberate and matches the 2A policies. A `before()` bypass
 * would create a hidden grant path that no route-level middleware can
 * mirror and no seeder can audit; the RBAC matrix (Step 7 v1.1.0 §A.2)
 * is expressed entirely through permissions.
 *
 * =============================================================================
 * What this policy deliberately does NOT do
 * =============================================================================
 *   - It does not check the wallet's `is_active` flag. A deactivated
 *     wallet is still viewable: the flag describes operational state,
 *     not access control. A caller who can view an active wallet can
 *     view an inactive one.
 *
 *   - It does not check the wallet's `closed_at` timestamp. Closure is
 *     deferred (Q5); the field exists for a future CR, and this policy
 *     has no rule about it.
 *
 *   - It does not check the wallet's currency. The currency is a
 *     business attribute, not an access-control attribute.
 *
 *   - It does not check the presence of any policy version on the
 *     wallet. The wallet's three policy-version references (2B.5
 *     §"Policy references") are extension slots; their presence or
 *     absence has no bearing on who may view the wallet.
 *
 *   - It does not delegate to a `WalletRepository` or any other
 *     domain-layer service. The policy is a pure decision function over
 *     (user, wallet) and the platform's permission service.
 *
 *   - It does not use `Response::deny()` or `Response::allow()`.
 *     Abilities return plain `bool` per the 2A conventions (§2.8).
 *
 *   - It does not throw. Absence of a tenant, absence of a shareholder,
 *     or any other missing value results in `false`, not an exception.
 *
 * =============================================================================
 * Correction history (v1.0.1)
 * =============================================================================
 * The v1.0.0 delivery called `AuthorizationService::hasPermission()`
 * positionally, against an assumed signature of
 * `hasPermission(?User $user, string $permissionSlug, ?int $tenantId)`.
 *
 * The deployed service's actual first parameter is `string
 * $permissionSlug`. Passing the `User` as the first argument therefore
 * triggered `TypeError: hasPermission(): Argument #1 ($permissionSlug)
 * must be of type string, User given` on every wallet authorization
 * check — causing 500 responses on every feature test that reached a
 * controller's `Gate::authorize(...)` call.
 *
 * v1.0.1 corrects all five call sites by switching to named arguments
 * that match the deployed signature exactly. The behavior and structure
 * of the policy are otherwise unchanged.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D11                          — Legal-entity representatives
 *   Architecture v1.2.0 §12      — Tenant isolation
 *   Architecture v1.2.0 §13      — RBAC integration
 *   Architecture v1.2.0 §16.2    — Services and their dependencies
 *   Architecture v1.2.0 §19      — Q5 resolution (closure deferred)
 *   Phase 1 Amendment A5         — AuthorizationService key-registry
 *                                  invalidation (positive path deferred;
 *                                  service-level positive path is
 *                                  exercised by the Phase 1 test suite)
 *   Step 5 (manifest) 2B.37      — This file
 *   Step 7 v1.1.0 §A.1.1         — Permission slugs
 *   Step 7 v1.1.0 §A.1.2         — F-2B-RBAC-1 resolution (choice c)
 *   Step 7 v1.1.0 §A.2           — Role binding matrix
 *   Step 7 v1.1.0 §C.1           — Nine-route API structure
 *   Step 7 v1.1.0 §C.1.1         — Statement policy-only authorization
 *   Step 7 v1.1.0 §C.6           — Cross-tenant child-validation invariant
 *   §2.8 (conventions)           — Policy conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Policies
 * @version 1.0.1
 */
final class WalletPolicy
{
    /**
     * Constructor.
     *
     * One dependency: the platform's `AuthorizationService`, used as the
     * sole decision point for permission checks. See the class docblock
     * §"Delegation to AuthorizationService".
     *
     * The service is bound as a singleton by `CoreServiceProvider`
     * (Phase 1). Injecting it here is the same pattern used by the 2A
     * policies (ShareholderPolicy, 2A.29; ShareHoldingPolicy, 2A.30).
     *
     * @param AuthorizationService $authorizationService
     */
    public function __construct(
        private readonly AuthorizationService $authorizationService,
    ) {
    }

    // =========================================================================
    // viewAny — list every wallet in the caller's tenant
    // =========================================================================

    /**
     * Whether the caller may list the wallets of the current tenant.
     *
     * The ability is invoked by `WalletController::index()` (2B.26) via
     * `Gate::authorize('viewAny', Wallet::class)`. The route middleware
     * `auth.permission:wallet.view` has already rejected callers without
     * the permission; this policy re-verifies the same permission as a
     * defence-in-depth layer and closes F-2A47-1 (the 2A finding that a
     * Shareholder controller's `index` lacked the explicit gate).
     *
     * The tenant is resolved from the request context via
     * `mases_tenant_id()`. The route has been mounted under the
     * `tenant.membership` middleware, so the tenant is guaranteed to be
     * present when this method runs; the null-check is defensive.
     *
     * There is no own-only path for this ability: the index lists the
     * tenant's wallets and is inherently administrative. A caller with
     * only `wallet.view_own` is denied. See the class docblock
     * §"Authorization paths".
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

        return $this->authorizationService->hasPermission(
            permissionSlug: 'wallet.view',
            user: $user,
            tenantId: $tenantId,
        );
    }

    // =========================================================================
    // view — access a single wallet
    // =========================================================================

    /**
     * Whether the caller may view (and, transitively, act on) a wallet.
     *
     * The ability is invoked by six controllers (see the class docblock
     * §"Abilities provided"). Two paths grant access:
     *
     *   1. `wallet.view` (broad) — any wallet in the wallet's tenant.
     *
     *   2. `wallet.view_own` (own-only) — only wallets whose shareholder
     *      is the caller, either directly (`shareholder.user_id`) or as
     *      the shareholder's representative
     *      (`shareholder.representative_user_id`).
     *
     * The two paths are checked in order. The broad check is performed
     * first because it is a single permission lookup and typically
     * succeeds for administrative callers; the ownership check is
     * reached only when the broad check fails.
     *
     * The tenant used for the permission checks is the wallet's own
     * `tenant_id`, not the request context. This makes the decision
     * deterministic for a given wallet regardless of the request path
     * that resolved it. Since the controller resolved the wallet through
     * `WalletRepository::findForTenant()` (2B.11), the wallet's
     * `tenant_id` already equals the request's tenant id; using the
     * wallet's own column simply removes one dependency on mutable
     * request state.
     *
     * @param User   $user
     * @param Wallet $wallet
     * @return bool
     */
    public function view(User $user, Wallet $wallet): bool
    {
        // ---------------------------------------------------------------------
        // Path 1: broad permission.
        // ---------------------------------------------------------------------
        if ($this->authorizationService->hasPermission(
            permissionSlug: 'wallet.view',
            user: $user,
            tenantId: (int) $wallet->tenant_id,
        )) {
            return true;
        }

        // ---------------------------------------------------------------------
        // Path 2: own-only permission + ownership.
        // ---------------------------------------------------------------------
        if ($this->authorizationService->hasPermission(
            permissionSlug: 'wallet.view_own',
            user: $user,
            tenantId: (int) $wallet->tenant_id,
        )) {
            return $this->userOwnsWallet($user, $wallet);
        }

        return false;
    }

    // =========================================================================
    // viewStatement — generate a statement for a wallet
    // =========================================================================

    /**
     * Whether the caller may generate a statement for a wallet.
     *
     * The ability is invoked by `StatementController::show()` (2B.30).
     * Its structure mirrors `view()` exactly, but with the statement-
     * specific permission pair.
     *
     * Two paths grant access:
     *
     *   1. `wallet.statement.view` (broad) — any wallet in the tenant.
     *
     *   2. `wallet.statement.view_own` (own-only) + ownership — only the
     *      caller's own wallet.
     *
     * The two paths are INDEPENDENT of the `view()` paths. A caller with
     * `wallet.view` and no statement permission cannot view the statement;
     * a caller with `wallet.statement.view` and no `wallet.view` cannot
     * view the wallet's own payload. See the class docblock
     * §"Authorization paths" for the reasoning.
     *
     * @param User   $user
     * @param Wallet $wallet
     * @return bool
     */
    public function viewStatement(User $user, Wallet $wallet): bool
    {
        // ---------------------------------------------------------------------
        // Path 1: broad permission.
        // ---------------------------------------------------------------------
        if ($this->authorizationService->hasPermission(
            permissionSlug: 'wallet.statement.view',
            user: $user,
            tenantId: (int) $wallet->tenant_id,
        )) {
            return true;
        }

        // ---------------------------------------------------------------------
        // Path 2: own-only permission + ownership.
        // ---------------------------------------------------------------------
        if ($this->authorizationService->hasPermission(
            permissionSlug: 'wallet.statement.view_own',
            user: $user,
            tenantId: (int) $wallet->tenant_id,
        )) {
            return $this->userOwnsWallet($user, $wallet);
        }

        return false;
    }

    // =========================================================================
    // Private — ownership determination
    // =========================================================================

    /**
     * Whether the caller owns the wallet's shareholder.
     *
     * A user owns a shareholder when either the shareholder's direct
     * user link or its representative user link points at the caller.
     * See the class docblock §"Ownership determination" for the full
     * rules.
     *
     * The helper does NOT verify tenant scope. That is enforced upstream
     * by the controller's resolution of the wallet through
     * `WalletRepository::findForTenant()` (2B.11). See the class
     * docblock §"Ownership determination".
     *
     * The `shareholder` relation is accessed lazily. If it has already
     * been eager-loaded by the controller, no additional query fires; if
     * it has not, one query fires. The policy does not force a load: the
     * six controllers that gate on `view` do not need the shareholder
     * for anything other than this ownership test, and forcing the load
     * would add a query to every request even when the broad-permission
     * path already granted access. Lazy access is correct here.
     *
     * @param User   $user
     * @param Wallet $wallet
     * @return bool
     */
    private function userOwnsWallet(User $user, Wallet $wallet): bool
    {
        $shareholder = $wallet->shareholder;

        if ($shareholder === null) {
            // A wallet without a shareholder is a data anomaly. The
            // policy denies access rather than throwing — see the class
            // docblock §"What this policy deliberately does NOT do".
            return false;
        }

        $userId = (int) $user->id;

        if (
            $shareholder->user_id !== null
            && (int) $shareholder->user_id === $userId
        ) {
            return true;
        }

        if (
            $shareholder->representative_user_id !== null
            && (int) $shareholder->representative_user_id === $userId
        ) {
            return true;
        }

        return false;
    }

    // =========================================================================
    // Private — tenant resolution
    // =========================================================================

    /**
     * Resolve the current request's tenant id.
     *
     * Used only by `viewAny()`, which has no wallet argument to read the
     * tenant from. The other two abilities read `$wallet->tenant_id`
     * directly.
     *
     * Returns null when no tenant is available. The policy treats null
     * as a denial in `viewAny()` — a caller with no resolvable tenant
     * cannot be granted access to the collection endpoint. This is a
     * defensive posture: the route is mounted under
     * `tenant.membership`, so a well-formed request always has a tenant.
     *
     * The helper reads `mases_tenant_id()` (the global helper from
     * `mases_core/src/Support/Helpers.php`). It does not read from the
     * request body, headers, or any other client-controlled source.
     *
     * @return int|null
     */
    private function resolveTenantId(): ?int
    {
        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;

        if ($tenantId === null) {
            return null;
        }

        return (int) $tenantId;
    }
}