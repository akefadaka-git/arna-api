<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Finance\\Http\\Controllers;

use App\\Http\\Controllers\\Controller;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Validation\\ValidationException;
use MaaleArna\\MASES\\Core\\Domains\\Finance\\Models\\GovernanceSettlementAccountMapping;
use MaaleArna\\MASES\\Core\\Domains\\Finance\\Models\\ShareholderDividendPayable;
use MaaleArna\\MASES\\Core\\Domains\\Finance\\Services\\GovernancePenaltySettlementService;
use MaaleArna\\MASES\\Core\\Tenancy\\Services\\TenantContextService;

final class GovernancePenaltySettlementController extends Controller
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly GovernancePenaltySettlementService $settlements,
    ) {}

    private function tenantId(): int
    {
        $id=$this->tenantContext->getTenantId();
        abort_if($id===null,403,'A resolved tenant context is required.');
        return (int)$id;
    }

    public function accountMappings(Request $request): JsonResponse
    {
        $q=GovernanceSettlementAccountMapping::query()->where('tenant_id',$this->tenantId())->with('account:id,code,name_en,account_type,normal_balance');
        if($request->filled('legal_entity_id')) $q->where('legal_entity_id',$request->integer('legal_entity_id'));
        return response()->json(['success'=>true,'data'=>$q->orderByDesc('id')->paginate(min(max($request->integer('per_page',25),1),100))]);
    }

    public function requestAccountMapping(Request $request): JsonResponse
    {
        $d=$request->validate(['legal_entity_id'=>['required','integer','min:1'],'account_role'=>['required','in:fine_receivable,cash_bank,dividend_payable,penalty_income'],'ledger_account_id'=>['required','integer','min:1'],'reason'=>['required','string','min:5','max:2000']]);
        $row=$this->settlements->requestAccountMapping($this->tenantId(),(int)$d['legal_entity_id'],$d['account_role'],(int)$d['ledger_account_id'],(int)$request->user()->getAuthIdentifier(),$d['reason']);
        return response()->json(['success'=>true,'data'=>$row],201);
    }

    public function approveAccountMapping(Request $request,int $mappingId): JsonResponse
    {
        $row=$this->settlements->approveAccountMapping($this->tenantId(),$mappingId,(int)$request->user()->getAuthIdentifier());
        return response()->json(['success'=>true,'data'=>$row]);
    }

    public function revokeAccountMapping(Request $request,int $mappingId): JsonResponse
    {
        $d=$request->validate(['reason'=>['required','string','min:5','max:2000']]);
        $row=$this->settlements->revokeAccountMapping($this->tenantId(),$mappingId,(int)$request->user()->getAuthIdentifier(),$d['reason']);
        return response()->json(['success'=>true,'data'=>$row]);
    }

    public function dividendPayables(Request $request): JsonResponse
    {
        $q=ShareholderDividendPayable::query()->where('tenant_id',$this->tenantId());
        foreach(['legal_entity_id','shareholder_id','status'] as $key) if($request->filled($key)) $q->where($key,$request->input($key));
        return response()->json(['success'=>true,'data'=>$q->orderByDesc('id')->paginate(min(max($request->integer('per_page',25),1),100))]);
    }

    public function createDividendPayable(Request $request): JsonResponse
    {
        $d=$request->validate(['legal_entity_id'=>['required','integer','min:1'],'shareholder_id'=>['required','integer','min:1'],'distribution_reference'=>['required','string','max:100'],'declared_on'=>['required','date'],'due_on'=>['required','date','after_or_equal:declared_on'],'net_payable_amount'=>['required','numeric','gt:0','decimal:0,4']]);
        $row=$this->settlements->createDividendPayable($this->tenantId(),(int)$d['legal_entity_id'],(int)$d['shareholder_id'],$d['distribution_reference'],$d['declared_on'],$d['due_on'],(string)$d['net_payable_amount'],(int)$request->user()->getAuthIdentifier());
        return response()->json(['success'=>true,'data'=>$row],201);
    }

    public function approveDividendPayable(Request $request,int $payableId): JsonResponse
    {
        $d=$request->validate(['approval_reference'=>['required','string','min:3','max:500']]);
        $row=$this->settlements->approveDividendPayable($this->tenantId(),$payableId,(int)$request->user()->getAuthIdentifier(),$d['approval_reference']);
        return response()->json(['success'=>true,'data'=>$row]);
    }

    public function executeCash(Request $request,int $settlementId): JsonResponse
    {
        $d=$request->validate(['effective_date'=>['required','date'],'idempotency_key'=>['required','string','max:128']]);
        $result=$this->settlements->executeCashSettlement($this->tenantId(),$settlementId,(int)$request->user()->getAuthIdentifier(),$d['effective_date'],$d['idempotency_key']);
        return response()->json(['success'=>true,'data'=>$result],$result['idempotent_replay']?200:201);
    }

    public function executeDividendOffset(Request $request,int $settlementId): JsonResponse
    {
        $d=$request->validate(['dividend_payable_id'=>['required','integer','min:1'],'effective_date'=>['required','date'],'idempotency_key'=>['required','string','max:128']]);
        $result=$this->settlements->executeDividendOffset($this->tenantId(),$settlementId,(int)$d['dividend_payable_id'],(int)$request->user()->getAuthIdentifier(),$d['effective_date'],$d['idempotency_key']);
        return response()->json(['success'=>true,'data'=>$result],$result['idempotent_replay']?200:201);
    }

    public function reconciliation(Request $request): JsonResponse
    {
        $d=$request->validate(['legal_entity_id'=>['required','integer','min:1']]);
        $tenant=$this->tenantId();
        $rows=DB::table('governance_penalty_settlements as s')
            ->join('governance_meeting_penalties as p','p.id','=','s.penalty_id')
            ->join('governance_meetings as m','m.id','=','p.meeting_id')
            ->leftJoin('journal_entries as j',function($join){$join->on('j.source_id','=','s.id')->where('j.source_type','=','governance_penalty_settlement');})
            ->where('s.tenant_id',$tenant)->where('p.tenant_id',$tenant)
            ->whereIn('s.status',['settled','pending_finance','pending_legal_finance_review'])
            ->select(['s.id as settlement_id','s.penalty_id','s.method','s.amount_cents','s.currency','s.status as settlement_status','s.reference','m.meeting_code','j.id as journal_id','j.journal_number','j.status as journal_status','j.legal_entity_id'])
            ->orderByDesc('s.id')->get()
            ->filter(fn($r)=>$r->journal_id===null || (int)$r->legal_entity_id===(int)$d['legal_entity_id'])->values();
        return response()->json(['success'=>true,'data'=>$rows]);
    }
}
