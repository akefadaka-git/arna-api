<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Domains\Finance\Models\DividendDistribution;
use MaaleArna\MASES\Core\Domains\Finance\Services\AnnualDividendDistributionService;
use MaaleArna\MASES\Core\Domains\Finance\Services\DividendReinvestmentService;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;

final class AnnualDividendDistributionController extends Controller
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly AnnualDividendDistributionService $distributions,
        private readonly DividendReinvestmentService $reinvestments,
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
            'approved_balance_sheet_reference' => ['required', 'string', 'max:200'],
            'distributable_profit_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'resolution_evidence_reference' => ['required', 'string', 'max:500'],
            'payment_method' => ['required', 'in:bank_transfer,account_payee_cheque,account_payee_bank_draft,nbe_authorized_payment,reinvestment_only'],
            'is_historical' => ['sometimes', 'boolean'],
            'historical_source_category' => ['required_if:is_historical,1', 'nullable', 'in:internal_revenue,other'],
            'historical_evidence_reference' => ['required_if:is_historical,1', 'nullable', 'string', 'min:3', 'max:500'],
            'historical_import_reason' => ['required_if:is_historical,1', 'nullable', 'string', 'min:5', 'max:500'],
            'accounting_framework' => ['required', 'in:ifrs,ifrs_sme,other_aabe_approved'],
            'replaces_distribution_id' => ['nullable', 'integer', 'min:1'],
            'replacement_reason' => ['required_with:replaces_distribution_id', 'nullable', 'string', 'min:5', 'max:500'],
        ]);
        $row = $this->distributions->create($this->tenantId(), (int) $request->user()->getAuthIdentifier(), $data);
        return response()->json(['success' => true, 'data' => $row], 201);
    }

    public function certifyProfit(Request $request, int $distributionId): JsonResponse
    {
        $row = $this->distributions->certifyProfit($this->tenantId(), $distributionId, (int) $request->user()->getAuthIdentifier());
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function assessRecipientTax(Request $request, int $distributionId, int $shareholderId): JsonResponse
    {
        $data = $request->validate([
            'recipient_type' => ['required', 'in:individual,company,other'],
            'tax_residency' => ['required', 'in:resident,non_resident'],
            'treatment' => ['required', 'in:statutory,treaty,exempt,special'],
            'withholding_rate_bps' => ['required', 'integer', 'between:0,10000'],
            'legal_basis' => ['required', 'string', 'min:3', 'max:500'],
            'evidence_reference' => ['nullable', 'string', 'max:500'],
            'effective_from' => ['required', 'date'],
            'evidence_expires_on' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        $row = $this->distributions->assessRecipientTax(
            $this->tenantId(), $distributionId, $shareholderId,
            (int) $request->user()->getAuthIdentifier(), $data
        );
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function cancel(Request $request, int $distributionId): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $row = $this->distributions->cancel(
            $this->tenantId(), $distributionId,
            (int) $request->user()->getAuthIdentifier(), $data['reason']
        );
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function shareClassWeights(int $distributionId): JsonResponse
    {
        $rows = DB::table('finance_dividend_share_class_weights')
            ->where('tenant_id', $this->tenantId())->where('distribution_id', $distributionId)
            ->orderBy('share_class_id')->get();
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function requestShareClassWeight(Request $request, int $distributionId): JsonResponse
    {
        $data = $request->validate([
            'share_class_id' => ['required', 'integer', 'min:1'],
            'weight_bps' => ['required', 'integer', 'between:0,100000'],
            'evidence_reference' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $row = $this->distributions->requestShareClassWeight(
            $this->tenantId(), $distributionId, (int) $data['share_class_id'],
            (int) $request->user()->getAuthIdentifier(), (int) $data['weight_bps'], $data['evidence_reference']
        );
        return response()->json(['success' => true, 'data' => $row], 201);
    }

    public function approveShareClassWeight(Request $request, int $distributionId, int $weightId): JsonResponse
    {
        $row = $this->distributions->approveShareClassWeight(
            $this->tenantId(), $distributionId, $weightId, (int) $request->user()->getAuthIdentifier()
        );
        return response()->json(['success' => true, 'data' => $row]);
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

    public function recordShareSubscriptionBalance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'shareholder_id' => ['required', 'integer', 'min:1'],
            'share_class_id' => ['required', 'integer', 'min:1'],
            'subscription_reference' => ['required', 'string', 'max:120'],
            'subscribed_share_quantity' => ['required', 'integer', 'min:1'],
            'price_per_share' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'original_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'outstanding_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'lte:original_amount'],
            'occurred_on' => ['required', 'date'],
            'evidence_reference' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $row = $this->reinvestments->recordSubscriptionBalance(
            $this->tenantId(), (int) $request->user()->getAuthIdentifier(), $data
        );
        return response()->json(['success' => true, 'data' => $row], 201);
    }

    public function reinvestments(Request $request): JsonResponse
    {
        $query = DB::table('finance_dividend_reinvestments')
            ->where('tenant_id', $this->tenantId());
        foreach (['distribution_id', 'shareholder_id', 'status', 'settlement_type'] as $field) {
            if ($request->filled($field)) $query->where($field, $request->input($field));
        }
        $rows = $query->orderByDesc('id')->paginate(min(max($request->integer('per_page', 25), 1), 100));
        return response()->json(['success' => true, 'data' => $rows->items(), 'meta' => [
            'current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
            'total' => $rows->total(), 'last_page' => $rows->lastPage(),
        ]]);
    }

    public function recordReinvestment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'distribution_id' => ['required', 'integer', 'min:1'],
            'distribution_item_id' => ['required', 'integer', 'min:1'],
            'shareholder_id' => ['required', 'integer', 'min:1'],
            'settlement_type' => ['required', 'in:subscription_balance,new_company_allotment,existing_share_transfer'],
            'source_category' => ['sometimes', 'in:internal_revenue,other'],
            'entitlement_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'subscription_balance_id' => ['required_if:settlement_type,subscription_balance', 'nullable', 'integer', 'min:1'],
            'share_class_id' => ['required_if:settlement_type,new_company_allotment,existing_share_transfer', 'nullable', 'integer', 'min:1'],
            'share_quantity' => ['required_if:settlement_type,new_company_allotment,existing_share_transfer', 'nullable', 'integer', 'min:1'],
            'price_per_share' => ['required_if:settlement_type,new_company_allotment,existing_share_transfer', 'nullable', 'numeric', 'gt:0', 'decimal:0,4'],
            'seller_shareholder_id' => ['required_if:settlement_type,existing_share_transfer', 'nullable', 'integer', 'min:1'],
            'occurred_on' => ['required', 'date'],
            'transaction_reference' => ['required', 'string', 'max:150'],
            'evidence_reference' => ['required', 'string', 'min:3', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);
        $row = $this->reinvestments->record(
            $this->tenantId(), (int) $request->user()->getAuthIdentifier(), $data
        );
        return response()->json(['success' => true, 'data' => $row], 201);
    }

    public function approveReinvestment(Request $request, int $reinvestmentId): JsonResponse
    {
        $row = $this->reinvestments->approve(
            $this->tenantId(), $reinvestmentId, (int) $request->user()->getAuthIdentifier()
        );
        return response()->json(['success' => true, 'data' => $row]);
    }


    public function settleReinvestmentSeller(Request $request, int $reinvestmentId): JsonResponse
    {
        $data = $request->validate([
            'bank_reference' => ['required', 'string', 'min:3', 'max:150'],
            'evidence_reference' => ['required', 'string', 'min:3', 'max:500'],
            'paid_on' => ['required', 'date'],
        ]);
        $row = $this->reinvestments->settleSeller(
            $this->tenantId(), $reinvestmentId, (int) $request->user()->getAuthIdentifier(), $data
        );
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function postReinvestment(Request $request, int $reinvestmentId): JsonResponse
    {
        $row = $this->reinvestments->post(
            $this->tenantId(), $reinvestmentId, (int) $request->user()->getAuthIdentifier()
        );
        return response()->json(['success' => true, 'data' => $row]);
    }

}
