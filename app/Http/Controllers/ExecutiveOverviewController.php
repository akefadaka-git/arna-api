<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use MaaleArna\MASES\Core\Authorization\ExecutiveOverviewAccessPolicy;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Services\FinancialReportService;

final class ExecutiveOverviewController
{
    public function __construct(
        private readonly ExecutiveOverviewAccessPolicy $audience,
        private readonly AuthorizationService $authorization,
        private readonly FinancialReportService $financialReports,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = mases_tenant_id();

        if ($user === null || $tenantId === null) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TENANT_REQUIRED',
                    'message' => 'A verified tenant context is required.',
                ],
            ], 400);
        }

        // The route permission is the first gate. This role check prevents
        // direct grants and platform wildcard roles from widening the frozen
        // initial audience.
        if (!$this->audience->allows($user, (int) $tenantId)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AUTH_PERMISSION_DENIED',
                    'message' => 'Executive Overview access is not granted.',
                ],
            ], 403);
        }

        $validated = $request->validate([
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $asOf = $validated['as_of'] ?? now()->toDateString();
        $widgets = [];

        // The entry permission is not a Finance data grant. CEO and President
        // receive no Finance widget unless their existing Finance permission
        // is independently granted. No role grants are widened here.
        if ($this->authorization->hasPermission('finance.report.view', $user, (int) $tenantId)) {
            $entities = LegalEntity::query()
                ->where('tenant_id', (int) $tenantId)
                ->orderBy('code')
                ->get();

            foreach ($entities as $entity) {
                // LegalEntityPolicy independently enforces finance.entity.view
                // and the trusted tenant/entity boundary before report reads.
                if (!Gate::forUser($user)->allows('view', $entity)) {
                    continue;
                }

                $report = $this->financialReports->trialBalance(
                    (int) $tenantId,
                    (int) $entity->id,
                    $asOf,
                );

                $widgets[] = [
                    'key' => 'finance.trial_balance',
                    'status' => 'available',
                    'entity' => [
                        'code' => (string) $entity->code,
                        'name_am' => (string) $entity->name_am,
                        'name_en' => (string) $entity->name_en,
                    ],
                    'as_of' => $report['as_of'],
                    'currency' => $report['currency'],
                    'value' => $report['summary'],
                ];
            }
        }

        // Never emit omitted-widget names, permission slugs, denial reasons,
        // or hidden-domain metadata. Sensitive financial responses are not
        // stored in shared or browser caches.
        return response()->json([
            'success' => true,
            'data' => [
                'contract_version' => '1.0',
                'generated_at' => now()->toIso8601String(),
                'widgets' => $widgets,
            ],
        ])->header('Cache-Control', 'private, no-store');
    }
}
