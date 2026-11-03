<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Shareholder\Enums\HoldingDirection;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareClass;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Shareholder\Services\ShareHoldingService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DividendReinvestmentService
{
    public function __construct(private readonly ShareHoldingService $holdings) {}

    public function recordSubscriptionBalance(int $tenantId, int $actorId, array $data): object
    {
        $entity = DB::table('legal_entities')->where('tenant_id', $tenantId)
            ->where('id', (int) $data['legal_entity_id'])->where('status', 'active')->exists();
        $shareholder = Shareholder::query()->where('tenant_id', $tenantId)
            ->whereKey((int) $data['shareholder_id'])->whereNull('deleted_at')->first();
        $shareClass = ShareClass::query()->whereKey((int) $data['share_class_id'])
            ->where('is_active', true)->first();
        if (!$entity || !$shareholder || !$shareClass) {
            throw ValidationException::withMessages(['shareholder_id' => 'An active legal entity, shareholder in this tenant and active share class are required.']);
        }

        $quantity = (int) $data['subscribed_share_quantity'];
        $price = $this->money((string) $data['price_per_share']);
        $expected = bcmul((string) $quantity, $price, 4);
        $original = $this->money((string) $data['original_amount']);
        if ($quantity < 1 || bccomp($expected, $original, 4) !== 0) {
            throw ValidationException::withMessages(['original_amount' => 'The subscription amount must equal whole shares multiplied by the agreed per-share price.']);
        }

        $id = DB::table('finance_share_subscription_balances')->insertGetId([
            'tenant_id' => $tenantId,
            'legal_entity_id' => (int) $data['legal_entity_id'],
            'shareholder_id' => (int) $shareholder->id,
            'share_class_id' => (int) $shareClass->id,
            'subscription_reference' => trim((string) $data['subscription_reference']),
            'subscribed_share_quantity' => $quantity,
            'price_per_share' => $price,
            'original_amount' => $original,
            'outstanding_amount' => $original,
            'occurred_on' => $data['occurred_on'],
            'evidence_reference' => trim((string) $data['evidence_reference']),
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return (object) DB::table('finance_share_subscription_balances')->where('id', $id)->first();
    }

    public function record(int $tenantId, int $actorId, array $data): object
    {
        return DB::transaction(function () use ($tenantId, $actorId, $data) {
            $distribution = DB::table('finance_dividend_distributions')
                ->where('tenant_id', $tenantId)->where('id', (int) $data['distribution_id'])
                ->lockForUpdate()->first();
            $item = DB::table('finance_dividend_distribution_items')
                ->where('tenant_id', $tenantId)->where('distribution_id', (int) $data['distribution_id'])
                ->where('id', (int) $data['distribution_item_id'])
                ->where('shareholder_id', (int) $data['shareholder_id'])->lockForUpdate()->first();
            if (!$distribution || !$item || !in_array($distribution->status, ['approved', 'posted', 'partially_paid', 'paid'], true)) {
                throw ValidationException::withMessages(['distribution_id' => 'Only an approved dividend distribution and its matching shareholder item may be reinvested.']);
            }
            if (empty($item->tax_assessment_id)) {
                throw ValidationException::withMessages(['tax_assessment' => 'A recipient-specific tax assessment must exist before a net dividend can be reinvested.']);
            }

            $source = (string) ($data['source_category'] ?? 'internal_revenue');
            if (!in_array($source, ['internal_revenue', 'other'], true)) {
                throw ValidationException::withMessages(['source_category' => 'Source category must be internal_revenue or other; tax treatment is recorded separately.']);
            }

            $amount = $this->money((string) $data['entitlement_amount']);
            $alreadyUsed = (string) DB::table('finance_dividend_reinvestments')
                ->where('tenant_id', $tenantId)->where('distribution_item_id', (int) $item->id)
                ->whereIn('status', ['pending', 'approved', 'posted'])->sum('applied_amount');
            $remaining = bcsub((string) $item->net_amount, $alreadyUsed, 4);
            if (bccomp($amount, '0.0000', 4) <= 0 || bccomp($amount, $remaining, 4) > 0) {
                throw ValidationException::withMessages(['entitlement_amount' => 'Reinvestment cannot exceed the shareholder net dividend remaining after prior reinvestment applications.']);
            }

            $type = (string) $data['settlement_type'];
            $subscriptionId = null;
            $shareClassId = null;
            $sellerId = null;
            $quantity = null;
            $unitPrice = null;
            $applied = $amount;
            $unapplied = '0.0000';

            if ($type === 'subscription_balance') {
                $balance = DB::table('finance_share_subscription_balances')->where('tenant_id', $tenantId)
                    ->where('id', (int) $data['subscription_balance_id'])
                    ->where('shareholder_id', (int) $item->shareholder_id)->lockForUpdate()->first();
                if (!$balance || bccomp((string) $balance->outstanding_amount, $amount, 4) < 0) {
                    throw ValidationException::withMessages(['subscription_balance_id' => 'The outstanding share-subscription balance does not exist for this shareholder or is insufficient.']);
                }
                $subscriptionId = (int) $balance->id;
            } else {
                if (!in_array($type, ['new_company_allotment', 'existing_share_transfer'], true)) {
                    throw ValidationException::withMessages(['settlement_type' => 'Unsupported reinvestment route.']);
                }
                $shareClass = ShareClass::query()->whereKey((int) $data['share_class_id'])
                    ->where('is_active', true)->first();
                if (!$shareClass) {
                    throw ValidationException::withMessages(['share_class_id' => 'An active share class is required.']);
                }
                $quantity = (int) $data['share_quantity'];
                $unitPrice = $this->money((string) $data['price_per_share']);
                if ($quantity < 1 || bccomp($unitPrice, '0.0000', 4) <= 0) {
                    throw ValidationException::withMessages(['share_quantity' => 'A positive whole-share quantity and positive unit price are required.']);
                }
                if ($type === 'new_company_allotment' && bccomp($unitPrice, (string) $shareClass->par_value, 4) < 0) {
                    throw ValidationException::withMessages(['price_per_share' => 'A new company allotment cannot be priced below the share class par value.']);
                }
                $shareClassId = (int) $shareClass->id;
                $applied = bcmul((string) $quantity, $unitPrice, 4);
                if (bccomp($applied, $amount, 4) > 0) {
                    throw ValidationException::withMessages(['share_quantity' => 'The share purchase consideration cannot exceed the dividend amount allocated to reinvestment.']);
                }
                $unapplied = bcsub($amount, $applied, 4);
                if ($type === 'existing_share_transfer') {
                    $seller = Shareholder::query()->where('tenant_id', $tenantId)
                        ->whereKey((int) $data['seller_shareholder_id'])->whereNull('deleted_at')->first();
                    if (!$seller || (int) $seller->id === (int) $item->shareholder_id) {
                        throw ValidationException::withMessages(['seller_shareholder_id' => 'A different, valid shareholder in the same tenant is required as seller.']);
                    }
                    $sellerId = (int) $seller->id;
                }
            }

            $id = DB::table('finance_dividend_reinvestments')->insertGetId([
                'tenant_id' => $tenantId,
                'distribution_id' => (int) $distribution->id,
                'distribution_item_id' => (int) $item->id,
                'shareholder_id' => (int) $item->shareholder_id,
                'settlement_type' => $type,
                'source_category' => $source,
                'entitlement_amount' => $amount,
                'applied_amount' => $applied,
                'unapplied_amount' => $unapplied,
                'subscription_balance_id' => $subscriptionId,
                'share_class_id' => $shareClassId,
                'seller_shareholder_id' => $sellerId,
                'share_quantity' => $quantity,
                'price_per_share' => $unitPrice,
                'occurred_on' => $data['occurred_on'],
                'transaction_reference' => trim((string) $data['transaction_reference']),
                'evidence_reference' => trim((string) $data['evidence_reference']),
                'idempotency_key' => trim((string) $data['idempotency_key']),
                'status' => 'pending',
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return (object) DB::table('finance_dividend_reinvestments')->where('id', $id)->first();
        });
    }

    public function approve(int $tenantId, int $id, int $actorId): object
    {
        return DB::transaction(function () use ($tenantId, $id, $actorId) {
            $row = DB::table('finance_dividend_reinvestments')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first();
            if (!$row || $row->status !== 'pending' || (int) $row->created_by === $actorId) {
                throw ValidationException::withMessages(['reinvestment' => 'Approval requires a pending record and a different checker.']);
            }
            DB::table('finance_dividend_reinvestments')->where('id', $id)->update([
                'status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now(), 'updated_at' => now(),
            ]);
            return (object) DB::table('finance_dividend_reinvestments')->where('id', $id)->first();
        });
    }

    public function post(int $tenantId, int $id, int $actorId): object
    {
        return DB::transaction(function () use ($tenantId, $id, $actorId) {
            $row = DB::table('finance_dividend_reinvestments')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first();
            if (!$row || $row->status !== 'approved' || (int) $row->created_by === $actorId || (int) $row->approved_by === $actorId) {
                throw ValidationException::withMessages(['reinvestment' => 'Posting requires an approved record and a posting actor independent of maker and checker.']);
            }
            $buyer = Shareholder::query()->where('tenant_id', $tenantId)->whereKey((int) $row->shareholder_id)->whereNull('deleted_at')->firstOrFail();
            if ($row->settlement_type === 'subscription_balance') {
                $balance = DB::table('finance_share_subscription_balances')->where('tenant_id', $tenantId)
                    ->where('id', (int) $row->subscription_balance_id)->lockForUpdate()->first();
                if (!$balance || bccomp((string) $balance->outstanding_amount, (string) $row->applied_amount, 4) < 0) {
                    throw new ConflictHttpException('The subscription balance changed after approval; posting is blocked.');
                }
                DB::table('finance_share_subscription_balances')->where('id', $balance->id)->update([
                    'outstanding_amount' => bcsub((string) $balance->outstanding_amount, (string) $row->applied_amount, 4),
                    'updated_at' => now(),
                ]);
            } else {
                $shareClass = ShareClass::query()->whereKey((int) $row->share_class_id)->firstOrFail();
                if ($row->settlement_type === 'new_company_allotment') {
                    $entry = $this->holdings->recordEntryFor(
                        $buyer, $shareClass, HoldingDirection::In, (int) $row->share_quantity,
                        'subscription', 'DividendReinvestment', (string) $row->id,
                        'Historical dividend reinvestment — new company allotment', $actorId,
                        CarbonImmutable::parse($row->occurred_on)
                    );
                    DB::table('finance_dividend_reinvestments')->where('id', $id)->update(['buyer_holding_entry_id' => $entry->id]);
                } else {
                    $seller = Shareholder::query()->where('tenant_id', $tenantId)->whereKey((int) $row->seller_shareholder_id)->whereNull('deleted_at')->firstOrFail();
                    $out = $this->holdings->recordEntryFor(
                        $seller, $shareClass, HoldingDirection::Out, (int) $row->share_quantity,
                        'transfer_out', 'DividendReinvestment', (string) $row->id,
                        'Historical dividend reinvestment — seller transfer', $actorId,
                        CarbonImmutable::parse($row->occurred_on)
                    );
                    $in = $this->holdings->recordEntryFor(
                        $buyer, $shareClass, HoldingDirection::In, (int) $row->share_quantity,
                        'transfer_in', 'DividendReinvestment', (string) $row->id,
                        'Historical dividend reinvestment — buyer transfer', $actorId,
                        CarbonImmutable::parse($row->occurred_on)
                    );
                    DB::table('finance_dividend_reinvestments')->where('id', $id)->update([
                        'seller_holding_entry_id' => $out->id, 'buyer_holding_entry_id' => $in->id,
                    ]);
                }
            }
            DB::table('finance_dividend_reinvestments')->where('id', $id)->update([
                'status' => 'posted', 'posted_by' => $actorId, 'posted_at' => now(), 'updated_at' => now(),
            ]);
            return (object) DB::table('finance_dividend_reinvestments')->where('id', $id)->first();
        });
    }

    private function money(string $value): string
    {
        if (!preg_match('/^\\d{1,16}(?:\\.\\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Money must be a non-negative decimal with up to four fractional digits.']);
        }
        return bcadd($value, '0', 4);
    }
}
