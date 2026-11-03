<?php

declare(strict_types=1);

namespace App\\Http\\Controllers\\Admin;

use App\\Http\\Controllers\\Controller;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use MaaleArna\\MASES\\Core\\Auth\\Models\\User;
use MaaleArna\\MASES\\Core\\Authorization\\Models\\Permission;
use MaaleArna\\MASES\\Core\\Authorization\\Models\\Role;
use MaaleArna\\MASES\\Core\\Tenancy\\Models\\Tenant;

final class AdminFoundationController extends Controller
{
    public function tenants(Request $request): JsonResponse
    {
        $tenants = Tenant::query()
            ->select(['id', 'name_am', 'name_en', 'slug', 'domain', 'is_active', 'created_at', 'updated_at'])
            ->orderBy('name_en')
            ->paginate(min(max((int) $request->integer('per_page', 25), 1), 100));

        return response()->json(['success' => true, 'data' => $tenants]);
    }

    public function tenant(Request $request, int $id): JsonResponse
    {
        $tenant = Tenant::query()
            ->select(['id', 'name_am', 'name_en', 'slug', 'domain', 'is_active', 'created_at', 'updated_at'])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $tenant]);
    }

    /**
     * Account directory is constrained to the active, middleware-resolved
     * tenant. No tenant identifier is accepted from the caller.
     */
    public function users(Request $request): JsonResponse
    {
        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;
        abort_if(!$tenantId, 400, 'A tenant context is required.');

        $users = User::query()
            ->whereHas('activeTenants', static fn ($query) => $query->where('tenants.id', $tenantId))
            ->select([
                'id', 'email', 'first_name_am', 'father_name_am', 'grandfather_name_am',
                'first_name_en', 'father_name_en', 'grandfather_name_en', 'locale', 'created_at',
            ])
            ->orderBy('id')
            ->paginate(min(max((int) $request->integer('per_page', 25), 1), 100));

        return response()->json(['success' => true, 'data' => $users]);
    }

    public function roles(Request $request): JsonResponse
    {
        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;
        abort_if(!$tenantId, 400, 'A tenant context is required.');

        $roles = Role::query()
            ->with(['permissions:id,slug,name_am,name_en,scope'])
            ->where('is_active', true)
            ->where(static function ($query) use ($tenantId): void {
                $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            })
            ->orderBy('scope')
            ->orderBy('slug')
            ->paginate(min(max((int) $request->integer('per_page', 50), 1), 100));

        return response()->json(['success' => true, 'data' => $roles]);
    }

    public function permissions(Request $request): JsonResponse
    {
        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;
        abort_if(!$tenantId, 400, 'A tenant context is required.');

        $permissions = Permission::query()
            ->where('is_active', true)
            ->where(static function ($query) use ($tenantId): void {
                $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            })
            ->orderBy('category')
            ->orderBy('slug')
            ->get([
                'id', 'slug', 'name_am', 'name_en', 'description_am', 'description_en',
                'category', 'scope', 'tenant_id', 'application_id', 'is_system',
            ]);

        return response()->json(['success' => true, 'data' => $permissions]);
    }
}
