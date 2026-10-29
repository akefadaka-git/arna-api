<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Finance\Models\FinanceConsolidationAccountMapping;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Support\MoneyArithmetic;

final class FinanceConsolidationService
{
    public function __construct(private readonly TrialBalanceService $trialBalance)
    {
    }

    /**
     * Read-only unadjusted report over explicitly selected entities.
     * Missing/unapproved account mappings fail closed; balances are never silently dropped.
     * Intercompany elimination, FX translation, tax and statutory adjustments are not applied.
     *
     * @param list<int> $legalEntityIds
     * @return array<string, mixed>
     */
    public function trialBalance(int $tenantId, array $legalEntityIds, string $asOf): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $legalEntityIds), static fn (int $id): bool => $id > 0)));
        if (count($ids) < 2) {
            throw ValidationException::withMessages(['legal_entity_ids' => 'Consolidated reporting requires at least two explicit legal entities.']);
        }

        $entities = LegalEntity::query()->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)->where('status', 'active')->orderBy('id')->get();
        if ($entities->count() !== count($ids)) {
            throw ValidationException::withMessages(['legal_entity_ids' => 'One or more selected entities are unavailable in this tenant.']);
        }

        $consolidated = [];
        $entityReports = [];
        foreach ($entities as $entity) {
            $rows = $this->trialBalance->forEntity($tenantId, (int) $entity->id, $asOf);
            $accountIds = $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $mappings = FinanceConsolidationAccountMapping::query()
                ->where('tenant_id', $tenantId)->where('legal_entity_id', $entity->id)
                ->whereIn('ledger_account_id', $accountIds)
                ->where('status', FinanceConsolidationAccountMapping::APPROVED)->get()->keyBy('ledger_account_id');

            foreach ($rows as $row) {
                $mapping = $mappings->get((int) $row->id);
                if (!$mapping) {
                    throw ValidationException::withMessages([
                        'consolidation_mapping' => 'Every account in each selected entity report requires an approved mapping; no partial report was returned.',
                    ]);
                }
                if ((string) $mapping->account_type !== (string) $row->account_type) {
                    throw ValidationException::withMessages([
                        'consolidation_mapping' => 'A mapping account type no longer matches its ledger account; report generation was stopped.',
                    ]);
                }

                $code = (string) $mapping->consolidation_account_code;
                if (!isset($consolidated[$code])) {
                    $consolidated[$code] = [
                        'consolidation_account_code' => $code, 'account_type' => (string) $row->account_type,
                        'debit_total' => '0.0000', 'credit_total' => '0.0000',
                        'net_debit' => '0.0000', 'net_credit' => '0.0000',
                    ];
                } elseif ($consolidated[$code]['account_type'] !== (string) $row->account_type) {
                    throw ValidationException::withMessages([
                        'consolidation_mapping' => 'A consolidation code maps incompatible account types; report generation was stopped.',
                    ]);
                }

                $consolidated[$code]['debit_total'] = MoneyArithmetic::format(MoneyArithmetic::add(
                    MoneyArithmetic::toMinorUnits($consolidated[$code]['debit_total']),
                    MoneyArithmetic::toMinorUnits((string) $row->debit_total)
                ));
                $consolidated[$code]['credit_total'] = MoneyArithmetic::format(MoneyArithmetic::add(
                    MoneyArithmetic::toMinorUnits($consolidated[$code]['credit_total']),
                    MoneyArithmetic::toMinorUnits((string) $row->credit_total)
                ));
            }

            $entityReports[] = [
                'legal_entity_id' => (int) $entity->id, 'entity_code' => (string) $entity->code,
                'currency' => (string) $entity->base_currency, 'account_count' => $rows->count(),
            ];
        }

        ksort($consolidated);
        $debits = '0';
        $credits = '0';
        foreach ($consolidated as &$row) {
            $debit = MoneyArithmetic::toMinorUnits($row['debit_total']);
            $credit = MoneyArithmetic::toMinorUnits($row['credit_total']);
            $debits = MoneyArithmetic::add($debits, $debit);
            $credits = MoneyArithmetic::add($credits, $credit);
            if (MoneyArithmetic::compare($debit, $credit) >= 0) {
                $row['net_debit'] = MoneyArithmetic::format(MoneyArithmetic::subtract($debit, $credit));
            } else {
                $row['net_credit'] = MoneyArithmetic::format(MoneyArithmetic::subtract($credit, $debit));
            }
        }
        unset($row);

        $comparison = MoneyArithmetic::compare($debits, $credits);
        return [
            'as_of' => $asOf, 'tenant_id' => $tenantId, 'legal_entities' => $entityReports,
            'accounts' => array_values($consolidated),
            'summary' => [
                'total_debits' => MoneyArithmetic::format($debits),
                'total_credits' => MoneyArithmetic::format($credits),
                'difference' => MoneyArithmetic::format($comparison >= 0
                    ? MoneyArithmetic::subtract($debits, $credits) : MoneyArithmetic::subtract($credits, $debits)),
                'is_balanced' => $comparison === 0,
            ],
            'adjustments' => [
                'intercompany_eliminations_applied' => false,
                'foreign_currency_translation_applied' => false,
                'tax_adjustments_applied' => false,
                'statutory_adjustments_applied' => false,
                'requires_accounting_review' => true,
            ],
        ];
    }
}
