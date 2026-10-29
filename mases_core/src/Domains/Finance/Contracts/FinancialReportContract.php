<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Contracts;

interface FinancialReportContract
{
    /** @return array{as_of:string,currency:string,accounts:\Illuminate\Support\Collection<int, object>,summary:array{total_debits:string,total_credits:string,difference:string,is_balanced:bool}} */
    public function trialBalance(int $tenantId, int $legalEntityId, string $asOf): array;

    /** @return array{from:string,to:string,currency:string,accounts:array<int,array<string,string>>,summary:array{total_revenue:string,total_expenses:string,net_income:string}} */
    public function incomeStatement(int $tenantId, int $legalEntityId, string $from, string $to): array;
}
