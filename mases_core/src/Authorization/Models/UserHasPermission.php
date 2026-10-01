<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Authorization\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;

/**
 * UserHasPermission Model
 *
 * Explicit pivot model for the `user_has_permissions` table.
 * Represents the direct assignment of a permission to a user.
 *
 * The table has a composite primary key (user_id, permission_id)
 * and additional columns:
 * - `assigned_at` – when the permission was assigned
 * - `assigned_by` – user ID of the assigner (nullable)
 *
 * There are no `created_at` or `updated_at` columns in this pivot table.
 *
 * @property int $user_id            User ID (part of composite primary key)
 * @property int $permission_id      Permission ID (part of composite primary key)
 * @property \Carbon\Carbon $assigned_at  When the permission was assigned
 * @property int|null $assigned_by   User ID of the assigner (nullable)
 *
 * @package MaaleArna\MASES\Core\Authorization\Models
 */
final class UserHasPermission extends Pivot
{
    /**
     * Indicates if the IDs are auto‑incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The primary key for the model.
     * Composite key consisting of user_id and permission_id.
     *
     * @var array<int, string>
     */
    protected $primaryKey = ['user_id', 'permission_id'];

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
        'user_id',
        'permission_id',
        'assigned_at',
        'assigned_by',
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
     * Get the user associated with this pivot entry.
     *
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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

    /**
     * Get the user who assigned this permission (if any).
     *
     * @return BelongsTo<User, self>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}