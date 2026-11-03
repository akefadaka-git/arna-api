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
use MaaleArna\\MASES\\Core\\Tenancy\\Models\\TenantMembership;

final class AdminControlPlaneController extends Controller
{
    private function pageSize(Request $request): int
    {
        return min(max((int) $request->integer('per_page', 25), 1), 100);
    }

    public function users(Request $request): JsonResponse
    {
        $query = User::query()->with(['roles:id,slug,name_en,name_am', 'tenants:id,slug,name_en,name_am']);
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('email', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('first_name_en', 'like', "%{$search}%")
                    ->orWhere('first_name_am', 'like', "%{$search}%")
                    ->orWhere('father_name_en', 'like', "%{$search}%")
                    ->orWhere('father_name_am', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('id')->paginate($this->pageSize($request));
        $users->getCollection()->transform(static fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'locale' => $user->locale,
            'last_login_at' => $user->last_login_at,
            'tenants' => $user->tenants->map(static fn ($tenant): array => [
                'id' => $tenant->id, 'slug' => $tenant->slug,
                'name' => $tenant->display_name,
            ])->values(),
            'roles' => $user->roles->pluck('slug')->values(),
        ]);

        return response()->json(['success' => true, 'data' => $users]);
    }

    public function roles(Request $request): JsonResponse
    {
        $query = Role::query()->with('permissions:id,slug,name_en,name_am')
            ->when($request->filled('scope'), fn ($q) => $q->where('scope', $request->string('scope')))
            ->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->integer('tenant_id')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($inner) => $inner
                ->where('slug', 'like', '%' . $request->string('search') . '%')
                ->orWhere('name_en', 'like', '%' . $request->string('search') . '%')
                ->orWhere('name_am', 'like', '%' . $request->string('search') . '%')));
        $roles = $query->orderBy('scope')->orderBy('slug')->paginate($this->pageSize($request));
        $roles->getCollection()->transform(static fn (Role $role): array => [
            'id' => $role->id, 'slug' => $role->slug,
            'name' => $role->display_name, 'scope' => $role->scope,
            'tenant_id' => $role->tenant_id, 'application_id' => $role->application_id,
            'is_active' => $role->is_active,
            'permissions' => $role->permissions->pluck('slug')->values(),
        ]);
        return response()->json(['success' => true, 'data' => $roles]);
    }

    public function permissions(Request $request): JsonResponse
    {
        $query = Permission::query()
            ->when($request->filled('scope'), fn ($q) => $q->where('scope', $request->string('scope')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($inner) => $inner
                ->where('slug', 'like', '%' . $request->string('search') . '%')
                ->orWhere('name_en', 'like', '%' . $request->string('search') . '%')
                ->orWhere('name_am', 'like', '%' . $request->string('search') . '%')));
        $permissions = $query->orderBy('category')->orderBy('slug')->paginate($this->pageSize($request));
        $permissions->getCollection()->transform(static fn (Permission $permission): array => [
            'id' => $permission->id, 'slug' => $permission->slug,
            'name' => $permission->display_name, 'category' => $permission->category,
            'scope' => $permission->scope, 'tenant_id' => $permission->tenant_id,
            'application_id' => $permission->application_id,
            'is_active' => $permission->is_active, 'is_system' => $permission->is_system,
        ]);
        return response()->json(['success' => true, 'data' => $permissions]);
    }

    public function tenants(Request $request): JsonResponse
    {
        $query = Tenant::query()
            ->when($request->filled('status'), fn ($q) => $request->string('status')->value() === 'active'
                ? $q->where('is_active', true) : $q->where('is_active', false))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($inner) => $inner
                ->where('slug', 'like', '%' . $request->string('search') . '%')
                ->orWhere('name_en', 'like', '%' . $request->string('search') . '%')
                ->orWhere('name_am', 'like', '%' . $request->string('search') . '%')));
        $tenants = $query->withCount(['memberships as active_memberships_count' => fn ($q) => $q->where('status', TenantMembership::STATUS_ACTIVE)])
            ->orderBy('slug')->paginate($this->pageSize($request));
        $tenants->getCollection()->transform(static fn (Tenant $tenant): array => [
            'id' => $tenant->id, 'slug' => $tenant->slug,
            'name' => $tenant->display_name, 'domain' => $tenant->domain,
            'is_active' => $tenant->is_active,
            'active_memberships_count' => $tenant->active_memberships_count,
        ]);
        return response()->json(['success' => true, 'data' => $tenants]);
    }

    public function memberships(Request $request, Tenant $tenant): JsonResponse
    {
        $request->validate(['status' => 'sometimes|in:active,suspended,revoked']);
        $memberships = TenantMembership::query()->where('tenant_id', $tenant->id)
            ->with(['user:id,email,username,first_name_en,first_name_am,father_name_en,father_name_am'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderBy('user_id')->paginate($this->pageSize($request));

        $memberships->getCollection()->transform(static fn (TenantMembership $membership): array => [
            'tenant_id' => $membership->tenant_id,
            'user_id' => $membership->user_id,
            'name' => $membership->user?->name,
            'email' => $membership->user?->email,
            'status' => $membership->status,
            'joined_at' => $membership->joined_at,
        ]);

        return response()->json(['success' => true, 'data' => [
            'tenant' => ['id' => $tenant->id, 'slug' => $tenant->slug, 'name' => $tenant->display_name],
            'memberships' => $memberships,
        ]]);
    }
}
