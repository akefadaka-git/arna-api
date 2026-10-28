<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\RelationsHasMany;

final class JournalEntry extends Model
{
    public const DRAFT = 'draft';
    public const POSTED = 'posted';
    public const REVERSED = 'reversed';

    protected $table = 'journal_entries';

    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'accounting_period_id', 'journal_number',
        'effective_date', 'status', 'currency', 'description', 'source_type',
        'source_id', 'idempotency_key', 'created_by', 'posted_by', 'posted_at',
        'reversal_of_id', 'reversed_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'legal_entity_id' => 'integer',
        'accounting_period_id' => 'integer',
        'effective_date' => 'date',
        'created_by' => 'integer',
        'posted_by' => 'integer',
        'posted_at' => 'datetime',
        'reversal_of_id' => 'integer',
        'reversed_at' => 'datetime',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id');
    }

    public function scopeForEntity($query, int $tenantId, int $entityId)
    {
        return $query->where('tenant_id', $tenantId)->where('legal_entity_id', $entityId);
    }
}
