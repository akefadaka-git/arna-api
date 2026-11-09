<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Repositories\LegalEntityRepository;
use MaaleArna\MASES\Core\Domains\Finance\Services\FinancialReportService;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;

/**
 * Read-only Executive Overview aggregation.
 *
 * The first delivery deliberately exposes only the verified Finance
 * trial-balance summary. All accounting calculations remain owned by
 * FinancialReportService; this controller only composes its result into
 * the dashboard response contract.
 */
final class ExecutiveOverviewController extends Controller
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly LegalEntityRepository $entities,
        private readonly FinancialReportService $financialReports,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', LegalEntity::class);
        Gate::authorize('viewReport', JournalEntry::class);

        $data = $request->validate([
            'entity_code' => ['required', 'string', 'max:64'],
            'as_of' => ['required', 'date_format:Y-m-d'],
        ]);

        $tenantId = $this->tenantContext->getTenantId();
        abort_if($tenantId === null, 403, 'A resolved tenant context is required.');

        $entity = $this->entities->findByCodeForTenant((int) $tenantId, $data['entity_code']);
        abort_if($entity === null, 404);

        $report = $this->financialReports->trialBalance(
            (int) $tenantId,
            (int) $entity->id,
            $data['as_of'],
        );

        $summary = $report['summary'];

        return response()->json([
            'success' => true,
            'data' => [
                'contract_version' => '1.0',
                'generated_at' => now()->toIso8601String(),
                'scope' => [
                    'entity_ref' => (string) $entity->code,
                    'entity_name' => [
                        'am' => $entity->name_am,
                        'en' => $entity->name_en,
                    ],
                    'as_of' => (string) $report['as_of'],
                    'currency' => (string) $report['currency'],
                ],
                'widgets' => [[
                    'key' => 'finance.trial_balance',
                    'status' => 'available',
                    'value' => [
                        'total_debits' => (string) $summary['total_debits'],
                        'total_credits' => (string) $summary['total_credits'],
                        'difference' => (string) $summary['difference'],
                        'is_balanced' => (bool) $summary['is_balanced'],
                        'currency' => (string) $report['currency'],
                    ],
                    'unit' => 'currency',
                    'source' => [
                        'domain' => 'finance',
                        'report_key' => 'trial_balance',
                        'calculation_version' => '1',
                    ],
                    'as_of' => (string) $report['as_of'],
                    'freshness' => 'current',
                ]],
                'omitted_widgets' => [],
            ],
        ]);
    }
}
