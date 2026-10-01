<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Tenancy\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use MaaleArna\MASES\Core\Auth\Models\User;

/**
 * TenantMembership Model
 *
 * Represents a user's membership in a tenant. This is an explicit pivot model
 * for the `tenant_memberships` table, which allows us to store additional
 * data such as `joined_at` and tenant‑specific settings.
 *
 * The composite primary key is (tenant_id, user_id), ensuring a user can
 * belong to a tenant only once.
 *
 * @property int        $tenant_id   Tenant ID (part of composite primary key)
 * @property int        $user_id     User ID (part of composite primary key)
 * @property \Carbon\Carbon $joined_at When the user joined the tenant
 * @property array|null $settings    Membership‑specific settings (JSON)
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @package MaaleArna\MASES\Core\Tenancy\Models
 */
final class TenantMembership extends Pivot
{
    /**
     * Indicates if the IDs are auto‑incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The primary key for the model.
     * Since this is a composite key, we set it as an array.
     *
     * @var array<int, string>
     */
    protected $primaryKey = ['tenant_id', 'user_id'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'joined_at',
        'settings',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'settings'   => 'array',
        'joined_at'  => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the tenant associated with this membership.
     *
     * @return BelongsTo<Tenant, self>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Get the user associated with this membership.
     *
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}