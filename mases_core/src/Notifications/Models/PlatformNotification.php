<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

final class PlatformNotification extends Model
{
    public $incrementing = false;

    protected $table = 'platform_notifications';

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $fillable = [
        'id', 'tenant_id', 'user_id', 'event_key', 'category', 'priority',
        'title', 'body', 'action_route_name', 'resource_ref', 'dedupe_key', 'read_at',
    ];

    protected $casts = [
        'title' => 'array',
        'body' => 'array',
        'resource_ref' => 'array',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
