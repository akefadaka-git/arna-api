<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Finance\\Http\\Resources;

use Illuminate\\Http\\Request;
use Illuminate\\Http\\Resources\\Json\\JsonResource;

/**
 * Stable, decimal-string API representation for a trial-balance report.
 *
 * @property array $resource
 */
final class TrialBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $report = (array) $this->resource;

        return [
            'as_of' => (string) ($report['as_of'] ?? ''),
            'currency' => (string) ($report['currency'] ?? 'ETB'),
            'accounts' => collect($report['accounts'] ?? [])->map(static function ($account): array {
                $account = (array) $account;

                return [
                    'id' => (int) ($account['id'] ?? 0),
                    'code' => (string) ($account['code'] ?? ''),
                    'name_en' => (string) ($account['name_en'] ?? ''),
                    'name_am' => $account['name_am'] ?? null,
                    'account_type' => (string) ($account['account_type'] ?? ''),
                    'debit_total' => (string) ($account['debit_total'] ?? '0.0000'),
                    'credit_total' => (string) ($account['credit_total'] ?? '0.0000'),
                    'net_debit' => (string) ($account['net_debit'] ?? '0.0000'),
                    'net_credit' => (string) ($account['net_credit'] ?? '0.0000'),
                ];
            })->values()->all(),
            'summary' => [
                'total_debits' => (string) data_get($report, 'summary.total_debits', '0.0000'),
                'total_credits' => (string) data_get($report, 'summary.total_credits', '0.0000'),
                'difference' => (string) data_get($report, 'summary.difference', '0.0000'),
                'is_balanced' => (bool) data_get($report, 'summary.is_balanced', false),
            ],
        ];
    }
}
