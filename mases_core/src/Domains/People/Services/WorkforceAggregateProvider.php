<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Services;

use Illuminate\Support\Facades\DB;

/**
 * Read-only workforce aggregate for the Executive Command Centre.
 * Counts active, non-deleted employee rows in exactly one resolved tenant.
 */
final class WorkforceAggregateProvider
{
    public function activeEmployeeCount(int $tenantId): int
    {
        return (int) DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->count();
    }
}
