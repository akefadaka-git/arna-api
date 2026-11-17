<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A server-owned record for one authenticated client installation.
 *
 * The installation identifier is stored only as a keyed hash. The public UUID
 * is an opaque API identifier and is not an authentication credential.
 */
final class DeviceSession extends Model
{
    protected $table = 'auth_device_sessions';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'personal_access_token_id',
        'installation_hash',
        'platform',
        'app_version',
        'device_label',
        'last_seen_at',
        'expires_at',
        'revoked_at',
        'revocation_reason',
    ];

    protected $hidden = [
        'id',
        'user_type',
        'user_id',
        'personal_access_token_id',
        'installation_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (self $session): void {
            if (!is_string($session->public_id) || $session->public_id === '') {
                $session->public_id = (string) Str::uuid();
            }
        });
    }

    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    public function scopeActive($query)
    {
        return $query
            ->whereNull('revoked_at')
            ->where(function ($builder): void {
                $builder->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
