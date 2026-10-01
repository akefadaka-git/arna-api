<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\WalletRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Services\StatementService;

/**
 * StatementController
 *
 * HTTP boundary for the wallet statement projection.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * One operation, mapped by the routes file (2B.48) per Step 7 v1.1.0 §C.1:
 *
 *     GET /wallets/{wallet}/statement  → show()  generate statement
 *
 * The controller deliberately exposes ONLY this one action. There is no
 * `index()`, `store()`, `update()`, or `destroy()`:
 *
 *   - A statement is not a persisted resource. It is a projection of the
 *     ledger computed on demand (D113, Architecture §11.1). There is no
 *     list of statements to index, no creation action (the projection is
 *     produced by the read), no update (nothing is stored), and no
 *     deletion (nothing to delete).
 *
 *   - The action is named `show()` (not `store()` or `generate()`) because
 *     the endpoint is a GET on a sub-resource of the wallet. The method
 *     name matches the HTTP semantics: the caller "shows" the statement
 *     for a period. The fact that the computation runs on demand is an
 *     implementation detail not visible to the caller.
 *
 * =============================================================================
 * Authorization — policy-only, no route middleware (Step 7 v1.1.0 §C.1.1)
 * =============================================================================
 * This is one of only two routes in the Wallet domain with NO route-level
 * permission middleware. The other is `show()` on the wallet itself
 * (2B.26, route #3).
 *
 * The reasoning (Step 7 v1.1.0 §C.1.1):
 *
 *   - The statement has TWO permission paths: broad
 *     (`wallet.statement.view`) and own-only
 *     (`wallet.statement.view_own`).
 *
 *   - A route-level `wallet.statement.view` middleware would reject a
 *     manager who holds ONLY `wallet.statement.view_own` — locking them
 *     out before the policy can evaluate ownership.
 *
 *   - The endpoint must therefore delegate BOTH paths to the policy
 *     layer, which grants via the OR: broad permission, OR
 *     own-only permission combined with wallet ownership.
 *
 * The controller's `Gate::authorize('viewStatement', $wallet)` call is the
 * sole authorization gate. `WalletPolicy::viewStatement()` (2B.37) is the
 * decision point.
 *
 * The analogous route for the wallet itself (`show`, 2B.26 route #3) uses
 * the same pattern with the `view` ability and `WalletPolicy::view()`.
 *
 * =============================================================================
 * Tenant scoping (Step 7 v1.1.0 §C.6)
 * =============================================================================
 * The route-bound `{wallet}` parameter is resolved through
 * `WalletRepository::findForTenant($tenantId, $walletId)` (2B.11) — NOT
 * through implicit route-model binding. This mirrors the pattern
 * established by every other controller in the Wallet domain
 * (2B.26 §"Tenant scoping", 2B.27, 2B.28, 2B.29).
 *
 * A cross-tenant wallet id yields `null`; the controller translates
 * `null` into the same 404 a non-existent id would produce, via
 * `ModelNotFoundException`. The caller cannot distinguish "the wallet
 * does not exist" from "the wallet exists but belongs to another tenant".
 *
 * Step 7 v1.1.0 §C.3 test method T12
 * (`test_statement_returns_404_for_cross_tenant_wallet`) exercises this
 * vector and relies on the repository's `null` return for its 404.
 *
 * =============================================================================
 * Period parameters
 * =============================================================================
 * The statement period is expressed via TWO query parameters:
 *
 *     ?from=<ISO 8601>  — required
 *     ?to=<ISO 8601>    — required
 *
 * The period follows the half-open convention `[from, to)` documented in
 * the service (2B.19 §"Period semantics"). An entry is included when
 * `occurred_at >= from` AND `occurred_at < to`.
 *
 * Both parameters are validated inline in the controller rather than
 * through a dedicated FormRequest:
 *
 *   - No `ShowStatementRequest` exists in the 2B manifest. Adding one
 *     would expand the manifest without proportionate benefit — the
 *     validation is two `date` rules and one ordering check.
 *
 *   - The pattern of inline validation for simple GET query parameters
 *     is established by 2A's `CertificateController::cancel()` (2A.24),
 *     which validates an optional `reason` inline rather than through a
 *     FormRequest.
 *
 *   - If the parameters grow (e.g. a `granularity` field, a `group_by`
 *     field, or a pagination cursor), a future CR promotes the inline
 *     validation to a FormRequest. This is documented on the `show()`
 *     method.
 *
 * The validation rules:
 *
 *     from  — required, date
 *     to    — required, date
 *
 * Plus one cross-field check: `from < to` strictly (the half-open
 * convention makes a same-instant period degenerate). The check is
 * enforced by the service (2B.19 §"assertPeriodIsWellFormed"), but the
 * controller reproduces it to produce a friendly 422 before the service
 * is invoked.
 *
 * =============================================================================
 * Response envelope
 * =============================================================================
 * The controller returns 200 with the canonical MASES envelope:
 *
 *     { "success": true, "data": <statement projection> }
 *
 * The `data` payload is the plain array produced by
 * `StatementService::generate()` (2B.19 §"generate"). No Resource class
 * wraps it — the projection is already a value object with a stable,
 * documented schema; a `StatementResource` would be a pass-through with
 * no transformation to perform. This is the design decision recorded in
 * Step 5 §7 item 3.
 *
 * The statement's schema is frozen in the service's docblock. Any change
 * is a CR.
 *
 * =============================================================================
 * No persistence, no event, no transaction
 * =============================================================================
 * Consistent with the service (2B.19 §"Read-only service"):
 *
 *   - No database transaction is opened by the controller or by the
 *     service.
 *
 *   - No rows are written by the controller or by the service.
 *
 *   - No domain event is dispatched. The statement is a read; it does
 *     not produce a state change.
 *
 *   - No materialized statement is stored. The projection is computed
 *     fresh on every request (Architecture §11.1, §11.5).
 *
 * =============================================================================
 * What this controller deliberately does NOT do
 * =============================================================================
 *   - It does not persist the statement. There is no `wallet_statements`
 *     table in 2B (Architecture §11.5).
 *
 *   - It does not paginate the period's entries. A wallet's entry count
 *     in a scoped period is bounded by its activity; a caller that needs
 *     a bounded window requests a shorter period. See 2B.19 §"What this
 *     service deliberately does NOT do".
 *
 *   - It does not aggregate by day, week, or month. The projection
 *     returns the raw entries and the two period totals; any grouping is
 *     the consumer's concern.
 *
 *   - It does not accept a `granularity`, `group_by`, `format`, or
 *     `currency` parameter. Each is either deferred to a future CR or
 *     fixed by the wallet's immutable currency (Q8).
 *
 *   - It does not evaluate interest, allocation, or KYC policies. The
 *     statement is a pure ledger projection (Architecture §11.2).
 *
 *   - It does not write to the audit trail. Statement generation is a
 *     read; the audit event surface of the Wallet domain (2B.40–2B.46)
 *     covers state-changing operations only.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D113                         — Statement model
 *   Architecture v1.2.0 §4.6    — Balance projection invariant
 *   Architecture v1.2.0 §11     — Statement projection boundary
 *   Architecture v1.2.0 §11.1   — Projection, not materialization
 *   Architecture v1.2.0 §11.2   — Content (per D113)
 *   Architecture v1.2.0 §11.3   — Historical immutability
 *   Architecture v1.2.0 §11.4   — Debit / credit column definitions
 *   Architecture v1.2.0 §11.5   — Materialization deferred
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §13     — RBAC integration
 *   Architecture v1.2.0 §16.2   — Services and their dependencies
 *   Step 5 (manifest) 2B.30     — This file
 *   Step 5 §7 item 3            — No dedicated StatementResource
 *   Step 7 v1.1.0 §A.1.1        — Permission slugs (wallet.statement.view)
 *   Step 7 v1.1.0 §A.2          — Role bindings for statement permissions
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (route #9)
 *   Step 7 v1.1.0 §C.1.1        — Statement policy-only authorization (S7-A)
 *   Step 7 v1.1.0 §C.3          — Cross-tenant test matrix (T12)
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *   §2.5 (conventions)          — Controller conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers
 * @version 1.0.0
 */
final class StatementController extends Controller
{
    /**
     * Constructor.
     *
     * Two dependencies:
     *
     *     StatementService   — the read-only projection producer.
     *     WalletRepository   — tenant-scoped wallet resolution.
     *
     * No ledger repository is injected: the service reads `WalletEntry`
     * rows directly through the model (2B.19 §"Dependencies").
     *
     * @param StatementService $statementService
     * @param WalletRepository $walletRepository
     */
    public function __construct(
        private readonly StatementService $statementService,
        private readonly WalletRepository $walletRepository,
    ) {
    }

    // =========================================================================
    // show — generate a statement
    // =========================================================================

    /**
     * Generate a statement projection for a wallet and a period.
     *
     * The route-bound `{wallet}` is resolved through the tenant-scoped
     * repository, then the controller gates on `viewStatement` of the
     * resolved wallet, then delegates to `StatementService::generate()`
     * (2B.19), which computes:
     *
     *   - The opening balance (the `balance_after` of the latest entry
     *     strictly before `from`, or zero if none).
     *   - The period entries, with per-entry debit / credit split.
     *   - The debit and credit totals for the period.
     *   - The closing balance (the last entry's `balance_after`, or the
     *     opening balance if the period is empty).
     *   - The provenance block, including the reproduction key
     *     `ledger_max_entry_id`.
     *
     * The statement is NOT persisted. See the class docblock
     * §"No persistence, no event, no transaction".
     *
     * The period is expressed via two required query parameters
     * (`from`, `to`). See the class docblock §"Period parameters".
     *
     * No dedicated FormRequest is used for the query parameters — the
     * validation is inline. See the class docblock §"Period parameters"
     * for the rationale and for the future-CR path if the parameters grow.
     *
     * @param Request $request
     * @param string  $wallet    Route parameter — resolved manually.
     * @return JsonResponse
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function show(Request $request, string $wallet): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $walletModel = $this->resolveWalletOrFail($tenantId, $wallet);

        // ---------------------------------------------------------------------
        // Policy-only authorization (S7-A). No route middleware — a manager
        // holding only `wallet.statement.view_own` must be able to reach
        // this endpoint, and the policy resolves ownership. See the class
        // docblock §"Authorization — policy-only".
        // ---------------------------------------------------------------------
        Gate::authorize('viewStatement', $walletModel);

        // ---------------------------------------------------------------------
        // Inline validation of the query parameters. Laravel's
        // `$request->validate()` throws ValidationException on failure;
        // the canonical error pipeline renders it as a 422.
        // ---------------------------------------------------------------------
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date'],
        ]);

        $fromAt = Carbon::parse((string) $validated['from']);
        $toAt   = Carbon::parse((string) $validated['to']);

        // ---------------------------------------------------------------------
        // Cross-field check: the period must be non-degenerate. The service
        // (2B.19 §"assertPeriodIsWellFormed") enforces the same rule; the
        // controller reproduces it to produce a friendly 422 before the
        // service is invoked.
        // ---------------------------------------------------------------------
        if (!$fromAt->lessThan($toAt)) {
            throw ValidationException::withMessages([
                'period' => [
                    'The `from` parameter must lie strictly before the `to` '
                    . 'parameter. The period is interpreted as [from, to).',
                ],
            ]);
        }

        $actorId = $request->user()?->id;

        $statement = $this->statementService->generate(
            wallet: $walletModel,
            from: $fromAt,
            to: $toAt,
            generatedBy: $actorId !== null ? (int) $actorId : null,
        );

        // ---------------------------------------------------------------------
        // No Resource wrapper. The service's return is a plain array with
        // a stable schema (2B.19 §"generate"); a pass-through Resource
        // would add a class without performing a transformation.
        // ---------------------------------------------------------------------
        return response()->json([
            'success' => true,
            'data'    => $statement,
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the tenant id from the server-side request context.
     *
     * The value comes from `mases_tenant_id()` — never from client input.
     * The `tenant.membership` middleware has already verified the caller's
     * membership before this controller runs. The defensive check exists
     * so that a misconfigured route (missing `tenant.membership`) fails
     * with a clear 422 rather than producing a query scoped by
     * `tenant_id = null`.
     *
     * This helper is identical to the ones in WalletController (2B.26),
     * ObligationController (2B.27), PaymentController (2B.28), and
     * AllocationController (2B.29). Duplication across sibling controllers
     * is deliberate: extracting it to a trait or base class would couple
     * the five controllers through a shared abstraction whose only shared
     * content is four lines of code. This is the fifth and final
     * occurrence of the pattern in Phase 2B; a sixth would justify
     * extraction.
     *
     * @return int
     *
     * @throws ValidationException
     */
    private function resolveTenantId(): int
    {
        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;

        if ($tenantId === null) {
            throw ValidationException::withMessages([
                'tenant' => [
                    'No tenant context is available for this request. '
                    . 'This route must be protected by the tenant resolution middleware.',
                ],
            ]);
        }

        return (int) $tenantId;
    }

    /**
     * Resolve the route-bound wallet under the tenant scope.
     *
     * See the class docblock §"Tenant scoping" for the rationale on
     * resolving through the repository rather than through implicit
     * route-model binding.
     *
     * @param int    $tenantId
     * @param string $walletId
     * @return Wallet
     *
     * @throws ModelNotFoundException
     */
    private function resolveWalletOrFail(int $tenantId, string $walletId): Wallet
    {
        $walletId = (int) $walletId;

        $wallet = $this->walletRepository->findForTenant($tenantId, $walletId);

        if ($wallet === null) {
            throw (new ModelNotFoundException())->setModel(
                Wallet::class,
                [$walletId],
            );
        }

        return $wallet;
    }
}