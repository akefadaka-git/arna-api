<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Auth\Models\User;

final class JournalSourceEntityMapping extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_ACTIVE = self::STATUS_APPROVED;
    public const STATUS_INACTIVE = self::STATUS_REVOKED;

    protected $table = 'journal_source_entity_mappings';

    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'source_type', 'source_id',
        'created_by', 'mapping_reason',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'legal_entity_id' => 'integer',
        'created_by' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForEntity(Builder $query, int $tenantId, int $legalEntityId): Builder
    {
        return $query->where('tenant_id', $tenantId)->where('legal_entity_id', $legalEntityId);
    }

    public function scopeForSource(Builder $query, int $tenantId, string $sourceType, string $sourceId): Builder
    {
        return $query->where('tenant_id', $tenantId)->where('source_type', $sourceType)->where('source_id', $sourceId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED)
            ->whereNotNull('approved_by')
            ->whereNotNull('approved_at');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_APPROVED
            && $this->approved_by !== null
            && $this->approved_at !== null;
    }
}
