<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Finance\Events\AccountingPeriodOpened;
use MaaleArna\MASES\Core\Domains\Finance\Events\AccountingPeriodClosed;
use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;

/**
 * Owns accounting-period lifecycle invariants.
 *
 * Period creation and closure lock the legal-entity/period rows so they
 * serialize against journal creation/posting, which locks the same period.
 */
final class AccountingPeriodService
{
    /** @param array{legal_entity_id:int|string,code:string,starts_on:string,ends_on:string} $data */
    public function create(int $tenantId, array $data, int $actorId): AccountingPeriod
    {
        $startsOn = trim((string) ($data['starts_on'] ?? ''));
        $endsOn = trim((string) ($data['ends_on'] ?? ''));
        $startDate = \\DateTimeImmutable::createFromFormat('!Y-m-d', $startsOn);
        $endDate = \\DateTimeImmutable::createFromFormat('!Y-m-d', $endsOn);
        $startErrors = \\DateTimeImmutable::getLastErrors();
        $endErrors = \\DateTimeImmutable::getLastErrors();

        if (!$startDate || !$endDate
            || $startDate->format('Y-m-d') !== $startsOn
            || $endDate->format('Y-m-d') !== $endsOn
            || ($startErrors !== false && ($startErrors['warning_count'] > 0 || $startErrors['error_count'] > 0))
            || ($endErrors !== false && ($endErrors['warning_count'] > 0 || $endErrors['error_count'] > 0))
            || $startDate > $endDate) {
            throw ValidationException::withMessages([
                'starts_on' => 'The accounting period must use valid ISO dates and its start date must not be after its end date.',
            ]);
        }

        $data['starts_on'] = $startsOn;
        $data['ends_on'] = $endsOn;

        return DB::transaction(function () use ($tenantId, $data, $actorId): AccountingPeriod {
            $entity = LegalEntity::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->findOrFail((int) $data['legal_entity_id']);

            $overlap = AccountingPeriod::query()
                ->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $entity->id)
                ->where('starts_on', '<=', $data['ends_on'])
                ->where('ends_on', '>=', $data['starts_on'])
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages([
                    'starts_on' => 'Accounting periods for one legal entity cannot overlap.',
                ]);
            }

            try {
                $period = AccountingPeriod::query()->create([
                    'tenant_id' => $tenantId,
                    'legal_entity_id' => $entity->id,
                    'code' => trim($data['code']),
                    'starts_on' => $data['starts_on'],
                    'ends_on' => $data['ends_on'],
                    'status' => AccountingPeriod::OPEN,
                ]);
                event(new AccountingPeriodOpened($period, $actorId));
                return $period;
            } catch (QueryException $exception) {
                $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
                $driverCode = (int) ($exception->errorInfo[1] ?? 0);
                $message = strtolower($exception->getMessage());
                $uniqueViolation = (
                    in_array($sqlState, ['23000', '23505'], true)
                    || in_array($driverCode, [19, 1062, 2601, 2627], true)
                ) && (str_contains($message, 'unique') || str_contains($message, 'duplicate'));

                if ($uniqueViolation && str_contains($message, 'accounting_periods')) {
                    throw ValidationException::withMessages([
                        'code' => 'This accounting-period code already exists for the legal entity.',
                    ]);
                }

                throw $exception;
            }
        });
    }

    public function close(int $tenantId, int $actorId, int $periodId, string $reason): AccountingPeriod
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages([
                'reason' => 'A closure reason between 5 and 2000 characters is required.',
            ]);
        }

        return DB::transaction(function () use ($tenantId, $actorId, $periodId, $reason): AccountingPeriod {
            $period = AccountingPeriod::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($periodId);

            if ($period->status !== AccountingPeriod::OPEN) {
                throw ValidationException::withMessages([
                    'status' => 'Only an open period can be closed.',
                ]);
            }

            $hasDrafts = JournalEntry::query()
                ->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $period->legal_entity_id)
                ->where('accounting_period_id', $period->id)
                ->where('status', JournalEntry::DRAFT)
                ->exists();

            if ($hasDrafts) {
                throw ValidationException::withMessages([
                    'period' => 'Resolve or cancel all draft journals before closing this period.',
                ]);
            }

            $period->status = AccountingPeriod::CLOSED;
            $period->closed_by = $actorId;
            $period->closed_at = now();
            $period->close_reason = $reason;
            $period->save();
            $period = $period->fresh();
            event(new AccountingPeriodClosed($period, $actorId, $reason));
            return $period;
        });
    }
}
