<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Security\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * SecurityEvent Model
 *
 * Represents a security event logged in the MASES platform.
 * Stores information about authentication attempts, password resets,
 * MFA events, suspicious activity, and account management events.
 *
 * This model provides filtering scopes and relationships to users and tenants.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|string|null $tenant_id
 * @property string $event_type
 * @property string $severity
 * @property string|null $description
 * @property array|null $event_data
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property \Carbon\Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property string|null $resolution_notes
 * @property \Carbon\Carbon $created_at
 *
 * @property-read User|null $user
 * @property-read Tenant|null $tenant
 * @property-read User|null $resolver
 *
 * @package MaaleArna\MASES\Core\Security\Models
 */
final class SecurityEvent extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'security_events';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'tenant_id',
        'event_type',
        'severity',
        'description',
        'event_data',
        'ip_address',
        'user_agent',
        'request_id',
        'resolved_at',
        'resolved_by',
        'resolution_notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'event_data' => 'array',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        // No sensitive data in the event model itself; data is already filtered.
    ];

    /**
     * Get the user who triggered the event.
     *
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the tenant context of the event.
     *
     * @return BelongsTo<Tenant, self>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Get the user who resolved the event.
     *
     * @return BelongsTo<User, self>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Scope a query to only include events of a given severity.
     *
     * @param Builder $query
     * @param string $severity
     * @return Builder
     */
    public function scopeOfSeverity(Builder $query, string $severity): Builder
    {
        return $query->where('severity', $severity);
    }

    /**
     * Scope a query to only include events of a given type.
     *
     * @param Builder $query
     * @param string $eventType
     * @return Builder
     */
    public function scopeOfType(Builder $query, string $eventType): Builder
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Scope a query to only include events for a specific user.
     *
     * @param Builder $query
     * @param int $userId
     * @return Builder
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include events for a specific tenant.
     *
     * @param Builder $query
     * @param int|string $tenantId
     * @return Builder
     */
    public function scopeForTenant(Builder $query, int|string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query to only include unresolved events.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * Scope a query to only include resolved events.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereNotNull('resolved_at');
    }

    /**
     * Scope a query to only include events from a given IP address.
     *
     * @param Builder $query
     * @param string $ipAddress
     * @return Builder
     */
    public function scopeFromIp(Builder $query, string $ipAddress): Builder
    {
        return $query->where('ip_address', $ipAddress);
    }

    /**
     * Scope a query to only include events from a date range.
     *
     * @param Builder $query
     * @param string $startDate
     * @param string|null $endDate
     * @return Builder
     */
    public function scopeDateRange(Builder $query, string $startDate, ?string $endDate = null): Builder
    {
        $query->where('created_at', '>=', $startDate);
        if ($endDate !== null) {
            $query->where('created_at', '<=', $endDate);
        }
        return $query;
    }

    /**
     * Mark the event as resolved.
     *
     * @param User|int $resolver The user or user ID resolving the event.
     * @param string|null $notes Resolution notes.
     * @return bool
     */
    public function resolve(User|int $resolver, ?string $notes = null): bool
    {
        $resolvedBy = $resolver instanceof User ? $resolver->id : $resolver;
        $this->resolved_at = now();
        $this->resolved_by = $resolvedBy;
        if ($notes !== null) {
            $this->resolution_notes = $notes;
        }
        return $this->save();
    }

    /**
     * Check if the event is resolved.
     *
     * @return bool
     */
    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * Check if the event is critical severity.
     *
     * @return bool
     */
    public function isCritical(): bool
    {
        return $this->severity === 'critical';
    }

    /**
     * Check if the event is a warning severity.
     *
     * @return bool
     */
    public function isWarning(): bool
    {
        return $this->severity === 'warning';
    }

    /**
     * Check if the event is info severity.
     *
     * @return bool
     */
    public function isInfo(): bool
    {
        return $this->severity === 'info';
    }
}