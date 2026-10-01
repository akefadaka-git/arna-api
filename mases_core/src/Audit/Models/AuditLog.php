<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Audit\Models;

use Illuminate\Database\Eloquent\Model;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * AuditLog
 *
 * Represents an audit trail entry for actions performed in the system.
 * Stores details about the action, affected resource, old and new values,
 * and request metadata (IP, user agent, request ID).
 *
 * This model is append‑only; no updates are expected after creation.
 * The `created_at` timestamp is automatically set by the database.
 *
 * @property int         $id
 * @property int|null    $user_id          User who performed the action
 * @property int|null    $tenant_id        Tenant context (nullable for system actions)
 * @property string      $action           Machine‑readable action slug
 * @property string|null $resource_type    e.g., 'file', 'user', 'tenant'
 * @property string|null $resource_id      ID of the affected resource
 * @property array|null  $old_values       JSON of values before change
 * @property array|null  $new_values       JSON of values after change
 * @property string|null $ip_address       Originating IP
 * @property string|null $user_agent       User agent string
 * @property string|null $request_id       Correlation ID
 * @property \Carbon\Carbon $created_at    When the event occurred
 */
final class AuditLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'audit_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'tenant_id',
        'action',
        'resource_type',
        'resource_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'request_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Disable the `updated_at` column – we only use `created_at`.
     */
    public const UPDATED_AT = null;

    /**
     * Get the user who performed the action.
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the tenant context of this audit entry.
     */
    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}