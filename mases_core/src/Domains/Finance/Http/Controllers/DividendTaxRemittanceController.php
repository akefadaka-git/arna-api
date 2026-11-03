<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Domains\Finance\Services\DividendTaxRemittanceService;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;

final class DividendTaxRemittanceController extends Controller
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly DividendTaxRemittanceService $remittances,
    ) {}

    private function tenantId(): int
    {
        $id = $this->tenantContext->getTenantId();
        abort_if($id === null, 403, 'A resolved tenant context is required.');
        return (int) $id;
    }

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('finance_dividend_tax_remittances')->where('tenant_id', $this->tenantId());
        foreach (['legal_entity_id', 'distribution_id', 'status'] as $field) {
            if ($request->filled($field)) $query->where($field, $request->input($field));
        }
        $rows = $query->orderByDesc('id')->paginate(min(max($request->integer('per_page', 25), 1), 100));
        return response()->json([
            'success' => true, 'data' => $rows->items(),
            'meta' => ['current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
                'total' => $rows->total(), 'last_page' => $rows->lastPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'distribution_id' => ['required', 'integer', 'min:1'],
            'remittance_reference' => ['required', 'string', 'max:100'],
            'tax_authority_reference' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
        ]);
        $row = $this->remittances->create(
            $this->tenantId(), (int) $data['legal_entity_id'], (int) $data['distribution_id'],
            (int) $request->user()->getAuthIdentifier(), $data['remittance_reference'],
            $data['tax_authority_reference'], (string) $data['amount']
        );
        return response()->json(['success' => true, 'data' => $row], 201);
    }

    public function approve(Request $request, int $remittanceId): JsonResponse
    {
        $row = $this->remittances->approve($this->tenantId(), $remittanceId, (int) $request->user()->getAuthIdentifier());
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function confirm(Request $request, int $remittanceId): JsonResponse
    {
        $data = $request->validate([
            'bank_reference' => ['required', 'string', 'max:200'],
            'evidence_reference' => ['required', 'string', 'max:500'],
            'paid_on' => ['required', 'date'],
        ]);
        $row = $this->remittances->confirm(
            $this->tenantId(), $remittanceId, (int) $request->user()->getAuthIdentifier(),
            $data['bank_reference'], $data['evidence_reference'], $data['paid_on']
        );
        return response()->json(['success' => true, 'data' => $row]);
    }
}
