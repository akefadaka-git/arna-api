<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Controllers;

use Gate;
use LogicException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Domains\Loan\Http\Resources\LoanProductResource;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Repositories\LoanProductRepository;

/**
 * LoanProductController
 *
 * The HTTP surface for the loan product catalogue. Owns exactly ONE
 * action — the catalogue listing — because the frozen Stage 6 §4.16 HTTP
 * surface exposes no product-mutation endpoints. Products are seeded or
 * created by administrative scripts; a future CR that introduces product
 * management extends this controller with the corresponding actions.
 *
 * =============================================================================
 * The single action
 * =============================================================================
 *   index()    GET  /loans/products    — list the tenant's catalogue
 *
 * The route is declared BEFORE `/loans/{loan}` in the routes file (A.84)
 * so that the static `products` path is not captured by the dynamic
 * `{loan}` placeholder of `LoanController::show()`.
 *
 * =============================================================================
 * The thin-delegator discipline
 * =============================================================================
 * The single action follows the same four-step shape as every action in
 * `LoanController` (A.77):
 *
 *   1. RESOLVE — read the tenant id from the request context.
 *
 *   2. AUTHORIZE — dispatch `Gate::authorize('viewAny', LoanProduct::class)`,
 *      which resolves through `LoanProductPolicy::viewAny()` (A.53). The
 *      policy grants the ability when the caller holds `loan.view` in
 *      the tenant; `loan.view_own` does NOT grant catalogue access (see
 *      A.53's class docblock §"The two abilities").
 *
 *   3. DELEGATE — call `LoanProductRepository::listForTenant()` or
 *      `LoanProductRepository::listActiveForTenant()` (A.56) based on
 *      the caller's optional `?active_only=` query parameter.
 *
 *   4. RESPOND — wrap the collection in `LoanProductResource` (A.70)
 *      and emit the canonical envelope.
 *
 * The controller contains no product logic, no catalogue reasoning, and
 * no filtering beyond the allowlisted `?active_only=` parameter.
 *
 * =============================================================================
 * The `?active_only=` query parameter
 * =============================================================================
 * The endpoint accepts one optional query parameter:
 *
 *   ?active_only=1   (or =true, =yes)
 *       Restricts the returned catalogue to products currently offered
 *       for new applications (`is_active = true`). This is the correct
 *       read for a new-application product selector, and for any caller
 *       who wants "what can I apply for?" rather than "what does the
 *       catalogue contain?".
 *
 *   ?active_only=0   (or absent, or any other value)
 *       Returns the FULL catalogue, including inactive products. This is
 *       the correct read for an administrative view of the catalogue.
 *
 * The boolean coercion is delegated to Laravel's `Request::boolean()`,
 * which accepts `1`, `true`, `on`, and `yes` (case-insensitive) as
 * `true`; every other value (including absence) resolves to `false`.
 *
 * No other query parameter is recognised. A caller who sends
 * `?include=policyVersion` finds the parameter silently ignored; the
 * endpoint does not expose an `?include=` mechanism.
 *
 * =============================================================================
 * Pagination
 * =============================================================================
 * The catalogue is NOT paginated. A tenant's product catalogue is
 * typically a small set (tens, not thousands), and every caller who
 * needs the full list (the new-application product selector, an
 * administrative catalogue view) needs it in its entirety. Pagination
 * would add friction without a benefit.
 *
 * A future CR that introduces a tenant with a large catalogue (or that
 * introduces an administrative paginated view) would add pagination at
 * that time. The repository's `listForTenant()` and
 * `listActiveForTenant()` methods (A.56) return full collections; a
 * paginated variant would be a repository extension.
 *
 * =============================================================================
 * The response envelope
 * =============================================================================
 * A successful action returns:
 *
 *     {
 *         "success": true,
 *         "data":    [ ... ]   // array of product payloads
 *     }
 *
 * No `meta` key is emitted: the response is not paginated, so there is
 * no pagination metadata to expose. The `data` array is produced by
 * `LoanProductResource::collection(...)` (A.70), whose `$wrap = null`
 * setting prevents Laravel's own `data` wrapper from double-wrapping the
 * collection inside the controller's envelope.
 *
 * =============================================================================
 * Why this controller is separate from LoanController (A.77)
 * =============================================================================
 * The product catalogue is not a loan. It has its own aggregate
 * (`LoanProduct`, A.17), its own repository (`LoanProductRepository`,
 * A.56), its own policy (`LoanProductPolicy`, A.53), and its own
 * resource (`LoanProductResource`, A.70). Bundling its single endpoint
 * into `LoanController` would put unrelated dependencies (the product
 * repository) into a controller whose other seven actions never touch
 * products.
 *
 * The separation mirrors the platform-wide controller-per-aggregate
 * convention established by Phase 2A (`ShareholderController`,
 * `ShareClassController`) and Phase 2B (`WalletController`,
 * `WalletStatementController`).
 *
 * =============================================================================
 * What this controller deliberately does NOT do
 * =============================================================================
 *   - It does NOT authorize via a model instance. The ability is
 *     dispatched with the model CLASS name,
 *     `Gate::authorize('viewAny', LoanProduct::class)`, because the
 *     catalogue is a collection-level read. This is the standard Laravel
 *     idiom for a `viewAny` policy ability.
 *
 *   - It does NOT paginate. See §"Pagination".
 *
 *   - It does NOT accept an `?include=` parameter. See §"The
 *     `?active_only=` query parameter".
 *
 *   - It does NOT filter by code, currency, or any other product
 *     attribute. A caller who needs a single product resolves it via the
 *     repository directly (through a future CR that introduces a
 *     `GET /loans/products/{product}` route).
 *
 *   - It does NOT expose product mutations. See the class docblock
 *     §"The single action".
 *
 *   - It does NOT catch exceptions. Every exception propagates to the
 *     framework's exception renderer, unchanged from Phase 2B.
 *
 *   - It does NOT contain product logic. The controller is a thin
 *     delegator; every rule is enforced by the repository, the policy,
 *     or the request pipeline.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D130 (product
 *                                                  catalogue), B6
 *                                                  (hybrid storage)
 *   Phase 2C Clarification Response, 2026-09-12  — Extra 1 (tenant
 *                                                  scoping),
 *                                                  Extra 2 (single
 *                                                  policy type)
 *   Phase 2C A.17                                — `LoanProduct`
 *   Phase 2C A.53                                — `LoanProductPolicy`
 *   Phase 2C A.56                                — `LoanProductRepository`
 *   Phase 2C A.70                                — `LoanProductResource`
 *   Phase 2C A.77                                — `LoanController` (the
 *                                                  sibling whose
 *                                                  `respondWithLoans()`
 *                                                  method this
 *                                                  controller's
 *                                                  response shape
 *                                                  mirrors, minus the
 *                                                  `meta` block)
 *   Phase 2C A.84                                — the routes file (the
 *                                                  route-ordering rule
 *                                                  that places
 *                                                  `/loans/products`
 *                                                  before
 *                                                  `/loans/{loan}`)
 *   Phase 2B 2B.24                               — `WalletController` (the
 *                                                  shape precedent)
 *   Step 5 (manifest) A.78                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Controllers
 * @version 1.0.0
 */
final class LoanProductController
{
    /**
     * Constructor.
     *
     * One dependency: the product catalogue's read-only repository.
     *
     * @param LoanProductRepository $productRepository
     */
    public function __construct(
        private readonly LoanProductRepository $productRepository,
    ) {
    }

    // =========================================================================
    // Public API — index
    // =========================================================================

    /**
     * List the tenant's loan product catalogue.
     *
     * See the class docblock for the response shape and for the
     * `?active_only=` parameter's semantics.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId();

        Gate::authorize('viewAny', LoanProduct::class);

        $activeOnly = $request->boolean('active_only');

        $products = $activeOnly
            ? $this->productRepository->listActiveForTenant($tenantId)
            : $this->productRepository->listForTenant($tenantId);

        return response()->json([
            'success' => true,
            'data'    => LoanProductResource::collection($products)->toArray($request),
        ]);
    }

    // =========================================================================
    // Private — tenant resolution
    // =========================================================================
    /**
     * Resolve the authenticated tenant's id from the request context.
     *
     * The `mases_tenant_id()` helper is populated by the
     * `tenant.membership` middleware (registered in `bootstrap/app.php`,
     * applied to every Loan route via the config's middleware stack,
     * A.1). The `index()` action runs AFTER the middleware, so a null
     * return here indicates a middleware-bypass defect.
     *
     * The method returns the cast value; a null or empty value throws
     * `LogicException` (a programmer defect, not a caller error). The
     * guard mirrors `LoanController::resolveTenantId()` (A.77) verbatim.
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
                'LoanProductController requires a resolved tenant id. '
                . 'The `tenant.membership` middleware must populate the '
                . 'request context before the product catalogue action '
                . 'runs. A missing tenant indicates a middleware-bypass '
                . 'defect or a route registered without the middleware '
                . 'stack.'
            );
        }

        return (int) $tenantId;
    }
}