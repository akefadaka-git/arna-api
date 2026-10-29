<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Finance\Models\FinanceExchangeRate;

final class FinanceExchangeRateService
{
    /**
     * Rate direction: quote-currency units for one base-currency unit.
     * A proposal is inert until a different actor approves it.
     *
     * @param array{base_currency:string,quote_currency:string,rate:string,effective_on:string,source:string,source_reference?:?string,proposal_reason:string} $data
     */
    public function propose(int $tenantId, int $actorId, array $data): FinanceExchangeRate
    {
        $base = strtoupper(trim($data['base_currency']));
        $quote = strtoupper(trim($data['quote_currency']));
        $rate = trim($data['rate']);
        $source = trim($data['source']);
        $reason = trim($data['proposal_reason']);

        if (!preg_match('/^[A-Z]{3}$/', $base) || !preg_match('/^[A-Z]{3}$/', $quote) || $base === $quote) {
            throw ValidationException::withMessages(['currency' => 'Base and quote currencies must be distinct three-letter currency codes.']);
        }
        if (!preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,10})?$/', $rate) || preg_match('/^0(?:\.0+)?$/', $rate)) {
            throw ValidationException::withMessages(['rate' => 'Rate must be positive, with at most fourteen integer and ten fractional digits.']);
        }
        if ($source === '' || strlen($source) > 120 || $reason === '') {
            throw ValidationException::withMessages(['source' => 'A rate source and proposal reason are required.']);
        }

        try {
            return FinanceExchangeRate::query()->create([
                'tenant_id' => $tenantId, 'base_currency' => $base, 'quote_currency' => $quote,
                'rate' => $rate, 'effective_on' => $data['effective_on'], 'source' => $source,
                'source_reference' => isset($data['source_reference']) ? trim((string) $data['source_reference']) : null,
                'status' => FinanceExchangeRate::PENDING, 'created_by' => $actorId, 'proposal_reason' => $reason,
            ]);
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? '');
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if (!in_array($sqlState, ['23000', '23505'], true) && !in_array($driverCode, [19, 1062], true)) {
                throw $exception;
            }
            throw ValidationException::withMessages([
                'effective_on' => 'A proposal already exists for this tenant, currency pair, and effective date. Review it instead of creating a competing rate.',
            ]);
        }
    }

    public function approve(int $tenantId, int $actorId, int $rateId, string $reason): FinanceExchangeRate
    {
        return DB::transaction(function () use ($tenantId, $actorId, $rateId, $reason): FinanceExchangeRate {
            $rate = FinanceExchangeRate::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($rateId);
            if ($rate->status !== FinanceExchangeRate::PENDING) {
                throw ValidationException::withMessages(['status' => 'Only pending exchange-rate proposals can be approved.']);
            }
            if ((int) $rate->created_by === $actorId) {
                throw ValidationException::withMessages(['reviewer' => 'The proposer cannot approve their own rate.']);
            }
            if (trim($reason) === '') {
                throw ValidationException::withMessages(['review_reason' => 'An approval reason is required.']);
            }
            $rate->status = FinanceExchangeRate::APPROVED;
            $rate->reviewed_by = $actorId;
            $rate->reviewed_at = now();
            $rate->review_reason = trim($reason);
            $rate->save();
            return $rate->fresh();
        });
    }

    public function reject(int $tenantId, int $actorId, int $rateId, string $reason): FinanceExchangeRate
    {
        return DB::transaction(function () use ($tenantId, $actorId, $rateId, $reason): FinanceExchangeRate {
            $rate = FinanceExchangeRate::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($rateId);
            if ($rate->status !== FinanceExchangeRate::PENDING) {
                throw ValidationException::withMessages(['status' => 'Only pending exchange-rate proposals can be rejected.']);
            }
            if ((int) $rate->created_by === $actorId) {
                throw ValidationException::withMessages(['reviewer' => 'The proposer cannot review their own rate.']);
            }
            if (trim($reason) === '') {
                throw ValidationException::withMessages(['review_reason' => 'A rejection reason is required.']);
            }
            $rate->status = FinanceExchangeRate::REJECTED;
            $rate->reviewed_by = $actorId;
            $rate->reviewed_at = now();
            $rate->review_reason = trim($reason);
            $rate->save();
            return $rate->fresh();
        });
    }

    /** Returns only the latest approved rate effective on or before the date. */
    public function resolveApproved(int $tenantId, string $baseCurrency, string $quoteCurrency, string $effectiveOn): FinanceExchangeRate
    {
        $base = strtoupper(trim($baseCurrency));
        $quote = strtoupper(trim($quoteCurrency));
        if ($base === $quote) {
            throw ValidationException::withMessages(['currency' => 'Same-currency amounts do not require an exchange rate.']);
        }
        return FinanceExchangeRate::query()
            ->where('tenant_id', $tenantId)->where('base_currency', $base)->where('quote_currency', $quote)
            ->where('status', FinanceExchangeRate::APPROVED)->whereDate('effective_on', '<=', $effectiveOn)
            ->orderByDesc('effective_on')->firstOrFail();
    }
}
