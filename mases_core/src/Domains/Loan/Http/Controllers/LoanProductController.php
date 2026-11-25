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
 * Read-only, tenant-scoped loan-product catalogue. Keep the static products route before the dynamic loan route.
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