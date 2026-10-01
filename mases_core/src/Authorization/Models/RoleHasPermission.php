<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Authorization\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Authorization\Models\Permission;

/**
 * RoleHasPermission Model
 *
 * Explicit pivot model for the `role_has_permissions` table.
 * Represents the assignment of a permission to a role.
 *
 * The table has a composite primary key (role_id, permission_id)
 * and an additional `assigned_at` timestamp column.
 * There are no `created_at` or `updated_at` columns in this pivot table.
 *
 * @property int $role_id            Role ID (part of composite primary key)
 * @property int $permission_id      Permission ID (part of composite primary key)
 * @property \Carbon\Carbon $assigned_at  When the permission was assigned
 *
 * @package MaaleArna\MASES\Core\Authorization\Models
 */
final class RoleHasPermission extends Pivot
{
    /**
     * Indicates if the IDs are auto‑incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The primary key for the model.
     * Composite key consisting of role_id and permission_id.
     *
     * @var array<int, string>
     */
    protected $primaryKey = ['role_id', 'permission_id'];

    /**
     * Indicates if the model should be timestamped.
     * This pivot table does not have created_at/updated_at columns.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role_id',
        'permission_id',
        'assigned_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    /**
     * Get the role associated with this pivot entry.
     *
     * @return BelongsTo<Role, self>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Get the permission associated with this pivot entry.
     *
     * @return BelongsTo<Permission, self>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id');
    }
}