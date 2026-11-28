<?php

declare(strict_types=1);

namespace MaaleArna\\Arna\\Core\\Domains\\Finance\\Http\\Controllers;

use App\\Http\\Controllers\\Controller;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use MaaleArna\\Arna\\Core\\Domains\\Finance\\Http\\Resources\\FinanceDimensionResource;
use MaaleArna\\Arna\\Core\\Domains\\Finance\\Http\\Resources\\FinanceDimensionValueResource;
use MaaleArna\\Arna\\Core\\Domains\\Finance\\Models\\FinanceDimension;
use MaaleArna\\Arna\\Core\\Domains\\Finance\\Repositories\\FinanceDimensionRepository;
use MaaleArna\\Arna\\Core\\Domains\\Finance\\Services\\FinanceDimensionService;
use MaaleArna\\Arna\\Core\\Tenancy\\Services\\TenantContextService;

final class FinanceDimensionController extends Controller
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly FinanceDimensionRepository $dimensions,
        private readonly FinanceDimensionService $service,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['legal_entity_id' => ['required', 'integer', 'min:1']]);
        $items = $this->dimensions->paginateForEntity($this->tenantId(), (int) $data['legal_entity_id'], $this->perPage($request));

        return response()->json(['success' => true, 'data' => FinanceDimensionResource::collection($items)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:48'],
            'dimension_type' => ['required', 'string', 'max:32'],
            'name_en' => ['required', 'string', 'max:180'],
            'name_am' => ['nullable', 'string', 'max:160'],
            'is_required' => ['sometimes', 'boolean'],
        ]);
        $dimension = $this->service->createDimension(
            $this->tenantId(),
            (int) $data['legal_entity_id'],
            $data,
            (int) $request->user()->getAuthIdentifier(),
        );

        return response()->json(['success' => true, 'data' => FinanceDimensionResource::make($dimension)], 201);
    }

    public function values(Request $request, int $dimensionId): JsonResponse
    {
        $data = $request->validate(['legal_entity_id' => ['required', 'integer', 'min:1']]);
        abort_unless(FinanceDimension::query()
            ->where('tenant_id', $this->tenantId())
            ->where('legal_entity_id', (int) $data['legal_entity_id'])
            ->whereKey($dimensionId)
            ->exists(), 404);

        $items = $this->dimensions->paginateValues(
            $this->tenantId(),
            (int) $data['legal_entity_id'],
            $dimensionId,
            $this->perPage($request),
        );

        return response()->json(['success' => true, 'data' => FinanceDimensionValueResource::collection($items)]);
    }

    public function storeValue(Request $request, int $dimensionId): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64'],
            'name_en' => ['required', 'string', 'max:180'],
            'name_am' => ['nullable', 'string', 'max:180'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        $value = $this->service->createValue(
            $this->tenantId(),
            (int) $data['legal_entity_id'],
            $dimensionId,
            $data,
            (int) $request->user()->getAuthIdentifier(),
        );

        return response()->json(['success' => true, 'data' => FinanceDimensionValueResource::make($value)], 201);
    }

    private function tenantId(): int
    {
        $tenantId = $this->tenantContext->getTenantId();
        abort_if($tenantId === null, 403, 'A resolved tenant context is required.');
        return (int) $tenantId;
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 25), 1), 100);
    }
}
