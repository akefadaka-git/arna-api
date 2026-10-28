<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Contracts;

interface FinancialReportContract
{
    /** @return array{as_of:string,currency:string,accounts:\Illuminate\Support\Collection<int, object>,summary:array{total_debits:string,total_credits:string,difference:string,is_balanced:bool}} */
    public function trialBalance(int $tenantId, int $legalEntityId, string $asOf): array;
}
