<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\TenantMembershipService;

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

    /** Tenant-scoped identity directory. */
    public function users(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();

        $users = User::query()
            ->whereHas('activeTenants', static fn ($query) => $query->where('tenants.id', $tenantId))
            ->select([
                'id', 'email', 'username', 'phone', 'first_name_am', 'father_name_am', 'grandfather_name_am',
                'first_name_en', 'father_name_en', 'grandfather_name_en', 'locale', 'is_active', 'created_at',
            ])
            ->orderBy('id')
            ->paginate(min(max((int) $request->integer('per_page', 25), 1), 100));

        return response()->json(['success' => true, 'data' => $users]);
    }

    /** Create a central identity and its first tenant membership atomically. */
    public function createUser(Request $request, TenantMembershipService $memberships, AuditService $audit): JsonResponse
    {
        $tenantId = $this->tenantId();
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'username' => ['nullable', 'string', 'max:100', Rule::unique('users', 'username')],
            'first_name_am' => ['required_without:first_name_en', 'nullable', 'string', 'max:100'],
            'first_name_en' => ['required_without:first_name_am', 'nullable', 'string', 'max:100'],
            'father_name_am' => ['nullable', 'string', 'max:100'],
            'father_name_en' => ['nullable', 'string', 'max:100'],
            'grandfather_name_am' => ['nullable', 'string', 'max:100'],
            'grandfather_name_en' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'locale' => ['nullable', Rule::in(['am', 'en'])],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $user = DB::transaction(function () use ($validated, $tenantId, $actor, $memberships, $audit): User {
            $user = User::query()->create([
                'email' => strtolower(trim($validated['email'])),
                'username' => $validated['username'] ?? null,
                'first_name_am' => $validated['first_name_am'] ?? null,
                'first_name_en' => $validated['first_name_en'] ?? null,
                'father_name_am' => $validated['father_name_am'] ?? null,
                'father_name_en' => $validated['father_name_en'] ?? null,
                'grandfather_name_am' => $validated['grandfather_name_am'] ?? null,
                'grandfather_name_en' => $validated['grandfather_name_en'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'locale' => $validated['locale'] ?? 'am',
                'password' => Hash::make($validated['password']),
                'is_active' => true,
            ]);

            $memberships->attach($tenantId, (int) $user->id, $actor, 'Initial tenant membership created with account.');

            $audit->log(
                action: 'admin.identity.user_created',
                user: $actor,
                tenantId: $tenantId,
                resourceType: 'user',
                resourceId: (string) $user->id,
                newValues: [
                    'email' => $user->email,
                    'username' => $user->username,
                    'is_active' => $user->is_active,
                ],
            );

            return $user;
        });

        return response()->json(['success' => true, 'data' => $user], 201);
    }

    /** Update only profile/contact fields; credentials and lifecycle use separate flows. */
    public function updateUser(Request $request, int $id, AuditService $audit): JsonResponse
    {
        $tenantId = $this->tenantId();
        $user = $this->tenantUser($id, $tenantId);
        $validated = $request->validate([
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'username' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('users', 'username')->ignore($user->id)],
            'first_name_am' => ['sometimes', 'nullable', 'string', 'max:100'],
            'first_name_en' => ['sometimes', 'nullable', 'string', 'max:100'],
            'father_name_am' => ['sometimes', 'nullable', 'string', 'max:100'],
            'father_name_en' => ['sometimes', 'nullable', 'string', 'max:100'],
            'grandfather_name_am' => ['sometimes', 'nullable', 'string', 'max:100'],
            'grandfather_name_en' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'locale' => ['sometimes', Rule::in(['am', 'en'])],
        ]);

        $before = $user->only(array_keys($validated));
        if (isset($validated['email']) && strtolower(trim($validated['email'])) !== $user->email) {
            $validated['email'] = strtolower(trim($validated['email']));
            $validated['email_verified_at'] = null;
        }

        $user->fill($validated)->save();

        $audit->log(
            action: 'admin.identity.user_updated',
            user: $request->user(),
            tenantId: $tenantId,
            resourceType: 'user',
            resourceId: (string) $user->id,
            oldValues: $before,
            newValues: $user->only(array_keys($validated)),
        );

        return response()->json(['success' => true, 'data' => $user->fresh()]);
    }

    /** Add an existing central identity to the current tenant. */
    public function addMembership(Request $request, int $id, TenantMembershipService $memberships): JsonResponse
    {
        $tenantId = $this->tenantId();
        $user = User::query()->findOrFail($id);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $memberships->attach(
            $tenantId,
            (int) $user->id,
            $actor,
            (string) $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']])['reason'],
        );

        return response()->json(['success' => true, 'data' => [
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'status' => 'active',
        ]], 201);
    }

    /** Suspend, reactivate or permanently revoke a tenant membership. */
    public function updateMembership(Request $request, int $id, TenantMembershipService $memberships): JsonResponse
    {
        $tenantId = $this->tenantId();
        $user = User::query()->findOrFail($id);
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'revoked'])],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        match ($validated['status']) {
            'active' => $memberships->reactivate($tenantId, (int) $user->id, $actor, $validated['reason']),
            'suspended' => $memberships->suspend($tenantId, (int) $user->id, $actor, $validated['reason']),
            'revoked' => $memberships->revoke($tenantId, (int) $user->id, $actor, $validated['reason']),
        };

        return response()->json(['success' => true, 'data' => [
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'status' => $validated['status'],
        ]]);
    }

    public function roles(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();

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

    public function createRole(Request $request, AuditService $audit): JsonResponse
    {
        $tenantId = $this->tenantId();
        $validated = $request->validate([
            'slug' => ['required', 'alpha_dash', 'max:100', Rule::unique('roles', 'slug')],
            'name_am' => ['nullable', 'string', 'max:150'],
            'name_en' => ['required', 'string', 'max:150'],
            'description_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:255'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $role = Role::query()->create([
            ...$validated,
            'scope' => 'tenant',
            'tenant_id' => $tenantId,
            'application_id' => null,
            'is_active' => true,
        ]);

        $audit->log(
            action: 'admin.rbac.role_created',
            user: $actor,
            tenantId: $tenantId,
            resourceType: 'role',
            resourceId: (string) $role->id,
            newValues: ['slug' => $role->slug, 'scope' => $role->scope, 'tenant_id' => $tenantId],
        );

        return response()->json(['success' => true, 'data' => $role], 201);
    }

    public function updateRole(Request $request, int $id, AuditService $audit, AuthorizationService $authorization): JsonResponse
    {
        $tenantId = $this->tenantId();
        $role = $this->tenantRole($id, $tenantId);
        $validated = $request->validate([
            'name_am' => ['sometimes', 'nullable', 'string', 'max:150'],
            'name_en' => ['sometimes', 'required', 'string', 'max:150'],
            'description_am' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $before = $role->only(array_keys($validated));
        $role->fill($validated)->save();

        if (array_key_exists('is_active', $validated)) {
            $this->clearRoleUsersCache($role, $authorization);
        }

        $audit->log(
            action: 'admin.rbac.role_updated',
            user: $actor,
            tenantId: $tenantId,
            resourceType: 'role',
            resourceId: (string) $role->id,
            oldValues: $before,
            newValues: $role->only(array_keys($validated)),
        );

        return response()->json(['success' => true, 'data' => $role->fresh()]);
    }

    /** Replace this tenant's role assignments for one member, preserving platform/application roles. */
    public function syncUserRoles(Request $request, int $id, AuditService $audit, AuthorizationService $authorization): JsonResponse
    {
        $tenantId = $this->tenantId();
        $user = $this->tenantUser($id, $tenantId);
        $validated = $request->validate([
            'role_ids' => ['present', 'array', 'max:50'],
            'role_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $roleIds = collect($validated['role_ids'])->map(static fn ($id): int => (int) $id)->unique()->values()->all();

        $roles = Role::query()
            ->where('scope', 'tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('id', $roleIds)
            ->get(['id', 'slug']);
        abort_if($roles->count() !== count($roleIds), 422, 'One or more roles are not assignable in this tenant.');

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $before = DB::table('user_has_roles')
            ->join('roles', 'roles.id', '=', 'user_has_roles.role_id')
            ->where('user_has_roles.user_id', $user->id)
            ->where('roles.scope', 'tenant')
            ->where('roles.tenant_id', $tenantId)
            ->pluck('roles.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        DB::transaction(function () use ($user, $tenantId, $roleIds, $actor): void {
            $tenantRoleIds = Role::query()
                ->where('scope', 'tenant')
                ->where('tenant_id', $tenantId)
                ->pluck('id');

            DB::table('user_has_roles')
                ->where('user_id', $user->id)
                ->whereIn('role_id', $tenantRoleIds)
                ->delete();

            if ($roleIds !== []) {
                $now = now();
                DB::table('user_has_roles')->insert(array_map(
                    static fn (int $roleId): array => [
                        'user_id' => $user->id,
                        'role_id' => $roleId,
                        'assigned_at' => $now,
                        'assigned_by' => $actor->id,
                    ],
                    $roleIds,
                ));
            }
        });

        $authorization->clearUserCache($user);

        $audit->log(
            action: 'admin.rbac.user_roles_synced',
            user: $actor,
            tenantId: $tenantId,
            resourceType: 'user',
            resourceId: (string) $user->id,
            oldValues: ['tenant_role_ids' => $before],
            newValues: ['tenant_role_ids' => $roleIds],
        );

        return response()->json(['success' => true, 'data' => [
            'user_id' => $user->id,
            'role_ids' => $roleIds,
        ]]);
    }

    /** Replace a tenant role's permission set; Role emits the synchronous cache-invalidation event. */
    public function syncRolePermissions(Request $request, int $id, AuditService $audit): JsonResponse
    {
        $tenantId = $this->tenantId();
        $role = $this->tenantRole($id, $tenantId);
        $validated = $request->validate([
            'permission_ids' => ['present', 'array', 'max:250'],
            'permission_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $permissionIds = collect($validated['permission_ids'])->map(static fn ($id): int => (int) $id)->unique()->values()->all();

        $permissions = Permission::query()
            ->whereIn('id', $permissionIds)
            ->where('is_active', true)
            ->where('is_system', false)
            ->where('scope', 'tenant')
            ->where(static function ($query) use ($tenantId): void {
                $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            })
            ->get(['id', 'slug']);
        abort_if($permissions->count() !== count($permissionIds), 422, 'One or more permissions are not assignable to this tenant role.');

        $before = $role->permissions()->pluck('permissions.id')->map(static fn ($id): int => (int) $id)->all();
        DB::transaction(function () use ($role, $permissionIds, $audit, $request, $tenantId): void {
            $role->syncPermissions($permissionIds);
            $audit->log(
                action: 'admin.rbac.role_permissions_synced',
                user: $request->user(),
                tenantId: $tenantId,
                resourceType: 'role',
                resourceId: (string) $role->id,
                oldValues: ['permission_ids' => $role->permissions()->pluck('permissions.id')->all()],
                newValues: ['permission_ids' => $permissionIds],
            );
        });

        return response()->json(['success' => true, 'data' => [
            'role_id' => $role->id,
            'permission_ids' => $permissionIds,
        ]]);
    }

    public function permissions(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();

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

    private function tenantId(): int
    {
        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;
        abort_if(!$tenantId, 400, 'A tenant context is required.');

        return (int) $tenantId;
    }

    private function tenantUser(int $userId, int $tenantId): User
    {
        return User::query()
            ->whereKey($userId)
            ->whereHas('activeTenants', static fn ($query) => $query->where('tenants.id', $tenantId))
            ->firstOrFail();
    }

    private function tenantRole(int $roleId, int $tenantId): Role
    {
        $role = Role::query()->whereKey($roleId)->firstOrFail();
        abort_unless($role->scope === 'tenant' && (int) $role->tenant_id === $tenantId, 404);

        return $role;
    }

    private function clearRoleUsersCache(Role $role, AuthorizationService $authorization): void
    {
        $role->users()->select('users.*')->chunkById(250, static function ($users) use ($authorization): void {
            foreach ($users as $user) {
                $authorization->clearUserCache($user);
            }
        });
    }
}
