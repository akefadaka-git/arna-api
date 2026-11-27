<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use MaaleArna\Arna\Core\Authorization\ExecutiveOverviewAccessPolicy;
use MaaleArna\Arna\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\Arna\Core\Domains\Finance\Contracts\FinancialReportContract;
use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Domains\People\Services\WorkforceAggregateProvider;

final class ExecutiveOverviewController
{
    public function __construct(
        private readonly ExecutiveOverviewAccessPolicy $audience,
        private readonly FinancialReportContract $financialReports,
        private readonly WorkforceAggregateProvider $workforce,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = arna_tenant_id();

        if ($user === null || $tenantId === null) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TENANT_REQUIRED',
                    'message' => 'A verified tenant context is required.',
                ],
            ], 400);
        }

        // Enforce the frozen audience using current role pivots.
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

        // Use current pivots so stale authorization cache cannot retain Finance access.
        if ($this->hasFreshTenantPermission($user, 'finance.report.view', (int) $tenantId)) {
            $entities = LegalEntity::query()
                ->where('tenant_id', (int) $tenantId)
                ->orderBy('code')
                ->get();

            foreach ($entities as $entity) {
                // Recheck current entity permission before applying the domain policy.
                if (!$this->hasFreshTenantPermission($user, 'finance.entity.view', (int) $tenantId)) {
                    continue;
                }

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

        // Workforce data requires its own tenant-scoped permission.
        if ($this->hasFreshPeopleWorkforcePermission($user, (int) $tenantId)) {
            $widgets[] = [
                'key' => 'people.workforce.active',
                'status' => 'available',
                'source' => [
                    'domain' => 'people',
                    'metric_key' => 'active_workforce',
                    'calculation_version' => '1',
                ],
                'observed_at' => now()->toIso8601String(),
                'value' => [
                    'active' => $this->workforce->activeEmployeeCount((int) $tenantId),
                ],
            ];
        }

        // Completeness applies only to the Finance and People providers below.
        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'complete',
                'contract_version' => '1.0',
                'generated_at' => now()->toIso8601String(),
                'widgets' => $widgets,
            ],
        ])->header('Cache-Control', 'private, no-store');
    }


    /** Checks the tenant-scoped workforce grant against current pivots. */
    private function hasFreshPeopleWorkforcePermission(User $user, int $tenantId): bool
    {
        $direct = $user->permissions()
            ->where('permissions.slug', 'people.workforce.view')
            ->where('permissions.scope', 'tenant')
            ->where('permissions.is_active', true)
            ->whereNull('permissions.application_id')
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
            ->whereIn('roles.scope', ['tenant', 'application'])
            ->where(function ($scope) use ($tenantId): void {
                $scope->whereNull('roles.tenant_id')
                    ->orWhere('roles.tenant_id', $tenantId);
            })
            ->where(function ($application): void {
                $application->whereNull('roles.application_id')
                    ->orWhereHas('application', static fn ($app) => $app
                        ->where('slug', 'people')
                        ->where('is_active', true));
            })
            ->whereHas('permissions', static function ($query) use ($tenantId): void {
                $query->where('permissions.slug', 'people.workforce.view')
                    ->where('permissions.scope', 'tenant')
                    ->where('permissions.is_active', true)
                    ->whereNull('permissions.application_id')
                    ->where(function ($scope) use ($tenantId): void {
                        $scope->whereNull('permissions.tenant_id')
                            ->orWhere('permissions.tenant_id', $tenantId);
                    });
            })
            ->exists();
    }

    /** Checks active direct or Finance-role grants for this tenant. */
    private function hasFreshTenantPermission(User $user, string $permission, int $tenantId): bool
    {
        $direct = $user->permissions()
            ->where('permissions.slug', $permission)
            ->where('permissions.scope', 'tenant')
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
                    ->where('permissions.scope', 'tenant')
                    ->where('permissions.is_active', true)
                    ->where(function ($scope) use ($tenantId): void {
                        $scope->whereNull('permissions.tenant_id')
                            ->orWhere('permissions.tenant_id', $tenantId);
                    });
            })
            ->exists();
    }
}
