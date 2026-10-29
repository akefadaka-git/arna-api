<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Contracts;

interface FinancialReportContract
{
    /** @return array{as_of:string,currency:string,accounts:\Illuminate\Support\Collection<int, object>,summary:array{total_debits:string,total_credits:string,difference:string,is_balanced:bool}} */
    public function trialBalance(int $tenantId, int $legalEntityId, string $asOf): array;

    /** @return array{from:string,to:string,currency:string,accounts:array<int,array<string,string>>,summary:array{total_revenue:string,total_expenses:string,net_income:string}} */
    public function incomeStatement(int $tenantId, int $legalEntityId, string $from, string $to): array;

    /** @return array{as_of:string,currency:string,accounts:array<int,array<string,string>>,summary:array{total_assets:string,total_liabilities:string,total_equity_accounts:string,current_earnings:string,total_liabilities_and_equity:string,is_balanced:bool}} */
    public function balanceSheet(int $tenantId, int $legalEntityId, string $asOf): array;
}
