<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Wallet\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\Arna\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\Arna\Core\Domains\Wallet\Http\Requests\StoreObligationRequest;
use MaaleArna\Arna\Core\Domains\Wallet\Http\Resources\ObligationResource;
use MaaleArna\Arna\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\Arna\Core\Domains\Wallet\Repositories\WalletRepository;
use MaaleArna\Arna\Core\Domains\Wallet\Services\ObligationService;

/**
 * Tenant-scoped HTTP boundary for recording obligations; exposes only POST store and no independent list or mutation endpoints.
 *
 * @package MaaleArna\Arna\Core\Domains\Wallet\Http\Controllers
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
     * The value comes from `arna_tenant_id()` — never from client input.
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
        $tenantId = function_exists('arna_tenant_id') ? arna_tenant_id() : null;

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