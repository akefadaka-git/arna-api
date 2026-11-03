<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Models\GovernanceSettlementAccountMapping;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Domains\Finance\Models\ShareholderDividendPayable;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;

final class GovernancePenaltySettlementService
{
    public function __construct(private readonly JournalEntryService $journals) {}

    public function requestAccountMapping(int $tenantId, int $entityId, string $role, int $accountId, int $actorId, string $reason): GovernanceSettlementAccountMapping
    {
        if (!in_array($role, GovernanceSettlementAccountMapping::roles(), true) || trim($reason) === '') {
            throw ValidationException::withMessages(['mapping' => 'A supported account role and configuration reason are required.']);
        }
        return DB::transaction(function () use ($tenantId,$entityId,$role,$accountId,$actorId,$reason) {
            $entity = DB::table('legal_entities')->where('tenant_id',$tenantId)->where('id',$entityId)->where('status','active')->lockForUpdate()->first();
            $account = LedgerAccount::query()->where('tenant_id',$tenantId)->where('legal_entity_id',$entityId)->whereKey($accountId)->where('status','active')->where('is_posting_allowed',true)->first();
            if (!$entity || !$account) throw ValidationException::withMessages(['ledger_account_id'=>'An active posting account in the selected legal entity is required.']);
            $expected = match ($role) {
                GovernanceSettlementAccountMapping::ROLE_FINE_RECEIVABLE => ['asset','debit'],
                GovernanceSettlementAccountMapping::ROLE_CASH_BANK => ['asset','debit'],
                GovernanceSettlementAccountMapping::ROLE_DIVIDEND_PAYABLE => ['liability','credit'],
                GovernanceSettlementAccountMapping::ROLE_PENALTY_INCOME => ['revenue','credit'],
                GovernanceSettlementAccountMapping::ROLE_DIVIDEND_EQUITY => ['equity','debit'],
                GovernanceSettlementAccountMapping::ROLE_WITHHOLDING_TAX_PAYABLE => ['liability','credit'],
                GovernanceSettlementAccountMapping::ROLE_SHARE_SUBSCRIPTION_RECEIVABLE => ['asset','debit'],
                GovernanceSettlementAccountMapping::ROLE_SHARE_CAPITAL => ['equity','credit'],
                GovernanceSettlementAccountMapping::ROLE_SHARE_TRANSFER_PAYABLE => ['liability','credit'],
            };
            if ($account->account_type !== $expected[0] || $account->normal_balance !== $expected[1]) {
                throw ValidationException::withMessages(['ledger_account_id'=>'The account type and normal balance do not match this settlement role.']);
            }
            $active = GovernanceSettlementAccountMapping::query()->forEntity($tenantId,$entityId)->where('account_role',$role)->where('status',GovernanceSettlementAccountMapping::STATUS_APPROVED)->exists();
            if ($active) throw new ConflictHttpException('An approved mapping already exists for this role and legal entity; revoke it before replacement.');
            return GovernanceSettlementAccountMapping::query()->create([
                'tenant_id'=>$tenantId,'legal_entity_id'=>$entityId,'account_role'=>$role,'ledger_account_id'=>$accountId,
                'status'=>GovernanceSettlementAccountMapping::STATUS_PENDING,'configuration_reason'=>trim($reason),'created_by'=>$actorId,
            ]);
        });
    }

    public function approveAccountMapping(int $tenantId, int $mappingId, int $actorId): GovernanceSettlementAccountMapping
    {
        return DB::transaction(function () use ($tenantId,$mappingId,$actorId) {
            $mapping = GovernanceSettlementAccountMapping::query()->where('tenant_id',$tenantId)->lockForUpdate()->findOrFail($mappingId);
            if ($mapping->status !== GovernanceSettlementAccountMapping::STATUS_PENDING || (int)$mapping->created_by === $actorId) {
                throw ValidationException::withMessages(['mapping'=>'Only a pending mapping submitted by another officer can be approved.']);
            }
            DB::table('legal_entities')->where('tenant_id',$tenantId)->where('id',$mapping->legal_entity_id)->lockForUpdate()->firstOrFail();
            $alreadyApproved = GovernanceSettlementAccountMapping::query()->forEntity($tenantId,(int)$mapping->legal_entity_id)
                ->where('account_role',$mapping->account_role)->where('status',GovernanceSettlementAccountMapping::STATUS_APPROVED)
                ->where('id','<>',$mapping->id)->exists();
            if ($alreadyApproved) throw new ConflictHttpException('Another approved mapping already exists for this account role.');
            $account = LedgerAccount::query()->where('tenant_id',$tenantId)->where('legal_entity_id',$mapping->legal_entity_id)->whereKey($mapping->ledger_account_id)->where('status','active')->where('is_posting_allowed',true)->first();
            if (!$account) throw ValidationException::withMessages(['ledger_account_id'=>'The mapped account is no longer eligible.']);
            $expected = match ($mapping->account_role) {
                GovernanceSettlementAccountMapping::ROLE_FINE_RECEIVABLE => ['asset','debit'],
                GovernanceSettlementAccountMapping::ROLE_CASH_BANK => ['asset','debit'],
                GovernanceSettlementAccountMapping::ROLE_DIVIDEND_PAYABLE => ['liability','credit'],
                GovernanceSettlementAccountMapping::ROLE_PENALTY_INCOME => ['revenue','credit'],
                GovernanceSettlementAccountMapping::ROLE_DIVIDEND_EQUITY => ['equity','debit'],
                GovernanceSettlementAccountMapping::ROLE_WITHHOLDING_TAX_PAYABLE => ['liability','credit'],
                GovernanceSettlementAccountMapping::ROLE_SHARE_SUBSCRIPTION_RECEIVABLE => ['asset','debit'],
                GovernanceSettlementAccountMapping::ROLE_SHARE_CAPITAL => ['equity','credit'],
                GovernanceSettlementAccountMapping::ROLE_SHARE_TRANSFER_PAYABLE => ['liability','credit'],
                default => null,
            };
            if ($expected === null || [$account->account_type,$account->normal_balance] !== $expected) throw ValidationException::withMessages(['ledger_account_id'=>'Account classification changed or is invalid.']);
            $mapping->forceFill(['status'=>GovernanceSettlementAccountMapping::STATUS_APPROVED,'approved_by'=>$actorId,'approved_at'=>now()])->save();
            return $mapping->fresh('account');
        });
    }

    public function revokeAccountMapping(int $tenantId, int $mappingId, int $actorId, string $reason): GovernanceSettlementAccountMapping
    {
        return DB::transaction(function () use ($tenantId,$mappingId,$actorId,$reason) {
            $mapping = GovernanceSettlementAccountMapping::query()->where('tenant_id',$tenantId)->lockForUpdate()->findOrFail($mappingId);
            if ($mapping->status !== GovernanceSettlementAccountMapping::STATUS_APPROVED || (int)$mapping->approved_by === $actorId || trim($reason)==='') {
                throw ValidationException::withMessages(['mapping'=>'Only an approved mapping may be revoked by an officer other than its approver, with a reason.']);
            }
            $mapping->forceFill(['status'=>GovernanceSettlementAccountMapping::STATUS_REVOKED,'revoked_by'=>$actorId,'revoked_at'=>now(),'configuration_reason'=>$mapping->configuration_reason."\nRevocation: ".trim($reason)])->save();
            return $mapping;
        });
    }

    /**
     * Recognize the approved dividend distribution and withholding liability.
     * Posting is idempotent per tenant, entity and distribution. Account mappings
     * must be independently approved before this method can post.
     */
    public function accrueDividendDistribution(
        int $tenantId,
        int $entityId,
        int $distributionId,
        string $reference,
        string $effectiveDate,
        string $grossAmount,
        string $netAmount,
        string $withholdingAmount,
        int $actorId,
    ): JournalEntry {
        $gross = $this->money($grossAmount);
        $net = $this->money($netAmount);
        $withholding = $this->money($withholdingAmount);
        if (bccomp($gross, '0.0000', 4) <= 0
            || bccomp(bcadd($net, $withholding, 4), $gross, 4) !== 0
            || $distributionId < 1 || trim($reference) === '') {
            throw ValidationException::withMessages(['distribution' => 'Dividend accrual totals must reconcile exactly: gross = net payable + withholding liability.']);
        }

        $entity = DB::table('legal_entities')->where('tenant_id', $tenantId)
            ->where('id', $entityId)->where('status', 'active')->first();
        if (!$entity) {
            throw ValidationException::withMessages(['legal_entity_id' => 'An active legal entity in the trusted tenant is required.']);
        }

        $mappings = GovernanceSettlementAccountMapping::query()
            ->forEntity($tenantId, $entityId)->approved()->get()->keyBy('account_role');
        foreach ([GovernanceSettlementAccountMapping::ROLE_DIVIDEND_EQUITY,
            GovernanceSettlementAccountMapping::ROLE_DIVIDEND_PAYABLE] as $role) {
            if (!$mappings->has($role)) {
                throw ValidationException::withMessages(['account_mapping' => 'Finance must independently approve the dividend equity and shareholder payable account mappings before approval.']);
            }
        }
        if (bccomp($withholding, '0.0000', 4) > 0
            && !$mappings->has(GovernanceSettlementAccountMapping::ROLE_WITHHOLDING_TAX_PAYABLE)) {
            throw ValidationException::withMessages(['account_mapping' => 'Finance must independently approve a withholding-tax liability account mapping before approval.']);
        }

        $lines = [
            ['account_id' => (int) $mappings[GovernanceSettlementAccountMapping::ROLE_DIVIDEND_EQUITY]->ledger_account_id,
                'debit_amount' => $gross, 'credit_amount' => '0.0000', 'description' => 'Approved dividend distribution '.$reference],
            ['account_id' => (int) $mappings[GovernanceSettlementAccountMapping::ROLE_DIVIDEND_PAYABLE]->ledger_account_id,
                'debit_amount' => '0.0000', 'credit_amount' => $net, 'description' => 'Net shareholder dividend payable '.$reference],
        ];
        if (bccomp($withholding, '0.0000', 4) > 0) {
            $lines[] = [
                'account_id' => (int) $mappings[GovernanceSettlementAccountMapping::ROLE_WITHHOLDING_TAX_PAYABLE]->ledger_account_id,
                'debit_amount' => '0.0000', 'credit_amount' => $withholding,
                'description' => 'Dividend withholding-tax liability '.$reference,
            ];
        }

        $journal = $this->journals->createDraft($tenantId, $actorId, [
            'legal_entity_id' => $entityId,
            'journal_number' => 'DIV-ACR-'.$distributionId,
            'effective_date' => $effectiveDate,
            'currency' => (string) $entity->base_currency,
            'description' => 'Dividend accrual '.$reference,
            'source_type' => JournalSourceType::SYSTEM->value,
            'idempotency_key' => 'dividend-accrual:'.$tenantId.':'.$entityId.':'.$distributionId,
        ], $lines);

        if ($journal->status === JournalEntry::DRAFT) {
            return $this->journals->post($tenantId, $actorId, (int) $journal->id);
        }
        if ($journal->status !== JournalEntry::POSTED) {
            throw new ConflictHttpException('The idempotent dividend accrual journal exists but is not posted.');
        }
        return $journal->load('lines');
    }

    /**
     * Reverse the posted accrual journal for a legally cancelled dividend.
     * The general ledger owns reversal mechanics and enforces an open period.
     */
    public function reverseDividendAccrual(
        int $tenantId,
        int $entityId,
        int $distributionId,
        string $journalNumber,
        string $effectiveDate,
        string $reason,
        int $actorId,
    ): JournalEntry {
        $original = JournalEntry::query()->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $entityId)->where('journal_number', $journalNumber)->first();
        if (!$original) {
            throw ValidationException::withMessages(['accrual_journal_reference' => 'The original dividend accrual journal cannot be found.']);
        }
        return $this->journals->reverse(
            $tenantId, $actorId, (int) $original->id,
            'DIV-REV-'.$distributionId, $effectiveDate, $reason
        );
    }

    public function createDividendPayable(int $tenantId, int $entityId, int $shareholderId, string $reference, string $declaredOn, string $dueOn, string $amount, int $actorId): ShareholderDividendPayable
    {
        $amount = $this->money($amount);
        if (bccomp($amount,'0.0000',4) <= 0 || trim($reference)==='') throw ValidationException::withMessages(['net_payable_amount'=>'A positive net payable and distribution reference are required.']);
        $shareholder = DB::table('shareholders')->where('tenant_id',$tenantId)->where('id',$shareholderId)->where('is_active',true)->whereNull('deleted_at')->exists();
        $entity = DB::table('legal_entities')->where('tenant_id',$tenantId)->where('id',$entityId)->where('status','active')->exists();
        if (!$shareholder || !$entity) throw ValidationException::withMessages(['shareholder_id'=>'An active shareholder and legal entity in this tenant are required.']);
        return ShareholderDividendPayable::query()->create([
            'tenant_id'=>$tenantId,'legal_entity_id'=>$entityId,'shareholder_id'=>$shareholderId,'distribution_reference'=>trim($reference),
            'declared_on'=>$declaredOn,'due_on'=>$dueOn,'net_payable_amount'=>$amount,'cash_paid_amount'=>'0.0000',
            'penalty_offset_amount'=>'0.0000','status'=>ShareholderDividendPayable::STATUS_DRAFT,'created_by'=>$actorId,
        ]);
    }

    public function approveDividendPayable(int $tenantId, int $payableId, int $actorId, string $approvalReference): ShareholderDividendPayable
    {
        return DB::transaction(function () use ($tenantId,$payableId,$actorId,$approvalReference) {
            $p = ShareholderDividendPayable::query()->where('tenant_id',$tenantId)->lockForUpdate()->findOrFail($payableId);
            if ($p->status !== ShareholderDividendPayable::STATUS_DRAFT || (int)$p->created_by === $actorId || trim($approvalReference)==='') throw ValidationException::withMessages(['payable'=>'Only a draft payable with an approval reference may be approved.']);
            $p->forceFill(['status'=>ShareholderDividendPayable::STATUS_PAYABLE,'approval_reference'=>trim($approvalReference),'approved_by'=>$actorId,'approved_at'=>now()])->save();
            return $p;
        });
    }

    public function executeCashSettlement(int $tenantId, int $settlementId, int $actorId, string $effectiveDate, string $idempotencyKey): array
    {
        return $this->execute($tenantId,$settlementId,$actorId,$effectiveDate,$idempotencyKey,'cash',null);
    }

    public function executeDividendOffset(int $tenantId, int $settlementId, int $payableId, int $actorId, string $effectiveDate, string $idempotencyKey): array
    {
        return $this->execute($tenantId,$settlementId,$actorId,$effectiveDate,$idempotencyKey,'dividend_offset',$payableId);
    }

    /**
     * Posts the fine accrual and its cash/offset settlement atomically. The penalty
     * row lock serializes partial settlements; the caller key makes retries safe.
     *
     * @return array<string, int|string|bool>
     */
    private function execute(int $tenantId,int $settlementId,int $actorId,string $effectiveDate,string $key,string $method,?int $payableId): array
    {
        return DB::transaction(function () use ($tenantId,$settlementId,$actorId,$effectiveDate,$key,$method,$payableId) {
            if (trim($key)==='' || strlen($key)>128 || str_starts_with($key,'gps-accrual:')) throw ValidationException::withMessages(['idempotency_key'=>'A unique caller-supplied idempotency key of at most 128 characters is required.']);
            $s = DB::table('governance_penalty_settlements')->where('tenant_id',$tenantId)->where('id',$settlementId)->lockForUpdate()->first();
            if (!$s || $s->method !== $method) throw ValidationException::withMessages(['settlement'=>'Settlement does not exist for this method in the trusted tenant.']);
            $existing = DB::table('journal_entries')->where('tenant_id',$tenantId)->where('idempotency_key',$key)->first();
            if ($existing) {
                if ($existing->source_type !== JournalSourceType::GOVERNANCE_PENALTY_SETTLEMENT->value || (string)$existing->source_id !== (string)$settlementId) throw new ConflictHttpException('Idempotency key is already used for another transaction.');
                if ($existing->status !== 'posted') throw new ConflictHttpException('An idempotent settlement journal exists but is not posted.');
                return ['journal_id'=>(int)$existing->id,'journal_number'=>$existing->journal_number,'settlement_id'=>$settlementId,'status'=>'settled','method'=>$method,'idempotent_replay'=>true];
            }
            if (!in_array($s->status,['pending_finance','pending_legal_finance_review'],true)) throw ValidationException::withMessages(['settlement'=>'Settlement is not pending Finance execution.']);
            $penalty = DB::table('governance_meeting_penalties')->where('tenant_id',$tenantId)->where('id',$s->penalty_id)->lockForUpdate()->first();
            if (!$penalty || $penalty->status !== 'assessed') throw ValidationException::withMessages(['penalty'=>'Penalty is disputed, waived, or already settled.']);
            $meeting = DB::table('governance_meetings')->where('tenant_id',$tenantId)->where('id',$penalty->meeting_id)->first();
            if (!$meeting) throw ValidationException::withMessages(['meeting'=>'Meeting not found in this tenant.']);
            $attendance = DB::table('governance_meeting_attendance')->where('tenant_id',$tenantId)->where('id',$penalty->attendance_id)->first();
            if (!$attendance) throw ValidationException::withMessages(['attendance'=>'Attendance source is missing.']);
            $cents = (int)$s->amount_cents;
            $amount = intdiv($cents,100).'.'.str_pad((string)($cents % 100),2,'0',STR_PAD_LEFT);
            if ((int)$s->amount_cents < 1 || $s->currency !== 'ETB') throw ValidationException::withMessages(['amount'=>'Only positive ETB settlements are supported.']);
            $entityMapping = GovernanceSettlementAccountMapping::query()->forEntity($tenantId,(int)$this->resolveEntity($tenantId,$settlementId))->approved()->get()->keyBy('account_role');
            $entityId = (int)$this->resolveEntity($tenantId,$settlementId);
            foreach ([GovernanceSettlementAccountMapping::ROLE_FINE_RECEIVABLE, GovernanceSettlementAccountMapping::ROLE_PENALTY_INCOME, $method==='cash'?GovernanceSettlementAccountMapping::ROLE_CASH_BANK:GovernanceSettlementAccountMapping::ROLE_DIVIDEND_PAYABLE] as $role) {
                if (!$entityMapping->has($role)) throw ValidationException::withMessages(['account_mapping'=>'Finance must approve all required settlement account mappings first.']);
            }
            $debitRole = $method==='cash'?GovernanceSettlementAccountMapping::ROLE_CASH_BANK:GovernanceSettlementAccountMapping::ROLE_DIVIDEND_PAYABLE;
            $debitAccount = (int)$entityMapping[$debitRole]->ledger_account_id;
            $receivableAccount = (int)$entityMapping[GovernanceSettlementAccountMapping::ROLE_FINE_RECEIVABLE]->ledger_account_id;
            $incomeAccount = (int)$entityMapping[GovernanceSettlementAccountMapping::ROLE_PENALTY_INCOME]->ledger_account_id;
            if ($method==='dividend_offset') {
                $payable = ShareholderDividendPayable::query()->where('tenant_id',$tenantId)->where('legal_entity_id',$entityId)->whereKey($payableId)->lockForUpdate()->first();
                if (!$payable || !$payable->isEligibleForPenaltyOffset()) throw ValidationException::withMessages(['dividend_payable'=>'A real, approved, due shareholder dividend payable is required.']);
                $shareholder = DB::table('shareholders')->where('tenant_id',$tenantId)->where('id',$payable->shareholder_id)->first(['user_id','representative_user_id']);
                $sameBeneficiary = $attendance->subject_type === 'shareholder'
                    ? (int)$attendance->subject_id === (int)$payable->shareholder_id
                    : (function () use ($tenantId,$attendance,$shareholder): bool {
                        if (!$shareholder) return false;
                        $membership = DB::table('governance_memberships')->where('tenant_id',$tenantId)->where('id',$attendance->subject_id)->first(['user_id']);
                        $memberUserId = (int)($membership->user_id ?? 0);
                        return $memberUserId > 0 && $memberUserId === (int)($shareholder->user_id ?? $shareholder->representative_user_id ?? 0);
                    })();
                if (!$sameBeneficiary) throw ValidationException::withMessages(['dividend_payable'=>'Dividend offset is allowed only where the penalty subject is the same shareholder or the Board director is the shareholder beneficiary.']);
                if (bccomp($payable->remainingPayable(),$amount,4)<0) throw ValidationException::withMessages(['dividend_payable'=>'Remaining dividend payable is less than the penalty amount.']);
            }
            // Recognize the assessed fine as a receivable and penalty income
            // in the same transaction as settlement. Both journals must post.
            $accrualKey = 'gps-accrual:'.$settlementId;
            $accrual = $this->journals->createDraft($tenantId,$actorId,[
                'legal_entity_id'=>$entityId,'journal_number'=>'GPA-'.$settlementId,
                'effective_date'=>$effectiveDate,'currency'=>'ETB','description'=>'Recognition of Governance penalty #'.$penalty->id,
                'source_type'=>JournalSourceType::GOVERNANCE_PENALTY_SETTLEMENT->value,'source_id'=>(string)$settlementId,'idempotency_key'=>$accrualKey,
            ],[
                ['account_id'=>$receivableAccount,'debit_amount'=>$amount,'credit_amount'=>'0'],
                ['account_id'=>$incomeAccount,'debit_amount'=>'0','credit_amount'=>$amount],
            ]);
            if ($accrual->status === 'draft') $this->journals->post($tenantId,$actorId,(int)$accrual->id);
            elseif ($accrual->status !== 'posted') throw new ConflictHttpException('Penalty accrual journal exists but is not posted.');

            $journal = $this->journals->createDraft($tenantId,$actorId,[
                'legal_entity_id'=>$entityId,'journal_number'=>'GPS-'.$settlementId.'-'.substr(hash('sha256',$key),0,8),
                'effective_date'=>$effectiveDate,'currency'=>'ETB','description'=>($method==='cash'?'Cash receipt':'Dividend offset').' for Governance penalty #'.$penalty->id,
                'source_type'=>JournalSourceType::GOVERNANCE_PENALTY_SETTLEMENT->value,'source_id'=>(string)$settlementId,'idempotency_key'=>$key,
            ],[
                ['account_id'=>$debitAccount,'debit_amount'=>$amount,'credit_amount'=>'0'],
                ['account_id'=>$receivableAccount,'debit_amount'=>'0','credit_amount'=>$amount],
            ]);
            $posted = $this->journals->post($tenantId,$actorId,(int)$journal->id);
            if ($method==='dividend_offset') {
                $payable->penalty_offset_amount = bcadd((string)$payable->penalty_offset_amount,$amount,4);
                if (bccomp($payable->remainingPayable(),'0',4)===0) $payable->status=ShareholderDividendPayable::STATUS_SETTLED;
                $payable->save();
            }
            DB::table('governance_penalty_settlements')->where('tenant_id',$tenantId)->where('id',$settlementId)->update(['status'=>'settled','reference'=>'journal:'.$posted->id,'updated_at'=>now()]);
            $settledCents = (int) DB::table('governance_penalty_settlements')->where('tenant_id',$tenantId)->where('penalty_id',$penalty->id)->where('status','settled')->sum('amount_cents');
            $penaltyStatus = $settledCents >= (int)$penalty->amount_cents ? 'settled' : 'assessed';
            DB::table('governance_meeting_penalties')->where('tenant_id',$tenantId)->where('id',$penalty->id)->update(['status'=>$penaltyStatus,'updated_at'=>now()]);
            DB::table('governance_meeting_activity')->insert(['tenant_id'=>$tenantId,'meeting_id'=>$meeting->id,'actor_id'=>$actorId,'action'=>'penalty.settlement.executed','payload'=>json_encode(['settlement_id'=>$settlementId,'journal_id'=>$posted->id,'method'=>$method,'amount'=>$amount,'payable_id'=>$payableId]),'occurred_at'=>now()]);
            return ['journal_id'=>(int)$posted->id,'journal_number'=>$posted->journal_number,'settlement_id'=>$settlementId,'status'=>'settled','method'=>$method,'amount'=>$amount,'idempotent_replay'=>false];
        });
    }


    /**
     * Post the balanced GL settlement for a dividend reinvestment.
     * The debit extinguishes the shareholder dividend payable; the credit
     * recognizes subscription receivable recovery, issued share capital,
     * or the consideration payable to an existing-share seller.
     */
    public function postDividendReinvestment(
        int $tenantId,
        int $entityId,
        int $reinvestmentId,
        int $actorId,
        string $settlementType,
        string $occurredOn,
        string $amount,
        bool $historical = false,
    ): JournalEntry {
        $value = $this->money($amount);
        $creditRole = match ($settlementType) {
            'subscription_balance' => GovernanceSettlementAccountMapping::ROLE_SHARE_SUBSCRIPTION_RECEIVABLE,
            'new_company_allotment' => GovernanceSettlementAccountMapping::ROLE_SHARE_CAPITAL,
            'existing_share_transfer' => GovernanceSettlementAccountMapping::ROLE_SHARE_TRANSFER_PAYABLE,
            default => throw ValidationException::withMessages(['settlement_type' => 'Unsupported dividend reinvestment settlement type.']),
        };
        if (bccomp($value, '0.0000', 4) <= 0) {
            throw ValidationException::withMessages(['amount' => 'A positive reinvestment settlement amount is required.']);
        }
        $entity = DB::table('legal_entities')->where('tenant_id', $tenantId)
            ->where('id', $entityId)->where('status', 'active')->where('base_currency', 'ETB')->first();
        if (!$entity) {
            throw ValidationException::withMessages(['legal_entity_id' => 'An active ETB legal entity is required for dividend reinvestment posting.']);
        }
        $mappings = GovernanceSettlementAccountMapping::query()
            ->forEntity($tenantId, $entityId)->approved()->get()->keyBy('account_role');
        foreach ([GovernanceSettlementAccountMapping::ROLE_DIVIDEND_PAYABLE, $creditRole] as $role) {
            if (!$mappings->has($role)) {
                throw ValidationException::withMessages(['account_mapping' => 'Finance must approve dividend payable and the matching share/subscription settlement account mappings before posting reinvestment.']);
            }
        }
        $journal = $this->journals->createDraft($tenantId, $actorId, [
            'legal_entity_id' => $entityId,
            'journal_number' => 'DIV-REINV-'.$reinvestmentId,
            'effective_date' => $historical ? today()->toDateString() : $occurredOn,
            'currency' => 'ETB',
            'description' => 'Dividend reinvestment settlement #'.$reinvestmentId.($historical ? ' · historical event date '.$occurredOn : ''),
            'source_type' => JournalSourceType::SYSTEM->value,
            'idempotency_key' => 'dividend-reinvestment:'.$tenantId.':'.$entityId.':'.$reinvestmentId,
        ], [
            ['account_id' => (int) $mappings[GovernanceSettlementAccountMapping::ROLE_DIVIDEND_PAYABLE]->ledger_account_id,
                'debit_amount' => $value, 'credit_amount' => '0.0000', 'description' => 'Settle shareholder dividend payable'],
            ['account_id' => (int) $mappings[$creditRole]->ledger_account_id,
                'debit_amount' => '0.0000', 'credit_amount' => $value, 'description' => 'Recognize reinvestment settlement'],
        ]);
        if ($journal->status === JournalEntry::DRAFT) {
            return $this->journals->post($tenantId, $actorId, (int) $journal->id)->load('lines');
        }
        if ($journal->status !== JournalEntry::POSTED) {
            throw new ConflictHttpException('The reinvestment settlement journal exists but is not posted.');
        }
        return $journal->load('lines');
    }

    /**
     * Settle the consideration payable to a selling shareholder after an
     * existing-share transfer. This is a separately evidenced bank event.
     */
    public function settleDividendShareTransfer(
        int $tenantId,
        int $entityId,
        int $reinvestmentId,
        int $actorId,
        string $effectiveDate,
        string $amount,
        string $bankReference,
        string $evidenceReference,
    ): JournalEntry {
        $value = $this->money($amount);
        if (bccomp($value, '0.0000', 4) <= 0 || trim($bankReference) === '' || trim($evidenceReference) === '') {
            throw ValidationException::withMessages(['seller_settlement' => 'Positive consideration, bank reference and payment evidence are required.']);
        }
        $mappings = GovernanceSettlementAccountMapping::query()
            ->forEntity($tenantId, $entityId)->approved()->get()->keyBy('account_role');
        foreach ([GovernanceSettlementAccountMapping::ROLE_SHARE_TRANSFER_PAYABLE, GovernanceSettlementAccountMapping::ROLE_CASH_BANK] as $role) {
            if (!$mappings->has($role)) {
                throw ValidationException::withMessages(['account_mapping' => 'Approved share-transfer payable and cash/bank account mappings are required to settle the seller.']);
            }
        }
        $journal = $this->journals->createDraft($tenantId, $actorId, [
            'legal_entity_id' => $entityId,
            'journal_number' => 'DIV-REINV-SELLER-'.$reinvestmentId,
            'effective_date' => $effectiveDate,
            'currency' => 'ETB',
            'description' => 'Settle existing-share seller consideration #'.$reinvestmentId.' · '.$bankReference,
            'source_type' => JournalSourceType::SYSTEM->value,
            'idempotency_key' => 'dividend-share-transfer-settlement:'.$tenantId.':'.$entityId.':'.$reinvestmentId,
        ], [
            ['account_id' => (int) $mappings[GovernanceSettlementAccountMapping::ROLE_SHARE_TRANSFER_PAYABLE]->ledger_account_id,
                'debit_amount' => $value, 'credit_amount' => '0.0000', 'description' => 'Clear share-transfer consideration payable'],
            ['account_id' => (int) $mappings[GovernanceSettlementAccountMapping::ROLE_CASH_BANK]->ledger_account_id,
                'debit_amount' => '0.0000', 'credit_amount' => $value, 'description' => 'Bank payment to selling shareholder'],
        ]);
        if ($journal->status === JournalEntry::DRAFT) {
            return $this->journals->post($tenantId, $actorId, (int) $journal->id)->load('lines');
        }
        if ($journal->status !== JournalEntry::POSTED) {
            throw new ConflictHttpException('The seller consideration journal exists but is not posted.');
        }
        return $journal->load('lines');
    }

    private function resolveEntity(int $tenantId,int $settlementId): int
    {
        $mapping = DB::table('journal_source_entity_mappings')->where('tenant_id',$tenantId)->where('source_type',JournalSourceType::GOVERNANCE_PENALTY_SETTLEMENT->value)->where('source_id',(string)$settlementId)->where('status','approved')->whereNotNull('approved_by')->whereNotNull('approved_at')->limit(2)->get();
        if ($mapping->count() !== 1) throw ValidationException::withMessages(['legal_entity_id'=>'Exactly one approved Finance source-to-entity mapping is required.']);
        return (int)$mapping->first()->legal_entity_id;
    }

    private function money(string $v): string
    {
        $v=trim($v); if(!preg_match('/^\d{1,16}(?:\.\d{1,4})?$/',$v)) throw ValidationException::withMessages(['amount'=>'Invalid decimal amount.']);
        [$w,$f]=array_pad(explode('.',$v,2),2,''); return (ltrim($w,'0')?:'0').'.'.str_pad($f,4,'0');
    }
}
