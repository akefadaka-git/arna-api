<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Models\GovernanceSettlementAccountMapping;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;
use MaaleArna\MASES\Core\Domains\Finance\Models\ShareholderDividendPayable;

final class DividendPaymentService
{
    public function __construct(private readonly JournalEntryService $journals) {}

    /** @param list<int> $payableIds */
    public function createBatch(int $tenantId, int $entityId, int $distributionId, int $actorId, string $reference, array $payableIds): object
    {
        if (trim($reference) === '' || count($payableIds) === 0 || count($payableIds) !== count(array_unique($payableIds))) {
            throw ValidationException::withMessages(['batch' => 'A unique batch reference and a non-empty list of distinct dividend payables are required.']);
        }

        return DB::transaction(function () use ($tenantId, $entityId, $distributionId, $actorId, $reference, $payableIds) {
            $distribution = DB::table('finance_dividend_distributions')->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $entityId)->where('id', $distributionId)->whereIn('status', ['approved', 'partially_paid'])
                ->lockForUpdate()->first();
            if (!$distribution) {
                throw ValidationException::withMessages(['distribution_id' => 'Only an approved distribution in the selected legal entity can be paid.']);
            }

            $entity = DB::table('legal_entities')->where('tenant_id', $tenantId)->where('id', $entityId)
                ->where('status', 'active')->first();
            if (!$entity || $entity->base_currency !== 'ETB') {
                throw ValidationException::withMessages(['legal_entity_id' => 'An active ETB legal entity is required.']);
            }

            $batchId = DB::table('finance_dividend_payment_batches')->insertGetId([
                'tenant_id' => $tenantId, 'legal_entity_id' => $entityId, 'distribution_id' => $distributionId,
                'batch_reference' => trim($reference), 'currency' => 'ETB', 'total_amount' => '0.0000',
                'status' => 'draft', 'reconciliation_status' => 'pending', 'created_by' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $total = '0.0000';
            foreach ($payableIds as $payableId) {
                $allocation = DB::table('finance_dividend_distribution_items as i')
                    ->join('finance_shareholder_dividend_payables as p', function ($join) {
                        $join->on('p.id', '=', 'i.payable_id')
                            ->on('p.tenant_id', '=', 'i.tenant_id')
                            ->on('p.shareholder_id', '=', 'i.shareholder_id');
                    })
                    ->join('finance_dividend_distributions as d', function ($join) {
                        $join->on('d.id', '=', 'i.distribution_id')->on('d.tenant_id', '=', 'i.tenant_id');
                    })
                    ->where('i.tenant_id', $tenantId)->where('i.distribution_id', $distributionId)
                    ->whereColumn('p.distribution_reference', 'd.distribution_reference')
                    ->where('p.legal_entity_id', $entityId)->where('p.id', $payableId)
                    ->select('i.shareholder_id', 'p.id as payable_id')->first();
                if (!$allocation) {
                    throw ValidationException::withMessages(['payable_ids' => 'Every selected payable must belong to this exact distribution, shareholder and legal entity.']);
                }

                $payable = ShareholderDividendPayable::query()->where('tenant_id', $tenantId)
                    ->where('legal_entity_id', $entityId)->lockForUpdate()->findOrFail($payableId);
                if ($payable->status !== ShareholderDividendPayable::STATUS_PAYABLE) {
                    throw ValidationException::withMessages(['payable_ids' => 'Only approved, unsettled dividend payables can enter a payment batch.']);
                }

                $reserved = DB::table('finance_dividend_payment_batch_items as i')
                    ->join('finance_dividend_payment_batches as b', function ($join) {
                        $join->on('b.id', '=', 'i.batch_id')->on('b.tenant_id', '=', 'i.tenant_id');
                    })
                    ->where('i.tenant_id', $tenantId)->where('i.payable_id', $payableId)
                    ->where('i.status', 'pending')->whereIn('b.status', ['draft', 'approved', 'partially_paid'])
                    ->sum('i.amount');
                $available = bcsub($payable->remainingPayable(), (string) $reserved, 4);
                if (bccomp($available, '0.0000', 4) <= 0) {
                    throw ValidationException::withMessages(['payable_ids' => 'The selected payable has already been fully reserved by another open payment batch.']);
                }

                DB::table('finance_dividend_payment_batch_items')->insert([
                    'tenant_id' => $tenantId, 'batch_id' => $batchId, 'payable_id' => $payableId,
                    'shareholder_id' => $allocation->shareholder_id, 'amount' => $available,
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $total = bcadd($total, $available, 4);
            }

            DB::table('finance_dividend_payment_batches')->where('tenant_id', $tenantId)->where('id', $batchId)
                ->update(['total_amount' => $total, 'updated_at' => now()]);
            $this->event($tenantId, $batchId, $actorId, 'batch.created', ['total_amount' => $total, 'payable_count' => count($payableIds)]);
            return DB::table('finance_dividend_payment_batches')->where('tenant_id', $tenantId)->where('id', $batchId)->first();
        });
    }

    public function approveBatch(int $tenantId, int $batchId, int $actorId): object
    {
        return DB::transaction(function () use ($tenantId, $batchId, $actorId) {
            $batch = DB::table('finance_dividend_payment_batches')->where('tenant_id', $tenantId)
                ->lockForUpdate()->where('id', $batchId)->first();
            if (!$batch || $batch->status !== 'draft' || (int) $batch->created_by === $actorId) {
                throw ValidationException::withMessages(['batch' => 'Only a different Finance officer may approve a draft payment batch.']);
            }
            $items = DB::table('finance_dividend_payment_batch_items')->where('tenant_id', $tenantId)
                ->where('batch_id', $batchId)->lockForUpdate()->get();
            if ($items->isEmpty() || $items->contains(fn ($item) => $item->status !== 'pending')) {
                throw ConflictHttpException('The payment batch is empty or contains a non-pending payment item.');
            }
            DB::table('finance_dividend_payment_batches')->where('tenant_id', $tenantId)->where('id', $batchId)
                ->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now(), 'updated_at' => now()]);
            $this->event($tenantId, $batchId, $actorId, 'batch.approved', []);
            return DB::table('finance_dividend_payment_batches')->where('tenant_id', $tenantId)->where('id', $batchId)->first();
        });
    }

    public function cancelBatch(int $tenantId, int $batchId, int $actorId, string $reason): object
    {
        return DB::transaction(function () use ($tenantId, $batchId, $actorId, $reason) {
            $batch = DB::table('finance_dividend_payment_batches')->where('tenant_id', $tenantId)
                ->where('id', $batchId)->lockForUpdate()->first();
            if (!$batch || !in_array($batch->status, ['draft', 'approved', 'failed'], true)
                || (int) $batch->created_by === $actorId
                || ($batch->approved_by !== null && (int) $batch->approved_by === $actorId)
                || trim($reason) === '') {
                throw ValidationException::withMessages(['batch' => 'Only an unpaid batch may be cancelled by an independent officer with a reason.']);
            }
            $paid = DB::table('finance_dividend_payment_batch_items')->where('tenant_id', $tenantId)
                ->where('batch_id', $batchId)->where('status', 'paid')->exists();
            if ($paid) {
                throw ConflictHttpException('A batch with confirmed shareholder payments cannot be cancelled.');
            }
            DB::table('finance_dividend_payment_batch_items')->where('tenant_id', $tenantId)
                ->where('batch_id', $batchId)->whereIn('status', ['pending', 'failed'])
                ->update(['status' => 'cancelled', 'failure_reason' => trim($reason), 'updated_at' => now()]);
            DB::table('finance_dividend_payment_batches')->where('tenant_id', $tenantId)->where('id', $batchId)
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->event($tenantId, $batchId, $actorId, 'batch.cancelled', ['reason' => trim($reason)]);
            return DB::table('finance_dividend_payment_batches')->where('tenant_id', $tenantId)->where('id', $batchId)->first();
        });
    }

    public function confirmPayment(int $tenantId, int $batchId, int $itemId, int $actorId, string $bankReference, string $evidenceReference, string $paidOn): array
    {
        return DB::transaction(function () use ($tenantId, $batchId, $itemId, $actorId, $bankReference, $evidenceReference, $paidOn) {
            $batch = DB::table('finance_dividend_payment_batches')->where('tenant_id', $tenantId)
                ->where('id', $batchId)->lockForUpdate()->first();
            $item = DB::table('finance_dividend_payment_batch_items')->where('tenant_id', $tenantId)
                ->where('batch_id', $batchId)->where('id', $itemId)->lockForUpdate()->first();
            if (!$batch || !$item) {
                throw ValidationException::withMessages(['payment' => 'The payment batch item was not found in the resolved tenant.']);
            }
            if ($item->status === 'paid') {
                if (hash_equals((string) $item->bank_reference, trim($bankReference))) {
                    return ['batch_id' => $batchId, 'item_id' => $itemId, 'status' => 'paid', 'journal_reference' => $item->journal_reference, 'idempotent_replay' => true];
                }
                throw new ConflictHttpException('This payment item was already confirmed with a different bank reference.');
            }
            if (!in_array($batch->status, ['approved', 'partially_paid'], true)
                || (int) $batch->created_by === $actorId
                || trim($bankReference) === '' || trim($evidenceReference) === '') {
                throw ValidationException::withMessages(['payment' => 'A different officer must confirm an approved payment with unique bank and evidence references.']);
            }
            if ($item->status !== 'pending') {
                throw ValidationException::withMessages(['payment' => 'Only a pending payment item can be confirmed.']);
            }
            if (DB::table('finance_dividend_payment_batch_items')->where('tenant_id', $tenantId)
                ->where('bank_reference', trim($bankReference))->exists()) {
                throw ConflictHttpException('The bank reference has already been used in this tenant.');
            }

            $taxAssessment = DB::table('finance_dividend_tax_assessments')->where('tenant_id', $tenantId)
                ->where('distribution_id', $batch->distribution_id)
                ->where('shareholder_id', $item->shareholder_id)->where('id', $item->tax_assessment_id)->first();
            if ($paidOn >= '2025-08-07') {
                $statutoryRate = 1500;
            } elseif ($paidOn < '2025-07-08') {
                $statutoryRate = 1000;
            } else {
                throw ValidationException::withMessages(['tax' => 'The actual payment falls in the 8 July–6 August 2025 Schedule D transition window and requires a documented tax-director determination.']);
            }
            if (!$taxAssessment || $taxAssessment->effective_from > $paidOn
                || ($taxAssessment->evidence_expires_on !== null && $taxAssessment->evidence_expires_on < $paidOn)
                || ($taxAssessment->treatment === 'statutory' && (int) $item->tax_rate_bps !== $statutoryRate)
                || ($taxAssessment->treatment === 'treaty' && (int) $item->tax_rate_bps > $statutoryRate)) {
                throw ValidationException::withMessages(['tax' => 'The recipient tax assessment is not effective and valid on the actual payment date; payment is blocked pending compliant tax review.']);
            }

            $payable = ShareholderDividendPayable::query()->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $batch->legal_entity_id)->lockForUpdate()->findOrFail($item->payable_id);
            if ($payable->status !== ShareholderDividendPayable::STATUS_PAYABLE
                || bccomp($payable->remainingPayable(), (string) $item->amount, 4) < 0) {
                throw ConflictHttpException('The payable balance changed after batch creation; reconcile or recreate the batch.');
            }

            $mappings = GovernanceSettlementAccountMapping::query()
                ->forEntity($tenantId, (int) $batch->legal_entity_id)->approved()->get()->keyBy('account_role');
            foreach ([GovernanceSettlementAccountMapping::ROLE_DIVIDEND_PAYABLE, GovernanceSettlementAccountMapping::ROLE_CASH_BANK] as $role) {
                if (!$mappings->has($role)) {
                    throw ValidationException::withMessages(['account_mapping' => 'Approved shareholder payable and bank accounts are required before payment confirmation.']);
                }
            }

            $key = 'dividend-payment:'.$tenantId.':'.$batchId.':'.$itemId;
            $journal = $this->journals->createDraft($tenantId, $actorId, [
                'legal_entity_id' => (int) $batch->legal_entity_id,
                'journal_number' => 'DIV-PAY-'.$batchId.'-'.$itemId,
                'effective_date' => $paidOn, 'currency' => 'ETB',
                'description' => 'Dividend payment '.$batch->batch_reference.' to shareholder '.$item->shareholder_id,
                'source_type' => JournalSourceType::SYSTEM->value, 'idempotency_key' => $key,
            ], [
                ['account_id' => (int) $mappings[GovernanceSettlementAccountMapping::ROLE_DIVIDEND_PAYABLE]->ledger_account_id,
                    'debit_amount' => (string) $item->amount, 'credit_amount' => '0.0000'],
                ['account_id' => (int) $mappings[GovernanceSettlementAccountMapping::ROLE_CASH_BANK]->ledger_account_id,
                    'debit_amount' => '0.0000', 'credit_amount' => (string) $item->amount],
            ]);
            if ($journal->status === JournalEntry::DRAFT) {
                $journal = $this->journals->post($tenantId, $actorId, (int) $journal->id);
            } elseif ($journal->status !== JournalEntry::POSTED) {
                throw new ConflictHttpException('The idempotent payment journal exists but is not posted.');
            }

            $payable->cash_paid_amount = bcadd((string) $payable->cash_paid_amount, (string) $item->amount, 4);
            if (bccomp($payable->remainingPayable(), '0.0000', 4) === 0) {
                $payable->status = ShareholderDividendPayable::STATUS_SETTLED;
            }
            $payable->save();

            DB::table('finance_dividend_payment_batch_items')->where('tenant_id', $tenantId)->where('id', $itemId)
                ->update(['status' => 'paid', 'bank_reference' => trim($bankReference),
                    'evidence_reference' => trim($evidenceReference), 'journal_reference' => (string) $journal->journal_number,
                    'paid_on' => $paidOn, 'confirmed_by' => $actorId, 'confirmed_at' => now(), 'updated_at' => now()]);

            $pending = DB::table('finance_dividend_payment_batch_items')->where('tenant_id', $tenantId)
                ->where('batch_id', $batchId)->where('status', 'pending')->count();
            $paid = DB::table('finance_dividend_payment_batch_items')->where('tenant_id', $tenantId)
                ->where('batch_id', $batchId)->where('status', 'paid')->count();
            $failed = DB::table('finance_dividend_payment_batch_items')->where('tenant_id', $tenantId)->where('batch_id', $batchId)->where('status', 'failed')->count();
            $status = $pending === 0 && $failed === 0 && $paid > 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'approved');
            DB::table('finance_dividend_payment_batches')->where('tenant_id', $tenantId)->where('id', $batchId)
                ->update(['status' => $status, 'updated_at' => now()]);

            $distributionPayables = DB::table('finance_dividend_distribution_items as i')
                ->join('finance_shareholder_dividend_payables as p', function ($join) {
                    $join->on('p.id', '=', 'i.payable_id')->on('p.tenant_id', '=', 'i.tenant_id');
                })
                ->where('i.tenant_id', $tenantId)->where('i.distribution_id', $batch->distribution_id)
                ->where('i.net_amount', '>', 0)->select('p.status')->get();
            $settledCount = $distributionPayables->where('status', ShareholderDividendPayable::STATUS_SETTLED)->count();
            $outstandingCount = $distributionPayables->where('status', '!=', ShareholderDividendPayable::STATUS_SETTLED)->count();
            $distributionStatus = $outstandingCount === 0 ? 'paid' : ($settledCount > 0 ? 'partially_paid' : 'approved');
            DB::table('finance_dividend_distributions')->where('tenant_id', $tenantId)
                ->where('id', $batch->distribution_id)->update(['status' => $distributionStatus, 'updated_at' => now()]);

            $this->event($tenantId, $batchId, $actorId, 'payment.confirmed', [
                'item_id' => $itemId, 'payable_id' => $payable->id, 'amount' => (string) $item->amount,
                'bank_reference' => trim($bankReference), 'journal_id' => $journal->id,
            ]);
            return ['batch_id' => $batchId, 'item_id' => $itemId, 'status' => 'paid', 'journal_reference' => $journal->journal_number, 'idempotent_replay' => false];
        });
    }

    public function failPayment(int $tenantId, int $batchId, int $itemId, int $actorId, string $reason): object
    {
        return DB::transaction(function () use ($tenantId, $batchId, $itemId, $actorId, $reason) {
            $batch = DB::table('finance_dividend_payment_batches')->where('tenant_id', $tenantId)->where('id', $batchId)->lockForUpdate()->first();
            $item = DB::table('finance_dividend_payment_batch_items')->where('tenant_id', $tenantId)->where('batch_id', $batchId)->where('id', $itemId)->lockForUpdate()->first();
            if (!$batch || !$item || !in_array($batch->status, ['approved', 'partially_paid'], true)
                || $item->status !== 'pending' || (int) $batch->created_by === $actorId || trim($reason) === '') {
                throw ValidationException::withMessages(['payment' => 'Only a pending item in an approved batch may be marked failed by another officer with a reason.']);
            }
            DB::table('finance_dividend_payment_batch_items')->where('tenant_id', $tenantId)->where('id', $itemId)
                ->update(['status' => 'failed', 'failure_reason' => trim($reason), 'confirmed_by' => $actorId, 'confirmed_at' => now(), 'updated_at' => now()]);
            $pending = DB::table('finance_dividend_payment_batch_items')->where('tenant_id', $tenantId)->where('batch_id', $batchId)->where('status', 'pending')->count();
            $paid = DB::table('finance_dividend_payment_batch_items')->where('tenant_id', $tenantId)->where('batch_id', $batchId)->where('status', 'paid')->count();
            $failed = DB::table('finance_dividend_payment_batch_items')->where('tenant_id', $tenantId)->where('batch_id', $batchId)->where('status', 'failed')->count();
            $status = $pending === 0 && $paid === 0 && $failed > 0 ? 'failed' : ($paid > 0 ? 'partially_paid' : 'approved');
            DB::table('finance_dividend_payment_batches')->where('tenant_id', $tenantId)->where('id', $batchId)->update(['status' => $status, 'updated_at' => now()]);
            $this->event($tenantId, $batchId, $actorId, 'payment.failed', ['item_id' => $itemId, 'reason' => trim($reason)]);
            return DB::table('finance_dividend_payment_batch_items')->where('tenant_id', $tenantId)->where('id', $itemId)->first();
        });
    }

    public function reconcileBatch(int $tenantId, int $batchId, int $actorId, string $statementReference, string $statementAmount): object
    {
        return DB::transaction(function () use ($tenantId, $batchId, $actorId, $statementReference, $statementAmount) {
            $batch = DB::table('finance_dividend_payment_batches')->where('tenant_id', $tenantId)->where('id', $batchId)->lockForUpdate()->first();
            if (!$batch || !in_array($batch->status, ['paid', 'partially_paid'], true)
                || (int) $batch->created_by === $actorId || trim($statementReference) === '') {
                throw ValidationException::withMessages(['batch' => 'Only a different officer may reconcile a batch with confirmed payments and a bank statement reference.']);
            }
            if ($batch->reconciliation_status === 'reconciled') {
                throw new ConflictHttpException('A reconciled bank statement is immutable; use a separate controlled correction record.');
            }
            $amount = $this->money($statementAmount);
            $confirmed = (string) DB::table('finance_dividend_payment_batch_items')->where('tenant_id', $tenantId)
                ->where('batch_id', $batchId)->where('status', 'paid')->sum('amount');
            $matched = bccomp($amount, $confirmed, 4) === 0;
            DB::table('finance_dividend_payment_batches')->where('tenant_id', $tenantId)->where('id', $batchId)->update([
                'reconciliation_status' => $matched ? 'reconciled' : 'discrepancy',
                'bank_statement_reference' => trim($statementReference),
                'bank_statement_amount' => $amount,
                'reconciled_by' => $matched ? $actorId : null,
                'reconciled_at' => $matched ? now() : null,
                'updated_at' => now(),
            ]);
            $this->event($tenantId, $batchId, $actorId, $matched ? 'batch.reconciled' : 'batch.reconciliation_discrepancy', [
                'statement_reference' => trim($statementReference), 'statement_amount' => $amount, 'confirmed_amount' => $confirmed,
            ]);
            return DB::table('finance_dividend_payment_batches')->where('tenant_id', $tenantId)->where('id', $batchId)->first();
        });
    }

    private function event(int $tenantId, int $batchId, int $actorId, string $action, array $payload): void
    {
        DB::table('finance_dividend_payment_events')->insert([
            'tenant_id' => $tenantId, 'batch_id' => $batchId, 'actor_id' => $actorId,
            'action' => $action, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
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
