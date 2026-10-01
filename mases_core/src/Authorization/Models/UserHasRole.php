<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Authorization\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Role;

/**
 * UserHasRole Model
 *
 * Explicit pivot model for the `user_has_roles` table.
 * Represents the assignment of a role to a user.
 *
 * The table has a composite primary key (user_id, role_id)
 * and additional columns:
 * - `assigned_at` – when the role was assigned
 * - `assigned_by` – user ID of the assigner (nullable)
 *
 * There are no `created_at` or `updated_at` columns in this pivot table.
 *
 * @property int $user_id            User ID (part of composite primary key)
 * @property int $role_id            Role ID (part of composite primary key)
 * @property \Carbon\Carbon $assigned_at  When the role was assigned
 * @property int|null $assigned_by   User ID of the assigner (nullable)
 *
 * @package MaaleArna\MASES\Core\Authorization\Models
 */
final class UserHasRole extends Pivot
{
    /**
     * Indicates if the IDs are auto‑incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The primary key for the model.
     * Composite key consisting of user_id and role_id.
     *
     * @var array<int, string>
     */
    protected $primaryKey = ['user_id', 'role_id'];

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
        'role_id',
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
     * Get the role associated with this pivot entry.
     *
     * @return BelongsTo<Role, self>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Get the user who assigned this role (if any).
     *
     * @return BelongsTo<User, self>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}