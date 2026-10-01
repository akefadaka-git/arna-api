<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanCollateral;

/**
 * LoanCollateralPolicy
 *
 * The authorization surface for the loan collateral records. Answers the
 * "may this user view / manage / release this collateral?" questions that
 * the Loan-domain controllers ask through `Gate::authorize(...)`.
 *
 * =============================================================================
 * Scope — the collateral records
 * =============================================================================
 * This policy covers the `LoanCollateral` aggregate (A.20). Collateral
 * records are sub-resources of a loan: every collateral belongs to
 * exactly one loan, and every collateral's authorization is composed with
 * the LOAN's authorization. A user who cannot view a loan cannot view,
 * manage, or release any of its collaterals.
 *
 * The composition is implemented by delegating every ability through
 * `LoanPolicy::view()` (A.52). The delegation is the load-bearing
 * property of this policy; see §"Delegation through LoanPolicy::view()".
 *
 * Phase 2C exposes three HTTP endpoints against collateral records:
 *
 *   GET  /loans/{loan}/collateral                          index   (A.79)
 *   POST /loans/{loan}/collateral                          store   (A.79)
 *   POST /loans/{loan}/collateral/{collateral}/release     release (A.79)
 *
 * =============================================================================
 * The three abilities
 * =============================================================================
 *   view     — may the user view a specific collateral? Used by the
 *              release endpoint's pre-flight and, in a future CR, by a
 *              `GET /loans/{loan}/collateral/{collateral}` show
 *              endpoint.
 *
 *   manage   — may the user register collateral against a loan? Used by
 *              the store endpoint (`POST /loans/{loan}/collateral`).
 *              Because the store endpoint's URL contains only the loan
 *              and not a collateral, the controller dispatches this
 *              ability against a transient `LoanCollateral` instance
 *              whose `loan_id` is set to the loan's id. See §"The
 *              transient-instance dispatch pattern" below.
 *
 *   release  — may the user release a specific collateral? Used by the
 *              release endpoint
 *              (`POST /loans/{loan}/collateral/{collateral}/release`).
 *
 * The index endpoint (`GET /loans/{loan}/collateral`) is authorized
 * through `LoanPolicy::view()` on the LOAN, not through this policy. The
 * list of collaterals is a projection of the loan; a user authorized to
 * view the loan is authorized to view the collateral list. This is
 * documented on `LoanCollateralController::index()` (A.79) and reflects
 * the design choice that "list a loan's collateral" is a loan-view
 * operation, not a collateral-view operation.
 *
 * =============================================================================
 * Delegation through `LoanPolicy::view()`
 * =============================================================================
 * EVERY ability of this policy delegates through `LoanPolicy::view()`
 * (A.52). The pattern for each ability is:
 *
 *   1. Check the ability's specific permission
 *      (`loan.collateral.view`, `loan.collateral.manage`, or
 *      `loan.collateral.release`) in the loan's tenant.
 *
 *   2. Check that the user can view the loan, via
 *      `LoanPolicy::view($user, $loan)`.
 *
 *   3. Return true only if BOTH checks pass.
 *
 * The composition has three consequences worth documenting:
 *
 *   - A user who cannot view a loan cannot authorize on any of its
 *     collaterals, even if they hold the collateral-specific permission.
 *     This is deliberate: a user without loan visibility has no business
 *     interrogating a loan's security position.
 *
 *   - The `LoanPolicy::view()` call already implements the
 *     "tenant-wide-view OR own-loan-view-with-ownership" logic. This
 *     policy therefore inherits the same ownership semantics without
 *     duplicating them: a manager who holds `loan.collateral.view` and
 *     `loan.view_own` can view collateral on the loans they own; a
 *     tenant_admin who holds `loan.collateral.view` and `loan.view` can
 *     view collateral on any loan in the tenant.
 *
 *   - A borrower (shareholder) holds neither `loan.collateral.view`,
 *     `loan.collateral.manage`, nor `loan.collateral.release` per the
 *     frozen RBAC matrix (B7), so this policy denies all three abilities
 *     to the borrower. A borrower who needs to see their loan's
 *     collateral reads it via the loan's own representation, gated by
 *     `LoanPolicy::view()` on the loan.
 *
 * =============================================================================
 * The RBAC matrix this policy implements
 * =============================================================================
 * The collateral-specific permissions (`loan.collateral.view`,
 * `loan.collateral.manage`, `loan.collateral.release`) are seeded by
 * `LoanPermissionsSeeder` (A.86) and assigned to roles by
 * `LoanRolesSeeder` (A.87). The frozen RBAC matrix (B7) assigns them as
 * follows:
 *
 *   super_admin    → all three
 *   platform_admin → view, manage            (excludes release)
 *   tenant_admin   → view only
 *   manager        → view only
 *   shareholder    → none
 *
 * The policy does not encode this matrix directly; it reads each
 * permission from the `AuthorizationService`. The matrix is enforced by
 * the seeders. A future CR that changes the matrix changes only the
 * seeders; this policy is unaffected.
 *
 * =============================================================================
 * The transient-instance dispatch pattern
 * =============================================================================
 * The `manage` ability is dispatched by `LoanCollateralController::store()`
 * (A.79) against a TRANSIENT `LoanCollateral` instance, constructed in the
 * controller with only the `loan_id` populated:
 *
 *     $transient = new LoanCollateral(['loan_id' => $loan->id]);
 *     Gate::authorize('manage', $transient);
 *
 * The transient instance is never persisted. Its sole purpose is to
 * satisfy Laravel's policy resolution — which keys the `LoanCollateral`
 * class to this policy — while carrying the `loan_id` that the policy
 * needs for its tenant and loan-viewability checks.
 *
 * The policy's `manage()` method loads the loan relation on demand
 * (`$collateral->loadMissing('loan')`), so the transient instance does not
 * need to carry a pre-loaded `loan` relation. When the controller passes
 * the transient instance, the policy issues one query to resolve the loan.
 *
 * This pattern is documented here so that the A.79 controller author has a
 * single, unambiguous dispatch form to implement. An alternative pattern
 * — dispatching against a `Loan` instance and using a custom gate
 * definition — is not used; the transient-instance form keeps every
 * policy dispatch through the standard `Gate::authorize('ability',
 * $model)` shape.
 *
 * =============================================================================
 * What this policy deliberately does NOT do
 * =============================================================================
 *   - It does NOT define a `before()` method. Same posture as `LoanPolicy`
 *     (A.52) and `LoanProductPolicy` (A.53): no policy-level bypass; the
 *     RBAC matrix is the single source of truth for role access.
 *
 *   - It does NOT return a `Response` instance. Every method returns a
 *     plain `bool`. Denials produce the canonical MASES 403 envelope
 *     through the standard `AuthorizationException` path.
 *
 *   - It does NOT expose a `viewAny` ability. The collateral list is
 *     authorized via `LoanPolicy::view()` on the parent loan. See
 *     §"The three abilities".
 *
 *   - It does NOT trust a caller-supplied tenant id. The tenant scope for
 *     every ability is read from the collateral's own loan relation
 *     (which resolves to the loan's `tenant_id`), not from a
 *     request-context helper. The only ability that needs the
 *     request-context tenant is the (absent) `viewAny`; because it is
 *     not implemented, this policy is entirely context-free.
 *
 *   - It does NOT evaluate the product's collateral policy. Whether a
 *     loan's product permits collateral, requires collateral, or limits
 *     the permitted collateral types is a DOMAIN question, evaluated by
 *     `LoanCollateralService` (A.37) and `LoanApprovalService` (A.29).
 *     The policy answers only the AUTHORIZATION question: may this user
 *     perform this action on this resource?
 *
 *   - It does NOT mutate the collateral, the loan, or the Shareholder
 *     ledger. The policy is a pure read-only predicate.
 *
 * =============================================================================
 * The `hasPermission` delegation
 * =============================================================================
 * Every permission check flows through
 * `AuthorizationService::hasPermission(permissionSlug:, user:, tenantId:)`,
 * using NAMED ARGUMENTS. Same delegation pattern as `LoanPolicy` (A.52)
 * and `LoanProductPolicy` (A.53). See A.52's class docblock §"The
 * `hasPermission` delegation" for the platform-wide rationale.
 *
 * =============================================================================
 * Registration
 * =============================================================================
 * Registered in `LoanServiceProvider::registerPolicies()` (A.85) against
 * the `LoanCollateral` model:
 *
 *     Gate::policy(LoanCollateral::class, LoanCollateralPolicy::class);
 *
 * Once registered, every `Gate::authorize('view', $collateral)` or
 * `Gate::authorize('manage', $transient)` or
 * `Gate::authorize('release', $collateral)` call resolves through this
 * policy. The controllers use `Gate::authorize(...)` exclusively; the
 * policy's methods are not called directly by production code.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A10 (collateral rules),
 *                                                  B4 (collateral
 *                                                  integration), B7 (RBAC)
 *   Phase 2C Clarification Response, 2026-09-12  — C2 (release endpoint),
 *                                                  C3 (Loan-side pledge
 *                                                  sequencing)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §4
 *   Phase 2C A.3                                 — `CollateralStatus`
 *   Phase 2C A.4                                 — `CollateralType`
 *   Phase 2C A.20                                — `LoanCollateral`
 *   Phase 2C A.37                                — `LoanCollateralService`
 *                                                  (the domain-layer
 *                                                  counterpart)
 *   Phase 2C A.52                                — `LoanPolicy` (the
 *                                                  delegation target)
 *   Phase 2C A.53                                — `LoanProductPolicy`
 *                                                  (the sibling policy)
 *   Phase 2C A.79                                — `LoanCollateralController`
 *                                                  (the dispatch site)
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *   Phase 2C A.86                                — `LoanPermissionsSeeder`
 *   Phase 2C A.87                                — `LoanRolesSeeder`
 *   Phase 2A 2A.16                               — `ShareholderPolicy`
 *                                                  (the shape template)
 *   Phase 2B 2B.20                               — `WalletPolicy`
 *                                                  (the direct sibling)
 *   Step 5 (manifest) A.54                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Policies
 * @version 1.0.1
 */
final class LoanCollateralPolicy
{
    /**
     * Constructor.
     *
     * Two dependencies:
     *
     *   - `AuthorizationService` — the platform's single source of truth
     *     for "does this user hold this permission in this tenant?".
     *
     *   - `LoanPolicy` — the delegation target for the
     *     loan-viewability check that every ability of this policy
     *     requires. The dependency is explicit (constructor injection)
     *     rather than resolved through the `Gate` facade, so that the
     *     policy's reliance on the loan-level authorization surface is
     *     visible at the type level.
     *
     * @param AuthorizationService $authorizationService
     * @param LoanPolicy           $loanPolicy
     */
    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly LoanPolicy $loanPolicy,
    ) {
    }

    // =========================================================================
    // Public API — abilities
    // =========================================================================

    /**
     * Determine whether the user may view a specific collateral.
     *
     * The user is authorized when BOTH of the following hold:
     *
     *   - the user holds `loan.collateral.view` in the loan's tenant; AND
     *
     *   - `LoanPolicy::view()` grants the user view access to the loan
     *     (tenant-wide view OR own-loan view with ownership).
     *
     * See the class docblock §"Delegation through `LoanPolicy::view()`".
     *
     * @param User           $user
     * @param LoanCollateral $collateral
     * @return bool
     */
    public function view(User $user, LoanCollateral $collateral): bool
    {
        $loan = $this->resolveLoan($collateral);

        if ($loan === null) {
            return false;
        }

        if (!$this->hasPermission(
            $user,
            'loan.collateral.view',
            (int) $loan->tenant_id,
        )) {
            return false;
        }

        return $this->loanPolicy->view($user, $loan);
    }

    /**
     * Determine whether the user may register collateral against a loan.
     *
     * The user is authorized when BOTH of the following hold:
     *
     *   - the user holds `loan.collateral.manage` in the loan's tenant;
     *     AND
     *
     *   - `LoanPolicy::view()` grants the user view access to the loan.
     *
     * This ability is dispatched against a TRANSIENT `LoanCollateral`
     * instance whose `loan_id` is set to the loan's id. See the class
     * docblock §"The transient-instance dispatch pattern".
     *
     * @param User           $user
     * @param LoanCollateral $collateral
     * @return bool
     */
    public function manage(User $user, LoanCollateral $collateral): bool
    {
        $loan = $this->resolveLoan($collateral);

        if ($loan === null) {
            return false;
        }

        if (!$this->hasPermission(
            $user,
            'loan.collateral.manage',
            (int) $loan->tenant_id,
        )) {
            return false;
        }

        return $this->loanPolicy->view($user, $loan);
    }

    /**
     * Determine whether the user may release a specific collateral.
     *
     * The user is authorized when BOTH of the following hold:
     *
     *   - the user holds `loan.collateral.release` in the loan's tenant;
     *     AND
     *
     *   - `LoanPolicy::view()` grants the user view access to the loan.
     *
     * Note that `loan.collateral.release` is one of the two permissions
     * the frozen RBAC matrix withholds from the `platform_admin` role
     * (the other being `loan.writeoff`); only `super_admin` holds it.
     * The policy itself does not encode this distinction; the permission
     * assignment does.
     *
     * @param User           $user
     * @param LoanCollateral $collateral
     * @return bool
     */
    public function release(User $user, LoanCollateral $collateral): bool
    {
        $loan = $this->resolveLoan($collateral);

        if ($loan === null) {
            return false;
        }

        if (!$this->hasPermission(
            $user,
            'loan.collateral.release',
            (int) $loan->tenant_id,
        )) {
            return false;
        }

        return $this->loanPolicy->view($user, $loan);
    }

    // =========================================================================
    // Private — loan resolution
    // =========================================================================

    /**
     * Resolve the loan the given collateral belongs to.
     *
     * Returns the loan when the collateral's `loan` relation is present
     * (either pre-loaded by the caller or loadable from the database);
     * returns null when the collateral has no `loan_id` — a state that
     * indicates a malformed transient instance (see the class docblock
     * §"The transient-instance dispatch pattern").
     *
     * The method triggers at most one query (`loadMissing('loan')`). If
     * the caller has already loaded the relation (e.g. a controller that
     * resolved the collateral with `with('loan')`), no query is issued.
     *
     * A `null` return causes every ability to deny. The alternative — a
     * `LogicException` — is rejected because a malformed transient
     * instance is a controller-side defect, not a policy-side one; the
     * policy's correct response is to deny rather than to crash the
     * authorization pipeline.
     *
     * @param LoanCollateral $collateral
     * @return Loan|null
     */
    private function resolveLoan(LoanCollateral $collateral): ?Loan
    {
        if ((int) $collateral->loan_id <= 0) {
            return null;
        }

        $collateral->loadMissing('loan');

        $loan = $collateral->loan;

        if ($loan === null) {
            return null;
        }

        return $loan;
    }

    // =========================================================================
    // Private — permission delegation
    // =========================================================================

    /**
     * Delegate a permission check to the Core `AuthorizationService`.
     *
     * Uses NAMED ARGUMENTS, following the platform-wide convention for
     * this service's call sites.
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
}