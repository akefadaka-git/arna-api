<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Domains\Finance\Models\DividendDistribution;
use MaaleArna\MASES\Core\Domains\Finance\Services\AnnualDividendDistributionService;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;

final class AnnualDividendDistributionController extends Controller
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly AnnualDividendDistributionService $distributions,
    ) {}

    private function tenantId(): int
    {
        $id = $this->tenantContext->getTenantId();
        abort_if($id === null, 403, 'A resolved tenant context is required.');
        return (int) $id;
    }

    public function index(Request $request): JsonResponse
    {
        $query = DividendDistribution::query()->where('tenant_id', $this->tenantId())->withCount('items');
        foreach (['legal_entity_id', 'financial_year', 'status'] as $field) {
            if ($request->filled($field)) $query->where($field, $request->input($field));
        }
        $paginator = $query->orderByDesc('id')->paginate(min(max($request->integer('per_page', 25), 1), 100));
        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(int $distributionId): JsonResponse
    {
        $row = DividendDistribution::query()->where('tenant_id', $this->tenantId())->with('items')->findOrFail($distributionId);
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'distribution_reference' => ['required', 'string', 'max:100'],
            'financial_year' => ['required', 'string', 'max:32'],
            'resolution_reference' => ['required', 'string', 'max:150'],
            'resolution_date' => ['required', 'date'],
            'declared_on' => ['required', 'date'],
            'record_date' => ['required', 'date'],
            'payment_due_on' => ['required', 'date', 'after_or_equal:declared_on'],
            'currency' => ['sometimes', 'in:ETB'],
            'declared_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'allocation_method' => ['sometimes', 'in:pro_rata_shares'],
            'withholding_rate_bps' => ['sometimes', 'integer', 'between:0,10000'],
        ]);
        $row = $this->distributions->create($this->tenantId(), (int) $request->user()->getAuthIdentifier(), $data);
        return response()->json(['success' => true, 'data' => $row], 201);
    }

    public function calculate(Request $request, int $distributionId): JsonResponse
    {
        $row = $this->distributions->snapshotAndCalculate($this->tenantId(), $distributionId, (int) $request->user()->getAuthIdentifier());
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function approve(Request $request, int $distributionId): JsonResponse
    {
        $row = $this->distributions->approve($this->tenantId(), $distributionId, (int) $request->user()->getAuthIdentifier());
        return response()->json(['success' => true, 'data' => $row]);
    }
}
