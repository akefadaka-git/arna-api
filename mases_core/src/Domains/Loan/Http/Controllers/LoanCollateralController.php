<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Controllers;

use Gate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Domains\Loan\Enums\CollateralType;
use MaaleArna\MASES\Core\Domains\Loan\Http\Requests\ReleaseCollateralRequest;
use MaaleArna\MASES\Core\Domains\Loan\Http\Requests\StoreCollateralRequest;
use MaaleArna\MASES\Core\Domains\Loan\Http\Resources\LoanCollateralResource;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanCollateral;
use MaaleArna\MASES\Core\Domains\Loan\Repositories\LoanCollateralRepository;
use MaaleArna\MASES\Core\Domains\Loan\Repositories\LoanRepository;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanCollateralService;

/**
 * LoanCollateralController
 *
 * The HTTP surface for a loan's collateral records. Owns three actions:
 *
 *   index()    GET  /loans/{loan}/collateral                            — list
 *   store()    POST /loans/{loan}/collateral                            — register
 *   release()  POST /loans/{loan}/collateral/{collateral}/release       — release
 *
 * =============================================================================
 * The three-action scope
 * =============================================================================
 * The three actions correspond to the three collateral endpoints in the
 * frozen Stage 6 §4.16 HTTP surface. No other collateral endpoint exists
 * in Phase 2C: there is no `show`, no `update`, no `delete`. A future CR
 * that introduces a `GET /loans/{loan}/collateral/{collateral}` show
 * endpoint extends this controller at that time.
 *
 * =============================================================================
 * The three-authorization-ability map
 * =============================================================================
 * Each action dispatches a distinct authorization ability, and — for the
 * two mutating actions — a distinct TARGET:
 *
 *   index()    → Gate::authorize('view', $loan)
 *                Ability lives on LoanPolicy (A.52), dispatched against
 *                the LOAN, not the collateral. The list of collaterals
 *                is a projection of the loan; a caller authorized to
 *                view the loan is authorized to view its collateral
 *                list. See A.54 §"The three abilities" for the design
 *                rationale.
 *
 *   store()    → Gate::authorize('manage', $transient)
 *                Ability lives on LoanCollateralPolicy (A.54),
 *                dispatched against a TRANSIENT `LoanCollateral` whose
 *                only populated column is `loan_id`. See §"The
 *                transient-instance dispatch".
 *
 *   release()  → Gate::authorize('release', $collateral)
 *                Ability lives on LoanCollateralPolicy (A.54),
 *                dispatched against the resolved collateral. The
 *                policy delegates its viewability check through
 *                LoanPolicy::view() on the parent loan.
 *
 * The three abilities require three DIFFERENT permission slugs in the
 * RBAC matrix (B7): `loan.view` / `loan.view_own` for index;
 * `loan.collateral.manage` for store; `loan.collateral.release` for
 * release. A caller may hold one and not the others; the actions are
 * independent.
 *
 * =============================================================================
 * The transient-instance dispatch
 * =============================================================================
 * `store()` dispatches `manage` against a transient `LoanCollateral`
 * instance:
 *
 *     $transient = new LoanCollateral(['loan_id' => $loan->id]);
 *     Gate::authorize('manage', $transient);
 *
 * The transient is never persisted. Its sole purpose is to satisfy
 * Laravel's policy resolution — which keys the `LoanCollateral` class to
 * `LoanCollateralPolicy` — while carrying the `loan_id` the policy needs
 * for its tenant and loan-viewability checks.
 *
 * The policy's `manage()` method loads the loan relation on demand
 * (`$collateral->loadMissing('loan')`), so the transient instance does
 * not need a pre-loaded `loan` relation. See A.54 §"The
 * transient-instance dispatch pattern" for the full rationale.
 *
 * The alternative dispatch form — `Gate::authorize('manage',
 * [LoanCollateral::class, $loan])` — does NOT work with Laravel's
 * standard Gate resolution: it would pass three arguments to the policy
 * method (`$user, LoanCollateral::class, $loan`) rather than two. The
 * transient-instance form is the correct idiom.
 *
 * =============================================================================
 * The route-bound `{collateral}` resolution
 * =============================================================================
 * The `release()` action resolves the route-bound `{collateral}` via
 * `LoanCollateralRepository::findForLoan()` (A.58), scoped to the
 * already-resolved loan. A non-existent collateral id — or one that
 * belongs to a DIFFERENT loan — produces a `ModelNotFoundException`
 * (rendered as 404 by the framework's exception renderer), preserving
 * the tenant-isolation contract documented in the handoff §3.
 *
 * The controller does NOT pass a raw route parameter directly to the
 * service; it resolves the collateral first, so the service always
 * receives a validated instance.
 *
 * =============================================================================
 * The response envelope
 * =============================================================================
 * Every action returns the canonical MASES envelope:
 *
 *     { "success": true, "data": {...} }   // for store and release
 *     { "success": true, "data": [...] }   // for index
 *
 * The `data` payload is produced by `LoanCollateralResource` (A.72),
 * whose `$wrap = null` setting prevents Laravel's own `data` wrapper
 * from double-wrapping the payload inside the controller's envelope.
 *
 * =============================================================================
 * What this controller deliberately does NOT do
 * =============================================================================
 *   - It does NOT authorize via the `LoanPolicy` for store or release.
 *     Those abilities live on `LoanCollateralPolicy`; the policy
 *     delegates its viewability check through `LoanPolicy::view()`
 *     internally.
 *
 *   - It does NOT authorize via the collateral policy for index. The
 *     index ability lives on the parent loan.
 *
 *   - It does NOT catch exceptions. Every exception propagates to the
 *     framework's exception renderer.
 *
 *   - It does NOT open transactions. Every mutating service opens its
 *     own transaction as the top-level entry point.
 *
 *   - It does NOT contain domain logic. The service (A.37) enforces
 *     every rule about pledge linkage, valuation, product policy, and
 *     loan state.
 *
 *   - It does NOT accept a `?include=` query parameter or any filter.
 *     The index returns every collateral of the loan.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A10 (collateral rules),
 *                                                  B4 (collateral
 *                                                  integration), B7
 *                                                  (RBAC)
 *   Phase 2C Clarification Response, 2026-09-12  — C2 (release endpoint),
 *                                                  C3 (Loan-side pledge
 *                                                  sequencing)
 *   Phase 2C A.4                                 — `CollateralType`
 *   Phase 2C A.20                                — `LoanCollateral`
 *   Phase 2C A.37                                — `LoanCollateralService`
 *   Phase 2C A.52                                — `LoanPolicy`
 *   Phase 2C A.54                                — `LoanCollateralPolicy`
 *   Phase 2C A.55                                — `LoanRepository`
 *   Phase 2C A.58                                — `LoanCollateralRepository`
 *   Phase 2C A.67                                — `StoreCollateralRequest`
 *   Phase 2C A.68                                — `ReleaseCollateralRequest`
 *   Phase 2C A.72                                — `LoanCollateralResource`
 *   Phase 2C A.77                                — `LoanController` (the
 *                                                  shape precedent)
 *   Phase 2C A.84                                — the routes file
 *   Phase 2B 2B.24                               — `WalletController` (the
 *                                                  sibling controller
 *                                                  with sub-resource
 *                                                  actions)
 *   CR-2C-COLLATERAL-PLEDGE-FK-001               — deferred FK
 *   Step 5 (manifest) A.79                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Controllers
 * @version 1.0.0
 */
final class LoanCollateralController
{
    /**
     * Constructor.
     *
     * Three dependencies:
     *
     *   - `LoanRepository`             — the tenant-scoped loan lookup.
     *   - `LoanCollateralRepository`   — the loan-scoped collateral
     *                                    lookup.
     *   - `LoanCollateralService`      — the register/release delegation
     *                                    target.
     *
     * @param LoanRepository            $loanRepository
     * @param LoanCollateralRepository  $collateralRepository
     * @param LoanCollateralService     $collateralService
     */
    public function __construct(
        private readonly LoanRepository $loanRepository,
        private readonly LoanCollateralRepository $collateralRepository,
        private readonly LoanCollateralService $collateralService,
    ) {
    }

    // =========================================================================
    // Public API — index
    // =========================================================================

    /**
     * List the collateral records of a loan.
     *
     * The response set includes BOTH `registered` and `released`
     * collaterals — the full security history of the loan. A caller
     * that needs only the currently-held collaterals filters by status
     * on their side.
     *
     * @param Request $request
     * @param int     $loanId
     * @return JsonResponse
     */
    public function index(Request $request, int $loanId): JsonResponse
    {
        $loan = $this->resolveLoan($loanId);

        Gate::authorize('view', $loan);

        $collaterals = $this->collateralRepository->listForLoan(
            loanId: $loan->id,
            with:   ['pledgedShareHolding', 'registeredBy', 'releasedBy'],
        );

        return response()->json([
            'success' => true,
            'data'    => LoanCollateralResource::collection($collaterals)->toArray($request),
        ]);
    }

    // =========================================================================
    // Public API — store
    // =========================================================================

    /**
     * Register a new collateral against a loan.
     *
     * The authorization dispatch uses a TRANSIENT `LoanCollateral`
     * instance whose only populated column is `loan_id`. See the class
     * docblock §"The transient-instance dispatch".
     *
     * @param StoreCollateralRequest $request
     * @param int                    $loanId
     * @return JsonResponse
     */
    public function store(StoreCollateralRequest $request, int $loanId): JsonResponse
    {
        $loan = $this->resolveLoan($loanId);

        // ---------------------------------------------------------------------
        // Authorization: dispatch `manage` against a TRANSIENT collateral.
        //
        // The collateral does not exist yet; the transient instance
        // carries only the parent loan's id so the policy can resolve
        // the loan (via its `loadMissing('loan')` call) and perform its
        // permission + loan-viewability checks.
        // ---------------------------------------------------------------------
        $transient = new LoanCollateral(['loan_id' => $loan->id]);

        Gate::authorize('manage', $transient);

        $validated = $request->validated();

        $pledgedShareholdingId = $validated['pledged_shareholding_id'] ?? null;

        $collateral = $this->collateralService->register(
            loan:                   $loan,
            collateralType:         CollateralType::from((string) $validated['collateral_type']),
            description:            (string) $validated['description'],
            externalReference:      $validated['external_reference'] ?? null,
            pledgedShareholdingId:  $pledgedShareholdingId !== null
                ? (int) $pledgedShareholdingId
                : null,
            pledgedQuantity:        $validated['pledged_quantity'] ?? null,
            valuationAmount:        $validated['valuation_amount'] ?? null,
            valuationCurrency:      $validated['valuation_currency'] ?? null,
            valuationDate:          $validated['valuation_date'] ?? null,
            actorUserId:            $request->user()?->getKey(),
        );

        // Refresh with the resource's eager-load set for the response.
        $collateral->loadMissing(['pledgedShareHolding', 'registeredBy', 'releasedBy']);

        return $this->respondWithCollateral($request, $collateral, 201);
    }

    // =========================================================================
    // Public API — release
    // =========================================================================

    /**
     * Release a collateral.
     *
     * @param ReleaseCollateralRequest $request
     * @param int                      $loanId
     * @param int                      $collateralId
     * @return JsonResponse
     */
    public function release(
        ReleaseCollateralRequest $request,
        int $loanId,
        int $collateralId,
    ): JsonResponse {
        $loan = $this->resolveLoan($loanId);

        $collateral = $this->resolveCollateral($loan, $collateralId);

        Gate::authorize('release', $collateral);

        $collateral = $this->collateralService->release(
            loan:        $loan,
            collateral:  $collateral,
            reason:      (string) $request->validated('reason'),
            actorUserId: $request->user()?->getKey(),
        );

        $collateral->loadMissing(['pledgedShareHolding', 'registeredBy', 'releasedBy']);

        return $this->respondWithCollateral($request, $collateral);
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
     * Resolve the collateral addressed by the route's `{collateral}`
     * parameter, scoped to the given loan.
     *
     * A collateral that does not exist, or that belongs to a DIFFERENT
     * loan, produces a `ModelNotFoundException` (rendered as 404). See
     * the class docblock §"The route-bound `{collateral}` resolution".
     *
     * @param Loan $loan
     * @param int  $collateralId
     * @return LoanCollateral
     */
    private function resolveCollateral(Loan $loan, int $collateralId): LoanCollateral
    {
        $collateral = $this->collateralRepository->findForLoan(
            loanId:        $loan->id,
            collateralId:  $collateralId,
        );

        if ($collateral === null) {
            throw (new ModelNotFoundException())
                ->setModel(LoanCollateral::class, [$collateralId]);
        }

        return $collateral;
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
                'LoanCollateralController requires a resolved tenant id. '
                . 'The `tenant.membership` middleware must populate the '
                . 'request context before any Loan collateral action '
                . 'runs. A missing tenant indicates a middleware-bypass '
                . 'defect or a route registered without the middleware '
                . 'stack.'
            );
        }

        return (int) $tenantId;
    }

    // =========================================================================
    // Private — response helpers
    // =========================================================================

    /**
     * Emit the canonical MASES envelope for a single collateral.
     *
     * @param Request         $request
     * @param LoanCollateral  $collateral
     * @param int             $status
     * @return JsonResponse
     */
    private function respondWithCollateral(
        Request $request,
        LoanCollateral $collateral,
        int $status = 200,
    ): JsonResponse {
        return response()->json(
            [
                'success' => true,
                'data'    => (new LoanCollateralResource($collateral))->toArray($request),
            ],
            $status,
        );
    }
}