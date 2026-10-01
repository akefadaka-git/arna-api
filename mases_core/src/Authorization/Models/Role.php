<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Authorization\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Authorization\Events\RolePermissionsChanged;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;
use MaaleArna\MASES\Core\Auth\Models\User;

/**
 * Role Model
 *
 * Represents a role in the MASES RBAC system. Roles are the primary
 * mechanism for grouping permissions and assigning them to users.
 *
 * Roles have three scopes:
 * - 'platform': System‑level roles (e.g., Super Admin)
 * - 'tenant': Tenant‑specific roles (e.g., Tenant Admin)
 * - 'application': Application‑specific roles (e.g., Finance Manager)
 *
 * The scope determines which foreign keys are applicable:
 * - platform: tenant_id = null, application_id = null
 * - tenant: tenant_id is set, application_id = null
 * - application: application_id is set, tenant_id may be set or null
 *
 * Display names and descriptions are stored in both Amharic and English
 * to support the Amharic‑first design with locale switching.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery. Model with three scopes, pivot relations,
 *          permission mutation helpers, and locale-aware display-name
 *          accessors.
 *
 * 1.0.1  — D-11b. `syncPermissions()` now dispatches a
 *          `RolePermissionsChanged` event after a successful sync.
 *
 *          WHY
 *          ---
 *          When a role's permission set changes, every user who holds
 *          that role has a cached authorization answer that is now
 *          stale. The `AuthorizationService` caches permission checks
 *          for 300 seconds per (user, permission, tenant, application)
 *          tuple, and its documented contract requires that callers
 *          who mutate permissions invalidate the affected caches.
 *          Without invalidation, a user whose role just gained a new
 *          permission continues to be denied until the TTL expires,
 *          and a user whose role just lost a permission continues to
 *          be allowed — a privilege-retention window of up to five
 *          minutes.
 *
 *          The `Role` model is the natural place to announce the
 *          mutation, but it is the wrong place to react to it: the
 *          model should not depend on `AuthorizationService`, which
 *          would invert the dependency direction (model → service)
 *          and complicate isolated model testing. Instead, the model
 *          dispatches an event and the Authorization capability
 *          registers a listener (D-11c, D-11d) that performs the
 *          invalidation.
 *
 *          WHAT EXACTLY CHANGES
 *          ---------------------
 *          In `syncPermissions()`, immediately after the existing
 *          `$this->permissions()->sync($permissionIds)` call succeeds
 *          and immediately before `return $this`, one line is added:
 *
 *              RolePermissionsChanged::dispatch(
 *                  roleId: $this->id,
 *                  roleSlug: $this->slug,
 *                  permissionIds: $permissionIds,
 *              );
 *
 *          The dispatch is UNCONDITIONAL — it fires even when the
 *          resulting permission set is identical to the set already
 *          attached. This is deliberate: the alternative is to pre-read
 *          the current pivot and compare, which adds a query and opens
 *          a race window where a concurrent mutation is not observed.
 *          The cost of a redundant dispatch is negligible because the
 *          listener is a no-op when no caches exist for the affected
 *          users.
 *
 *          The dispatch is NOT wrapped in a try/catch. If the event
 *          class cannot be resolved or a listener throws, that is a
 *          real defect and should surface rather than be silently
 *          swallowed. The listener (D-11c) has its own try/catch at
 *          the persistence boundary; anything that escapes it is a
 *          programming error that must be fixed, not masked.
 *
 *          If `sync()` throws (DB error), the event is NOT dispatched —
 *          no successful mutation occurred, so no stale cache exists to
 *          invalidate.
 *
 *          WHAT DOES NOT CHANGE
 *          ---------------------
 *          · The `$permissionIds` normalisation logic is unchanged.
 *          · No other method in this class is touched.
 *          · No new property, relation, scope, or accessor is added.
 *          · No model → service dependency is introduced; the model
 *            depends only on an inert event class.
 *          · No listener registration is performed here (that is D-11d).
 *
 * @property int           $id
 * @property string|null   $name_am              Role name in Amharic
 * @property string|null   $name_en              Role name in English
 * @property string        $slug                 Unique role slug
 * @property string|null   $description_am       Description in Amharic
 * @property string|null   $description_en       Description in English
 * @property string        $scope                Role scope ('platform','tenant','application')
 * @property int|null      $tenant_id            Tenant ID (if scope = tenant)
 * @property int|null      $application_id       Application ID (if scope = application)
 * @property bool          $is_active            Whether the role is active
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 *
 * @package MaaleArna\MASES\Core\Authorization\Models
 * @version 1.0.1
 */
final class Role extends Model
{
    use SoftDeletes;
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'roles';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name_am',
        'name_en',
        'slug',
        'description_am',
        'description_en',
        'scope',
        'tenant_id',
        'application_id',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active'  => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the tenant that this role belongs to (if scope is 'tenant').
     *
     * @return BelongsTo<Tenant, self>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Get the application that this role belongs to (if scope is 'application').
     *
     * @return BelongsTo<PlatformApplication, self>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(PlatformApplication::class, 'application_id');
    }

    /**
     * Get all permissions assigned to this role.
     *
     * Note: The pivot table role_has_permissions has only an `assigned_at`
     * column and no created_at/updated_at, so withTimestamps() is not used.
     *
     * @return BelongsToMany<Permission>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'role_has_permissions',
            'role_id',
            'permission_id'
        )->withPivot('assigned_at');
    }

    /**
     * Get all users assigned to this role.
     *
     * Note: The pivot table user_has_roles has `assigned_at` and `assigned_by`,
     * but no created_at/updated_at, so withTimestamps() is not used.
     *
     * @return BelongsToMany<User>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_has_roles',
            'role_id',
            'user_id'
        )->withPivot('assigned_at', 'assigned_by');
    }

    /**
     * Assign a permission to this role.
     *
     * @param string|Permission $permission The permission slug or instance.
     * @return self
     */
    public function givePermissionTo(string|Permission $permission): self
    {
        $permissionId = $permission instanceof Permission
            ? $permission->id
            : Permission::where('slug', $permission)->value('id');

        if ($permissionId && !$this->permissions()->where('permission_id', $permissionId)->exists()) {
            $this->permissions()->attach($permissionId, ['assigned_at' => now()]);
        }

        return $this;
    }

    /**
     * Revoke a permission from this role.
     *
     * @param string|Permission $permission The permission slug or instance.
     * @return self
     */
    public function revokePermissionTo(string|Permission $permission): self
    {
        $permissionId = $permission instanceof Permission
            ? $permission->id
            : Permission::where('slug', $permission)->value('id');

        if ($permissionId) {
            $this->permissions()->detach($permissionId);
        }

        return $this;
    }

    /**
     * Check if the role has a specific permission.
     *
     * @param string $permissionSlug
     * @return bool
     */
    public function hasPermission(string $permissionSlug): bool
    {
        return $this->permissions()->where('slug', $permissionSlug)->exists();
    }

    /**
     * Sync permissions for this role.
     *
     * Accepts a mixed array of permission IDs, permission slugs, and/or
     * `Permission` instances, normalises them to a flat list of integer
     * IDs, and syncs the `role_has_permissions` pivot to that list.
     *
     * After a successful sync, a `RolePermissionsChanged` event is
     * dispatched so that any registered listener can invalidate the
     * authorization caches of every user who holds this role. See the
     * class docblock §"Revision history" for the rationale.
     *
     * The event is dispatched UNCONDITIONALLY after the sync returns —
     * including when the resulting permission set is identical to the
     * previous one — to avoid a pre-read query and a race window. The
     * event is NOT dispatched if `sync()` throws, because no successful
     * mutation occurred.
     *
     * @param array<int|string> $permissions Array of permission IDs or slugs.
     * @return self
     */
    public function syncPermissions(array $permissions): self
    {
        $permissionIds = collect($permissions)->map(function ($permission) {
            if ($permission instanceof Permission) {
                return $permission->id;
            }
            if (is_string($permission)) {
                return Permission::where('slug', $permission)->value('id');
            }
            return $permission;
        })->filter()->unique()->values()->all();

        $this->permissions()->sync($permissionIds);

        // Announce the mutation so registered listeners can invalidate
        // any cached authorization answers belonging to the users who
        // hold this role. The event is inert until a listener is
        // registered against it (see D-11d).
        RolePermissionsChanged::dispatch(
            roleId: $this->id,
            roleSlug: $this->slug,
            permissionIds: $permissionIds,
        );

        return $this;
    }

    /**
     * Determine if the role is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Scope a query to only include active roles.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include roles of a given scope.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $scope
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfScope($query, string $scope)
    {
        return $query->where('scope', $scope);
    }

    /**
     * Scope a query to only include roles for a specific tenant.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $tenantId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query to only include roles for a specific application.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $applicationId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForApplication($query, int $applicationId)
    {
        return $query->where('application_id', $applicationId);
    }

    /**
     * Get the role display name based on current locale.
     *
     * @return string
     */
    public function getDisplayNameAttribute(): string
    {
        $locale = app()->getLocale();

        if ($locale === 'am' && $this->name_am) {
            return $this->name_am;
        }

        if ($this->name_en) {
            return $this->name_en;
        }

        if ($this->name_am) {
            return $this->name_am;
        }

        return $this->slug;
    }

    /**
     * Get the role description based on current locale.
     *
     * @return string|null
     */
    public function getDisplayDescriptionAttribute(): ?string
    {
        $locale = app()->getLocale();

        if ($locale === 'am' && $this->description_am) {
            return $this->description_am;
        }

        if ($this->description_en) {
            return $this->description_en;
        }

        return $this->description_am;
    }
}