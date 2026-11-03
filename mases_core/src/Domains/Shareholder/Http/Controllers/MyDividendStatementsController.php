<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MyDividendStatementsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 403, 'A resolved tenant context is required.');
        $userId = (int) $request->user()->getAuthIdentifier();

        $query = DB::table('finance_dividend_distribution_items as i')
            ->join('finance_dividend_distributions as d', function ($join) {
                $join->on('d.id', '=', 'i.distribution_id')
                    ->on('d.tenant_id', '=', 'i.tenant_id');
            })
            ->join('shareholders as s', function ($join) {
                $join->on('s.id', '=', 'i.shareholder_id')
                    ->on('s.tenant_id', '=', 'i.tenant_id');
            })
            ->leftJoin('finance_shareholder_dividend_payables as p', function ($join) {
                $join->on('p.id', '=', 'i.payable_id')
                    ->on('p.tenant_id', '=', 'i.tenant_id');
            })
            ->leftJoin('finance_dividend_tax_assessments as t', function ($join) {
                $join->on('t.id', '=', 'i.tax_assessment_id')
                    ->on('t.tenant_id', '=', 'i.tenant_id')
                    ->on('t.distribution_id', '=', 'i.distribution_id')
                    ->on('t.shareholder_id', '=', 'i.shareholder_id');
            })
            ->where('i.tenant_id', (int) $tenantId)
            ->where('s.user_id', $userId)
            ->whereNull('s.deleted_at')
            ->whereIn('d.status', ['approved', 'posted', 'partially_paid', 'paid'])
            ->select([
                'i.id', 'd.financial_year', 'd.distribution_reference',
                'd.resolution_reference', 'd.declared_on', 'd.payment_due_on',
                'd.currency', 'i.eligible_share_quantity', 'i.gross_amount',
                'i.withholding_amount', 'i.net_amount', 'p.cash_paid_amount',
                'p.penalty_offset_amount', 'p.status as payable_status',
                'i.tax_rate_bps', 'i.tax_treatment', 't.legal_basis as tax_legal_basis',
                't.tax_certificate_number', 't.certificate_issued_at', 't.certificate_status',
            ])
            ->selectRaw("(SELECT COALESCE(SUM(r.applied_amount), 0) FROM finance_dividend_reinvestments r WHERE r.tenant_id = i.tenant_id AND r.distribution_item_id = i.id AND r.status = 'posted') AS reinvested_amount")
            ->selectRaw("(SELECT COALESCE(SUM(r.share_quantity), 0) FROM finance_dividend_reinvestments r WHERE r.tenant_id = i.tenant_id AND r.distribution_item_id = i.id AND r.status = 'posted' AND r.share_quantity IS NOT NULL) AS reinvested_share_quantity")
            ->selectRaw("(SELECT COALESCE(SUM(r.applied_amount), 0) FROM finance_dividend_reinvestments r WHERE r.tenant_id = i.tenant_id AND r.distribution_item_id = i.id AND r.status = 'posted' AND r.settlement_type = 'subscription_balance') AS subscription_balance_applied_amount")
            ->selectRaw("(SELECT COALESCE(SUM(r.unapplied_amount), 0) FROM finance_dividend_reinvestments r WHERE r.tenant_id = i.tenant_id AND r.distribution_item_id = i.id AND r.status = 'posted') AS reinvestment_unapplied_amount")
            ->selectRaw("COALESCE(p.net_payable_amount - p.cash_paid_amount - p.penalty_offset_amount - (SELECT COALESCE(SUM(r.applied_amount), 0) FROM finance_dividend_reinvestments r WHERE r.tenant_id = i.tenant_id AND r.distribution_item_id = i.id AND r.status = 'posted'), 0) AS remaining_payable_amount")
            ->orderByDesc('d.declared_on');

        $paginator = $query->paginate(min(max($request->integer('per_page', 25), 1), 100));

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
}
