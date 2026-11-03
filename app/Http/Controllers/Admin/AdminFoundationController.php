<?php

declare(strict_types=1);

namespace App\\Http\\Controllers\\Admin;

use App\\Http\\Controllers\\Controller;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Validation\\Rule;
use MaaleArna\\MASES\\Core\\Auth\\Models\\User;
use MaaleArna\\MASES\\Core\\Authorization\\Models\\Permission;
use MaaleArna\\MASES\\Core\\Authorization\\Models\\Role;
use MaaleArna\\MASES\\Core\\Tenancy\\Models\\Tenant;

final class AdminFoundationController extends Controller
{
    /**
     * Platform tenant directory. The route requires the global tenant.list
     * permission; tenant records are never inferred from the caller's
     * current tenant context.
     */
    public function tenants(Request $request): JsonResponse
    {
        $tenants = Tenant::query()
            ->select(['id', 'name_am', 'name_en', 'slug', 'domain', 'is_active', 'created_at', 'updated_at'])
            ->orderBy('name_en')
            ->paginate(min(max((int) $request->integer('per_page', 25), 1), 100));

        return response()->json(['success' => true, 'data' => $tenants]);
    }

    /**
     * Return a tenant directory entry without exposing settings or secrets.
     */
    public function tenant(Request $request, int $id): JsonResponse
    {
        $tenant = Tenant::query()
            ->select(['id', 'name_am', 'name_en', 'slug', 'domain', 'is_active', 'created_at', 'updated_at'])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $tenant]);
    }

    /**
     * Change only the tenant's operational active flag. Tenant creation,
     * deletion and membership provisioning remain separate controlled flows.
     */
    public function updateTenant(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $tenant = Tenant::query()->findOrFail($id);
        $tenant->forceFill(['is_active' => $validated['is_active']])->save();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'is_active' => $tenant->is_active,
                'updated_at' => $tenant->updated_at,
            ],
        ]);
    }

    /**
     * List accounts belonging to the already-resolved active tenant.
     * The route requires tenant.membership and user.list; callers cannot
     * supply a tenant identifier in the body or query string.
     */
    public function users(Request $request): JsonResponse
    {
        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;
        abort_if(!$tenantId, 400, 'A tenant context is required.');

        $users = User::query()
            ->whereHas('activeTenants', static fn ($query) => $query->where('tenants.id', $tenantId))
            ->select(['id', 'email', 'first_name_am', 'father_name_am', 'grandfather_name_am',
                'first_name_en', 'father_name_en', 'grandfather_name_en', 'locale', 'created_at'])
            ->orderBy('id')
            ->paginate(min(max((int) $request->integer('per_page', 25), 1), 100));

        return response()->json(['success' => true, 'data' => $users]);
    }

    /**
     * Return only roles visible in the active tenant: platform roles and
     * roles explicitly attached to that tenant. Application-scoped roles
     * are included only when they are not tenant-bound or belong to it.
     */
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

    /**
     * Read-only permission catalogue for the active tenant's RBAC workspace.
     * System permissions are visible for inspection but cannot be mutated
     * through this endpoint.
     */
    public function permissions(Request $request): JsonResponse
    {
        $permissions = Permission::query()
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('slug')
            ->get(['id', 'slug', 'name_am', 'name_en', 'description_am',
                'description_en', 'category', 'scope', 'tenant_id',
                'application_id', 'is_system']);

        return response()->json(['success' => true, 'data' => $permissions]);
    }
}
