<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — CR-L1
 * =============================================================================
 *
 * File:            MyLoanController.php  (NEW)
 * Unit:            CR-L1 — Portal self-service loans endpoint
 * Delivery type:   New file.
 * Net lines:       new file.
 *
 * Before:
 *   The file did not exist. The Loan domain had no self-service endpoint.
 *   A caller holding only `loan.view_own` could reach their own loans
 *   through GET /api/loans — the aggregate index, whose authorization
 *   (`LoanPolicy::viewAny`) grants via `loan.view_own` and whose
 *   controller (`LoanController::index`) narrows scope via
 *   `paginateForCallerAsBorrower()` when the caller lacks `loan.view`.
 *
 *   That path works, but it does not carry the identity contract the
 *   portal's other self-service endpoints carry. A caller with no
 *   shareholder record in the tenant receives
 *   `{ success: true, data: [], meta: { pagination: { total: 0, … } } }`
 *   — indistinguishable from a caller whose shareholder record exists
 *   but has no loans. The portal's frontend has no way to render
 *   distinct copy for the two states.
 *
 * After:
 *   A single-action controller that resolves the authenticated user's
 *   own loans and returns the two-outcome identity contract the
 *   portal's self-service pages consume. The contract mirrors
 *   /api/shareholders/me and /api/wallets/me in shape:
 *
 *       linked: true   →  { success: true, data: { linked: true,  loans: [ <LoanResource>, … ] } }
 *       linked: false  →  { success: true, data: { linked: false, loans: [] } }
 *
 *   The unlinked branch collapses every reason the caller has no loans:
 *   no shareholder record in the tenant, or a shareholder record with
 *   zero loans. The two states are indistinguishable by design — the
 *   caller cannot steer the lookup, and the collapse is the same
 *   anti-enumeration posture /api/shareholders/me records.
 *
 *   No Gate::authorize. The endpoint resolves the caller's own loans
 *   through a server-side chain that accepts no caller input; there
 *   is no wallet or loan id in the URL to steer at another row. The
 *   reasoning is reproduced in the class docblock, and is structurally
 *   identical to MyWalletController's and MyShareholderController's.
 *
 *   The response shape follows the Loan domain's LoanResource. The
 *   controller does not paginate — listForBorrower returns a bounded
 *   Collection, and a shareholder's own loans are a small set.
 *
 * Public surface introduced:
 *   · Class:  MaaleArna\MASES\Core\Domains\Loan\Http\Controllers\MyLoanController
 *   · Method: index(Request): JsonResponse
 *
 * Files modified:            none (the route is registered by CR-L1 file 3)
 * Files deleted:             none
 * Routes registered:         none (declared by CR-L1 file 3 — GET /api/loans/me)
 * Policies modified:         none
 * Resources modified:        none
 * Repository modified:       none
 * Migrations:                none
 * Seeders:                   none
 *
 * Dependency note:
 *   · Depends on LoanRepository::listForBorrower() — present in the
 *     repository at its current on-disk version, confirmed in the
 *     evidence pass.
 *   · Depends on LoanResource — present at the resource's current
 *     on-disk version (v1.0.0). No `_display` date fields are
 *     expected here; CR-L2 delivers those.
 *   · Depends on Shareholder (Phase 2A model) for the inline
 *     shareholder-resolution query. Same import the Loan domain's
 *     LoanPolicy and LoanController use.
 *   · No new class, no trait, no interface. No composer dump-autoload
 *     is required for this file alone beyond the standard refresh that
 *     a new class triggers under "optimize-autoloader": true.
 *   · Does not depend on the CR-L1 config prefix fix (file 1) at
 *     compile time — the class loads and runs regardless. At runtime,
 *     the new /me route (file 3) will land under the correct /api
 *     prefix only if file 1 is deployed.
 *
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Controllers;

use LogicException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Domains\Loan\Http\Resources\LoanResource;
use MaaleArna\MASES\Core\Domains\Loan\Repositories\LoanRepository;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;

/**
 * MyLoanController
 *
 * HTTP boundary for the authenticated user's OWN loans — the portal's
 * self-service loan-list endpoint.
 *
 * =============================================================================
 * Scope — one operation
 * =============================================================================
 *     GET /api/loans/me  →  index()   self-lookup
 *
 * The route is registered by the domain's Routes/api.php (CR-L1 file 3)
 * as a static sibling of /products, declared BEFORE the dynamic
 * /{loan} route so the router's first-match resolution does not treat
 * `me` as a numeric id. The route carries NO middleware — see
 * §"Authorization" below for the reasoning, which mirrors the Loan
 * domain's existing treatment of the schedule and statement routes
 * (which likewise carry no route middleware).
 *
 * The controller exists as its own class rather than as a method on
 * LoanController because the two surfaces have different
 * responsibilities:
 *
 *   LoanController  — the aggregate's lifecycle. Its class docblock
 *                     declares its scope as eight actions (index,
 *                     store, show, approve, reject, disburse,
 *                     restructure, writeoff) and states that the
 *                     domain's sixteen-route surface is split across
 *                     six sibling controllers — one per non-lifecycle
 *                     concern.
 *
 *   MyLoanController — self-service identity resolution. Its single
 *                      action resolves the caller's own loans through
 *                      the structural link
 *                      (loans.borrower_shareholder_id →
 *                      shareholders.user_id = caller id within the
 *                      resolved tenant), not through a policy grant.
 *
 * Merging the two would either break LoanController's declared scope
 * or put a policy-bypassing action on a controller whose class
 * docblock declares the policy as its authorization layer. Keeping
 * them separate keeps each class's contract honest. This mirrors the
 * Shareholder and Wallet domains' decisions to place
 * MyShareholderController and MyWalletController alongside their
 * aggregate-root siblings.
 *
 * =============================================================================
 * The identity contract — two outcomes, no leak
 * =============================================================================
 * The response is one of exactly two shapes:
 *
 *     linked: true   →  { success: true, data: { linked: true,  loans: [ <LoanResource>, … ] } }
 *     linked: false  →  { success: true, data: { linked: false, loans: [] } }
 *
 * The `linked: false` outcome deliberately COLLAPSES every reason the
 * caller has no own loans:
 *
 *     · no shareholder record references this user in this tenant
 *     · the shareholder record exists but the row is soft-deleted
 *     · the shareholder record exists but has no loans
 *     · the shareholder record exists but all their loans belong to
 *       a different tenant (impossible by construction, since
 *       borrower_shareholder_id is tenant-scoped, but the collapse
 *       covers the case defensively)
 *
 * The collapse is intentional and mirrors /api/shareholders/me's and
 * /api/wallets/me's own collapses. Distinguishing the cases would turn
 * the endpoint into a cross-tenant and cross-role enumeration oracle:
 * a caller could iterate user ids and discover, from the response
 * shape, which ids exist as linked shareholders in another tenant. The
 * frontend's contract models this collapse exactly — one boolean, one
 * array — and MyLoansPage (CR-L3) will consume it accordingly.
 *
 * =============================================================================
 * Authorization — why there is no Gate::authorize
 * =============================================================================
 * This endpoint does NOT call Gate::authorize. The rationale is
 * structurally identical to MyShareholderController's and
 * MyWalletController's, and is a property of what the endpoint
 * resolves, not an oversight:
 *
 *   1. The endpoint cannot return another user's loans. The lookup
 *      chain is entirely server-resolved:
 *
 *          $tenantId      = mases_tenant_id()
 *          $userId        = $request->user()->id
 *          $shareholderId = Shareholder::query()
 *                              ->where('tenant_id', $tenantId)
 *                              ->where('user_id', $userId)
 *                              ->value('id')
 *          $loans         = LoanRepository::listForBorrower($tenantId, $shareholderId)
 *
 *      A caller cannot influence any of these values. There is no
 *      `?shareholder_id=` parameter, no path id, no request body that
 *      could redirect the query at a different borrower.
 *
 *   2. The LoanPolicy's `viewAny` ability governs access to the
 *      aggregate index (GET /api/loans), where a caller with
 *      `loan.view` sees every loan in the tenant. Applying the policy
 *      here would invert the dependency: `loan.view_own` — the very
 *      permission that grants self-service scope — would gate the
 *      identity-resolution step that determines whether the permission
 *      is meaningful in the first place.
 *
 *   3. Requiring `loan.view_own` on this endpoint would also change
 *      the meaning of `linked: false`. A shareholder-role user whose
 *      RBAC is not yet aligned would receive `linked: false` for a
 *      shareholder record they are structurally linked to — a
 *      semantically wrong answer to the question "do I have loans?",
 *      even though the resource-fetch gate on the aggregate endpoint
 *      would still correctly narrow the visible set.
 *
 * The policy is therefore untouched by this controller. If a future
 * CR decides the endpoint should be permission-gated, that is a
 * change to the identity contract — the frontend's `linked` semantics,
 * MyLoansPage's state machine, and this class's contract would all
 * need to be re-evaluated in the same CR. It is not a bug fix and is
 * not done here.
 *
 * =============================================================================
 * Tenant scoping
 * =============================================================================
 * The tenant id is resolved from `mases_tenant_id()` — the same
 * server-side helper every other Loan-domain controller uses
 * (LoanController::resolveTenantId, and the equivalent helper on the
 * six sibling controllers). The value originates from
 * TenantContextMiddleware via TenantContextService, which is populated
 * before the auth and tenant-membership middlewares run. The
 * controller does NOT read the tenant from:
 *
 *     · the request body,
 *     · a query string parameter,
 *     · a custom header other than the one RequestTenantResolver
 *       itself consumes (X-Tenant-ID, handled earlier in the pipeline).
 *
 * The route's `tenant.membership` middleware (inherited from the Loan
 * domain's config-declared middleware stack) guarantees a tenant is
 * present and that the caller is a member. The defensive validation
 * here mirrors LoanController::resolveTenantId()'s pattern: if the
 * helper is unavailable or returns null, the request cannot be
 * processed as-is and a LogicException is raised — a deployment or
 * routing defect, not a caller error, and the framework's exception
 * renderer will produce the canonical error envelope.
 *
 * =============================================================================
 * The Shareholder resolution — the inline pattern
 * =============================================================================
 * The controller resolves the caller's shareholder id via an inline
 * scoped query on the Shareholder model, NOT via an injected
 * ShareholderRepository. The pattern is the same one LoanPolicy
 * (isBorrower) and LoanController (paginateForCallerAsBorrower) use —
 * a direct query against the shareholders table, tenant-scoped by the
 * resolved tenant, filtered by the authenticated user's id.
 *
 * The choice is deliberate: the Loan domain does not inject
 * ShareholderRepository in any of its read paths. Introducing a
 * repository dependency here would diverge from the domain's own
 * convention without changing the resolution semantics — the query is
 * three lines and, since the identical pattern already exists twice in
 * the codebase, a reader will recognise it immediately.
 *
 * The query is served by the shareholders table's existing composite
 * index (`shareholders_tenant_id_user_id_unique`), so the lookup is
 * constant-time.
 *
 * =============================================================================
 * Response shape — Shape 1 (CR-L1)
 * =============================================================================
 * The list of loans is not paginated. The repository's
 * `listForBorrower()` returns a bounded Collection — a shareholder's
 * own loan count is small — and the controller passes it directly to
 * `LoanResource::collection()`. No `meta.pagination` block is emitted.
 *
 * This differs from the aggregate index (GET /api/loans), whose
 * pagination serves a tenant-wide loan book that can be large. The
 * self-service case does not warrant the same machinery, and adding an
 * artificial paginator to satisfy an unneeded shape would be
 * complexity without value. If a future CR finds a shareholder with
 * enough loans that pagination is warranted, adding a `meta.pagination`
 * block later is additive and does not break existing consumers.
 *
 * =============================================================================
 * What this controller does NOT do
 * =============================================================================
 *   · It does not accept a loan id, shareholder id, user id, email,
 *     or name as input.
 *
 *   · It does not support the aggregate index's filter set
 *     (status, loan_product_id, in_arrears, active_only). The
 *     self-service list is the caller's own loans; filtering is a
 *     feature the frontend does not currently require. A future CR
 *     adds filters when a page needs them.
 *
 *   · It does not consult `representative_user_id`. Representation is
 *     a structural relationship that grants ACCESS, not self-service
 *     IDENTITY. A representative of a legal-entity borrower does not
 *     receive that entity's loans from this endpoint. When a future CR
 *     adds representative-context switching to the portal, it will be
 *     a distinct endpoint (e.g. /api/loans/me/represented) with its
 *     own contract — not a widening of this one.
 *
 *   · It does not fall back to any other endpoint. There is no id
 *     search, no name search, no "find the closest match".
 *
 *   · It does not cache. Every call performs the same two indexed
 *     lookups.
 *
 *   · It does not log the request. The middleware stack and the
 *     authorization layers already produce audit rows for the cases
 *     that warrant them; a successful self-lookup is a routine read.
 *
 *   · It does not write. No audit row, no security event, no cache
 *     invalidation. The endpoint is a pure read.
 *
 * =============================================================================
 * Class shape — bare final class
 * =============================================================================
 * This class is declared as a bare `final class` with no base class and
 * no traits. It does NOT extend App\Http\Controllers\Controller, and it
 * does NOT use the AuthorizesRequests trait. The choice matches the
 * Loan domain's own convention — LoanController and LoanPolicy are
 * both bare `final class` declarations — and is consistent with the
 * controller's authorization posture: the endpoint carries no
 * `Gate::authorize` call, so the base class's `AuthorizesRequests`
 * trait would be unused.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D129 (borrower is
 *                                                  shareholder), B7 (RBAC)
 *   Phase 2C Clarification Response, 2026-09-12  — C1 (shareholder role)
 *   Phase 2C A.18                                — Loan model
 *   Phase 2C A.52                                — LoanPolicy (the
 *                                                  isBorrower pattern
 *                                                  this controller
 *                                                  follows)
 *   Phase 2C A.55                                — LoanRepository
 *                                                  (listForBorrower)
 *   Phase 2C A.69                                — LoanResource
 *   Phase 2C A.77                                — LoanController (the
 *                                                  pattern reference
 *                                                  for resolveTenantId
 *                                                  and for the inline
 *                                                  Shareholder query)
 *   Phase 2A 2A.11                               — ShareholderRepository
 *                                                  (the alternative
 *                                                  this controller
 *                                                  deliberately does
 *                                                  not use)
 *   CR-W1                                        — MyWalletController
 *                                                  (the identity-contract
 *                                                  precedent)
 *   P1-1a                                        — MyShareholderController
 *                                                  (the earliest
 *                                                  identity-contract
 *                                                  precedent)
 *   CR-L1                                        — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Controllers
 * @version 1.0.0
 */
final class MyLoanController
{
    /**
     * Constructor.
     *
     * One dependency: the domain's LoanRepository, for the
     * listForBorrower call. The controller does not inject an
     * AuthorizationService (no permission check), a ShareholderRepository
     * (the Shareholder model is queried inline, matching the Loan
     * domain's own convention), or any service class (the endpoint is a
     * pure read).
     *
     * The repository is bound as a singleton by LoanServiceProvider, so
     * resolving this controller within a request yields the same
     * instance every other consumer in that request would receive.
     *
     * @param LoanRepository $loanRepository
     */
    public function __construct(
        private readonly LoanRepository $loanRepository,
    ) {
    }

    // =========================================================================
    // index — resolve the authenticated user's own loans
    // =========================================================================
    /**
     * Return the authenticated user's own loans, resolved server-side.
     *
     * The resolution is structural, at two levels:
     *
     *   1. Within the tenant the request-scoped context has established,
     *      find the shareholder whose `user_id` column equals the
     *      authenticated user's id. The query is the same one
     *      LoanPolicy::isBorrower() performs; it is served by the
     *      shareholders table's composite index.
     *
     *   2. Within the same tenant, find every loan whose
     *      `borrower_shareholder_id` equals the shareholder's id. The
     *      repository's `listForBorrower()` composes both the tenant
     *      scope and the borrower scope; it returns a Collection ordered
     *      by `application_date DESC, id DESC`.
     *
     * The caller supplies no identity input; the response is therefore
     * impossible to steer toward a different row.
     *
     * -----------------------------------------------------------------------------
     * Two-null handling
     * -----------------------------------------------------------------------------
     * The first lookup can return null (no shareholder record); the
     * second lookup cannot return null (it returns an empty Collection
     * for zero matches). Either case produces the same
     * `{ linked: false, loans: [] }` response. The class docblock
     * §"The identity contract — two outcomes, no leak" enumerates the
     * conditions that collapse into the unlinked branch and the
     * reasoning for the collapse.
     *
     * -----------------------------------------------------------------------------
     * Resource serialization
     * -----------------------------------------------------------------------------
     * When the caller is linked, the loans Collection is passed to
     * `LoanResource::collection(...)` and resolved via
     * `->toArray($request)`. The resource's `$wrap = null` setting means
     * it contributes no additional wrapper; the controller's envelope
     * remains the sole wrapping layer. This mirrors
     * LoanController::respondWithLoans()'s identical call shape.
     *
     * @param Request $request
     * @return JsonResponse
     *
     * @throws LogicException When no tenant context is available.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        /*
         * The authenticated user. The route inherits `auth:sanctum` from
         * the Loan domain's config-declared middleware stack, so
         * `$request->user()` is guaranteed non-null when this method
         * runs. The local variable narrows the type for static analysis
         * without adding a runtime null check the middleware already
         * guarantees.
         */
        $userId = (int) $request->user()->getKey();

        // ---------------------------------------------------------------------
        // Step 1: resolve the caller's own shareholder record in the tenant.
        //
        // The query is the same one LoanPolicy::isBorrower() performs. No
        // eager-loads — the loans query below is the only one that loads
        // anything, and the shareholder record itself is not serialized
        // in this endpoint's response.
        // ---------------------------------------------------------------------
        /** @var int|string|null $shareholderId */
        $shareholderId = Shareholder::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->value('id');

        /*
         * Unlinked outcome. The caller has no shareholder record in this
         * tenant — the collapse covers every reason. See the class
         * docblock §"The identity contract" for the reasoning.
         */
        if ($shareholderId === null) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'linked' => false,
                    'loans'  => [],
                ],
            ]);
        }

        // ---------------------------------------------------------------------
        // Step 2: resolve the caller's loans within the same tenant.
        //
        // listForBorrower() composes both the tenant scope and the
        // borrower scope; a loan belonging to another tenant's borrower
        // cannot surface here. The Collection is empty when the caller
        // has no loans, and the linked: true / loans: [] response is
        // the correct outcome for that case — the caller IS linked,
        // they simply have no loans yet.
        // ---------------------------------------------------------------------
        $loans = $this->loanRepository->listForBorrower(
            $tenantId,
            (int) $shareholderId,
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'linked' => true,
                'loans'  => LoanResource::collection($loans)->toArray($request),
            ],
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================
    /**
     * Resolve the authenticated tenant's id from the request context.
     *
     * The `mases_tenant_id()` helper is populated by the
     * `tenant.membership` middleware (registered in `bootstrap/app.php`,
     * applied to every Loan route via the config's middleware stack),
     * which resolves the tenant before any controller runs. A null
     * return here indicates a middleware-bypass defect or a route
     * registered without the middleware stack.
     *
     * The method returns the cast value; a null or empty value throws
     * `LogicException` — a programmer defect, not a caller error. This
     * matches LoanController::resolveTenantId()'s identical helper and
     * the Loan domain's convention.
     *
     * The duplication of this helper across every Loan-domain controller
     * is intentional: each controller is an independent HTTP boundary,
     * and the helper's body is a short guard whose extraction into a
     * shared trait would add indirection without removing any invariant.
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
                'MyLoanController requires a resolved tenant id. The '
                . '`tenant.membership` middleware must populate the '
                . 'request context before any Loan controller action '
                . 'runs. A missing tenant indicates a middleware-bypass '
                . 'defect or a route registered without the middleware '
                . 'stack.'
            );
        }

        return (int) $tenantId;
    }
}