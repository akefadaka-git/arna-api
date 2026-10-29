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
        $effectiveOn = trim((string) ($data['effective_on'] ?? ''));

        if (!preg_match('/^[A-Z]{3}$/', $base) || !preg_match('/^[A-Z]{3}$/', $quote) || $base === $quote) {
            throw ValidationException::withMessages(['currency' => 'Base and quote currencies must be distinct three-letter currency codes.']);
        }
        if (!preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,10})?$/', $rate) || preg_match('/^0(?:\.0+)?$/', $rate)) {
            throw ValidationException::withMessages(['rate' => 'Rate must be positive, with at most fourteen integer and ten fractional digits.']);
        }
        if ($source === '' || strlen($source) > 120 || $reason === '') {
            throw ValidationException::withMessages(['source' => 'A rate source and proposal reason are required.']);
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveOn);
        if (!$date || $date->format('Y-m-d') !== $effectiveOn) {
            throw ValidationException::withMessages(['effective_on' => 'Effective date must be a valid ISO date (YYYY-MM-DD).']);
        }

        try {
            return DB::transaction(function () use ($tenantId, $actorId, $data, $base, $quote, $rate, $source, $reason, $effectiveOn): FinanceExchangeRate {
                $tenant = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
                if (!$tenant) {
                    throw ValidationException::withMessages(['tenant_id' => 'Tenant does not exist.']);
                }

                $activeProposalExists = FinanceExchangeRate::query()
                    ->where('tenant_id', $tenantId)->where('base_currency', $base)->where('quote_currency', $quote)
                    ->whereDate('effective_on', $effectiveOn)
                    ->whereIn('status', [FinanceExchangeRate::PENDING, FinanceExchangeRate::APPROVED])->exists();
                if ($activeProposalExists) {
                    throw ValidationException::withMessages(['effective_on' => 'A pending or approved rate already exists for this pair and date. Use the governed correction workflow for approved rates.']);
                }

                $version = ((int) FinanceExchangeRate::query()->where('tenant_id', $tenantId)
                    ->where('base_currency', $base)->where('quote_currency', $quote)
                    ->whereDate('effective_on', $effectiveOn)->max('proposal_version')) + 1;

                return FinanceExchangeRate::query()->create([
                    'tenant_id' => $tenantId, 'base_currency' => $base, 'quote_currency' => $quote,
                    'rate' => $rate, 'effective_on' => $effectiveOn, 'source' => $source,
                    'source_reference' => isset($data['source_reference']) ? trim((string) $data['source_reference']) : null,
                    'status' => FinanceExchangeRate::PENDING, 'proposal_version' => $version,
                    'created_by' => $actorId, 'proposal_reason' => $reason,
                ]);
            }, 3);
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? '');
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            $message = strtolower($exception->getMessage());
            $isUniqueViolation = $sqlState === '23505' || $driverCode === 1062
                || ($driverCode === 19 && str_contains($message, 'unique constraint'));
            if (!$isUniqueViolation) throw $exception;
            throw ValidationException::withMessages(['effective_on' => 'A competing rate proposal was created. Reload the pair/date before retrying.']);
        }
    }

    public function approve(int $tenantId, int $actorId, int $rateId, string $reason): FinanceExchangeRate
    {
        return $this->review($tenantId, $actorId, $rateId, $reason, FinanceExchangeRate::APPROVED);
    }

    public function reject(int $tenantId, int $actorId, int $rateId, string $reason): FinanceExchangeRate
    {
        return $this->review($tenantId, $actorId, $rateId, $reason, FinanceExchangeRate::REJECTED);
    }

    private function review(int $tenantId, int $actorId, int $rateId, string $reason, string $decision): FinanceExchangeRate
    {
        return DB::transaction(function () use ($tenantId, $actorId, $rateId, $reason, $decision): FinanceExchangeRate {
            $tenant = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            if (!$tenant) {
                throw ValidationException::withMessages(['tenant_id' => 'Tenant does not exist.']);
            }
            $rate = FinanceExchangeRate::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($rateId);
            if ($rate->status !== FinanceExchangeRate::PENDING) {
                throw ValidationException::withMessages(['status' => 'Only pending exchange-rate proposals can be reviewed.']);
            }
            if ((int) $rate->created_by === $actorId) {
                throw ValidationException::withMessages(['reviewer' => 'The proposer cannot review their own rate.']);
            }
            if (trim($reason) === '') {
                throw ValidationException::withMessages(['review_reason' => 'A review reason is required.']);
            }
            if ($decision === FinanceExchangeRate::APPROVED
                && FinanceExchangeRate::query()->where('tenant_id', $tenantId)
                    ->where('base_currency', $rate->base_currency)->where('quote_currency', $rate->quote_currency)
                    ->whereDate('effective_on', $rate->effective_on)->where('status', FinanceExchangeRate::APPROVED)
                    ->where('id', '<>', $rate->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'An approved rate already exists for this pair and date; use the governed correction workflow.']);
            }

            $rate->status = $decision;
            $rate->reviewed_by = $actorId;
            $rate->reviewed_at = now();
            $rate->review_reason = trim($reason);
            $rate->save();
            return $rate->fresh();
        }, 3);
    }

    /** Returns only the latest approved rate effective on or before the date. */
    public function resolveApproved(int $tenantId, string $baseCurrency, string $quoteCurrency, string $effectiveOn): FinanceExchangeRate
    {
        $base = strtoupper(trim($baseCurrency));
        $quote = strtoupper(trim($quoteCurrency));
        if (!preg_match('/^[A-Z]{3}$/', $base) || !preg_match('/^[A-Z]{3}$/', $quote) || $base === $quote) {
            throw ValidationException::withMessages(['currency' => 'Base and quote currencies must be distinct three-letter currency codes.']);
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveOn);
        if (!$date || $date->format('Y-m-d') !== $effectiveOn) {
            throw ValidationException::withMessages(['effective_on' => 'Effective date must be a valid ISO date (YYYY-MM-DD).']);
        }
        return FinanceExchangeRate::query()
            ->where('tenant_id', $tenantId)->where('base_currency', $base)->where('quote_currency', $quote)
            ->where('status', FinanceExchangeRate::APPROVED)->whereDate('effective_on', '<=', $effectiveOn)
            ->orderByDesc('effective_on')->firstOrFail();
    }
}
