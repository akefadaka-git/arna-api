<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use MaaleArna\MASES\Core\Authorization\ExecutiveOverviewAccessPolicy;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Services\FinancialReportService;
use MaaleArna\MASES\Core\Auth\Models\User;

final class ExecutiveOverviewController
{
    public function __construct(
        private readonly ExecutiveOverviewAccessPolicy $audience,
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

        // The route permission is the first gate. This uncached role-pivot
        // check is authoritative and prevents direct grants and platform
        // wildcard roles from widening the frozen audience.
        if (!$this->audience->allows($user, (int) $tenantId)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AUTH_PERMISSION_DENIED',
                    'message' => 'You are not authorized to perform this action.',
                ],
            ], 403);
        }

        $validated = $request->validate([
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $asOf = $validated['as_of'] ?? now()->toDateString();
        $widgets = [];

        // Finance report access is evaluated against the current database
        // pivots, not AuthorizationService's TTL cache. This ensures that a
        // failed cache invalidation cannot retain access to financial data.
        if ($this->hasFreshTenantPermission($user, 'finance.report.view', (int) $tenantId)) {
            $entities = LegalEntity::query()
                ->where('tenant_id', (int) $tenantId)
                ->orderBy('code')
                ->get();

            foreach ($entities as $entity) {
                // FinancePolicy performs an uncached, trusted-tenant check
                // for finance.entity.view before any report read.
                if (!Gate::forUser($user)->allows('view', $entity)) {
                    continue;
                }

                $report = $this->financialReports->trialBalance(
                    (int) $tenantId,
                    (int) $entity->id,
                    $asOf,
                );

                if ($report['accounts']->isEmpty()) {
                    continue;
                }

                $widgets[] = [
                    'key' => 'finance.trial_balance',
                    'status' => 'available',
                    'source' => [
                        'domain' => 'finance',
                        'report_key' => 'trial_balance',
                        'calculation_version' => '1',
                    ],
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

    /**
     * Check a tenant-scoped permission directly from authoritative pivots.
     * Both direct grants and role grants are supported; role grants must be
     * active, tenant-compatible and either global or bound to Finance.
     */
    private function hasFreshTenantPermission(User $user, string $permission, int $tenantId): bool
    {
        $direct = $user->permissions()
            ->where('permissions.slug', $permission)
            ->where('permissions.is_active', true)
            ->where(function ($scope) use ($tenantId): void {
                $scope->whereNull('permissions.tenant_id')
                    ->orWhere('permissions.tenant_id', $tenantId);
            })
            ->exists();

        if ($direct) {
            return true;
        }

        return $user->roles()
            ->where('roles.is_active', true)
            ->where(function ($scope) use ($tenantId): void {
                $scope->whereNull('roles.tenant_id')
                    ->orWhere('roles.tenant_id', $tenantId);
            })
            ->where(function ($application): void {
                $application->whereNull('roles.application_id')
                    ->orWhereHas('application', static fn ($app) => $app
                        ->where('slug', 'finance')
                        ->where('is_active', true));
            })
            ->whereHas('permissions', static function ($query) use ($permission, $tenantId): void {
                $query->where('permissions.slug', $permission)
                    ->where('permissions.is_active', true)
                    ->where(function ($scope) use ($tenantId): void {
                        $scope->whereNull('permissions.tenant_id')
                            ->orWhere('permissions.tenant_id', $tenantId);
                    });
            })
            ->exists();
    }
}
