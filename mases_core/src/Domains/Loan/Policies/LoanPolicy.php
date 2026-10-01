<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;

/**
 * LoanPolicy
 *
 * The authorization surface for the Loan aggregate. Answers every
 * "may this user perform this action on this loan?" question that the
 * Loan-domain controllers ask through `Gate::authorize(...)`.
 *
 * =============================================================================
 * Scope — one policy for the Loan aggregate
 * =============================================================================
 * This policy covers the Loan aggregate root and the abilities that are
 * meaningful on a specific `Loan` instance. Two sibling policies cover
 * the domain's other aggregates:
 *
 *   LoanProductPolicy    (A.53) — the loan product catalogue.
 *   LoanCollateralPolicy (A.54) — the collateral records, delegating its
 *                                 `view` ability through this policy's
 *                                 `view` ability.
 *
 * The three policies together cover every Loan-domain `Gate::authorize()`
 * call site in the frozen Stage 6 §4.16 HTTP surface.
 *
 * =============================================================================
 * The ten abilities
 * =============================================================================
 * The policy exposes exactly ten abilities, corresponding to the ten
 * distinct authorization questions the Loan-domain controllers ask:
 *
 *   viewAny         — may the user enumerate loans at all? Used by the
 *                     index endpoint (`GET /loans`, A.77).
 *
 *   view            — may the user view a specific loan? Used by the
 *                     show endpoint (`GET /loans/{loan}`, A.77).
 *
 *   viewSchedule    — may the user view a specific loan's schedule? Used
 *                     by the schedule endpoint
 *                     (`GET /loans/{loan}/schedule`, A.81).
 *
 *   viewStatement   — may the user view a specific loan's statement?
 *                     Used by the statement endpoint
 *                     (`GET /loans/{loan}/statement`, A.82).
 *
 *   approve         — may the user approve a loan? Used by the approve
 *                     endpoint (`POST /loans/{loan}/approve`, A.77).
 *
 *   reject          — may the user reject a loan? Used by the reject
 *                     endpoint (`POST /loans/{loan}/reject`, A.77).
 *
 *   disburse        — may the user disburse a loan? Used by the disburse
 *                     endpoint (`POST /loans/{loan}/disburse`, A.77).
 *
 *   restructure     — may the user restructure a loan? Used by the
 *                     restructure endpoint
 *                     (`POST /loans/{loan}/restructure`, A.77).
 *
 *   writeoff        — may the user write off a loan? Used by the write-off
 *                     endpoint (`POST /loans/{loan}/writeoff`, A.77).
 *
 *   repay           — may the user record a repayment against a loan?
 *                     Used by the repay endpoint
 *                     (`POST /loans/{loan}/repay`, A.80).
 *
 * The reconciliation endpoint (`GET /loans/{loan}/reconciliation`, A.83)
 * is authorized through this policy's `view` ability: the reconciliation
 * report is a view of the loan's financial state, not a separate
 * authorization concept. This is deliberate; if a future CR introduces a
 * distinct `loan.reconciliation.view` permission, this policy gains a
 * dedicated ability at that time.
 *
 * =============================================================================
 * The RBAC matrix this policy implements
 * =============================================================================
 * The permissions are seeded by `LoanPermissionsSeeder` (A.86) and
 * assigned to roles by `LoanRolesSeeder` (A.87). The frozen role matrix
 * (B7) is:
 *
 *   super_admin    → all 15 permissions
 *   platform_admin → 13 (excludes loan.writeoff, loan.collateral.release)
 *   tenant_admin   → 9  (view, apply, approve, reject, disburse, repay,
 *                        schedule.view, statement.view, collateral.view)
 *   manager        → 4  (view_own, schedule.view, statement.view_own,
 *                        collateral.view)
 *   shareholder    → 4  (view_own, apply, schedule.view,
 *                        statement.view_own)
 *
 * The policy translates this matrix into per-ability predicates. The
 * mapping is documented on each ability's method; the running theme is
 * that abilities fall into three families:
 *
 *   1. TENANT-WIDE abilities (`approve`, `reject`, `disburse`,
 *      `restructure`, `writeoff`, `repay`) — granted purely by the
 *      corresponding permission. A tenant_admin who holds `loan.approve`
 *      may approve any loan in the tenant.
 *
 *   2. HYBRID abilities (`viewAny`, `view`) — granted by the
 *      tenant-wide permission (`loan.view`, `loan.view_own`) OR by the
 *      narrower permission (`loan.view_own`) combined with ownership.
 *
 *   3. OWNERSHIP-CONDITIONAL abilities (`viewSchedule`,
 *      `viewStatement`) — granted by the permission AND either
 *      tenant-wide `loan.view` or ownership. See the per-method
 *      docblocks for the exact predicate; the reasoning is explained
 *      in §"Why `viewSchedule` and `viewStatement` are conditional".
 *
 * =============================================================================
 * Why `viewSchedule` and `viewStatement` are conditional
 * =============================================================================
 * The frozen RBAC matrix assigns the same `loan.schedule.view` permission
 * to both tenant_admin (which is expected to see any loan's schedule) and
 * to manager and shareholder (which are expected to see only their own).
 * The permission alone cannot distinguish the two cases, so the policy
 * pairs the permission with an ownership fallback:
 *
 *   - A user who holds `loan.schedule.view` AND `loan.view` may view any
 *     schedule. This covers tenant_admin (which holds both) and the
 *     platform-level roles.
 *
 *   - A user who holds `loan.schedule.view` but NOT `loan.view` may view
 *     only their own schedule. This covers manager and shareholder
 *     (which hold `loan.schedule.view` but not `loan.view`).
 *
 * `viewStatement` follows the same principle but uses the distinct
 * `loan.statement.view` and `loan.statement.view_own` permissions, so the
 * distinction is explicit in the permission names rather than in the
 * presence/absence of `loan.view`. The two mechanisms coexist because the
 * frozen RBAC matrix uses two different permission-naming conventions for
 * the two endpoints; the policy implements each according to its own
 * convention.
 *
 * =============================================================================
 * What this policy deliberately does NOT do
 * =============================================================================
 *   - It does NOT define a `before()` method. The handoff's frozen pattern
 *     for Phase 2C policies is "no `before()` bypass": a super_admin's
 *     access is granted through the RBAC matrix (which assigns all 15
 *     permissions to the super_admin role), not through a policy-level
 *     override. This keeps the authorization path uniform across every
 *     role and makes the RBAC matrix the single source of truth for
 *     "who can do what".
 *
 *   - It does NOT return a `Response` instance. Every method returns a
 *     plain `bool`. The default Laravel `Illuminate\Auth\Access\Response`
 *     wrapping is not used; a denial produces a 403 through the standard
 *     `AuthorizationException`, with the exception renderer in
 *     `bootstrap/app.php` producing the canonical MASES error envelope.
 *
 *   - It does NOT query the loan for its relations beyond what the
 *     ownership check requires. The ownership check reads the borrower's
 *     shareholder id from the loan row and resolves the authenticated
 *     user's own shareholder id via a single scoped query on the
 *     `shareholders` table. No `borrower` relation is loaded.
 *
 *   - It does NOT trust a caller-supplied tenant id for the ownership
 *     check. The tenant scope is always the loan's own `tenant_id`
 *     column, so a loan cannot be cross-tenant compared against a
 *     different tenant's shareholders.
 *
 *   - It does NOT cache the resolved shareholder id between ability
 *     invocations. Laravel resolves a fresh policy instance per
 *     authorization call, so cross-call caching is not available in the
 *     policy lifecycle; a future CR that needs it would introduce a
 *     request-scoped resolver. Phase 2C's authorization volume does not
 *     justify the caching complexity.
 *
 * =============================================================================
 * The `hasPermission` delegation
 * =============================================================================
 * Every permission check flows through
 * `AuthorizationService::hasPermission(permissionSlug:, user:, tenantId:)`.
 * The service is the platform's single source of truth for "does this
 * user hold this permission in this tenant?"; the policy does not
 * duplicate its logic.
 *
 * The service call uses NAMED ARGUMENTS so that a reader at the call site
 * sees the intent at a glance:
 *
 *     $this->authorizationService->hasPermission(
 *         permissionSlug: 'loan.approve',
 *         user:           $user,
 *         tenantId:       $loan->tenant_id,
 *     );
 *
 * The named-argument style is the platform-wide convention for this
 * service's call sites, established by Phase 2A's and Phase 2B's
 * policies.
 *
 * =============================================================================
 * Registration
 * =============================================================================
 * Registered in `LoanServiceProvider::registerPolicies()` (A.85) against
 * the `Loan` model:
 *
 *     Gate::policy(Loan::class, LoanPolicy::class);
 *
 * Once registered, every `Gate::authorize('view', $loan)` or
 * `$user->can('approve', $loan)` call resolves through this policy. The
 * controllers use `Gate::authorize(...)` exclusively; the policy's
 * methods are not called directly by production code.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — B7 (RBAC), D129
 *   Phase 2C Clarification Response, 2026-09-12  — C1 (shareholder role)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §4 (loan-list
 *                                                  authorization)
 *   Phase 2C A.18                                — `Loan`
 *   Phase 2C A.53                                — `LoanProductPolicy`
 *   Phase 2C A.54                                — `LoanCollateralPolicy`
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *   Phase 2C A.86                                — `LoanPermissionsSeeder`
 *   Phase 2C A.87                                — `LoanRolesSeeder`
 *   Phase 2A 2A.16                               — `ShareholderPolicy`
 *                                                  (the ownership-check
 *                                                  precedent)
 *   Phase 2B 2B.20                               — `WalletPolicy`
 *                                                  (the direct sibling)
 *   Step 5 (manifest) A.52                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Policies
 * @version 1.0.1
 */
final class LoanPolicy
{
    /**
     * Constructor.
     *
     * One dependency: the Core `AuthorizationService`, which answers
     * "does this user hold this permission in this tenant?".
     *
     * @param AuthorizationService $authorizationService
     */
    public function __construct(
        private readonly AuthorizationService $authorizationService,
    ) {
    }

    // =========================================================================
    // Public API — abilities
    // =========================================================================

    /**
     * Determine whether the user may enumerate loans.
     *
     * Used by `GET /loans` (A.77). The policy's answer is intentionally
     * broad: a user who holds EITHER `loan.view` (tenant-wide view) OR
     * `loan.view_own` (own-loans view) is authorized. The controller
     * resolves the SCOPE (all loans vs. the caller's own loans) via a
     * tenant-scoped repository query, based on which permission the
     * caller actually holds. See the class docblock of
     * `LoanController::index()` (A.77) for the scope-resolution logic.
     *
     * This is the single-endpoint design resolved by the Phase 2C
     * final reconciliation: there is one `GET /loans` endpoint, and its
     * behavior narrows to the caller's own loans when the caller holds
     * only `loan.view_own`.
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        $tenantId = $this->resolveTenantIdForListAbility();

        if ($tenantId === null) {
            // Without a resolved tenant, we cannot evaluate the
            // permission. The `tenant.membership` middleware should
            // have populated the context before any controller ran;
            // a null here indicates a request that bypassed the
            // middleware (a defect) or a legitimate non-HTTP context
            // that lacks tenant context.
            return false;
        }

        if ($this->hasPermission($user, 'loan.view', $tenantId)) {
            return true;
        }

        return $this->hasPermission($user, 'loan.view_own', $tenantId);
    }

    /**
     * Determine whether the user may view a specific loan.
     *
     * Used by `GET /loans/{loan}` (A.77) and by
     * `GET /loans/{loan}/reconciliation` (A.83), which shares the same
     * "read the loan" authorization concept (see the class docblock
     * §"The ten abilities").
     *
     * The user is authorized when EITHER of the following holds:
     *
     *   - the user holds `loan.view` in the loan's tenant (tenant-wide
     *     view); OR
     *
     *   - the user holds `loan.view_own` in the loan's tenant AND is the
     *     borrower of the loan.
     *
     * The ownership check resolves the authenticated user's shareholder
     * record in the loan's tenant and compares its id to the loan's
     * `borrower_shareholder_id`. A user with no shareholder record in the
     * tenant cannot be the borrower, so the own-path fails and only
     * `loan.view` can grant access.
     *
     * @param User $user
     * @param Loan $loan
     * @return bool
     */
    public function view(User $user, Loan $loan): bool
    {
        $tenantId = (int) $loan->tenant_id;

        if ($this->hasPermission($user, 'loan.view', $tenantId)) {
            return true;
        }

        if (!$this->hasPermission($user, 'loan.view_own', $tenantId)) {
            return false;
        }

        return $this->isBorrower($user, $loan);
    }

    /**
     * Determine whether the user may view a specific loan's schedule.
     *
     * Used by `GET /loans/{loan}/schedule` (A.81).
     *
     * The user is authorized when BOTH of the following hold:
     *
     *   - the user holds `loan.schedule.view` in the loan's tenant; AND
     *
     *   - the user either holds `loan.view` (tenant-wide) OR is the
     *     borrower of the loan.
     *
     * The first condition is the endpoint's specific permission. The
     * second condition is the "whose schedule is this?" predicate. See
     * the class docblock §"Why `viewSchedule` and `viewStatement` are
     * conditional" for the RBAC matrix reasoning.
     *
     * @param User $user
     * @param Loan $loan
     * @return bool
     */
    public function viewSchedule(User $user, Loan $loan): bool
    {
        $tenantId = (int) $loan->tenant_id;

        if (!$this->hasPermission($user, 'loan.schedule.view', $tenantId)) {
            return false;
        }

        if ($this->hasPermission($user, 'loan.view', $tenantId)) {
            return true;
        }

        return $this->isBorrower($user, $loan);
    }

    /**
     * Determine whether the user may view a specific loan's statement.
     *
     * Used by `GET /loans/{loan}/statement` (A.82).
     *
     * The user is authorized when EITHER of the following holds:
     *
     *   - the user holds `loan.statement.view` in the loan's tenant
     *     (tenant-wide statement view); OR
     *
     *   - the user holds `loan.statement.view_own` in the loan's tenant
     *     AND is the borrower of the loan.
     *
     * The distinction between `loan.statement.view` and
     * `loan.statement.view_own` is explicit in the permission names; the
     * policy reads each according to its own semantics. This differs from
     * `viewSchedule`, where the distinction is implicit in the presence
     * or absence of `loan.view`. See the class docblock §"Why
     * `viewSchedule` and `viewStatement` are conditional" for the
     * reasoning.
     *
     * @param User $user
     * @param Loan $loan
     * @return bool
     */
    public function viewStatement(User $user, Loan $loan): bool
    {
        $tenantId = (int) $loan->tenant_id;

        if ($this->hasPermission($user, 'loan.statement.view', $tenantId)) {
            return true;
        }

        if (!$this->hasPermission($user, 'loan.statement.view_own', $tenantId)) {
            return false;
        }

        return $this->isBorrower($user, $loan);
    }

    /**
     * Determine whether the user may approve a loan.
     *
     * Used by `POST /loans/{loan}/approve` (A.77). The ability is granted
     * purely by `loan.approve`; the loan's state (whether the loan is in
     * a decidable status) is validated by `LoanApprovalService` (A.29),
     * not by the policy. The policy answers the AUTHORIZATION question;
     * the service answers the DOMAIN question.
     *
     * @param User $user
     * @param Loan $loan
     * @return bool
     */
    public function approve(User $user, Loan $loan): bool
    {
        return $this->hasPermission(
            $user,
            'loan.approve',
            (int) $loan->tenant_id,
        );
    }

    /**
     * Determine whether the user may reject a loan.
     *
     * Used by `POST /loans/{loan}/reject` (A.77). Same posture as
     * `approve()`.
     *
     * @param User $user
     * @param Loan $loan
     * @return bool
     */
    public function reject(User $user, Loan $loan): bool
    {
        return $this->hasPermission(
            $user,
            'loan.reject',
            (int) $loan->tenant_id,
        );
    }

    /**
     * Determine whether the user may disburse a loan.
     *
     * Used by `POST /loans/{loan}/disburse` (A.77). Same posture as
     * `approve()`.
     *
     * @param User $user
     * @param Loan $loan
     * @return bool
     */
    public function disburse(User $user, Loan $loan): bool
    {
        return $this->hasPermission(
            $user,
            'loan.disburse',
            (int) $loan->tenant_id,
        );
    }

    /**
     * Determine whether the user may restructure a loan.
     *
     * Used by `POST /loans/{loan}/restructure` (A.77). Same posture as
     * `approve()`.
     *
     * @param User $user
     * @param Loan $loan
     * @return bool
     */
    public function restructure(User $user, Loan $loan): bool
    {
        return $this->hasPermission(
            $user,
            'loan.restructure',
            (int) $loan->tenant_id,
        );
    }

    /**
     * Determine whether the user may write off a loan.
     *
     * Used by `POST /loans/{loan}/writeoff` (A.77). Same posture as
     * `approve()`.
     *
     * Note that `loan.writeoff` is one of the two permissions the frozen
     * RBAC matrix withholds from the `platform_admin` role (the other
     * being `loan.collateral.release`); only `super_admin` holds it
     * among the platform-level roles, and no tenant-scoped role holds
     * it. The policy itself does not encode this distinction; the
     * permission assignment does.
     *
     * @param User $user
     * @param Loan $loan
     * @return bool
     */
    public function writeoff(User $user, Loan $loan): bool
    {
        return $this->hasPermission(
            $user,
            'loan.writeoff',
            (int) $loan->tenant_id,
        );
    }

    /**
     * Determine whether the user may record a repayment against a loan.
     *
     * Used by `POST /loans/{loan}/repay` (A.80). Same posture as
     * `approve()`.
     *
     * The actor who performs a repayment is recorded on the Wallet's
     * Payment and Allocations, not on a loan-row actor column; see
     * `LoanRepaymentService::record()` (A.31) for the recording
     * discipline. The policy does not distinguish "who the borrower is"
     * from "who is authorized to record"; the controller passes the
     * authenticated user to the service, and the service records them
     * as the actor of the Payment and Allocations.
     *
     * @param User $user
     * @param Loan $loan
     * @return bool
     */
    public function repay(User $user, Loan $loan): bool
    {
        return $this->hasPermission(
            $user,
            'loan.repay',
            (int) $loan->tenant_id,
        );
    }

    // =========================================================================
    // Private — permission delegation
    // =========================================================================

    /**
     * Delegate a permission check to the Core `AuthorizationService`.
     *
     * The service call uses NAMED ARGUMENTS, following the platform-wide
     * convention for this service's call sites. See the class docblock
     * §"The `hasPermission` delegation".
     *
     * @param User   $user
     * @param string $permissionSlug
     * @param int    $tenantId
     * @return bool
     */
    private function hasPermission(
        User $user,
        string $permissionSlug,
        int $tenantId,
    ): bool {
        return $this->authorizationService->hasPermission(
            permissionSlug: $permissionSlug,
            user:           $user,
            tenantId:       $tenantId,
        );
    }

    // =========================================================================
    // Private — tenant resolution for list abilities
    // =========================================================================

    /**
     * Resolve the tenant id for a list-scoped ability.
     *
     * The `viewAny` ability has no `Loan` instance from which to read a
     * `tenant_id`, so the tenant is resolved from the request context
     * via the `mases_tenant_id()` helper. The helper is populated by the
     * `tenant.membership` middleware (registered in `bootstrap/app.php`
     * and applied to every Loan route via the config's middleware stack,
     * A.1), which resolves the tenant before any controller runs.
     *
     * If the helper returns null — a context that bypassed the middleware
     * or a legitimate non-HTTP context (a console command, a test without
     * a bound tenant) — the ability returns false rather than throwing.
     * The choice is deliberate: an authorization context without a
     * resolved tenant cannot grant a list-scoped ability, because the
     * list itself is tenant-scoped.
     *
     * @return int|null
     */
    private function resolveTenantIdForListAbility(): ?int
    {
        if (!function_exists('mases_tenant_id')) {
            // The helper is expected to be defined by the platform's
            // tenancy bootstrapping. A missing helper indicates a
            // deployment misconfiguration; the safe response is to
            // deny the list ability rather than to fall through to an
            // unscoped query.
            return null;
        }

        /** @var int|string|null $tenantId */
        $tenantId = mases_tenant_id();

        if ($tenantId === null || $tenantId === '') {
            return null;
        }

        return (int) $tenantId;
    }

    // =========================================================================
    // Private — ownership check
    // =========================================================================

    /**
     * Whether the authenticated user is the borrower of the given loan.
     *
     * The user is the borrower when their shareholder record in the
     * loan's tenant carries the same primary key as the loan's
     * `borrower_shareholder_id` column. The check is performed via a
     * single scoped query against the `shareholders` table; the loan's
     * `borrower` relation is NOT loaded, because the comparison only
     * needs the primary key.
     *
     * The query is tenant-scoped by the loan's own `tenant_id`, so a
     * user who happens to be a shareholder in a DIFFERENT tenant cannot
     * be mistakenly identified as the borrower of a loan in this tenant.
     *
     * A user with no shareholder record in the loan's tenant returns
     * false from this method, which correctly denies the own-path. The
     * own-path's caller already verified that the user holds the
     * narrower permission (`loan.view_own`,
     * `loan.statement.view_own`, or `loan.schedule.view` paired with
     * the absence of `loan.view`); the ownership check is the second
     * gate.
     *
     * @param User $user
     * @param Loan $loan
     * @return bool
     */
    private function isBorrower(User $user, Loan $loan): bool
    {
        /** @var int|string|null $shareholderId */
        $shareholderId = Shareholder::query()
            ->where('tenant_id', (int) $loan->tenant_id)
            ->where('user_id', (int) $user->getKey())
            ->value('id');

        if ($shareholderId === null) {
            return false;
        }

        return (int) $shareholderId === (int) $loan->borrower_shareholder_id;
    }
}