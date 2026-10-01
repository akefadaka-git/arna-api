<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Authorization\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;
use MaaleArna\MASES\Core\Authorization\Models\Role;

/**
 * Permission Model
 *
 * Represents a granular permission in the MASES RBAC system.
 * Permissions are the atomic units of authorization and can be
 * assigned to roles or directly to users.
 *
 * Permissions have three scopes:
 * - 'global': Applies across the entire platform.
 * - 'tenant': Applies within a specific tenant context.
 * - 'application': Applies within a specific application context.
 *
 * The slug follows the convention: {domain}.{resource}.{action}
 * Example: 'finance.transaction.create', 'hr.employee.view'
 *
 * Display names and descriptions are stored in both Amharic and English
 * to support the Amharic‑first design with locale switching.
 *
 * @property int           $id
 * @property string|null   $name_am              Permission name in Amharic
 * @property string|null   $name_en              Permission name in English
 * @property string        $slug                 Unique permission slug
 * @property string|null   $description_am       Description in Amharic
 * @property string|null   $description_en       Description in English
 * @property string|null   $category             Permission category
 * @property string        $scope                Permission scope ('global','tenant','application')
 * @property int|null      $tenant_id            Tenant ID (if scope = tenant)
 * @property int|null      $application_id       Application ID (if scope = application)
 * @property bool          $is_active            Whether the permission is active
 * @property bool          $is_system            Whether the permission is system‑critical
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 *
 * @package MaaleArna\MASES\Core\Authorization\Models
 */
final class Permission extends Model
{
    use SoftDeletes;
    use HasFactory; // Enables model factory support

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'permissions';

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
        'category',
        'scope',
        'tenant_id',
        'application_id',
        'is_active',
        'is_system',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active'  => 'boolean',
        'is_system'  => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the tenant that this permission belongs to (if scope is 'tenant').
     *
     * @return BelongsTo<Tenant, self>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Get the application that this permission belongs to (if scope is 'application').
     *
     * @return BelongsTo<PlatformApplication, self>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(PlatformApplication::class, 'application_id');
    }

    /**
     * Get all roles that have this permission assigned.
     *
     * Note: The pivot table role_has_permissions has only `assigned_at`
     * and no created_at/updated_at, so withTimestamps() is not used.
     *
     * @return BelongsToMany<Role>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'role_has_permissions',
            'permission_id',
            'role_id'
        )->withPivot('assigned_at');
    }

    /**
     * Get all users that have this permission assigned directly.
     *
     * Note: The pivot table user_has_permissions has `assigned_at` and
     * `assigned_by`, but no created_at/updated_at, so withTimestamps() is not used.
     *
     * @return BelongsToMany<User>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_has_permissions',
            'permission_id',
            'user_id'
        )->withPivot('assigned_at', 'assigned_by');
    }

    /**
     * Determine if the permission is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Determine if the permission is system-critical and cannot be deleted.
     *
     * @return bool
     */
    public function isSystem(): bool
    {
        return $this->is_system === true;
    }

    /**
     * Scope a query to only include active permissions.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include permissions of a given scope.
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
     * Scope a query to only include permissions for a specific category.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $category
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope a query to only include permissions for a specific tenant.
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
     * Scope a query to only include permissions for a specific application.
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
     * Get the permission display name based on current locale.
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
     * Get the permission description based on current locale.
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