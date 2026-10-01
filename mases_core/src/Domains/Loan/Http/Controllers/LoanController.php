<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Controllers;

use Gate;
use LogicException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Loan\Http\Requests\ApproveLoanRequest;
use MaaleArna\MASES\Core\Domains\Loan\Http\Requests\DisburseLoanRequest;
use MaaleArna\MASES\Core\Domains\Loan\Http\Requests\RejectLoanRequest;
use MaaleArna\MASES\Core\Domains\Loan\Http\Requests\StoreLoanRequest;
use MaaleArna\MASES\Core\Domains\Loan\Http\Requests\StoreRestructureRequest;
use MaaleArna\MASES\Core\Domains\Loan\Http\Requests\StoreWriteOffRequest;
use MaaleArna\MASES\Core\Domains\Loan\Http\Resources\LoanResource;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Repositories\LoanRepository;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanApplicationService;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanApprovalService;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanDisbursementService;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanRestructuringService;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanWriteOffService;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;

/**
 * LoanController
 *
 * The HTTP surface for the Loan aggregate's lifecycle endpoints. Owns
 * eight actions, all of which are thin delegators: resolve the
 * route-bound loan through the tenant-scoped repository, enforce the
 * policy ability through `Gate::authorize(...)` (or, for the store
 * endpoint's permission-only ability, through the platform's global
 * permission gate), delegate to the appropriate domain service, and
 * return the canonical MASES response envelope.
 *
 * =============================================================================
 * The eight actions
 * =============================================================================
 *   index()       GET  /loans                         — list (paginated)
 *   store()       POST /loans                         — create a draft
 *   show()        GET  /loans/{loan}                  — show
 *   approve()     POST /loans/{loan}/approve          — record approval
 *   reject()      POST /loans/{loan}/reject           — record rejection
 *   disburse()    POST /loans/{loan}/disburse         — disburse
 *   restructure() POST /loans/{loan}/restructure      — restructure
 *   writeoff()    POST /loans/{loan}/writeoff         — write off
 *
 * The remaining eight endpoints in the frozen sixteen-route surface
 * (A.84) are owned by six sibling controllers:
 *
 *   LoanProductController          (A.78) — GET  /loans/products
 *   LoanCollateralController       (A.79) — collateral index/store/release
 *   LoanRepaymentController        (A.80) — POST /loans/{loan}/repay
 *   LoanScheduleController         (A.81) — GET  /loans/{loan}/schedule
 *   LoanStatementController        (A.82) — GET  /loans/{loan}/statement
 *   LoanReconciliationController   (A.83) — GET  /loans/{loan}/reconciliation
 *
 * This controller deliberately does NOT own any of those six routes;
 * each has its own controller with its own dependencies and its own
 * focused test surface.
 *
 * =============================================================================
 * The thin-delegator discipline
 * =============================================================================
 * No financial logic, no state-machine logic, no arithmetic, no
 * cross-entity validation, and no policy reasoning live in this
 * controller. Every action follows the same four-step shape:
 *
 *   1. RESOLVE — read the tenant id from the request context; resolve
 *      the route-bound `{loan}` through
 *      `LoanRepository::findForTenantOrFail()` (A.55). A non-existent
 *      id and a cross-tenant id both produce a `ModelNotFoundException`,
 *      which the framework's exception renderer maps to the canonical
 *      404 envelope.
 *
 *   2. AUTHORIZE — dispatch the relevant policy ability via
 *      `Gate::authorize('ability', $loan)`, or — for the store
 *      endpoint — via `Gate::authorize('loan.apply')`. The policy
 *      (A.52) enforces the RBAC matrix (B7).
 *
 *   3. DELEGATE — call the domain service. The service enforces every
 *      domain rule (state machine, bounds, currency, Wallet presence,
 *      etc.) and produces the refreshed loan on success.
 *
 *   4. RESPOND — wrap the refreshed loan in `LoanResource` (A.69) and
 *      emit the canonical envelope.
 *
 * =============================================================================
 * The store endpoint's authorization
 * =============================================================================
 * `POST /loans` is the ONE endpoint whose authorization does not flow
 * through a policy ability. The `loan.apply` permission is a
 * PERMISSION-ONLY ability: it has no model instance to authorize
 * against (the loan does not exist yet), and `LoanPolicy` (A.52) has no
 * `apply()` method. The authorization is therefore dispatched as a raw
 * ability string — `Gate::authorize('loan.apply')` — which the
 * platform's global permission gate (registered by the platform's
 * AuthServiceProvider alongside the `AuthorizationService`) resolves to
 * a `hasPermission('loan.apply')` check.
 *
 * This mirrors the handoff §5's description of the store endpoint: the
 * `StoreLoanRequest` (A.60) returns `true` from `authorize()`, and the
 * controller enforces the ability at the delegation boundary.
 *
 * =============================================================================
 * The index endpoint's scope resolution
 * =============================================================================
 * `GET /loans` is the ONE endpoint whose response set depends on which
 * permission the caller holds, not merely on whether they hold it.
 * `LoanPolicy::viewAny()` (A.52) grants the ability to a caller who
 * holds EITHER `loan.view` (tenant-wide) OR `loan.view_own` (own-only).
 * The policy does not distinguish between the two grants; the
 * controller does, via a direct `AuthorizationService::hasPermission()`
 * check.
 *
 *   - A caller with `loan.view` sees every loan in the tenant.
 *   - A caller with `loan.view_own` (but not `loan.view`) sees only the
 *     loans for which they are the borrower. The borrower identity is
 *     resolved by looking up the caller's Shareholder record in the
 *     tenant and matching it against the loan's
 *     `borrower_shareholder_id` column.
 *   - A caller with neither permission is rejected by the policy's
 *     `viewAny()` before the controller's scope-resolution logic runs.
 *
 * The scope resolution requires a Shareholder lookup, which is a
 * cross-domain read. The controller performs it via a narrow scoped
 * query on the `Shareholder` model; the same pattern `LoanPolicy`
 * (A.52) uses for its `isBorrower()` helper. No additional dependency
 * is injected.
 *
 * =============================================================================
 * The response envelope
 * =============================================================================
 * Every successful action returns a JSON response with the canonical
 * MASES envelope:
 *
 *     {
 *         "success": true,
 *         "data":    {...},   // the resource payload
 *         "meta":    {...}    // only for paginated responses
 *     }
 *
 * The `data` payload is produced by `LoanResource` (A.69), whose
 * `$wrap = null` setting prevents Laravel's own `data` wrapper from
 * double-wrapping the payload inside the controller's envelope.
 *
 * Error responses (404, 403, 422, 500) are produced by the platform's
 * exception renderer in `bootstrap/app.php`, unchanged from Phase 2B.
 * The controller does NOT catch exceptions or construct error
 * envelopes.
 *
 * =============================================================================
 * What this controller deliberately does NOT do
 * =============================================================================
 *   - It does NOT contain financial logic. All arithmetic, projections,
 *     and monetary handling live in the domain services.
 *
 *   - It does NOT contain state-machine logic. The state machine
 *     (A.26) is invoked exclusively by the services.
 *
 *   - It does NOT perform cross-entity validation. The request classes
 *     (A.60–A.68) validate shape; the services validate domain rules.
 *
 *   - It does NOT catch exceptions. Every exception — including the
 *     `ModelNotFoundException` from the repository, the
 *     `AuthorizationException` from the gate, and the
 *     `ValidationException` from the services — propagates to the
 *     framework's renderer.
 *
 *   - It does NOT open transactions. Every service that mutates state
 *     opens its own transaction as the top-level entry point.
 *
 *   - It does NOT mutate the resolved loan. The services return a
 *     refreshed instance; the controller passes it to the resource.
 *
 *   - It does NOT translate strings. All user-facing text is resolved
 *     from the Loan domain's translation namespace by the request
 *     classes and the exception renderer.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — B7 (RBAC), D129,
 *                                                  D130
 *   Phase 2C Clarification Response, 2026-09-12  — C1 (shareholder role),
 *                                                  C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §4 (loan-list
 *                                                  authorization)
 *   Phase 2C A.28                                — `LoanApplicationService`
 *   Phase 2C A.29                                — `LoanApprovalService`
 *   Phase 2C A.30                                — `LoanDisbursementService`
 *   Phase 2C A.34                                — `LoanRestructuringService`
 *   Phase 2C A.35                                — `LoanWriteOffService`
 *   Phase 2C A.52                                — `LoanPolicy`
 *   Phase 2C A.55                                — `LoanRepository`
 *   Phase 2C A.60–A.68                           — the request classes
 *   Phase 2C A.69                                — `LoanResource`
 *   Phase 2C A.78–A.83                           — the sibling controllers
 *   Phase 2C A.84                                — the routes file
 *   Phase 2B 2B.24                               — `WalletController` (the
 *                                                  shape precedent)
 *   Step 5 (manifest) A.77                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Controllers
 * @version 1.0.1
 */
final class LoanController
{
    /**
     * The default page size for the index endpoint.
     *
     * Matches the `LoanRepository::DEFAULT_PER_PAGE` value (A.55). A
     * caller may override via the `?per_page=` query parameter; the
     * controller clamps the value to a safe range.
     */
    private const DEFAULT_PER_PAGE = 25;

    /**
     * The maximum page size a caller may request.
     *
     * A defensive ceiling against a caller who requests an absurd page
     * size. The repository does not enforce a cap (see A.55 §"Deployment
     * Notes" item 5); the controller enforces it at the HTTP boundary.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Constructor.
     *
     * Seven dependencies:
     *
     *   - `LoanRepository`             — the tenant-scoped loan lookup.
     *   - `AuthorizationService`       — the scope-resolution check for
     *                                    the index endpoint.
     *   - `LoanApplicationService`     — the store delegation target.
     *   - `LoanApprovalService`        — the approve/reject delegation
     *                                    target.
     *   - `LoanDisbursementService`    — the disburse delegation target.
     *   - `LoanRestructuringService`   — the restructure delegation
     *                                    target.
     *   - `LoanWriteOffService`        — the write-off delegation target.
     *
     * The container resolves each dependency by reflection; no explicit
     * binding is required beyond the provider registrations in A.85.
     *
     * @param LoanRepository           $loanRepository
     * @param AuthorizationService     $authorizationService
     * @param LoanApplicationService   $applicationService
     * @param LoanApprovalService      $approvalService
     * @param LoanDisbursementService  $disbursementService
     * @param LoanRestructuringService $restructuringService
     * @param LoanWriteOffService      $writeOffService
     */
    public function __construct(
        private readonly LoanRepository $loanRepository,
        private readonly AuthorizationService $authorizationService,
        private readonly LoanApplicationService $applicationService,
        private readonly LoanApprovalService $approvalService,
        private readonly LoanDisbursementService $disbursementService,
        private readonly LoanRestructuringService $restructuringService,
        private readonly LoanWriteOffService $writeOffService,
    ) {
    }

    // =========================================================================
    // Public API — index
    // =========================================================================

    /**
     * List loans in the authenticated tenant.
     *
     * The response set is scope-narrowed by the caller's permissions; see
     * the class docblock §"The index endpoint's scope resolution".
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        Gate::authorize('viewAny', Loan::class);

        $perPage = $this->resolvePerPage($request);
        $filters = $this->resolveIndexFilters($request);

        $paginator = $this->authorizationService->hasPermission(
            permissionSlug: 'loan.view',
            user:           $request->user(),
            tenantId:       $tenantId,
        )
            ? $this->loanRepository->paginateForTenant($tenantId, $filters, $perPage)
            : $this->paginateForCallerAsBorrower($request, $tenantId, $filters, $perPage);

        return $this->respondWithLoans($request, $paginator);
    }

    // =========================================================================
    // Public API — store
    // =========================================================================

    /**
     * Create a new loan in the `draft` state.
     *
     * @param StoreLoanRequest $request
     * @return JsonResponse
     */
    public function store(StoreLoanRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        // ---------------------------------------------------------------------
        // Authorization: the permission-only `loan.apply` ability.
        //
        // The loan does not exist yet, so no policy instance is available;
        // the ability is dispatched as a raw string against the platform's
        // global permission gate. See the class docblock §"The store
        // endpoint's authorization".
        // ---------------------------------------------------------------------
        Gate::authorize('loan.apply');

        $loan = $this->applicationService->createDraft(
            tenantId:               $tenantId,
            borrowerShareholderId:  (int) $request->validated('borrower_shareholder_id'),
            loanProductId:          (int) $request->validated('loan_product_id'),
            principalAmount:        (string) $request->validated('principal_amount'),
            termMonths:             (int) $request->validated('term_months'),
            purpose:                $request->validated('purpose'),
            externalReference:      $request->validated('external_reference'),
            actorUserId:            $request->user()?->getKey(),
        );

        return $this->respondWithLoan($request, $loan, 201);
    }

    // =========================================================================
    // Public API — show
    // =========================================================================

    /**
     * Show a specific loan.
     *
     * @param Request $request
     * @param int     $loanId
     * @return JsonResponse
     */
    public function show(Request $request, int $loanId): JsonResponse
    {
        $loan = $this->resolveLoan($loanId);

        Gate::authorize('view', $loan);

        $loan->loadMissing([
            'borrower',
            'product',
            'policyVersion',
            'installments',
            'collaterals',
            'restructurings',
            'writeOff',
            'statusHistories',
        ]);

        return $this->respondWithLoan($request, $loan);
    }

    // =========================================================================
    // Public API — approve
    // =========================================================================

    /**
     * Record an approval decision on a loan.
     *
     * @param ApproveLoanRequest $request
     * @param int                $loanId
     * @return JsonResponse
     */
    public function approve(ApproveLoanRequest $request, int $loanId): JsonResponse
    {
        $loan = $this->resolveLoan($loanId);

        Gate::authorize('approve', $loan);

        $loan = $this->approvalService->approve(
            loan:        $loan,
            reason:      $request->validated('reason'),
            actorUserId: $request->user()?->getKey(),
        );

        return $this->respondWithLoan($request, $loan);
    }

    // =========================================================================
    // Public API — reject
    // =========================================================================

    /**
     * Record a rejection decision on a loan.
     *
     * @param RejectLoanRequest $request
     * @param int               $loanId
     * @return JsonResponse
     */
    public function reject(RejectLoanRequest $request, int $loanId): JsonResponse
    {
        $loan = $this->resolveLoan($loanId);

        Gate::authorize('reject', $loan);

        $loan = $this->approvalService->reject(
            loan:        $loan,
            reason:      (string) $request->validated('reason'),
            actorUserId: $request->user()?->getKey(),
        );

        return $this->respondWithLoan($request, $loan);
    }

    // =========================================================================
    // Public API — disburse
    // =========================================================================

    /**
     * Disburse an approved loan.
     *
     * @param DisburseLoanRequest $request
     * @param int                 $loanId
     * @return JsonResponse
     */
    public function disburse(DisburseLoanRequest $request, int $loanId): JsonResponse
    {
        $loan = $this->resolveLoan($loanId);

        Gate::authorize('disburse', $loan);

        $loan = $this->disbursementService->disburse(
            loan:        $loan,
            description: $request->validated('description'),
            actorUserId: $request->user()?->getKey(),
        );

        return $this->respondWithLoan($request, $loan);
    }

    // =========================================================================
    // Public API — restructure
    // =========================================================================

    /**
     * Restructure a loan.
     *
     * @param StoreRestructureRequest $request
     * @param int                     $loanId
     * @return JsonResponse
     */
    public function restructure(StoreRestructureRequest $request, int $loanId): JsonResponse
    {
        $loan = $this->resolveLoan($loanId);

        Gate::authorize('restructure', $loan);

        $loan = $this->restructuringService->restructure(
            loan:                 $loan,
            newPrincipalAmount:   (string) $request->validated('new_principal_amount'),
            newTermMonths:        (int) $request->validated('new_term_months'),
            newPolicyVersionId:   (int) $request->validated('new_policy_version_id'),
            reason:               (string) $request->validated('reason'),
            actorUserId:          $request->user()?->getKey(),
        );

        return $this->respondWithLoan($request, $loan);
    }

    // =========================================================================
    // Public API — writeoff
    // =========================================================================

    /**
     * Write off a loan.
     *
     * @param StoreWriteOffRequest $request
     * @param int                  $loanId
     * @return JsonResponse
     */
    public function writeoff(StoreWriteOffRequest $request, int $loanId): JsonResponse
    {
        $loan = $this->resolveLoan($loanId);

        Gate::authorize('writeoff', $loan);

        $loan = $this->writeOffService->writeOff(
            loan:        $loan,
            reason:      (string) $request->validated('reason'),
            actorUserId: $request->user()?->getKey(),
        );

        return $this->respondWithLoan($request, $loan);
    }

    // =========================================================================
    // Private — resolution
    // =========================================================================

    /**
     * Resolve the loan addressed by the route's `{loan}` parameter.
     *
     * Uses `LoanRepository::findForTenantOrFail()` (A.55), which throws
     * `ModelNotFoundException` when the loan does not exist OR belongs
     * to a different tenant. The two failure cases produce identical
     * outcomes (404 through the framework's exception renderer),
     * preserving the tenant-isolation contract (handoff §3).
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
     * The `mases_tenant_id()` helper is populated by the
     * `tenant.membership` middleware (registered in `bootstrap/app.php`,
     * applied to every Loan route via the config's middleware stack,
     * A.1). Every controller action runs AFTER the middleware, so a null
     * return here indicates a middleware-bypass defect.
     *
     * The method returns the cast value; a null or empty value throws
     * `LogicException` (a programmer defect, not a caller error).
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
                'LoanController requires a resolved tenant id. The '
                . '`tenant.membership` middleware must populate the '
                . 'request context before any Loan controller action '
                . 'runs. A missing tenant indicates a middleware-bypass '
                . 'defect or a route registered without the middleware '
                . 'stack.'
            );
        }

        return (int) $tenantId;
    }

    // =========================================================================
    // Private — index helpers
    // =========================================================================

    /**
     * Resolve the requested per-page value for the index endpoint.
     *
     * The value is clamped to the inclusive range `[1, MAX_PER_PAGE]`. A
     * missing or non-numeric value yields `DEFAULT_PER_PAGE`.
     *
     * @param Request $request
     * @return int
     */
    private function resolvePerPage(Request $request): int
    {
        $raw = $request->query('per_page');

        if (!is_numeric($raw)) {
            return self::DEFAULT_PER_PAGE;
        }

        $value = (int) $raw;

        if ($value < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        if ($value > self::MAX_PER_PAGE) {
            return self::MAX_PER_PAGE;
        }

        return $value;
    }

    /**
     * Resolve the supported filter set from the request's query string.
     *
     * Only the four keys the repository understands are extracted; any
     * other query parameter is silently ignored. See A.55 §"The
     * `$filters` parameter" for the supported keys.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    private function resolveIndexFilters(Request $request): array
    {
        $filters = [];

        if ($request->filled('status')) {
            $filters['status'] = (string) $request->query('status');
        }

        if ($request->filled('borrower_shareholder_id')) {
            $filters['borrower_shareholder_id'] = (int) $request->query('borrower_shareholder_id');
        }

        if ($request->filled('loan_product_id')) {
            $filters['loan_product_id'] = (int) $request->query('loan_product_id');
        }

        if ($request->boolean('in_arrears')) {
            $filters['in_arrears'] = true;
        }

        if ($request->boolean('active_only')) {
            $filters['active_only'] = true;
        }

        return $filters;
    }

    /**
     * Paginate the loans for which the authenticated user is the
     * borrower.
     *
     * Used by the index endpoint when the caller holds `loan.view_own`
     * but NOT `loan.view`. The caller's Shareholder id is resolved via a
     * narrow scoped query on the `Shareholder` model — the same pattern
     * `LoanPolicy::isBorrower()` (A.52) uses.
     *
     * A caller who holds `loan.view_own` but has no Shareholder record
     * in the tenant cannot be the borrower of any loan; the method
     * returns an empty paginator. This is the correct outcome: the
     * caller's permission scope is empty.
     *
     * @param Request              $request
     * @param int                  $tenantId
     * @param array<string, mixed> $filters
     * @param int                  $perPage
     * @return LengthAwarePaginator
     */
    private function paginateForCallerAsBorrower(
        Request $request,
        int $tenantId,
        array $filters,
        int $perPage,
    ): LengthAwarePaginator {
        /** @var int|string|null $shareholderId */
        $shareholderId = Shareholder::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', (int) $request->user()->getKey())
            ->value('id');

        if ($shareholderId === null) {
            // No Shareholder record in this tenant: the caller cannot
            // be the borrower of any loan, so the scope is empty. The
            // repository would produce an empty result for an
            // impossibly-negative shareholder id; we short-circuit
            // with a proper empty paginator to avoid emitting a query
            // that a reader would have to interpret.
            return $this->emptyPaginator($perPage);
        }

        $filters['borrower_shareholder_id'] = (int) $shareholderId;

        return $this->loanRepository->paginateForTenant(
            $tenantId,
            $filters,
            $perPage,
        );
    }

    /**
     * Build an empty paginator suitable for the index response.
     *
     * The paginator reports zero total results across exactly one page,
     * with the requested per-page value. This matches what the
     * repository would produce if it ran the query against an empty
     * result set.
     *
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    private function emptyPaginator(int $perPage): LengthAwarePaginator
    {
        return new \Illuminate\Pagination\LengthAwarePaginator(
            items: [],
            total: 0,
            perPage: $perPage,
            currentPage: 1,
        );
    }

    // =========================================================================
    // Private — response helpers
    // =========================================================================

    /**
     * Emit the canonical MASES envelope for a single loan.
     *
     * @param Request $request
     * @param Loan    $loan
     * @param int     $status
     * @return JsonResponse
     */
    private function respondWithLoan(
        Request $request,
        Loan $loan,
        int $status = 200,
    ): JsonResponse {
        return response()->json(
            [
                'success' => true,
                'data'    => (new LoanResource($loan))->toArray($request),
            ],
            $status,
        );
    }

    /**
     * Emit the canonical MASES envelope for a paginated list of loans.
     *
     * The `data` key carries the array of loan payloads; the `meta`
     * key carries the pagination metadata.
     *
     * @param Request              $request
     * @param LengthAwarePaginator $paginator
     * @return JsonResponse
     */
    private function respondWithLoans(
        Request $request,
        LengthAwarePaginator $paginator,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data'    => LoanResource::collection($paginator->items())->toArray($request),
            'meta'    => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                    'last_page'    => $paginator->lastPage(),
                    'from'         => $paginator->firstItem(),
                    'to'           => $paginator->lastItem(),
                ],
            ],
        ]);
    }
}