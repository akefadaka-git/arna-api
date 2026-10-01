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
 * LoanReconciliationController
 *
 * The HTTP surface for the loan reconciliation report. Owns exactly ONE
 * action:
 *
 *   show()    GET  /loans/{loan}/reconciliation    — the reconciliation report
 *
 * =============================================================================
 * The endpoint's contract
 * =============================================================================
 * The endpoint returns the frozen C5 reconciliation report for a single
 * loan. The report is produced by
 * `LoanReconciliationService::reconcile()` (A.36) and answers the exact
 * invariant frozen in the Phase 2C Final Reconciliation §3:
 *
 *     Σ(wallet_obligations.outstanding_amount
 *         WHERE reference_type = 'Loan'
 *           AND reference_id   = (string) loans.id)
 *       ==
 *     loans.outstanding_principal
 *       + loans.outstanding_interest
 *       + loans.outstanding_penalty
 *
 * The report explicitly does NOT compare against
 * `wallets.current_balance` — that column includes non-loan obligations
 * and adjustments made for unrelated reasons (see A.36's class docblock
 * §"The frozen invariant" for the full reasoning).
 *
 * =============================================================================
 * The thin-delegator discipline
 * =============================================================================
 * The controller follows the same four-step shape as every Loan-domain
 * controller:
 *
 *   1. RESOLVE — read the tenant id from the request context; resolve
 *      the route-bound `{loan}` through
 *      `LoanRepository::findForTenantOrFail()` (A.55). A non-existent
 *      id and a cross-tenant id both produce a `ModelNotFoundException`
 *      (rendered as 404).
 *
 *   2. AUTHORIZE — dispatch `Gate::authorize('view', $loan)`. The
 *      reconciliation endpoint SHARES the loan-view ability rather than
 *      defining a distinct reconciliation-specific permission; see the
 *      class docblock of `LoanPolicy` (A.52) §"The ten abilities" for
 *      the design rationale. The ability grants access to a caller who
 *      holds `loan.view` OR `loan.view_own` with ownership.
 *
 *   3. DELEGATE — call `LoanReconciliationService::reconcile($loan)`
 *      (A.36). The service performs the entire multi-query aggregation
 *      — reading the loan, the loan-linked Wallet obligations, the
 *      loan-linked Wallet payments, the contractual schedule, and the
 *      per-installment breakdown — and returns the report array.
 *
 *   4. RESPOND — wrap the report in `LoanReconciliationResource` (A.75)
 *      and emit the canonical envelope.
 *
 * The controller contains no aggregation logic, no Wallet-side reads,
 * no arithmetic, and no invariant checking. Every one of those
 * responsibilities belongs to the reconciler (A.36), which is delivered
 * as a standalone read-only service.
 *
 * =============================================================================
 * The eager-load posture
 * =============================================================================
 * Unlike the schedule endpoint (A.81) and the statement endpoint (A.82),
 * this controller does NOT compose an eager-load set. The reconciler
 * (A.36) performs its own reads directly against the loan's child
 * tables and the Wallet-side tables — see A.36's
 * `collectWalletObligations()`, `collectWalletPayments()`,
 * `collectContractualTotals()`, and `collectPerInstallmentBreakdown()`
 * methods — rather than through the loan's relations. The controller
 * therefore resolves the loan WITHOUT a `with:` array and passes the
 * instance to the service.
 *
 * The alternative — eager-loading relations and letting the service
 * read them — is not what A.36 was built for. The service's methods
 * issue their own scoped queries for determinism and to keep the read
 * paths visible; the controller respects that design.
 *
 * =============================================================================
 * The read-only posture
 * =============================================================================
 * The reconciliation service opens no transaction (A.36's class docblock
 * §"What this service is NOT": "It does NOT open a transaction. It is a
 * read-only service"). The controller follows: no transaction, no
 * mutation, no event dispatch. The endpoint is a pure read of an
 * already-consistent state.
 *
 * A caller that needs to enforce the invariant at a transaction boundary
 * — rather than merely observe it via the endpoint — uses the service's
 * `assertBalanced()` method (A.36) from within its own transaction. The
 * endpoint exposes only the observation path.
 *
 * =============================================================================
 * The response envelope
 * =============================================================================
 * A successful call returns HTTP 200 with the canonical MASES envelope:
 *
 *     { "success": true, "data": {...} }
 *
 * The `data` payload is the reconciler's report serialized by
 * `LoanReconciliationResource` (A.75), whose `$wrap = null` setting
 * prevents Laravel's own `data` wrapper from double-wrapping the
 * payload inside the controller's envelope.
 *
 * The report's shape is documented on A.36's `reconcile()` method and
 * on A.75's class docblock. The most important field for a caller is
 * `is_balanced`, the SINGLE authoritative boolean derived from the
 * aggregate invariant check.
 *
 * =============================================================================
 * What this controller deliberately does NOT do
 * =============================================================================
 *   - It does NOT run the aggregation. That is the reconciler's job
 *     (A.36).
 *
 *   - It does NOT compose an eager-load set. The service's own reads
 *     supersede it. See §"The eager-load posture".
 *
 *   - It does NOT check the invariant, compute the divergence, or
 *     classify a balanced state. Every one of those results is
 *     produced by the service and emitted by the resource.
 *
 *   - It does NOT call `assertBalanced()`. The assertion is a
 *     transaction-boundary guard for the domain services; the endpoint
 *     exposes the observation path via `reconcile()`. See §"The
 *     read-only posture".
 *
 *   - It does NOT accept any query parameter. There is no `?as_of=`,
 *     no `?include=`, no filter. The report is always produced with
 *     the current moment as the reconciliation timestamp.
 *
 *   - It does NOT catch exceptions. Every exception — including the
 *     `ModelNotFoundException` from the repository and the
 *     `AuthorizationException` from the gate — propagates to the
 *     framework's exception renderer.
 *
 *   - It does NOT open a transaction. The endpoint is a read.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — B1, B2, B3
 *   Phase 2C Clarification Response, 2026-09-12  — C5 (reconciliation
 *                                                  combination)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §3 (the exact
 *                                                  invariant and the
 *                                                  wallet-balance
 *                                                  exclusion)
 *   Phase 2C A.36                                — `LoanReconciliationService`
 *                                                  (the report
 *                                                  producer)
 *   Phase 2C A.52                                — `LoanPolicy` (the
 *                                                  shared `view`
 *                                                  ability this
 *                                                  endpoint uses)
 *   Phase 2C A.55                                — `LoanRepository`
 *   Phase 2C A.75                                — `LoanReconciliationResource`
 *                                                  (the composite
 *                                                  serializer this
 *                                                  endpoint returns)
 *   Phase 2C A.77                                — `LoanController` (the
 *                                                  shape precedent)
 *   Phase 2C A.84                                — the routes file
 *   Step 5 (manifest) A.83                       — This file
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