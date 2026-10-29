<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;

/**
 * Read-only general-ledger reporting.
 *
 * Scope is always tenant + legal entity. A reversal contributes its posted
 * counter-entry while the original reversed entry remains in the ledger, so
 * both statuses must be included for a net-zero reversal to be represented.
 */
final class TrialBalanceService
{
    /** @return Collection<int, object> */
    public function forEntity(int $tenantId, int $legalEntityId, string $asOf): Collection
    {
        return LedgerAccount::query()
            ->where('ledger_accounts.tenant_id', $tenantId)
            ->where('ledger_accounts.legal_entity_id', $legalEntityId)
            ->leftJoin('journal_lines', function ($join): void {
                $join->on('journal_lines.account_id', '=', 'ledger_accounts.id')
                    ->on('journal_lines.tenant_id', '=', 'ledger_accounts.tenant_id')
                    ->on('journal_lines.legal_entity_id', '=', 'ledger_accounts.legal_entity_id');
            })
            ->leftJoin('journal_entries', function ($join) use ($asOf): void {
                $join->on('journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->on('journal_entries.tenant_id', '=', 'journal_lines.tenant_id')
                    ->on('journal_entries.legal_entity_id', '=', 'journal_lines.legal_entity_id')
                    ->whereIn('journal_entries.status', [JournalEntry::POSTED, JournalEntry::REVERSED])
                    ->whereDate('journal_entries.effective_date', '<=', $asOf);
            })
            ->groupBy(
                'ledger_accounts.id',
                'ledger_accounts.code',
                'ledger_accounts.name_en',
                'ledger_accounts.name_am',
                'ledger_accounts.account_type',
            )
            ->orderBy('ledger_accounts.code')
            ->get([
                'ledger_accounts.id',
                'ledger_accounts.code',
                'ledger_accounts.name_am',
                'ledger_accounts.name_en',
                'ledger_accounts.account_type',
                DB::raw('COALESCE(SUM(CASE WHEN journal_entries.id IS NOT NULL THEN journal_lines.debit_amount ELSE 0 END), 0) AS debit_total'),
                DB::raw('COALESCE(SUM(CASE WHEN journal_entries.id IS NOT NULL THEN journal_lines.credit_amount ELSE 0 END), 0) AS credit_total'),
            ]);
    }
}
