<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Finance\\Services;

use Illuminate\\Database\\QueryException;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Validator;
use Illuminate\\Validation\\ValidationException;
use MaaleArna\\MASES\\Core\\Domains\\Finance\\Models\\FinanceExchangeRate;

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
        $base = strtoupper(trim($data['base_currency'] ?? ''));
        $quote = strtoupper(trim($data['quote_currency'] ?? ''));
        $rate = trim((string) ($data['rate'] ?? ''));
        $effectiveOn = trim((string) ($data['effective_on'] ?? ''));
        $source = trim((string) ($data['source'] ?? ''));
        $sourceReference = trim((string) ($data['source_reference'] ?? ''));
        $reason = trim((string) ($data['proposal_reason'] ?? ''));

        if (!preg_match('/^[A-Z]{3}$/', $base) || !preg_match('/^[A-Z]{3}$/', $quote) || $base === $quote) {
            throw ValidationException::withMessages(['currency' => 'Base and quote currencies must be distinct three-letter currency codes.']);
        }
        if (!preg_match('/^(?:0|[1-9]\\d{0,13})(?:\\.\\d{1,10})?$/', $rate) || preg_match('/^0(?:\\.0+)?$/', $rate)) {
            throw ValidationException::withMessages(['rate' => 'Rate must be positive, with at most fourteen integer and ten fractional digits.']);
        }
        Validator::make(['effective_on' => $effectiveOn], [
            'effective_on' => ['required', 'date_format:Y-m-d'],
        ])->validate();
        if ($source === '' || strlen($source) > 120 || $reason === '') {
            throw ValidationException::withMessages(['source' => 'A rate source and proposal reason are required.']);
        }
        if (strlen($sourceReference) > 160) {
            throw ValidationException::withMessages(['source_reference' => 'Source reference must not exceed 160 characters.']);
        }

        try {
            return DB::transaction(function () use ($tenantId, $actorId, $base, $quote, $rate, $effectiveOn, $source, $sourceReference, $reason): FinanceExchangeRate {
                // A tenant-row lock serializes pair/date proposal-version allocation across workers.
                $tenant = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
                if (!$tenant) {
                    throw ValidationException::withMessages(['tenant_id' => 'The tenant does not exist.']);
                }

                $existing = FinanceExchangeRate::query()->where('tenant_id', $tenantId)
                    ->where('base_currency', $base)->where('quote_currency', $quote)
                    ->whereDate('effective_on', $effectiveOn);
                if ((clone $existing)->where('status', FinanceExchangeRate::APPROVED)->exists()) {
                    throw ValidationException::withMessages(['effective_on' => 'An approved rate already exists for this pair and date. Approved rates are immutable; use a separately governed correction workflow.']);
                }
                if ((clone $existing)->where('status', FinanceExchangeRate::PENDING)->exists()) {
                    throw ValidationException::withMessages(['effective_on' => 'A pending proposal already exists for this pair and date. Review it before creating another.']);
                }

                $version = ((int) (clone $existing)->max('proposal_version')) + 1;
                return FinanceExchangeRate::query()->create([
                    'tenant_id' => $tenantId, 'base_currency' => $base, 'quote_currency' => $quote,
                    'rate' => $rate, 'effective_on' => $effectiveOn, 'source' => $source,
                    'source_reference' => $sourceReference !== '' ? $sourceReference : null,
                    'status' => FinanceExchangeRate::PENDING, 'proposal_version' => $version,
                    'created_by' => $actorId, 'proposal_reason' => $reason,
                ]);
            });
        } catch (QueryException $exception) {
            if (!$this->isUniqueViolation($exception)) {
                throw $exception;
            }
            throw ValidationException::withMessages([
                'effective_on' => 'A competing exchange-rate proposal was created concurrently. Reload the pair/date and review its current state.',
            ]);
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
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['review_reason' => 'A review reason is required.']);
        }

        return DB::transaction(function () use ($tenantId, $actorId, $rateId, $reason, $decision): FinanceExchangeRate {
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            $rate = FinanceExchangeRate::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($rateId);
            if ($rate->status !== FinanceExchangeRate::PENDING) {
                throw ValidationException::withMessages(['status' => 'Only pending exchange-rate proposals can be reviewed.']);
            }
            if ((int) $rate->created_by === $actorId) {
                throw ValidationException::withMessages(['reviewer' => 'The proposer cannot review their own rate.']);
            }
            if ($decision === FinanceExchangeRate::APPROVED
                && FinanceExchangeRate::query()->where('tenant_id', $tenantId)
                    ->where('base_currency', $rate->base_currency)->where('quote_currency', $rate->quote_currency)
                    ->whereDate('effective_on', $rate->effective_on)
                    ->where('status', FinanceExchangeRate::APPROVED)->where('id', '<>', $rate->id)->exists()) {
                throw ValidationException::withMessages(['effective_on' => 'Another rate is already approved for this pair and date. Use a governed correction workflow.']);
            }

            $rate->status = $decision;
            $rate->reviewed_by = $actorId;
            $rate->reviewed_at = now();
            $rate->review_reason = $reason;
            $rate->save();
            return $rate->fresh();
        });
    }

    /** Returns only the latest approved rate effective on or before the date. */
    public function resolveApproved(int $tenantId, string $baseCurrency, string $quoteCurrency, string $effectiveOn): FinanceExchangeRate
    {
        $base = strtoupper(trim($baseCurrency));
        $quote = strtoupper(trim($quoteCurrency));
        if (!preg_match('/^[A-Z]{3}$/', $base) || !preg_match('/^[A-Z]{3}$/', $quote) || $base === $quote) {
            throw ValidationException::withMessages(['currency' => 'Base and quote currencies must be distinct three-letter currency codes.']);
        }
        Validator::make(['effective_on' => $effectiveOn], [
            'effective_on' => ['required', 'date_format:Y-m-d'],
        ])->validate();

        return FinanceExchangeRate::query()
            ->where('tenant_id', $tenantId)->where('base_currency', $base)->where('quote_currency', $quote)
            ->where('status', FinanceExchangeRate::APPROVED)->whereDate('effective_on', '<=', $effectiveOn)
            ->orderByDesc('effective_on')->orderByDesc('proposal_version')->firstOrFail();
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());
        return $sqlState === '23505' || $driverCode === 1062
            || ($driverCode === 19 && str_contains($message, 'unique constraint'));
    }
}
