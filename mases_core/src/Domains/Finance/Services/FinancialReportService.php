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
