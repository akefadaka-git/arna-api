<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use MaaleArna\MASES\Core\Domains\Finance\Services\FinanceIntegrationHealthService;

final class FinanceIntegrationHealthController extends Controller
{
    public function __construct(private readonly FinanceIntegrationHealthService $health)
    {
    }

    public function __invoke(): JsonResponse
    {
        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;
        abort_if($tenantId === null, 403, 'An authenticated tenant context is required.');

        return response()->json([
            'success' => true,
            'data' => $this->health->snapshot((int) $tenantId),
        ]);
    }
}
