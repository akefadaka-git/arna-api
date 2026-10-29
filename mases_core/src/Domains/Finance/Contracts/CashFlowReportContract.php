<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Contracts;

interface CashFlowReportContract
{
    /**
     * Report posted cash movements for one tenant/legal entity and inclusive
     * effective-date window. Unclassified movements are returned explicitly;
     * the service must never infer activity from account code or description.
     *
     * @return array<string, mixed>
     */
    public function cashFlow(int $tenantId, int $legalEntityId, string $from, string $to): array;
}
