<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Http\Requests\StoreObligationRequest;
use MaaleArna\MASES\Core\Domains\Wallet\Http\Resources\ObligationResource;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\WalletRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Services\ObligationService;

/**
 * ObligationController
 *
 * HTTP boundary for the `Obligation` scoped business aggregate.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * One operation, mapped by the routes file (2B.48) per Step 7 v1.1.0 §C.1:
 *
 *     POST /wallets/{wallet}/obligations  → store()  record an obligation
 *
 * The controller deliberately exposes NO `index()`, `show()`, `update()`,
 * or `destroy()` action. The reasons, in order:
 *
 *   - `index()` — obligations are embedded under the parent wallet's
 *     `show` response via `WalletResource::whenLoaded()`. There is no
 *     separate list endpoint (Step 7 v1.1.0 §A.4, F-2B-LIST-1 resolution).
 *     A caller that needs a wallet's obligations performs a single `GET
 *     /wallets/{wallet}` and reads the `obligations` key.
 *
 *   - `show()` — the obligation is a sub-resource of the wallet. Its
 *     canonical address is the wallet's URL. A dedicated show endpoint
 *     would duplicate the wallet's addressing scheme without adding
 *     information.
 *
 *   - `update()` — obligations are immutable in their economic facts
 *     (component, currency, original_amount, occurred_at), enforced at
 *     the model level (2B.6 §"Immutability") and by the absence of any
 *     service update method (2B.16). Their only mutable field —
 *     `outstanding_amount` — is a projection updated by AllocationService
 *     (2B.18) as a side effect of recording allocations, never through a
 *     direct obligation-update endpoint.
 *
 *   - `destroy()` — financial records are not deletable through HTTP
 *     (Q6, Architecture §17.3).
 *
 * A single write action is therefore the entire HTTP surface for this
 * aggregate.
 *
 * =============================================================================
 * Authorization layering (Step 7 v1.1.0 §C.1, route #5)
 * =============================================================================
 * Two layers cooperate:
 *
 *   - Route middleware (`auth.permission:wallet.obligation.create`) runs
 *     before the controller is instantiated. It enforces the required
 *     permission and rejects with a 403 `AUTH_PERMISSION_DENIED` if the
 *     caller lacks it.
 *
 *   - The controller calls `Gate::authorize('view', $wallet)` on the
 *     target wallet. This is the "view-ability gate on the wallet"
 *     mandated by Step 7 v1.1.0 §C.1 for route #5. It ensures that a
 *     caller who holds `wallet.obligation.create` cannot record an
 *     obligation against a wallet they are not otherwise entitled to
 *     view.
 *
 * The two layers are independent: the route middleware gates the
 * permission; the policy gate ensures the caller's relationship to the
 * target wallet is legitimate. Both are required.
 *
 * =============================================================================
 * Tenant scoping (Step 7 v1.1.0 §C.6)
 * =============================================================================
 * The route-bound `{wallet}` parameter is resolved through
 * `WalletRepository::findForTenant($tenantId, $walletId)` (2B.11) — NOT
 * through implicit route-model binding. This mirrors the pattern
 * established by WalletController (2B.26 §"Tenant scoping") and honours
 * the architecture §12 mandate that every controller resolves the wallet
 * through the repository.
 *
 * A cross-tenant wallet id yields `null`; the controller translates
 * `null` into the same 404 a non-existent id would produce, via
 * `ModelNotFoundException`. The caller cannot distinguish "the wallet
 * does not exist" from "the wallet exists but belongs to another tenant".
 *
 * The `shareholder_id` and other cross-entity references in the request
 * payload are resolved by the service (2B.16) under the same tenant
 * scope; this controller performs only the wallet resolution and the
 * policy gate.
 *
 * Step 7 v1.1.0 §C.3 test method T5 (`test_obligation_store_returns_404_for_cross_tenant_wallet`)
 * exercises this vector and relies on the repository's `null` return for
 * its 404.
 *
 * =============================================================================
 * The `component` field — closure of the enum vocabulary
 * =============================================================================
 * The request (2B.22) validates `component` with `Rule::enum(ObligationComponent::class)`,
 * which accepts the case NAME, the case VALUE, or the case instance. By
 * the time this controller runs, the request has already rejected any
 * value outside the six-case vocabulary.
 *
 * The controller re-resolves the raw input to the enum case via
 * `resolveComponent()`. The resolution accepts both accepted forms; the
 * enum's case is what `ObligationService::record()` (2B.16) requires.
 * See the helper's docblock for the rationale.
 *
 * =============================================================================
 * Date parsing
 * =============================================================================
 * The `occurred_at` and `due_at` fields are validated as ISO 8601
 * date-times by the request (2B.22) via Laravel's `date` rule. The
 * controller parses them to `CarbonInterface` instances for the service.
 * A null input is passed through as null; the service defaults
 * `occurred_at` to `now()` and treats a null `due_at` as "no maturity".
 *
 * `Carbon::parse()` accepts any format the `date` rule accepts, so the
 * controller does not need to enumerate formats. A malformed string
 * cannot reach the controller — the request's `date` rule would have
 * already rejected it with a 422.
 *
 * =============================================================================
 * Response envelope
 * =============================================================================
 * `store()` returns 201 Created with:
 *
 *     { "success": true, "data": <ObligationResource payload> }
 *
 * The created obligation is loaded with its `createdBy` relation so the
 * resource can render the actor's display name without a second query.
 * The parent wallet is NOT re-loaded: the wallet is the URL context, not
 * the response subject.
 *
 * The ledger entry that was posted alongside the obligation is NOT part
 * of this response. It is visible via the statement endpoint (2B.30) or
 * the wallet's `entries` relation (2B.5). The obligation is the resource
 * this endpoint addresses.
 *
 * =============================================================================
 * What this controller deliberately does NOT do
 * =============================================================================
 *   - It does not accept or forward `wallet_id`, `currency`,
 *     `original_amount`, `outstanding_amount`, or `created_by`. Each is
 *     either resolved from the route, inherited from the wallet, or
 *     resolved from `auth()`. The request (2B.22 §"Fields accepted by
 *     this request") excludes them all.
 *
 *   - It does not evaluate interest or fee policies. The obligation's
 *     component is descriptive metadata in Phase 2B; no policy is read
 *     or evaluated (A8, Architecture §9).
 *
 *   - It does not write ledger entries directly. The service (2B.16)
 *     owns the transactional write path, calls
 *     `WalletService::recordEntry()` (2B.15), and acquires the wallet
 *     row lock. This controller delegates unconditionally.
 *
 *   - It does not allocate. A newly-recorded obligation is `open`
 *     (outstanding_amount = original_amount); it becomes settled only
 *     when an explicit allocation is recorded (2B.29, route #7).
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D109                         — Obligation types
 *   Architecture v1.2.0 §3.3    — Obligation entity
 *   Architecture v1.2.0 §6.1    — Obligation projection
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §13     — RBAC integration
 *   Architecture v1.2.0 §16.2   — Services and their dependencies
 *   Architecture v1.2.0 §19     — Q6 resolution (no financial cascades)
 *   Step 5 (manifest) 2B.27     — This file
 *   Step 7 v1.1.0 §A.1.1        — Permission slugs (wallet.obligation.create)
 *   Step 7 v1.1.0 §A.4          — F-2B-LIST-1 (obligations embedded)
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (route #5)
 *   Step 7 v1.1.0 §C.3          — Cross-tenant test matrix (T5)
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *   §2.5 (conventions)          — Controller conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Controllers
 * @version 1.0.0
 */
final class ObligationController extends Controller
{
    /**
     * Constructor.
     *
     * Two dependencies:
     *
     *     ObligationService    — the write path for the obligation
     *                            aggregate and its ledger entry.
     *     WalletRepository     — tenant-scoped wallet resolution.
     *
     * The service (2B.16) already owns the ledger-write dependency (via
     * `WalletService::recordEntry()`); this controller does not touch
     * `WalletService` directly.
     *
     * @param ObligationService $obligationService
     * @param WalletRepository  $walletRepository
     */
    public function __construct(
        private readonly ObligationService $obligationService,
        private readonly WalletRepository $walletRepository,
    ) {
    }

    // =========================================================================
    // store — record an obligation
    // =========================================================================

    /**
     * Record an obligation against a wallet.
     *
     * The StoreObligationRequest (2B.22) has already validated the input
     * shape: `component` (closed enum), `amount` (positive decimal,
     * non-zero), `occurred_at` (optional date), `due_at` (optional date),
     * `reference_type` and `reference_id` (optional, all-or-nothing,
     * each ≤ 64 chars), `description` (optional, ≤ 500 chars).
     *
     * The route-bound `{wallet}` is resolved through the tenant-scoped
     * repository, then the controller gates on `view` of the resolved
     * wallet, then delegates to `ObligationService::record()` (2B.16),
     * which:
     *
     *   - Creates the Obligation row with `currency` inherited from the
     *     wallet (Q8) and `outstanding_amount` initialized to equal
     *     `original_amount`.
     *   - Posts a `wallet_entries` row of type
     *     `obligation_recorded`, with a negated signed quantity
     *     (debit-side, per Architecture §4.2) and the obligation as its
     *     reference.
     *   - Updates the wallet's `current_balance` inside the same
     *     transaction, under the wallet row lock.
     *   - Dispatches `ObligationRecorded` after commit, gated on
     *     `config('wallet.audit.emit_events')`.
     *
     * The actor (`created_by`) is resolved from `auth()->id()` here. The
     * request does not accept it — see 2B.22 §"Fields accepted by this
     * request".
     *
     * @param StoreObligationRequest $request
     * @param string                 $wallet    Route parameter — resolved manually.
     * @return JsonResponse  201 Created
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function store(StoreObligationRequest $request, string $wallet): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        $walletModel = $this->resolveWalletOrFail($tenantId, $wallet);

        Gate::authorize('view', $walletModel);

        // ---------------------------------------------------------------------
        // Resolve the enum, the two optional dates, and the actor. Each of
        // these has a default shape that the service expects; the mapping
        // is documented on the helper methods and on the class docblock
        // §"The component field" and §"Date parsing".
        // ---------------------------------------------------------------------
        $component = $this->resolveComponent((string) $request->input('component'));

        $occurredAt = $this->resolveDate($request->input('occurred_at'));
        $dueAt      = $this->resolveDate($request->input('due_at'));

        $actorId = $request->user()?->id;

        $obligation = $this->obligationService->record(
            wallet: $walletModel,
            component: $component,
            amount: (string) $request->input('amount'),
            occurredAt: $occurredAt,
            dueAt: $dueAt,
            referenceType: $request->input('reference_type') !== null
                ? (string) $request->input('reference_type')
                : null,
            referenceId: $request->input('reference_id') !== null
                ? (string) $request->input('reference_id')
                : null,
            description: $request->input('description') !== null
                ? (string) $request->input('description')
                : null,
            createdBy: $actorId !== null ? (int) $actorId : null,
        );

        // ---------------------------------------------------------------------
        // Eager-load the actor relation so the resource can render the
        // display name without a second query. The wallet is not reloaded:
        // it is the URL context, not the response subject.
        // ---------------------------------------------------------------------
        $obligation->loadMissing('createdBy');

        return response()->json([
            'success' => true,
            'data'    => (new ObligationResource($obligation))->resolve(),
        ], 201);
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
     * This helper is identical to the one in WalletController (2B.26).
     * Duplication across sibling controllers is deliberate: extracting it
     * to a trait or base class would couple the two controllers through a
     * shared abstraction whose only shared content is four lines of code.
     * If a third controller repeats the pattern, that calculus changes.
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

    /**
     * Resolve the request's `component` input to an `ObligationComponent`
     * enum case.
     *
     * The request (2B.22) validates `component` with `Rule::enum(...)`,
     * which accepts three forms of input:
     *
     *   - The case NAME   (e.g. `'Principal'`)
     *   - The case VALUE  (e.g. `'principal'`)
     *   - The case INSTANCE
     *
     * By the time this helper runs, the input has already been validated
     * as one of the three. The helper normalises the two string forms to
     * the enum case that `ObligationService::record()` (2B.16) requires.
     *
     * The value form is tried first because it is the overwhelmingly
     * common JSON payload (snake_case values), and the case-name form is
     * tried as a fallback for callers that send the PascalCase name.
     *
     * The `LogicException` thrown on the impossible branch is a genuine
     * "unreachable" marker: `Rule::enum` rejects any input that does not
     * resolve, so this helper is called only when the input is one of the
     * two accepted forms. If the exception fires, it indicates a
     * behavioural change in `Rule::enum` that the resolver does not
     * account for, which is a defect worth failing loudly on. Per the
     * conventions, `LogicException` — not `ValidationException` — is the
     * appropriate type: the caller's input is not at fault.
     *
     * @param string $raw
     * @return ObligationComponent
     *
     * @throws LogicException
     */
    private function resolveComponent(string $raw): ObligationComponent
    {
        // Try by value first — the common JSON form.
        $component = ObligationComponent::tryFrom($raw);

        if ($component !== null) {
            return $component;
        }

        // Try by case name as a fallback for PascalCase input.
        foreach (ObligationComponent::cases() as $case) {
            if ($case->name === $raw) {
                return $case;
            }
        }

        // Rule::enum accepted the value; this resolver did not. That is a
        // behavioural mismatch between the rule and the resolver.
        throw new LogicException(
            sprintf(
                'Unreachable: component value "%s" passed Rule::enum '
                . 'validation but could not be resolved to an '
                . 'ObligationComponent case.',
                $raw,
            )
        );
    }

    /**
     * Parse an optional date input to a Carbon instance.
     *
     * The request (2B.22) validates `occurred_at` and `due_at` with
     * Laravel's `date` rule, which accepts any format `strtotime()` can
     * parse. This helper converts the validated string to a `Carbon`
     * instance for the service.
     *
     * A null input (or an empty string, which `prepareForValidation()`
     * has already normalised to null for these fields — see 2B.22
     * §"prepareForValidation") yields null. The service treats null
     * `occurred_at` as "now" and null `due_at` as "no maturity" (2B.16
     * §"record").
     *
     * `Carbon::parse()` cannot throw on a string that passed the `date`
     * rule; the guard is retained for defensive symmetry with the
     * service's own null-handling.
     *
     * @param mixed $value
     * @return Carbon|null
     */
    private function resolveDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        return Carbon::parse((string) $value);
    }
}