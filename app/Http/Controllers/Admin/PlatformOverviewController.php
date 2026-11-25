<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Models\TenantMembership;

final class PlatformOverviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'generated_at' => now()->toIso8601String(),
                'counts' => [
                    'users' => User::query()->count(),
                    'tenants' => Tenant::query()->count(),
                    'applications' => PlatformApplication::query()->count(),
                    'active_memberships' => TenantMembership::query()
                        ->where('status', TenantMembership::STATUS_ACTIVE)
                        ->count(),
                    'roles' => Role::query()->count(),
                    'permissions' => Permission::query()->count(),
                ],
            ],
        ]);
    }
}
