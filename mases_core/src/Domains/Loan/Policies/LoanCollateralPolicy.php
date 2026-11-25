<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanCollateral;

/**
 * Collateral authorization delegates to LoanPolicy; users cannot view, manage or release collateral without access to its parent loan.
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