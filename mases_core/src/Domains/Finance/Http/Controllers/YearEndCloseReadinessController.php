<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Domains\Finance\Services\YearEndCloseReadinessService;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;

final class YearEndCloseReadinessController extends Controller
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly YearEndCloseReadinessService $readiness,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'fiscal_year_start' => ['required', 'date_format:Y-m-d'],
            'fiscal_year_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:fiscal_year_start'],
        ]);

        $tenantId = $this->tenantContext->getTenantId();
        abort_if($tenantId === null, 403, 'A resolved tenant context is required.');

        $result = $this->readiness->assess(
            (int) $tenantId,
            (int) $data['legal_entity_id'],
            (string) $data['fiscal_year_start'],
            (string) $data['fiscal_year_end'],
        );

        return response()->json(['success' => true, 'data' => $result]);
    }
}
