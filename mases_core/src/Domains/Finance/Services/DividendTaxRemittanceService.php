<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Models\GovernanceSettlementAccountMapping;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;

final class DividendTaxRemittanceService
{
    public function __construct(private readonly JournalEntryService $journals) {}

    public function create(
        int $tenantId,
        int $entityId,
        int $distributionId,
        int $actorId,
        string $reference,
        string $authorityReference,
        string $amount,
    ): object {
        $amount = $this->money($amount);
        if (trim($reference) === '' || trim($authorityReference) === '' || bccomp($amount, '0.0000', 4) <= 0) {
            throw ValidationException::withMessages(['remittance' => 'A positive amount, unique remittance reference and tax-authority reference are required.']);
        }
        return DB::transaction(function () use ($tenantId, $entityId, $distributionId, $actorId, $reference, $authorityReference, $amount) {
            $d = DB::table('finance_dividend_distributions')->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $entityId)->where('id', $distributionId)
                ->whereIn('status', ['approved', 'paid', 'partially_paid'])->lockForUpdate()->first();
            if (!$d) {
                throw ValidationException::withMessages(['distribution_id' => 'Tax remittance requires an approved dividend distribution.']);
            }
            $paidAllocations = DB::table('finance_dividend_distribution_items as i')
                ->join('finance_dividend_payment_batch_items as bi', function ($join) {
                    $join->on('bi.payable_id', '=', 'i.payable_id')
                        ->on('bi.tenant_id', '=', 'i.tenant_id');
                })
                ->join('finance_dividend_payment_batches as b', function ($join) {
                    $join->on('b.id', '=', 'bi.batch_id')->on('b.tenant_id', '=', 'bi.tenant_id');
                })
                ->where('i.tenant_id', $tenantId)->where('i.distribution_id', $distributionId)
                ->where('bi.status', 'paid')
                ->groupBy('i.id', 'i.withholding_amount', 'i.net_amount')
                ->select('i.id', 'i.withholding_amount', 'i.net_amount')
                ->selectRaw('SUM(bi.amount) AS paid_net_amount')->get();
            $reinvestedAllocations = DB::table('finance_dividend_reinvestments')
                ->where('tenant_id', $tenantId)->where('distribution_id', $distributionId)
                ->where('status', 'posted')
                ->groupBy('distribution_item_id')
                ->select('distribution_item_id')
                ->selectRaw('SUM(applied_amount) AS reinvested_net_amount')
                ->get()->keyBy('distribution_item_id');
            $withholdingEarned = '0.0000';
            foreach ($paidAllocations as $allocation) {
                if (bccomp((string) $allocation->net_amount, '0.0000', 4) <= 0) continue;
                $reinvestedNet = (string) ($reinvestedAllocations->get($allocation->id)->reinvested_net_amount ?? '0.0000');
                $settledNet = bcadd((string) $allocation->paid_net_amount, $reinvestedNet, 4);
                $paidNet = bccomp($settledNet, (string) $allocation->net_amount, 4) > 0
                    ? (string) $allocation->net_amount : $settledNet;
                $withholdingEarned = bcadd(
                    $withholdingEarned,
                    bcdiv(bcmul((string) $allocation->withholding_amount, $paidNet, 8), (string) $allocation->net_amount, 4),
                    4
                );
            }
            $reserved = (string) DB::table('finance_dividend_tax_remittances')->where('tenant_id', $tenantId)
                ->where('distribution_id', $distributionId)->whereIn('status', ['draft', 'approved', 'paid'])->sum('amount');
            $outstanding = bcsub($withholdingEarned, $reserved, 4);
            if (bccomp($amount, $outstanding, 4) > 0) {
                throw ValidationException::withMessages(['amount' => 'The remittance exceeds withholding tax actually deducted from confirmed cash payments and posted dividend reinvestments, after existing remittance reservations and payments.']);
            }
            $id = DB::table('finance_dividend_tax_remittances')->insertGetId([
                'tenant_id' => $tenantId, 'legal_entity_id' => $entityId,
                'distribution_id' => $distributionId, 'remittance_reference' => trim($reference),
                'amount' => $amount, 'currency' => 'ETB', 'status' => 'draft',
                'tax_authority_reference' => trim($authorityReference), 'created_by' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return DB::table('finance_dividend_tax_remittances')->where('tenant_id', $tenantId)->where('id', $id)->first();
        });
    }

    public function approve(int $tenantId, int $remittanceId, int $actorId): object
    {
        return DB::transaction(function () use ($tenantId, $remittanceId, $actorId) {
            $row = DB::table('finance_dividend_tax_remittances')->where('tenant_id', $tenantId)
                ->where('id', $remittanceId)->lockForUpdate()->first();
            if (!$row || $row->status !== 'draft' || (int) $row->created_by === $actorId) {
                throw ValidationException::withMessages(['remittance' => 'A different Finance officer must approve a draft tax remittance.']);
            }
            DB::table('finance_dividend_tax_remittances')->where('tenant_id', $tenantId)->where('id', $remittanceId)
                ->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now(), 'updated_at' => now()]);
            return DB::table('finance_dividend_tax_remittances')->where('tenant_id', $tenantId)->where('id', $remittanceId)->first();
        });
    }

    public function cancel(int $tenantId, int $remittanceId, int $actorId, string $reason): object
    {
        return DB::transaction(function () use ($tenantId, $remittanceId, $actorId, $reason) {
            $row = DB::table('finance_dividend_tax_remittances')->where('tenant_id', $tenantId)
                ->where('id', $remittanceId)->lockForUpdate()->first();
            if (!$row || !in_array($row->status, ['draft', 'approved'], true)
                || (int) $row->created_by === $actorId || trim($reason) === '') {
                throw ValidationException::withMessages(['remittance' => 'Only an unpaid remittance may be cancelled by an independent officer with a reason.']);
            }
            DB::table('finance_dividend_tax_remittances')->where('tenant_id', $tenantId)->where('id', $remittanceId)
                ->update(['status' => 'cancelled', 'cancellation_reason' => trim($reason), 'cancelled_by' => $actorId, 'cancelled_at' => now(), 'updated_at' => now()]);
            return DB::table('finance_dividend_tax_remittances')->where('tenant_id', $tenantId)->where('id', $remittanceId)->first();
        });
    }

    public function confirm(
        int $tenantId,
        int $remittanceId,
        int $actorId,
        string $bankReference,
        string $evidenceReference,
        string $paidOn,
    ): object {
        return DB::transaction(function () use ($tenantId, $remittanceId, $actorId, $bankReference, $evidenceReference, $paidOn) {
            $row = DB::table('finance_dividend_tax_remittances')->where('tenant_id', $tenantId)
                ->where('id', $remittanceId)->lockForUpdate()->first();
            if (!$row) {
                throw ValidationException::withMessages(['remittance' => 'Tax remittance not found in the resolved tenant.']);
            }
            if ($row->status === 'paid' && hash_equals((string) $row->bank_reference, trim($bankReference))) {
                return $row;
            }
            if ($row->status !== 'approved' || (int) $row->created_by === $actorId
                || trim($bankReference) === '' || trim($evidenceReference) === '') {
                throw ValidationException::withMessages(['remittance' => 'A different officer must confirm an approved tax remittance with bank and authority evidence.']);
            }
            if (DB::table('finance_dividend_tax_remittances')->where('tenant_id', $tenantId)
                ->where('bank_reference', trim($bankReference))->exists()) {
                throw ConflictHttpException('The bank reference has already been used for a tax remittance in this tenant.');
            }
            $mappings = GovernanceSettlementAccountMapping::query()
                ->forEntity($tenantId, (int) $row->legal_entity_id)->approved()->get()->keyBy('account_role');
            foreach ([GovernanceSettlementAccountMapping::ROLE_WITHHOLDING_TAX_PAYABLE,
                GovernanceSettlementAccountMapping::ROLE_CASH_BANK] as $role) {
                if (!$mappings->has($role)) {
                    throw ValidationException::withMessages(['account_mapping' => 'Approved withholding-tax liability and bank account mappings are required before remittance.']);
                }
            }
            $journal = $this->journals->createDraft($tenantId, $actorId, [
                'legal_entity_id' => (int) $row->legal_entity_id,
                'journal_number' => 'DIV-TAX-REM-'.$remittanceId,
                'effective_date' => $paidOn, 'currency' => 'ETB',
                'description' => 'Dividend withholding tax remittance '.$row->remittance_reference,
                'source_type' => JournalSourceType::SYSTEM->value,
                'idempotency_key' => 'dividend-tax-remittance:'.$tenantId.':'.$remittanceId,
            ], [
                ['account_id' => (int) $mappings[GovernanceSettlementAccountMapping::ROLE_WITHHOLDING_TAX_PAYABLE]->ledger_account_id,
                    'debit_amount' => (string) $row->amount, 'credit_amount' => '0.0000'],
                ['account_id' => (int) $mappings[GovernanceSettlementAccountMapping::ROLE_CASH_BANK]->ledger_account_id,
                    'debit_amount' => '0.0000', 'credit_amount' => (string) $row->amount],
            ]);
            if ($journal->status === JournalEntry::DRAFT) {
                $journal = $this->journals->post($tenantId, $actorId, (int) $journal->id);
            } elseif ($journal->status !== JournalEntry::POSTED) {
                throw new ConflictHttpException('The idempotent tax remittance journal exists but is not posted.');
            }
            DB::table('finance_dividend_tax_remittances')->where('tenant_id', $tenantId)->where('id', $remittanceId)
                ->update([
                    'status' => 'paid', 'bank_reference' => trim($bankReference),
                    'evidence_reference' => trim($evidenceReference), 'journal_reference' => (string) $journal->journal_number,
                    'paid_on' => $paidOn, 'confirmed_by' => $actorId, 'confirmed_at' => now(), 'updated_at' => now(),
                ]);
            return DB::table('finance_dividend_tax_remittances')->where('tenant_id', $tenantId)->where('id', $remittanceId)->first();
        });
    }

    private function money(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^\d{1,16}(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Amount must be a non-negative decimal with at most four fractional digits.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return ltrim($whole, '0') === '' ? '0.'.str_pad($fraction, 4, '0') : ltrim($whole, '0').'.'.str_pad($fraction, 4, '0');
    }
}
