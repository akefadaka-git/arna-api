<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

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
        $amount = $this->money((string) $data['declared_amount']);
        if (bccomp($amount, '0.0000', 4) <= 0) {
            throw ValidationException::withMessages(['declared_amount' => 'The GA-declared distribution amount must be positive.']);
        }
        if (($data['currency'] ?? 'ETB') !== 'ETB') {
            throw ValidationException::withMessages(['currency' => 'Only the legal entity base currency ETB is enabled for this distribution workflow.']);
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
            'allocation_method' => 'pro_rata_shares',
            'withholding_rate_bps' => (int) ($data['withholding_rate_bps'] ?? 0),
            'status' => 'draft',
            'created_by' => $actorId,
        ]);
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
                $withholding = bcdiv(bcmul($gross, (string) $d->withholding_rate_bps, 8), '10000', 4);
                $net = bcsub($gross, $withholding, 4);
                $items[] = [
                    'tenant_id' => $tenantId,
                    'distribution_id' => $d->id,
                    'shareholder_id' => $shareholderId,
                    'eligible_share_quantity' => $byShareholder[$shareholderId],
                    'gross_amount' => $gross,
                    'withholding_amount' => $withholding,
                    'net_amount' => $net,
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
            if ($d->status !== 'calculated' || (int) $d->created_by === $actorId) {
                throw ValidationException::withMessages(['distribution' => 'A calculated distribution must be approved by an officer other than its maker.']);
            }
            $items = $d->items()->lockForUpdate()->orderBy('shareholder_id')->get();
            if ($items->count() !== (int) $d->snapshot_row_count || $items->isEmpty()) {
                throw new ConflictHttpException('The immutable distribution snapshot is incomplete.');
            }
            foreach ($items as $item) {
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
            $d->forceFill(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now()])->save();
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
