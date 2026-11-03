<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Domains\Finance\Services\DividendPaymentService;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;

final class DividendPaymentController extends Controller
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly DividendPaymentService $payments,
    ) {}

    private function tenantId(): int
    {
        $id = $this->tenantContext->getTenantId();
        abort_if($id === null, 403, 'A resolved tenant context is required.');
        return (int) $id;
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $query = DB::table('finance_dividend_payment_batches')
            ->where('tenant_id', $tenantId);
        foreach (['legal_entity_id', 'distribution_id', 'status', 'reconciliation_status'] as $field) {
            if ($request->filled($field)) $query->where($field, $request->input($field));
        }
        $rows = $query->orderByDesc('id')->paginate(min(max($request->integer('per_page', 25), 1), 100));
        $ids = collect($rows->items())->pluck('id')->all();
        $items = DB::table('finance_dividend_payment_batch_items')->where('tenant_id', $tenantId)
            ->whereIn('batch_id', $ids)->orderBy('id')->get()->groupBy('batch_id');
        $data = collect($rows->items())->map(function ($batch) use ($items) {
            $batch->items = $items->get($batch->id, collect())->values();
            return $batch;
        });
        return response()->json([
            'success' => true, 'data' => $data,
            'meta' => ['current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
                'total' => $rows->total(), 'last_page' => $rows->lastPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'distribution_id' => ['required', 'integer', 'min:1'],
            'batch_reference' => ['required', 'string', 'max:100'],
            'payable_ids' => ['required', 'array', 'min:1', 'max:500'],
            'payable_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);
        $batch = $this->payments->createBatch(
            $this->tenantId(), (int) $data['legal_entity_id'], (int) $data['distribution_id'],
            (int) $request->user()->getAuthIdentifier(), $data['batch_reference'], $data['payable_ids']
        );
        return response()->json(['success' => true, 'data' => $batch], 201);
    }

    public function approve(Request $request, int $batchId): JsonResponse
    {
        $batch = $this->payments->approveBatch($this->tenantId(), $batchId, (int) $request->user()->getAuthIdentifier());
        return response()->json(['success' => true, 'data' => $batch]);
    }

    public function cancel(Request $request, int $batchId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $batch = $this->payments->cancelBatch(
            $this->tenantId(), $batchId, (int) $request->user()->getAuthIdentifier(), $data['reason']
        );
        return response()->json(['success' => true, 'data' => $batch]);
    }

    public function confirm(Request $request, int $batchId, int $itemId): JsonResponse
    {
        $data = $request->validate([
            'bank_reference' => ['required', 'string', 'max:200'],
            'evidence_reference' => ['required', 'string', 'max:500'],
            'paid_on' => ['required', 'date'],
        ]);
        $result = $this->payments->confirmPayment(
            $this->tenantId(), $batchId, $itemId, (int) $request->user()->getAuthIdentifier(),
            $data['bank_reference'], $data['evidence_reference'], $data['paid_on']
        );
        return response()->json(['success' => true, 'data' => $result]);
    }

    public function fail(Request $request, int $batchId, int $itemId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $item = $this->payments->failPayment(
            $this->tenantId(), $batchId, $itemId, (int) $request->user()->getAuthIdentifier(), $data['reason']
        );
        return response()->json(['success' => true, 'data' => $item]);
    }

    public function reconcile(Request $request, int $batchId): JsonResponse
    {
        $data = $request->validate([
            'statement_reference' => ['required', 'string', 'max:200'],
            'statement_amount' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
        ]);
        $batch = $this->payments->reconcileBatch(
            $this->tenantId(), $batchId, (int) $request->user()->getAuthIdentifier(),
            $data['statement_reference'], (string) $data['statement_amount']
        );
        return response()->json(['success' => true, 'data' => $batch]);
    }
}
