<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use MaaleArna\MASES\Core\Domains\Finance\Models\DividendDistribution;
use MaaleArna\MASES\Core\Domains\Finance\Models\DividendDistributionItem;

final class AnnualDividendDistributionService
{
    public function __construct(private readonly GovernancePenaltySettlementService $payables) {}

    public function create(int $tenantId, int $actorId, array $data): DividendDistribution
    {
        foreach (['approved_balance_sheet_reference', 'distributable_profit_amount', 'resolution_evidence_reference', 'payment_method', 'accounting_framework'] as $required) {
            if (!isset($data[$required]) || trim((string) $data[$required]) === '') {
                throw ValidationException::withMessages([$required => 'Approved financial and General Meeting evidence is mandatory before a dividend distribution can be created.']);
            }
        }

        $amount = $this->money((string) $data['declared_amount']);
        $distributableProfit = $this->money((string) $data['distributable_profit_amount']);
        if (bccomp($distributableProfit, $amount, 4) < 0) {
            throw ValidationException::withMessages(['declared_amount' => 'The declared dividend cannot exceed the certified distributable net profit.']);
        }
        if (bccomp($amount, '0.0000', 4) <= 0) {
            throw ValidationException::withMessages(['declared_amount' => 'The GA-declared distribution amount must be positive.']);
        }
        if (($data['currency'] ?? 'ETB') !== 'ETB') {
            throw ValidationException::withMessages(['currency' => 'Only the legal entity base currency ETB is enabled for this distribution workflow.']);
        }
        $resolutionDate = CarbonImmutable::parse((string) $data['resolution_date'])->startOfDay();
        $declaredDate = CarbonImmutable::parse((string) $data['declared_on'])->startOfDay();
        if ($declaredDate->lessThan($resolutionDate)) {
            throw ValidationException::withMessages(['declared_on' => 'The dividend declaration date cannot precede the General Meeting resolution.']);
        }
        $paymentDueDate = CarbonImmutable::parse((string) $data['payment_due_on'])->startOfDay();
        if ($paymentDueDate->greaterThan($resolutionDate->addMonths(4))) {
            throw ValidationException::withMessages([
                'payment_due_on' => 'Commercial Code Article 438(3): the general meeting payment date must not be later than four months after the profit-distribution resolution.',
            ]);
        }
        if (CarbonImmutable::parse((string) $data['record_date'])->startOfDay()->greaterThan($paymentDueDate)) {
            throw ValidationException::withMessages(['record_date' => 'The shareholder record date cannot be later than the dividend payment due date.']);
        }

        if (($data['allocation_method'] ?? 'pro_rata_shares') !== 'pro_rata_shares') {
            throw ValidationException::withMessages(['allocation_method' => 'This release supports pro-rata allocation by eligible shares only.']);
        }

        $entityExists = DB::table('legal_entities')
            ->where('tenant_id', $tenantId)
            ->where('id', (int) $data['legal_entity_id'])
            ->where('status', 'active')
            ->exists();
        if (!$entityExists) {
            throw ValidationException::withMessages(['legal_entity_id' => 'An active legal entity in the resolved tenant is required.']);
        }

        return DividendDistribution::query()->create([
            'tenant_id' => $tenantId,
            'legal_entity_id' => (int) $data['legal_entity_id'],
            'distribution_reference' => trim($data['distribution_reference']),
            'financial_year' => trim($data['financial_year']),
            'resolution_reference' => trim($data['resolution_reference']),
            'resolution_date' => $data['resolution_date'],
            'declared_on' => $data['declared_on'],
            'record_date' => $data['record_date'],
            'payment_due_on' => $data['payment_due_on'],
            'currency' => 'ETB',
            'declared_amount' => $amount,
            'approved_balance_sheet_reference' => trim((string) $data['approved_balance_sheet_reference']),
            'distributable_profit_amount' => $distributableProfit,
            'resolution_evidence_reference' => trim((string) $data['resolution_evidence_reference']),
            'payment_method' => trim((string) $data['payment_method']),
            'accounting_framework' => trim((string) $data['accounting_framework']),
            'allocation_method' => 'pro_rata_shares',
            // Legacy aggregate column retained for schema compatibility only.
            // Tax is assessed and snapshotted per shareholder below.
            'withholding_rate_bps' => 0,
            'status' => 'draft',
            'created_by' => $actorId,
        ]);
    }

    /**
     * Independent Finance officer attests that the referenced approved balance
     * sheet supports the declared distributable profit. This is an evidence
     * attestation; it does not replace external audit/legal review.
     */
    public function certifyProfit(int $tenantId, int $distributionId, int $actorId): DividendDistribution
    {
        return DB::transaction(function () use ($tenantId, $distributionId, $actorId) {
            $d = DividendDistribution::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($distributionId);
            if ($d->status !== 'draft' || (int) $d->created_by === $actorId) {
                throw ValidationException::withMessages(['distribution' => 'Only a different Finance officer may certify a draft distribution.']);
            }
            if ($d->profit_certified_by !== null || $d->profit_certified_at !== null) {
                throw new ConflictHttpException('Profit certification is immutable once recorded; correct the source evidence through a controlled cancellation and replacement.');
            }
            if (trim((string) $d->approved_balance_sheet_reference) === ''
                || trim((string) $d->resolution_evidence_reference) === ''
                || bccomp((string) $d->distributable_profit_amount, (string) $d->declared_amount, 4) < 0) {
                throw ValidationException::withMessages(['profit' => 'Approved balance-sheet, GA resolution evidence and sufficient distributable profit are required.']);
            }
            $d->forceFill(['profit_certified_by' => $actorId, 'profit_certified_at' => now()])->save();
            return $d->fresh();
        });
    }

    /**
     * Record a recipient-specific tax decision. Every decision is independently
     * reviewed by someone other than the distribution maker.
     */
    public function assessRecipientTax(int $tenantId, int $distributionId, int $shareholderId, int $actorId, array $data): object
    {
        return DB::transaction(function () use ($tenantId, $distributionId, $shareholderId, $actorId, $data) {
            $d = DividendDistribution::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($distributionId);
            if ($d->status !== 'draft' || (int) $d->created_by === $actorId) {
                throw ValidationException::withMessages(['tax' => 'Tax assessment must be completed by an independent reviewer while the distribution is draft.']);
            }
            $shareholder = DB::table('shareholders')->where('tenant_id', $tenantId)->where('id', $shareholderId)
                ->where('is_active', true)->whereNull('deleted_at')->first(['shareholder_type']);
            if (!$shareholder) {
                throw ValidationException::withMessages(['shareholder_id' => 'The shareholder does not belong to this tenant or is inactive.']);
            }
            $actualRecipientType = in_array((string) $shareholder->shareholder_type, ['individual', 'company'], true)
                ? (string) $shareholder->shareholder_type : 'other';
            if ((string) ($data['recipient_type'] ?? '') !== $actualRecipientType) {
                throw ValidationException::withMessages(['recipient_type' => 'Recipient classification must match the verified shareholder record.']);
            }
            $rate = (int) ($data['withholding_rate_bps'] ?? -1);
            if ($rate < 0 || $rate > 10000) {
                throw ValidationException::withMessages(['withholding_rate_bps' => 'A recipient-specific withholding rate from 0 to 10000 basis points is required.']);
            }
            $treatment = (string) ($data['treatment'] ?? '');
            if (!in_array($treatment, ['statutory', 'treaty', 'exempt', 'special'], true)) {
                throw ValidationException::withMessages(['treatment' => 'A supported, explicit tax treatment is required.']);
            }
            $effectiveFrom = CarbonImmutable::parse((string) $data['effective_from'])->toDateString();
            if ($effectiveFrom > $d->payment_due_on->toDateString()) {
                throw ValidationException::withMessages(['effective_from' => 'The tax treatment must be effective no later than the scheduled dividend payment date.']);
            }
            $statutoryRate = $effectiveFrom >= '2025-07-08' ? 1500 : 1000;
            if (!in_array((string) $data['tax_residency'], ['resident', 'non_resident'], true)) {
                throw ValidationException::withMessages(['tax_residency' => 'Residency must be established before tax can be assessed.']);
            }
            if ($treatment === 'statutory' && $rate !== $statutoryRate) {
                throw ValidationException::withMessages(['withholding_rate_bps' => 'The statutory dividend rate is 15% for income derived on or after 8 July 2025 under Proclamation 1395/2025, and 10% for the prior statutory period.']);
            }
            if ($treatment === 'treaty' && ($rate > $statutoryRate || trim((string) ($data['evidence_reference'] ?? '')) === '')) {
                throw ValidationException::withMessages(['withholding_rate_bps' => 'A treaty rate must not exceed the domestic statutory rate and requires valid treaty/residency evidence.']);
            }
            if (($treatment === 'exempt' || $treatment === 'special') && trim((string) ($data['evidence_reference'] ?? '')) === '') {
                throw ValidationException::withMessages(['evidence_reference' => 'Exempt and special treatments require documentary evidence.']);
            }
            if ($treatment === 'exempt' && ($rate !== 0
                || (string) $data['recipient_type'] !== 'company'
                || (string) $data['tax_residency'] !== 'resident')) {
                throw ValidationException::withMessages(['withholding_rate_bps' => 'Dividend exemption requires a resident company recipient, a zero rate and evidence of the applicable group-company exemption.']);
            }
            if ($treatment === 'special' && $rate > $statutoryRate) {
                throw ValidationException::withMessages(['withholding_rate_bps' => 'A special treatment cannot exceed the statutory dividend rate without an independently documented legal basis.']);
            }
            $legalBasis = trim((string) $data['legal_basis']);
            if ($treatment === 'statutory') {
                $legalBasis = $effectiveFrom >= '2025-07-08'
                    ? ((string) $data['tax_residency'] === 'resident'
                        ? 'Federal Income Tax Proclamation 979/2016 as amended by Proclamation 1395/2025, Article 57(1); effective for income derived on or after 8 July 2025.'
                        : 'Federal Income Tax Proclamation 979/2016 as amended by Proclamation 1395/2025, Article 52(2)(b); non-resident dividend tax at 15%.')
                    : ((string) $data['tax_residency'] === 'resident'
                        ? 'Federal Income Tax Proclamation 979/2016, Article 55 (pre-amendment statutory dividend rate).'
                        : 'Federal Income Tax Proclamation 979/2016, Article 51(2)(b) (pre-amendment non-resident dividend rate).');
            }
            $payload = [
                'tenant_id' => $tenantId, 'distribution_id' => $distributionId, 'shareholder_id' => $shareholderId,
                'recipient_type' => (string) $data['recipient_type'], 'tax_residency' => (string) $data['tax_residency'],
                'treatment' => $treatment, 'withholding_rate_bps' => $rate,
                'legal_basis' => $legalBasis,
                'evidence_reference' => isset($data['evidence_reference']) ? trim((string) $data['evidence_reference']) : null,
                'effective_from' => (string) $data['effective_from'],
                'evidence_expires_on' => $data['evidence_expires_on'] ?? null,
                'assessed_by' => $actorId, 'assessed_at' => now(), 'updated_at' => now(),
            ];
            $existing = DB::table('finance_dividend_tax_assessments')->where('tenant_id', $tenantId)->where('distribution_id', $distributionId)->where('shareholder_id', $shareholderId)->first();
            if ($existing) {
                DB::table('finance_dividend_tax_assessments')->where('id', $existing->id)->update($payload);
                return (object) array_merge((array) $existing, $payload);
            }
            $payload['created_at'] = now();
            $id = DB::table('finance_dividend_tax_assessments')->insertGetId($payload);
            return DB::table('finance_dividend_tax_assessments')->where('id', $id)->first();
        });
    }

    /**
     * Snapshot eligible holdings from the append-only holding ledger as at the
     * approved record date. Current-state projections are deliberately not used.
     */
    public function snapshotAndCalculate(int $tenantId, int $distributionId, int $actorId): DividendDistribution
    {
        return DB::transaction(function () use ($tenantId, $distributionId, $actorId) {
            $d = DividendDistribution::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($distributionId);
            if ($d->status !== 'draft' || (int) $d->created_by !== $actorId) {
                throw new ConflictHttpException('Only the distribution maker may snapshot a draft distribution.');
            }
            if ($d->items()->exists()) {
                throw new ConflictHttpException('A distribution snapshot already exists and is immutable.');
            }

            $rows = DB::table('share_holding_entries as e')
                ->whereDate('e.occurred_at', '<=', $d->record_date->toDateString())
                ->whereNotExists(function ($query) use ($d): void {
                    $query->selectRaw('1')
                        ->from('share_holding_entries as newer')
                        ->whereColumn('newer.share_holding_id', 'e.share_holding_id')
                        ->whereDate('newer.occurred_at', '<=', $d->record_date->toDateString())
                        ->where(function ($later) {
                            $later->whereColumn('newer.occurred_at', '>', 'e.occurred_at')
                                ->orWhere(function ($tie) {
                                    $tie->whereColumn('newer.occurred_at', '=', 'e.occurred_at')
                                        ->whereColumn('newer.id', '>', 'e.id');
                                });
                        });
                })
                ->join('share_holdings as h', 'h.id', '=', 'e.share_holding_id')
                ->join('shareholders as s', 's.id', '=', 'h.shareholder_id')
                ->where('s.tenant_id', $tenantId)
                ->where('s.is_active', true)
                ->whereNull('s.deleted_at')
                ->where('e.balance_after', '>', 0)
                ->select('s.id as shareholder_id', 'h.share_class_id', 'e.balance_after')
                ->orderBy('s.id')
                ->get();

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['record_date' => 'No eligible shareholder ledger balances were found on the selected record date.']);
            }
            if ($rows->pluck('share_class_id')->unique()->count() !== 1) {
                throw ValidationException::withMessages(['allocation_method' => 'Pro-rata allocation requires one comparable share class. Multiple share classes require an approved class-weighting rule.']);
            }

            $byShareholder = [];
            $totalShares = '0.0000';
            foreach ($rows as $row) {
                $quantity = $this->money((string) $row->balance_after);
                $id = (int) $row->shareholder_id;
                $byShareholder[$id] = bcadd($byShareholder[$id] ?? '0.0000', $quantity, 4);
                $totalShares = bcadd($totalShares, $quantity, 4);
            }
            if (bccomp($totalShares, '0.0000', 4) <= 0) {
                throw ValidationException::withMessages(['record_date' => 'The eligible share quantity must be positive.']);
            }

            $shareholderIds = array_keys($byShareholder);
            $grossAllocated = '0.0000';
            $items = [];
            $last = count($shareholderIds) - 1;
            foreach ($shareholderIds as $index => $shareholderId) {
                $gross = $index === $last
                    ? bcsub((string) $d->declared_amount, $grossAllocated, 4)
                    : bcdiv(bcmul((string) $d->declared_amount, $byShareholder[$shareholderId], 8), $totalShares, 4);
                $grossAllocated = bcadd($grossAllocated, $gross, 4);
                $assessment = DB::table('finance_dividend_tax_assessments')
                    ->where('tenant_id', $tenantId)->where('distribution_id', $d->id)
                    ->where('shareholder_id', $shareholderId)
                    ->whereDate('effective_from', '<=', $d->payment_due_on->toDateString())
                    ->first();
                if (!$assessment || (int) $assessment->assessed_by === (int) $d->created_by
                    || ($assessment->evidence_expires_on !== null && $assessment->evidence_expires_on < $d->payment_due_on->toDateString())) {
                    throw ValidationException::withMessages(['tax' => 'Every eligible shareholder requires a valid, independently reviewed tax assessment effective on the declaration date.']);
                }
                $taxRate = (int) $assessment->withholding_rate_bps;
                $withholding = bcdiv(bcmul($gross, (string) $taxRate, 8), '10000', 4);
                $net = bcsub($gross, $withholding, 4);
                $items[] = [
                    'tenant_id' => $tenantId,
                    'distribution_id' => $d->id,
                    'shareholder_id' => $shareholderId,
                    'eligible_share_quantity' => $byShareholder[$shareholderId],
                    'gross_amount' => $gross,
                    'withholding_amount' => $withholding,
                    'net_amount' => $net,
                    'tax_assessment_id' => $assessment->id,
                    'tax_treatment' => $assessment->treatment,
                    'tax_rate_bps' => $taxRate,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            foreach ($items as $item) {
                DividendDistributionItem::query()->create($item);
            }
            $withholdingTotal = '0.0000';
            $netTotal = '0.0000';
            foreach ($items as $item) {
                $withholdingTotal = bcadd($withholdingTotal, $item['withholding_amount'], 4);
                $netTotal = bcadd($netTotal, $item['net_amount'], 4);
            }
            $canonical = array_map(static fn ($item) => [
                $item['shareholder_id'], $item['eligible_share_quantity'],
                $item['gross_amount'], $item['withholding_amount'], $item['net_amount'],
            ], $items);
            $d->forceFill([
                'status' => 'calculated',
                'snapshot_row_count' => count($items),
                'eligible_share_quantity' => $totalShares,
                'gross_total' => $grossAllocated,
                'withholding_total' => $withholdingTotal,
                'net_total' => $netTotal,
                'snapshot_sha256' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)),
                'snapshot_at' => now(),
            ])->save();

            return $d->fresh('items');
        });
    }

    /**
     * Independent distribution approval creates and independently approves
     * each payable atomically. Payment execution remains a separate control.
     */
    public function approve(int $tenantId, int $distributionId, int $actorId): DividendDistribution
    {
        return DB::transaction(function () use ($tenantId, $distributionId, $actorId) {
            $d = DividendDistribution::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($distributionId);
            if ($d->status !== 'calculated' || (int) $d->created_by === $actorId
                || $d->profit_certified_by === null || $d->profit_certified_at === null
                || (int) $d->profit_certified_by === $actorId) {
                throw ValidationException::withMessages(['distribution' => 'A calculated distribution must be approved by an officer other than its maker.']);
            }
            $items = $d->items()->lockForUpdate()->orderBy('shareholder_id')->get();
            if ($items->count() !== (int) $d->snapshot_row_count || $items->isEmpty()
                || $items->contains(fn ($item) => $item->tax_assessment_id === null || $item->tax_rate_bps === null)) {
                throw new ConflictHttpException('The immutable distribution snapshot is incomplete.');
            }
            $taxReviewByApprover = DB::table('finance_dividend_tax_assessments')
                ->where('tenant_id', $tenantId)->where('distribution_id', $d->id)
                ->where('assessed_by', $actorId)->exists();
            if ($taxReviewByApprover) {
                throw ValidationException::withMessages(['tax' => 'The distribution approver cannot also be the recipient tax assessor.']);
            }
            $grossTotal = '0.0000';
            $netTotal = '0.0000';
            $withholdingTotal = '0.0000';
            foreach ($items as $item) {
                $grossTotal = bcadd($grossTotal, (string) $item->gross_amount, 4);
                $netTotal = bcadd($netTotal, (string) $item->net_amount, 4);
                $withholdingTotal = bcadd($withholdingTotal, (string) $item->withholding_amount, 4);
                if (bccomp((string) $item->net_amount, '0.0000', 4) > 0) {
                    $payable = $this->payables->createDividendPayable(
                        $tenantId, (int) $d->legal_entity_id, (int) $item->shareholder_id,
                        (string) $d->distribution_reference, $d->declared_on->toDateString(),
                        $d->payment_due_on->toDateString(), (string) $item->net_amount, (int) $d->created_by
                    );
                    $this->payables->approveDividendPayable(
                        $tenantId, (int) $payable->id, $actorId,
                        'GA distribution '.$d->resolution_reference
                    );
                    $item->forceFill(['payable_id' => $payable->id])->save();
                }
            }
            if (bccomp($grossTotal, (string) $d->declared_amount, 4) !== 0
                || bccomp(bcadd($netTotal, $withholdingTotal, 4), $grossTotal, 4) !== 0) {
                throw new ConflictHttpException('Dividend allocation totals do not reconcile to the approved gross distribution.');
            }
            $journal = $this->payables->accrueDividendDistribution(
                $tenantId, (int) $d->legal_entity_id, (int) $d->id,
                (string) $d->distribution_reference, $d->declared_on->toDateString(),
                $grossTotal, $netTotal, $withholdingTotal, $actorId
            );
            $d->forceFill([
                'status' => 'approved',
                'approved_by' => $actorId,
                'approved_at' => now(),
                'accrual_journal_reference' => (string) $journal->journal_number,
                'withholding_journal_reference' => bccomp($withholdingTotal, '0.0000', 4) > 0 ? (string) $journal->journal_number : null,
            ])->save();
            return $d->fresh('items');
        });
    }

    /**
     * Apply Commercial Code Article 438(4) through a controlled cancellation.
     * A posted accrual is reversed; any distribution with actual cash payments
     * or penalty offsets is blocked because it requires a separately approved
     * recovery/refund process rather than silently undoing shareholder rights.
     */
    public function cancel(int $tenantId, int $distributionId, int $actorId, string $reason): DividendDistribution
    {
        return DB::transaction(function () use ($tenantId, $distributionId, $actorId, $reason) {
            $d = DividendDistribution::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($distributionId);
            if (!in_array($d->status, ['draft', 'calculated', 'approved'], true)
                || (int) $d->created_by === $actorId
                || ($d->approved_by !== null && (int) $d->approved_by === $actorId)
                || trim($reason) === ''
                || today()->greaterThan($d->payment_due_on)) {
                throw ValidationException::withMessages(['distribution' => 'Cancellation requires a reason, an independent officer and an unexpired Article 438(3) payment period.']);
            }

            $reversal = null;
            if ($d->status === 'approved') {
                $paid = DB::table('finance_dividend_payment_batch_items as i')
                    ->join('finance_dividend_payment_batches as b', function ($join) {
                        $join->on('b.id', '=', 'i.batch_id')->on('b.tenant_id', '=', 'i.tenant_id');
                    })
                    ->where('b.tenant_id', $tenantId)->where('b.distribution_id', $d->id)
                    ->where('i.status', 'paid')->exists();
                $offset = DB::table('finance_shareholder_dividend_payables')
                    ->where('tenant_id', $tenantId)->where('legal_entity_id', $d->legal_entity_id)
                    ->where('distribution_reference', $d->distribution_reference)
                    ->where(function ($query) {
                        $query->where('cash_paid_amount', '>', 0)->orWhere('penalty_offset_amount', '>', 0);
                    })->exists();
                if ($paid || $offset) {
                    throw ConflictHttpException('This distribution has settled shareholder value. Use a separately approved recovery/refund workflow; cancellation cannot erase creditor rights.');
                }
                if (trim((string) $d->accrual_journal_reference) === '') {
                    throw ConflictHttpException('The approved distribution has no traceable accrual journal to reverse.');
                }
                $reversal = $this->payables->reverseDividendAccrual(
                    $tenantId, (int) $d->legal_entity_id, (int) $d->id,
                    (string) $d->accrual_journal_reference, today()->toDateString(),
                    trim($reason), $actorId
                );
                DB::table('finance_shareholder_dividend_payables')
                    ->where('tenant_id', $tenantId)->where('legal_entity_id', $d->legal_entity_id)
                    ->where('distribution_reference', $d->distribution_reference)
                    ->whereIn('status', ['draft', 'approved', 'payable'])
                    ->update(['status' => 'void', 'updated_at' => now()]);

                $batchIds = DB::table('finance_dividend_payment_batches')->where('tenant_id', $tenantId)
                    ->where('distribution_id', $d->id)->pluck('id');
                if ($batchIds->isNotEmpty()) {
                    DB::table('finance_dividend_payment_batch_items')->where('tenant_id', $tenantId)
                        ->whereIn('batch_id', $batchIds)->whereIn('status', ['pending', 'failed'])
                        ->update(['status' => 'cancelled', 'updated_at' => now()]);
                    DB::table('finance_dividend_payment_batches')->where('tenant_id', $tenantId)
                        ->whereIn('id', $batchIds)->update(['status' => 'cancelled', 'updated_at' => now()]);
                    foreach ($batchIds as $batchId) {
                        DB::table('finance_dividend_payment_events')->insert([
                            'tenant_id' => $tenantId, 'batch_id' => $batchId, 'actor_id' => $actorId,
                            'action' => 'batch.cancelled', 'payload' => json_encode(['reason' => trim($reason)], JSON_THROW_ON_ERROR),
                            'occurred_at' => now(),
                        ]);
                    }
                }
            }

            $d->forceFill([
                'status' => 'cancelled',
                'cancelled_by' => $actorId,
                'cancelled_at' => now(),
                'cancellation_reason' => trim($reason),
                'reversal_journal_reference' => $reversal?->journal_number,
            ])->save();

            return $d->fresh('items');
        });
    }

    private function money(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^\d{1,16}(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Amounts and quantities must be non-negative decimal strings with at most four decimal places.']);
        }
        return bcadd($value, '0', 4);
    }
}
