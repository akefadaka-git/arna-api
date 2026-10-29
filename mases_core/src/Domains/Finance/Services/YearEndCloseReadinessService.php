<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Finance\\Services;

use DateTimeImmutable;
use MaaleArna\\MASES\\Core\\Domains\\Finance\\Models\\AccountingPeriod;
use MaaleArna\\MASES\\Core\\Domains\\Finance\\Models\\JournalEntry;
use MaaleArna\\MASES\\Core\\Domains\\Finance\\Models\\JournalSourceEntityMapping;
use MaaleArna\\MASES\\Core\\Domains\\Finance\\Models\\LegalEntity;

/**
 * Read-only year-end close gate. This service reports prerequisites; it does
 * not close periods, create closing journals, or certify statutory compliance.
 */
final class YearEndCloseReadinessService
{
    public function __construct(private readonly FinancialReportService $reports)
    {
    }

    /** @return array<string, mixed> */
    public function assess(int $tenantId, int $legalEntityId, string $yearStart, string $yearEnd): array
    {
        if ($yearStart > $yearEnd) {
            throw new \\InvalidArgumentException('Fiscal year start must not be after fiscal year end.');
        }

        $entity = LegalEntity::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($legalEntityId);

        $periods = AccountingPeriod::query()
            ->forEntity($tenantId, $legalEntityId)
            ->whereDate('starts_on', '<=', $yearEnd)
            ->whereDate('ends_on', '>=', $yearStart)
            ->orderBy('starts_on')
            ->get();

        $periodRows = $periods->map(static fn (AccountingPeriod $period): array => [
            'starts_on' => $period->starts_on->format('Y-m-d'),
            'ends_on' => $period->ends_on->format('Y-m-d'),
            'status' => (string) $period->status,
            'code' => (string) $period->code,
        ])->all();

        $coverage = self::periodCoverage($periodRows, $yearStart, $yearEnd);
        $periodsClosed = $periods->isNotEmpty()
            && $periods->every(static fn (AccountingPeriod $period): bool => $period->status === AccountingPeriod::CLOSED);

        $draftJournals = JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->whereBetween('effective_date', [$yearStart, $yearEnd])
            ->where('status', JournalEntry::DRAFT)
            ->count();

        $pendingMappings = JournalSourceEntityMapping::query()
            ->forEntity($tenantId, $legalEntityId)
            ->where('status', JournalSourceEntityMapping::STATUS_PENDING)
            ->count();

        $trialBalance = $this->reports->trialBalance($tenantId, $legalEntityId, $yearEnd);
        $ownership = config('finance.control_account_ownership_v1', []);
        $configuredControls = is_array($ownership) ? count($ownership) : 0;
        $pendingOwners = 0;
        if (is_array($ownership)) {
            foreach ($ownership as $policy) {
                if (!is_array($policy)
                    || empty($policy['named_accountable_person'])
                    || ($policy['designation_status'] ?? null) !== 'DESIGNATED') {
                    $pendingOwners++;
                }
            }
        }

        $checks = [
            'accounting_period_coverage' => [
                'passed' => $coverage['covered'],
                'details' => $coverage['covered'] ? 'Fiscal year is covered without gaps.' : 'Fiscal year has missing accounting-period coverage.',
                'gaps' => $coverage['gaps'],
            ],
            'all_accounting_periods_closed' => [
                'passed' => $periodsClosed,
                'details' => $periodsClosed ? 'All accounting periods intersecting the fiscal year are closed.' : 'At least one intersecting period is open, or no period exists.',
                'period_count' => $periods->count(),
            ],
            'no_draft_journals' => [
                'passed' => $draftJournals === 0,
                'details' => $draftJournals === 0 ? 'No draft journals remain in the fiscal year.' : 'Draft journals must be posted or cancelled before close.',
                'count' => $draftJournals,
            ],
            'trial_balance_balanced' => [
                'passed' => (bool) ($trialBalance['summary']['is_balanced'] ?? false),
                'details' => (bool) ($trialBalance['summary']['is_balanced'] ?? false) ? 'Trial balance debits and credits balance as of fiscal year end.' : 'Trial balance does not balance or could not be confirmed.',
                'summary' => $trialBalance['summary'] ?? [],
            ],
            'source_entity_mappings_resolved' => [
                'passed' => $pendingMappings === 0,
                'details' => $pendingMappings === 0 ? 'No pending source-to-entity mappings remain.' : 'Pending source-to-entity mappings require resolution.',
                'count' => $pendingMappings,
            ],
            'control_account_owners_designated' => [
                'passed' => $configuredControls > 0 && $pendingOwners === 0,
                'details' => $configuredControls === 0
                    ? 'Control-account ownership register is unavailable; readiness fails closed.'
                    : ($pendingOwners === 0 ? 'All control-account owners are designated.' : 'Named accountable persons and formal designation remain pending.'),
                'control_account_count' => $configuredControls,
                'pending_designations' => $pendingOwners,
            ],
        ];

        $ready = collect($checks)->every(static fn (array $check): bool => $check['passed'] === true);

        return [
            'legal_entity_id' => $legalEntityId,
            'legal_entity_code' => (string) $entity->code,
            'fiscal_year_start' => $yearStart,
            'fiscal_year_end' => $yearEnd,
            'currency' => (string) $entity->base_currency,
            'status' => $ready ? 'ready_for_owner_review' : 'blocked',
            'ready_for_owner_review' => $ready,
            'checks' => $checks,
            'limitations' => [
                'This is a read-only readiness assessment, not an executed year-end close.',
                'No closing or retained-earnings journal is generated.',
                'Account tax defaults remain provisional routing hints; this report does not calculate or certify tax liabilities.',
                'This report is not an IFRS or Ethiopian statutory audit opinion.',
                'Subledger reconciliation evidence is not independently attested by this endpoint.',
            ],
        ];
    }

    /**
     * Pure date-coverage calculation, separated for deterministic unit testing.
     *
     * @param array<int, array{starts_on:string,ends_on:string,status?:string,code?:string}> $periods
     * @return array{covered:bool,gaps:array<int,array{from:string,to:string}>}
     */
    public static function periodCoverage(array $periods, string $yearStart, string $yearEnd): array
    {
        $cursor = new DateTimeImmutable($yearStart);
        $end = new DateTimeImmutable($yearEnd);
        $gaps = [];
        $periods = array_values(array_filter($periods, static function (array $period) use ($yearStart, $yearEnd): bool {
            return $period['starts_on'] <= $yearEnd && $period['ends_on'] >= $yearStart;
        }));
        usort($periods, static fn (array $a, array $b): int => $a['starts_on'] <=> $b['starts_on']);

        foreach ($periods as $period) {
            $periodStart = new DateTimeImmutable($period['starts_on']);
            $periodEnd = new DateTimeImmutable($period['ends_on']);
            if ($periodEnd < $cursor) {
                continue;
            }

            if ($periodStart > $cursor) {
                $gapEnd = $periodStart->modify('-1 day');
                if ($gapEnd > $end) {
                    $gapEnd = $end;
                }
                $gaps[] = ['from' => $cursor->format('Y-m-d'), 'to' => $gapEnd->format('Y-m-d')];
                $cursor = $periodStart;
            }

            if ($periodStart <= $cursor && $periodEnd >= $cursor) {
                $cursor = $periodEnd->modify('+1 day');
            }

            if ($cursor > $end) {
                break;
            }
        }

        if ($cursor <= $end) {
            $gaps[] = ['from' => $cursor->format('Y-m-d'), 'to' => $end->format('Y-m-d')];
        }

        return ['covered' => $gaps === [] && $cursor > $end, 'gaps' => $gaps];
    }
}
