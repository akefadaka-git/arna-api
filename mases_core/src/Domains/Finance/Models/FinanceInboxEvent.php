<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Model;

final class FinanceInboxEvent extends Model
{
    public const RECEIVED = 'received';
    public const PROCESSING = 'processing';
    public const POSTED = 'posted';
    public const QUARANTINED = 'quarantined';

    protected $table = 'finance_inbox_events';

    protected $fillable = [
        'tenant_id', 'source_type', 'source_event_id', 'source_version',
        'event_type', 'payload', 'payload_sha256', 'evidence_ref', 'status',
        'attempts', 'journal_id', 'last_error', 'received_at', 'processed_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'journal_id' => 'integer',
        'payload' => 'array',
        'attempts' => 'integer',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
