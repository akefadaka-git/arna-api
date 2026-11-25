<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Controllers;

use Gate;
use LogicException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Domains\Loan\Http\Resources\LoanReconciliationResource;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Repositories\LoanRepository;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanReconciliationService;

/**
 * Returns the loan reconciliation report. Compare outstanding Loan components with linked Wallet obligations, not wallet.current_balance.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Controllers
 * @version 1.0.0
 */
final class LoanReconciliationController
{
    /**
     * Constructor.
     *
     * Two dependencies:
     *
     *   - `LoanRepository`              — the tenant-scoped loan lookup.
     *   - `LoanReconciliationService`   — the report producer.
     *
     * @param LoanRepository            $loanRepository
     * @param LoanReconciliationService $reconciliationService
     */
    public function __construct(
        private readonly LoanRepository $loanRepository,
        private readonly LoanReconciliationService $reconciliationService,
    ) {
    }

    // =========================================================================
    // Public API — show
    // =========================================================================

    /**
     * Return the reconciliation report for a loan.
     *
     * See the class docblock for the endpoint's contract and for the
     * response shape.
     *
     * @param Request $request
     * @param int     $loanId
     * @return JsonResponse
     */
    public function show(Request $request, int $loanId): JsonResponse
    {
        $loan = $this->resolveLoan($loanId);

        Gate::authorize('view', $loan);

        $report = $this->reconciliationService->reconcile($loan);

        return response()->json([
            'success' => true,
            'data'    => (new LoanReconciliationResource($report))->toArray($request),
        ]);
    }

    // =========================================================================
    // Private — resolution
    // =========================================================================

    /**
     * Resolve the loan addressed by the route's `{loan}` parameter.
     *
     * The loan is resolved WITHOUT an eager-load set: the reconciler
     * (A.36) performs its own reads directly against the loan's child
     * tables and the Wallet-side tables. See the class docblock §"The
     * eager-load posture".
     *
     * The `LoanRepository::findForTenantOrFail()` call (A.55) throws
     * `ModelNotFoundException` when the loan does not exist OR belongs
     * to a different tenant; the two failure cases produce identical
     * outcomes (404 through the framework's renderer), preserving the
     * tenant-isolation contract (handoff §3).
     *
     * @param int $loanId
     * @return Loan
     */
    private function resolveLoan(int $loanId): Loan
    {
        return $this->loanRepository->findForTenantOrFail(
            tenantId: $this->resolveTenantId(),
            loanId:   $loanId,
        );
    }

    /**
     * Resolve the authenticated tenant's id from the request context.
     *
     * See `LoanController::resolveTenantId()` (A.77) for the shared
     * rationale. The guard mirrors that method verbatim.
     *
     * @return int
     *
     * @throws LogicException
     */
    private function resolveTenantId(): int
    {
        /** @var int|string|null $tenantId */
        $tenantId = function_exists('mases_tenant_id')
            ? mases_tenant_id()
            : null;

        if ($tenantId === null || $tenantId === '') {
            throw new LogicException(
                'LoanReconciliationController requires a resolved tenant '
                . 'id. The `tenant.membership` middleware must populate '
                . 'the request context before the reconciliation action '
                . 'runs. A missing tenant indicates a middleware-bypass '
                . 'defect or a route registered without the middleware '
                . 'stack.'
            );
        }

        return (int) $tenantId;
    }
}