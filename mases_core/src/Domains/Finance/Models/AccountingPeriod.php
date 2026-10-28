<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\RelationsHasMany;

final class AccountingPeriod extends Model
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';

    protected $table = 'accounting_periods';

    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'code', 'starts_on', 'ends_on',
        'status', 'closed_by', 'closed_at', 'close_reason',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'legal_entity_id' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'closed_by' => 'integer',
        'closed_at' => 'datetime',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function journals(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'accounting_period_id');
    }

    public function scopeForEntity($query, int $tenantId, int $entityId)
    {
        return $query->where('tenant_id', $tenantId)->where('legal_entity_id', $entityId);
    }
}
