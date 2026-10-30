<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Domains\Finance\Contracts\CashFlowReportContract;
use MaaleArna\MASES\Core\Domains\Finance\Enums\CashFlowActivity;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Support\MoneyArithmetic;

/**
 * Read-only cash-movement report. Activity is explicit metadata on cash lines;
 * account codes and descriptions are never used to guess classification.
 *
 * This report shows movement for the selected period. Opening/closing cash
 * balances are deliberately omitted until a separately approved balance
 * snapshot and cash-equivalent reconciliation contract exists.
 */
final class CashFlowReportService implements CashFlowReportContract
{
    public function cashFlow(int $tenantId, int $legalEntityId, string $from, string $to): array
    {
        $entity = LegalEntity::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($legalEntityId);

        $rows = DB::table('journal_lines')
            ->join('journal_entries', function ($join): void {
                $join->on('journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->on('journal_entries.tenant_id', '=', 'journal_lines.tenant_id')
                    ->on('journal_entries.legal_entity_id', '=', 'journal_lines.legal_entity_id');
            })
            ->join('ledger_accounts', function ($join): void {
                $join->on('ledger_accounts.id', '=', 'journal_lines.account_id')
                    ->on('ledger_accounts.tenant_id', '=', 'journal_lines.tenant_id')
                    ->on('ledger_accounts.legal_entity_id', '=', 'journal_lines.legal_entity_id');
            })
            ->where('journal_lines.tenant_id', $tenantId)
            ->where('journal_lines.legal_entity_id', $legalEntityId)
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.legal_entity_id', $legalEntityId)
            ->whereIn('journal_entries.status', [JournalEntry::POSTED, JournalEntry::REVERSED])
            ->whereBetween('journal_entries.effective_date', [$from, $to])
            ->where('ledger_accounts.is_cash_equivalent', true)
            ->orderBy('journal_entries.effective_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_lines.line_number')
            ->get([
                'journal_entries.id as journal_entry_id',
                'journal_entries.journal_number',
                'journal_entries.effective_date',
                'journal_entries.currency',
                'journal_lines.id as journal_line_id',
                'journal_lines.line_number',
                'journal_lines.description',
                'journal_lines.debit_amount',
                'journal_lines.credit_amount',
                'journal_lines.cash_flow_activity',
                'journal_lines.cash_flow_classification_note',
                'ledger_accounts.id as account_id',
                'ledger_accounts.code as account_code',
                'ledger_accounts.name_en as account_name_en',
                'ledger_accounts.name_am as account_name_am',
            ]);

        $activities = [
            CashFlowActivity::OPERATING->value => self::emptyBucket(),
            CashFlowActivity::INVESTING->value => self::emptyBucket(),
            CashFlowActivity::FINANCING->value => self::emptyBucket(),
            'undetermined' => self::emptyBucket(),
        ];
        $unclassified = [];

        foreach ($rows as $row) {
            $activity = in_array($row->cash_flow_activity, [
                CashFlowActivity::OPERATING->value,
                CashFlowActivity::INVESTING->value,
                CashFlowActivity::FINANCING->value,
            ], true) ? $row->cash_flow_activity : 'undetermined';

            $debit = MoneyArithmetic::toMinorUnits((string) $row->debit_amount);
            $credit = MoneyArithmetic::toMinorUnits((string) $row->credit_amount);
            $bucket = &$activities[$activity];
            $bucket['inflows'] = MoneyArithmetic::add($bucket['inflows'], $debit);
            $bucket['outflows'] = MoneyArithmetic::add($bucket['outflows'], $credit);
            $bucket['movement_count']++;
            unset($bucket);

            if ($activity === 'undetermined') {
                $unclassified[] = [
                    'journal_entry_id' => (int) $row->journal_entry_id,
                    'journal_number' => (string) $row->journal_number,
                    'journal_line_id' => (int) $row->journal_line_id,
                    'effective_date' => (string) $row->effective_date,
                    'account_code' => (string) $row->account_code,
                    'account_name_en' => (string) $row->account_name_en,
                    'description' => $row->description,
                    'debit_amount' => (string) $row->debit_amount,
                    'credit_amount' => (string) $row->credit_amount,
                    'classification_note' => $row->cash_flow_classification_note,
                ];
            }
        }

        foreach ($activities as &$bucket) {
            $bucket['inflows'] = MoneyArithmetic::format($bucket['inflows']);
            $bucket['outflows'] = MoneyArithmetic::format($bucket['outflows']);
            $bucket['net_movement'] = self::signedDifference(
                MoneyArithmetic::toMinorUnits($bucket['inflows']),
                MoneyArithmetic::toMinorUnits($bucket['outflows']),
            );
        }
        unset($bucket);

        $totalInflows = '0';
        $totalOutflows = '0';
        foreach ($activities as $bucket) {
            $totalInflows = MoneyArithmetic::add($totalInflows, MoneyArithmetic::toMinorUnits($bucket['inflows']));
            $totalOutflows = MoneyArithmetic::add($totalOutflows, MoneyArithmetic::toMinorUnits($bucket['outflows']));
        }

        return [
            'period' => ['from' => $from, 'to' => $to],
            'legal_entity_id' => $legalEntityId,
            'currency' => (string) $entity->base_currency,
            'basis' => 'posted_cash_movements',
            'classification_policy' => 'explicit_cash_line_activity_no_account_code_inference',
            'activities' => $activities,
            'summary' => [
                'total_inflows' => MoneyArithmetic::format($totalInflows),
                'total_outflows' => MoneyArithmetic::format($totalOutflows),
                'net_movement' => self::signedDifference($totalInflows, $totalOutflows),
                'movement_count' => array_sum(array_column($activities, 'movement_count')),
                'unclassified_movement_count' => count($unclassified),
                'has_unclassified_movements' => $unclassified !== [],
            ],
            'unclassified_movements' => $unclassified,
            'limitations' => [
                'This is a period movement report, not a complete statement with opening and closing cash balances.',
                'Only ledger accounts explicitly marked is_cash_equivalent=true are included.',
                'Missing or invalid activity is reported as undetermined; no account-code or description inference is performed.',
                'Cash-flow classifications and cash-equivalent account flags require controlled authorization and reconciliation.',
            ],
        ];
    }

    /** @return array{inflows:string,outflows:string,net_movement:string,movement_count:int} */
    private static function emptyBucket(): array
    {
        return ['inflows' => '0', 'outflows' => '0', 'net_movement' => '0.0000', 'movement_count' => 0];
    }

    private static function signedDifference(string $inflows, string $outflows): string
    {
        $comparison = MoneyArithmetic::compare($inflows, $outflows);
        if ($comparison === 0) {
            return '0.0000';
        }

        $difference = $comparison > 0
            ? MoneyArithmetic::subtract($inflows, $outflows)
            : MoneyArithmetic::subtract($outflows, $inflows);

        return ($comparison < 0 ? '-' : '').MoneyArithmetic::format($difference);
    }
}
