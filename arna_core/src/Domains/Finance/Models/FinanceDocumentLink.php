<?php

declare(strict_types=1);

namespace MaaleArna\\Arna\\Core\\Domains\\Finance\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

final class FinanceDocumentLink extends Model
{
    protected $table = 'finance_document_links';

    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'document_id', 'source_domain',
        'source_type', 'source_id', 'purpose', 'is_primary', 'linked_by',
        'linked_at', 'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'legal_entity_id' => 'integer',
        'document_id' => 'integer',
        'is_primary' => 'boolean',
        'linked_by' => 'integer',
        'linked_at' => 'immutable_datetime',
        'metadata' => 'array',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(\\MaaleArna\\Arna\\Core\\Domains\\Documents\\Models\\Document::class, 'document_id');
    }

    public function scopeForEntity($query, int $tenantId, int $entityId)
    {
        return $query->where('tenant_id', $tenantId)->where('legal_entity_id', $entityId);
    }
}
