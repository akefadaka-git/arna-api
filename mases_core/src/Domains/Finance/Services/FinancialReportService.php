<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use MaaleArna\MASES\Core\Domains\Finance\Contracts\FinancialReportContract;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Support\MoneyArithmetic;

/** Read-only report composition and exact aggregate arithmetic. */
final class FinancialReportService implements FinancialReportContract
{
    public function __construct(private readonly TrialBalanceService $trialBalance) {}


    /**
     * Period-based income statement from posted ledger activity only.
     * Monetary values remain exact decimal strings; no binary floats are used.
     *
     * @return array{from:string,to:string,currency:string,accounts:array<int,array<string,string>>,summary:array{total_revenue:string,total_expenses:string,net_income:string}}
     */
    public function incomeStatement(int $tenantId, int $legalEntityId, string $from, string $to): array
    {
        if ($from > $to) {
            throw new \InvalidArgumentException('Income statement start date must not be after its end date.');
        }

        $entity = LegalEntity::query()->where('tenant_id', $tenantId)->findOrFail($legalEntityId);
        $rows = \MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount::query()
            ->where('ledger_accounts.tenant_id', $tenantId)
            ->where('ledger_accounts.legal_entity_id', $legalEntityId)
            ->whereIn('ledger_accounts.account_type', ['revenue', 'expense'])
            ->leftJoin('journal_lines', function ($join): void {
                $join->on('journal_lines.account_id', '=', 'ledger_accounts.id')
                    ->on('journal_lines.tenant_id', '=', 'ledger_accounts.tenant_id')
                    ->on('journal_lines.legal_entity_id', '=', 'ledger_accounts.legal_entity_id');
            })
            ->leftJoin('journal_entries', function ($join) use ($tenantId, $legalEntityId, $from, $to): void {
                $join->on('journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->on('journal_entries.tenant_id', '=', 'journal_lines.tenant_id')
                    ->on('journal_entries.legal_entity_id', '=', 'journal_lines.legal_entity_id')
                    ->where('journal_entries.tenant_id', '=', $tenantId)
                    ->where('journal_entries.legal_entity_id', '=', $legalEntityId)
                    ->whereIn('journal_entries.status', [
                        \MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry::POSTED,
                        \MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry::REVERSED,
                    ])
                    ->whereBetween('journal_entries.effective_date', [$from, $to]);
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
                'ledger_accounts.code',
                'ledger_accounts.name_en',
                'ledger_accounts.name_am',
                'ledger_accounts.account_type',
                \Illuminate\Support\Facades\DB::raw('COALESCE(SUM(CASE WHEN journal_entries.id IS NOT NULL THEN journal_lines.debit_amount ELSE 0 END), 0) AS debit_total'),
                \Illuminate\Support\Facades\DB::raw('COALESCE(SUM(CASE WHEN journal_entries.id IS NOT NULL THEN journal_lines.credit_amount ELSE 0 END), 0) AS credit_total'),
            ]);

        $revenueCredits = '0';
        $revenueDebits = '0';
        $expenseDebits = '0';
        $expenseCredits = '0';
        $accounts = [];

        foreach ($rows as $row) {
            $debits = MoneyArithmetic::toMinorUnits((string) $row->debit_total);
            $credits = MoneyArithmetic::toMinorUnits((string) $row->credit_total);
            if ($row->account_type === 'revenue') {
                $revenueCredits = MoneyArithmetic::add($revenueCredits, $credits);
                $revenueDebits = MoneyArithmetic::add($revenueDebits, $debits);
                $amount = $this->signedDifference($credits, $debits);
            } else {
                $expenseDebits = MoneyArithmetic::add($expenseDebits, $debits);
                $expenseCredits = MoneyArithmetic::add($expenseCredits, $credits);
                $amount = $this->signedDifference($debits, $credits);
            }

            $accounts[] = [
                'code' => (string) $row->code,
                'name_en' => (string) $row->name_en,
                'name_am' => (string) ($row->name_am ?? ''),
                'account_type' => (string) $row->account_type,
                'debit_total' => MoneyArithmetic::format($debits),
                'credit_total' => MoneyArithmetic::format($credits),
                'amount' => $amount,
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'currency' => (string) $entity->base_currency,
            'accounts' => $accounts,
            'summary' => [
                'total_revenue' => $this->signedDifference($revenueCredits, $revenueDebits),
                'total_expenses' => $this->signedDifference($expenseDebits, $expenseCredits),
                'net_income' => $this->signedDifference(
                    MoneyArithmetic::add($revenueCredits, $expenseCredits),
                    MoneyArithmetic::add($revenueDebits, $expenseDebits),
                ),
            ],
        ];
    }


    /**
     * Read-only balance sheet as of an inclusive posting date. Unclosed
     * cumulative profit/loss is presented as current earnings within equity.
     *
     * @return array{as_of:string,currency:string,accounts:array<int,array<string,string>>,summary:array{total_assets:string,total_liabilities:string,total_equity_accounts:string,current_earnings:string,total_liabilities_and_equity:string,is_balanced:bool}}
     */
    public function balanceSheet(int $tenantId, int $legalEntityId, string $asOf): array
    {
        $entity = LegalEntity::query()->where('tenant_id', $tenantId)->findOrFail($legalEntityId);
        $rows = $this->trialBalance->forEntity($tenantId, $legalEntityId, $asOf);

        $assetDebits = $assetCredits = '0';
        $liabilityDebits = $liabilityCredits = '0';
        $equityDebits = $equityCredits = '0';
        $revenueDebits = $revenueCredits = '0';
        $expenseDebits = $expenseCredits = '0';
        $accounts = [];

        foreach ($rows as $row) {
            $debits = MoneyArithmetic::toMinorUnits((string) $row->debit_total);
            $credits = MoneyArithmetic::toMinorUnits((string) $row->credit_total);
            $type = (string) $row->account_type;

            if ($type === 'asset') {
                $assetDebits = MoneyArithmetic::add($assetDebits, $debits);
                $assetCredits = MoneyArithmetic::add($assetCredits, $credits);
                $amount = $this->signedDifference($debits, $credits);
            } elseif ($type === 'liability') {
                $liabilityDebits = MoneyArithmetic::add($liabilityDebits, $debits);
                $liabilityCredits = MoneyArithmetic::add($liabilityCredits, $credits);
                $amount = $this->signedDifference($credits, $debits);
            } elseif ($type === 'equity') {
                $equityDebits = MoneyArithmetic::add($equityDebits, $debits);
                $equityCredits = MoneyArithmetic::add($equityCredits, $credits);
                $amount = $this->signedDifference($credits, $debits);
            } elseif ($type === 'revenue') {
                $revenueDebits = MoneyArithmetic::add($revenueDebits, $debits);
                $revenueCredits = MoneyArithmetic::add($revenueCredits, $credits);
                continue;
            } elseif ($type === 'expense') {
                $expenseDebits = MoneyArithmetic::add($expenseDebits, $debits);
                $expenseCredits = MoneyArithmetic::add($expenseCredits, $credits);
                continue;
            } else {
                continue;
            }

            $accounts[] = [
                'code' => (string) $row->code,
                'name_en' => (string) $row->name_en,
                'name_am' => (string) ($row->name_am ?? ''),
                'account_type' => $type,
                'debit_total' => MoneyArithmetic::format($debits),
                'credit_total' => MoneyArithmetic::format($credits),
                'amount' => $amount,
            ];
        }

        $currentEarningsCredits = MoneyArithmetic::add($revenueCredits, $expenseCredits);
        $currentEarningsDebits = MoneyArithmetic::add($revenueDebits, $expenseDebits);
        $totalAssets = $this->signedDifference($assetDebits, $assetCredits);
        $totalLiabilities = $this->signedDifference($liabilityCredits, $liabilityDebits);
        $totalEquityAccounts = $this->signedDifference($equityCredits, $equityDebits);
        $currentEarnings = $this->signedDifference($currentEarningsCredits, $currentEarningsDebits);
        $totalLiabilitiesAndEquity = $this->signedDifference(
            MoneyArithmetic::add(
                MoneyArithmetic::add($liabilityCredits, $equityCredits),
                $currentEarningsCredits,
            ),
            MoneyArithmetic::add(
                MoneyArithmetic::add($liabilityDebits, $equityDebits),
                $currentEarningsDebits,
            ),
        );

        return [
            'as_of' => $asOf,
            'currency' => (string) $entity->base_currency,
            'accounts' => $accounts,
            'summary' => [
                'total_assets' => $totalAssets,
                'total_liabilities' => $totalLiabilities,
                'total_equity_accounts' => $totalEquityAccounts,
                'current_earnings' => $currentEarnings,
                'total_liabilities_and_equity' => $totalLiabilitiesAndEquity,
                'is_balanced' => $totalAssets === $totalLiabilitiesAndEquity,
            ],
        ];
    }

    private function signedDifference(string $positive, string $negative): string
    {
        if (MoneyArithmetic::compare($positive, $negative) >= 0) {
            return MoneyArithmetic::format(MoneyArithmetic::subtract($positive, $negative));
        }

        return '-'.MoneyArithmetic::format(MoneyArithmetic::subtract($negative, $positive));
    }

    public function trialBalance(int $tenantId, int $legalEntityId, string $asOf): array
    {
        $entity = LegalEntity::query()->where('tenant_id', $tenantId)->findOrFail($legalEntityId);
        $accounts = $this->trialBalance->forEntity($tenantId, $legalEntityId, $asOf);
        $debits = '0';
        $credits = '0';

        foreach ($accounts as $account) {
            $debit = MoneyArithmetic::toMinorUnits((string) $account->debit_total);
            $credit = MoneyArithmetic::toMinorUnits((string) $account->credit_total);
            $debits = MoneyArithmetic::add($debits, $debit);
            $credits = MoneyArithmetic::add($credits, $credit);
            if (MoneyArithmetic::compare($debit, $credit) >= 0) {
                $account->net_debit = MoneyArithmetic::format(MoneyArithmetic::subtract($debit, $credit));
                $account->net_credit = '0.0000';
            } else {
                $account->net_debit = '0.0000';
                $account->net_credit = MoneyArithmetic::format(MoneyArithmetic::subtract($credit, $debit));
            }
        }

        $comparison = MoneyArithmetic::compare($debits, $credits);
        $difference = $comparison >= 0
            ? MoneyArithmetic::subtract($debits, $credits)
            : MoneyArithmetic::subtract($credits, $debits);

        return [
            'as_of' => $asOf,
            'currency' => (string) $entity->base_currency,
            'accounts' => $accounts,
            'summary' => [
                'total_debits' => MoneyArithmetic::format($debits),
                'total_credits' => MoneyArithmetic::format($credits),
                'difference' => MoneyArithmetic::format($difference),
                'is_balanced' => $comparison === 0,
            ],
        ];
    }
}
