<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Controllers;

use Gate;
use LogicException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Domains\Loan\Http\Resources\LoanScheduleResource;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Repositories\LoanRepository;

/**
 * LoanScheduleController
 *
 * The HTTP surface for a loan's contractual schedule. Owns exactly ONE
 * action:
 *
 *   show()    GET  /loans/{loan}/schedule    — the schedule view
 *
 * =============================================================================
 * The endpoint's contract
 * =============================================================================
 * The endpoint returns the loan's schedule as a `LoanScheduleResource`
 * composite (A.73). The resource emits:
 *
 *   - the loan's schedule-relevant identity and terms,
 *   - the persisted schedule-summary columns written by
 *     `LoanScheduleService::generate()` (A.27),
 *   - the `maturity_date`,
 *   - a `totals` object that aggregates the loaded installments,
 *   - the full `installments` collection serialized through
 *     `LoanInstallmentResource` (A.71).
 *
 * The `totals` and `installments` fields are BOTH gated by
 * `whenLoaded('installments', ...)` in A.73. The controller MUST
 * eager-load the `installments` relation before instantiating the
 * resource; otherwise both fields are silently omitted and the endpoint
 * returns a schedule view with neither installments nor totals. See the
 * class docblock of A.73 §"The `whenLoaded` contract" for the full
 * rationale.
 *
 * =============================================================================
 * Why the eager-load is mandatory, not an optimization
 * =============================================================================
 * The `$with` parameter on `LoanRepository::findForTenantOrFail()`
 * (A.55) is an optimization for the OTHER loan endpoints: it lets a
 * controller pre-load the relations it needs without triggering lazy
 * loads inside a resource. For the schedule endpoint, the eager-load is
 * not an optimization — it is a CONTRACT. The endpoint's entire purpose
 * is to present the schedule, and the schedule IS the installments. A
 * schedule response without installments would be a defective API.
 *
 * The controller therefore passes `with: ['installments']` to the
 * repository at the resolution step. The resource observes the loaded
 * relation and emits both `installments` and `totals`.
 *
 * =============================================================================
 * What about the totals?
 * =============================================================================
 * The `totals` object is computed by `LoanScheduleResource` (A.73) from
 * the loaded installments collection. The controller does NOT compute
 * it; the resource does. The controller's responsibility is solely to
 * ensure the installments are loaded before the resource is
 * instantiated.
 *
 * =============================================================================
 * What about the loan's other relations?
 * =============================================================================
 * The schedule view is deliberately narrow. It does NOT expose the
 * borrower, the product, the policy version, the collaterals, the
 * restructurings, the write-off, or the status history. A client that
 * needs those reads them through `GET /loans/{loan}` (which uses
 * `LoanResource`, A.69) — not through the schedule endpoint.
 *
 * The controller therefore eager-loads ONLY `installments`, and does
 * not compose any other relation. The resource's `toArray()` accesses
 * only the loan's own columns and the installments collection; no
 * lazy-load is triggered by any other access.
 *
 * =============================================================================
 * The response envelope
 * =============================================================================
 * A successful call returns HTTP 200 with the canonical MASES envelope:
 *
 *     { "success": true, "data": {...} }
 *
 * The `data` payload is the schedule view emitted by
 * `LoanScheduleResource` (A.73), whose `$wrap = null` setting prevents
 * Laravel's own `data` wrapper from double-wrapping the payload inside
 * the controller's envelope.
 *
 * =============================================================================
 * What this controller deliberately does NOT do
 * =============================================================================
 *   - It does NOT filter the installments. Every installment is
 *     serialized, including superseded ones from prior schedules. A
 *     caller that needs only the current schedule's installments
 *     filters by `status` on their side; a caller that needs only
 *     "unsettled" reads through a different endpoint (a future CR
 *     would introduce one).
 *
 *   - It does NOT paginate. The schedule is a bounded set (typically
 *     tens to low hundreds of installments); pagination would be
 *     gratuitous.
 *
 *   - It does NOT accept any query parameter. There is no `?include=`,
 *     no `?status=`, no `?from=`/`?to=` range filter. The endpoint
 *     returns the full schedule.
 *
 *   - It does NOT compute the totals. The resource (A.73) does.
 *
 *   - It does NOT contain financial logic. Every monetary value
 *     emitted is a direct model read or a delegated helper call.
 *
 *   - It does NOT catch exceptions. Every exception propagates to the
 *     framework's exception renderer, unchanged from Phase 2B.
 *
 *   - It does NOT open a transaction. The endpoint is a read-only
 *     operation.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9, D132, D135
 *   Phase 2C Clarification Response, 2026-09-12  — C5 (reconciliation)
 *   Phase 2C A.18                                — `Loan`
 *   Phase 2C A.19                                — `LoanInstallment`
 *   Phase 2C A.27                                — `LoanScheduleService`
 *                                                  (the writer of the
 *                                                  schedule-summary
 *                                                  columns)
 *   Phase 2C A.52                                — `LoanPolicy::viewSchedule()`
 *   Phase 2C A.55                                — `LoanRepository`
 *   Phase 2C A.71                                — `LoanInstallmentResource`
 *   Phase 2C A.73                                — `LoanScheduleResource`
 *                                                  (the composite
 *                                                  serializer this
 *                                                  endpoint returns)
 *   Phase 2C A.77                                — `LoanController` (the
 *                                                  shape precedent)
 *   Phase 2C A.84                                — the routes file
 *   Phase 2B 2B.24                               — `WalletController` (the
 *                                                  sibling controller
 *                                                  with a sub-resource
 *                                                  read endpoint)
 *   Step 5 (manifest) A.81                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Controllers
 * @version 1.0.0
 */
final class LoanScheduleController
{
    /**
     * Constructor.
     *
     * One dependency: the tenant-scoped loan lookup.
     *
     * @param LoanRepository $loanRepository
     */
    public function __construct(
        private readonly LoanRepository $loanRepository,
    ) {
    }

    // =========================================================================
    // Public API — show
    // =========================================================================

    /**
     * Return a loan's contractual schedule.
     *
     * The `installments` relation is eager-loaded at the resolution
     * step. See the class docblock §"Why the eager-load is mandatory".
     *
     * @param Request $request
     * @param int     $loanId
     * @return JsonResponse
     */
    public function show(Request $request, int $loanId): JsonResponse
    {
        $loan = $this->resolveLoanWithInstallments($loanId);

        Gate::authorize('viewSchedule', $loan);

        return response()->json([
            'success' => true,
            'data'    => (new LoanScheduleResource($loan))->toArray($request),
        ]);
    }

    // =========================================================================
    // Private — resolution
    // =========================================================================

    /**
     * Resolve the loan addressed by the route's `{loan}` parameter,
     * eager-loading its installments.
     *
     * The eager-load is part of the resolution step — NOT a later,
     * optional step — because the endpoint's response DEPENDS on the
     * installments being loaded (see the class docblock §"Why the
     * eager-load is mandatory").
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
    private function resolveLoanWithInstallments(int $loanId): Loan
    {
        return $this->loanRepository->findForTenantOrFail(
            tenantId: $this->resolveTenantId(),
            loanId:   $loanId,
            with:     ['installments'],
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
                'LoanScheduleController requires a resolved tenant id. '
                . 'The `tenant.membership` middleware must populate the '
                . 'request context before the schedule action runs. '
                . 'A missing tenant indicates a middleware-bypass defect '
                . 'or a route registered without the middleware stack.'
            );
        }

        return (int) $tenantId;
    }
}