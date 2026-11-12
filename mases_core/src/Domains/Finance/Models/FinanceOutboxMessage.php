<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Model;

final class FinanceOutboxMessage extends Model
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const DELIVERED = 'delivered';

    protected $table = 'finance_outbox_messages';

    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'event_key', 'event_id', 'event_type',
        'subject_type', 'subject_id', 'payload', 'status', 'attempts',
        'available_at', 'claimed_at', 'claim_token', 'delivered_at', 'last_error',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'legal_entity_id' => 'integer',
        'subject_id' => 'integer',
        'payload' => 'array',
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'claimed_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];
}
