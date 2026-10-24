<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Controllers;

use Gate;
use LogicException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Domains\Loan\Http\Requests\StoreRepaymentRequest;
use MaaleArna\MASES\Core\Domains\Loan\Http\Resources\LoanResource;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Repositories\LoanRepository;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanRepaymentService;

/**
 * LoanRepaymentController
 *
 * The HTTP surface for recording a repayment against a loan. Owns
 * exactly ONE action:
 *
 *   store()    POST /loans/{loan}/repay    — record a repayment
 *
 * =============================================================================
 * The endpoint's contract
 * =============================================================================
 * The caller submits a repayment amount, an ordered list of allocation
 * instructions, and four optional supplementary fields. The
 * `StoreRepaymentRequest` (A.64) validates the SHAPE of every field and
 * performs ONE cross-field check — that the sum of the allocation amounts
 * equals the repayment amount, computed with BCMath at scale 4.
 *
 * The controller then delegates to
 * `LoanRepaymentService::record()` (A.31), which is the authoritative
 * boundary for:
 *
 *   - the loan's repayable-state check,
 *   - the borrower's Wallet resolution,
 *   - the currency-equality check (Q8),
 *   - each obligation's reference-pair check
 *     (`reference_type = 'Loan'`, `reference_id = (string) $loan->id`),
 *   - the Wallet-side payment recording,
 *   - the per-instruction allocation application,
 *   - the projection recomputation,
 *   - the installment settlement detection and the loan-level
 *     settlement transition when applicable.
 *
 * =============================================================================
 * The received_at conversion
 * =============================================================================
 * `StoreRepaymentRequest` (A.64) validates `received_at` as a `date`
 * string. `LoanRepaymentService::record()` (A.31) expects a
 * `?Carbon $receivedAt` instance (or null, in which case the service
 * uses `Carbon::now()`).
 *
 * The controller therefore parses the string into a `Carbon` instance
 * before delegating. A null or absent `received_at` passes through as
 * `null`, letting the service use its own default moment.
 *
 * The parse is the controller's ONLY substantive transformation. Every
 * other field is passed verbatim.
 *
 * =============================================================================
 * The two dead parameters
 * =============================================================================
 * `LoanRepaymentService::record()` (A.31) declares
 * `?string $referenceType` and `?string $referenceId` parameters but its
 * body IGNORES both, hardcoding:
 *
 *     referenceType: self::WALLET_REFERENCE_TYPE_LOAN_REPAYMENT,  // = 'LoanRepayment'
 *     referenceId:   (string) $current->id,
 *
 * on the Wallet-side payment (see A.64's deployment note 6). The
 * controller passes the caller-supplied values through faithfully —
 * removing them would break the handoff §5 request contract — and
 * documents the current behavior so a future reader is not surprised
 * when the Wallet payment's `reference_type`/`reference_id` do not
 * match the request body.
 *
 * =============================================================================
 * The response
 * =============================================================================
 * A successful repayment returns HTTP 200 with the canonical MASES
 * envelope:
 *
 *     { "success": true, "data": {...} }
 *
 * The `data` payload is the REFRESHED loan, wrapped in `LoanResource`
 * (A.69). The refresh reflects:
 *
 *   - the updated `settled_amount` (increased by the repayment amount),
 *   - the recomputed `outstanding_principal`, `outstanding_interest`,
 *     and `outstanding_penalty` (from the post-allocation Wallet
 *     obligation state),
 *   - the loan's `status` — `Settled` if this repayment completed the
 *     loan, otherwise unchanged,
 *   - the `installments` relation, eager-loaded so the client sees the
 *     per-installment settlement effect immediately.
 *
 * =============================================================================
 * The eager-load choice for the response
 * =============================================================================
 * The response eager-loads the loan's `installments` relation. The
 * rationale: a repayment's most observable consequence on the LOAN side
 * (besides the projection updates visible on the loan's own columns) is
 * the settlement of one or more installments. Loading `installments`
 * lets the client render "which installments settled" without a
 * follow-up call.
 *
 * The `statusHistories`, `borrower`, `product`, and other relations
 * are NOT eager-loaded for this response. A client that needs them
 * calls `GET /loans/{loan}` (which returns the full representation via
 * `LoanController::show()`, A.77). Keeping the mutation response lean
 * matches the aggregate's other mutation endpoints.
 *
 * =============================================================================
 * What this controller deliberately does NOT do
 * =============================================================================
 *   - It does NOT validate the allocation sum. `StoreRepaymentRequest`
 *     (A.64) performs the sum check at the HTTP boundary; the service
 *     (A.31) performs the same check for programmatic callers. The
 *     controller trusts the request's `validated()` output.
 *
 *   - It does NOT resolve the borrower's Wallet. That is the service's
 *     concern (A.31).
 *
 *   - It does NOT resolve any Wallet obligation. That is the service's
 *     concern.
 *
 *   - It does NOT check the loan's currency against the Wallet's. That
 *     is the service's concern.
 *
 *   - It does NOT perform arithmetic. Every monetary value flows
 *     verbatim through `validated()`.
 *
 *   - It does NOT catch exceptions. Every exception — including the
 *     `ModelNotFoundException` from the repository, the
 *     `AuthorizationException` from the gate, the `ValidationException`
 *     from the service, and any `ModelNotFoundException` the service
 *     raises for a non-existent obligation — propagates to the
 *     framework's renderer.
 *
 *   - It does NOT open a transaction. The service (A.31) is the
 *     top-level entry point and owns its own transaction.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D110 (allocation
 *                                                  policy), B1
 *                                                  (reference types),
 *                                                  B2 (hybrid ledger),
 *                                                  B7 (RBAC)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C A.31                                — `LoanRepaymentService`
 *   Phase 2C A.52                                — `LoanPolicy::repay()`
 *   Phase 2C A.55                                — `LoanRepository`
 *   Phase 2C A.64                                — `StoreRepaymentRequest`
 *   Phase 2C A.69                                — `LoanResource`
 *   Phase 2C A.77                                — `LoanController` (the
 *                                                  shape precedent for
 *                                                  mutations)
 *   Phase 2C A.84                                — the routes file
 *   Phase 2B 2B.17                               — `PaymentService`
 *   Phase 2B 2B.18                               — `AllocationService`
 *   Step 5 (manifest) A.80                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Controllers
 * @version 1.0.0
 */
final class LoanRepaymentController
{
    /**
     * Constructor.
     *
     * Two dependencies:
     *
     *   - `LoanRepository`        — the tenant-scoped loan lookup.
     *   - `LoanRepaymentService`  — the record delegation target.
     *
     * @param LoanRepository        $loanRepository
     * @param LoanRepaymentService  $repaymentService
     */
    public function __construct(
        private readonly LoanRepository $loanRepository,
        private readonly LoanRepaymentService $repaymentService,
    ) {
    }

    // =========================================================================
    // Public API — store
    // =========================================================================

    /**
     * Record a repayment against a loan.
     *
     * See the class docblock for the endpoint's contract and for the
     * response shape.
     *
     * @param StoreRepaymentRequest $request
     * @param int                   $loanId
     * @return JsonResponse
     */
    public function store(StoreRepaymentRequest $request, int $loanId): JsonResponse
    {
        $loan = $this->resolveLoan($loanId);

        Gate::authorize('repay', $loan);

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $receivedAt = $this->parseReceivedAt($validated['received_at'] ?? null);

        /** @var list<array{obligation_id: int, amount: string}> $allocations */
        $allocations = $validated['allocations'];

        $responseFactory = static function (Loan $responseLoan) use ($request): array {
            // Build the response while the repayment transaction is still open,
            // so the exact successful payload can be committed with the payment.
            $responseLoan->loadMissing('installments');

            return [
                'success' => true,
                'data' => (new LoanResource($responseLoan))->toArray($request),
            ];
        };

        $loan = $this->repaymentService->record(
            loan:           $loan,
            amount:         (string) $validated['amount'],
            allocations:    $allocations,
            idempotencyKey: (string) $validated['idempotency_key'],
            responseFactory: $responseFactory,
            receivedAt:     $receivedAt,
            referenceType:  $validated['reference_type'] ?? null,
            referenceId:    $validated['reference_id'] ?? null,
            description:    $validated['description'] ?? null,
            actorUserId:    $request->user()?->getKey(),
        );

        // A duplicate request returns the original committed response snapshot,
        // not a newly serialized representation of the loan's current state.
        $tenantId = (int) $loan->tenant_id;
        $snapshot = DB::table('loan_repayment_idempotencies')
            ->where('tenant_id', $tenantId)
            ->where('loan_id', (int) $loan->id)
            ->where('idempotency_key', (string) $validated['idempotency_key'])
            ->first(['response_status', 'response_body']);

        if ($snapshot !== null && $snapshot->response_body !== null) {
            $body = json_decode(
                (string) $snapshot->response_body,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            return response()->json($body, (int) ($snapshot->response_status ?? 200));
        }

        // Defensive fallback for legacy/incomplete idempotency records.
        $loan->loadMissing('installments');

        return response()->json([
            'success' => true,
            'data'    => (new LoanResource($loan))->toArray($request),
        ]);
    }

    // =========================================================================
    // Private — resolution
    // =========================================================================

    /**
     * Resolve the loan addressed by the route's `{loan}` parameter.
     *
     * See `LoanController::resolveLoan()` (A.77) for the shared rationale.
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
                'LoanRepaymentController requires a resolved tenant id. '
                . 'The `tenant.membership` middleware must populate the '
                . 'request context before any Loan repayment action runs. '
                . 'A missing tenant indicates a middleware-bypass defect '
                . 'or a route registered without the middleware stack.'
            );
        }

        return (int) $tenantId;
    }

    // =========================================================================
    // Private — input normalization
    // =========================================================================

    /**
     * Parse the optional `received_at` value into a `Carbon` instance.
     *
     * `StoreRepaymentRequest` (A.64) validates `received_at` as a `date`
     * string. The service (A.31) expects `?Carbon`. The controller
     * performs the conversion.
     *
     * Returns null when the input is null, absent, or empty-after-trim.
     * In that case the service uses `Carbon::now()`.
     *
     * @param mixed $value
     * @return Carbon|null
     */
    private function parseReceivedAt(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        return Carbon::parse($value);
    }
}