<?php

declare(strict_types=1);

namespace MaaleArna\\Arna\\Core\\Domains\\Finance\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;
use Illuminate\\Database\\Eloquent\\Relations\\HasMany;

final class FinanceDimensionValue extends Model
{
    protected $table = 'finance_dimension_values';

    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'dimension_id', 'parent_id', 'code',
        'name_am', 'name_en', 'status', 'effective_from', 'effective_to',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'legal_entity_id' => 'integer',
        'dimension_id' => 'integer',
        'parent_id' => 'integer',
        'effective_from' => 'immutable_date',
        'effective_to' => 'immutable_date',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function dimension(): BelongsTo
    {
        return $this->belongsTo(FinanceDimension::class, 'dimension_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scopeForDimension($query, int $tenantId, int $entityId, int $dimensionId)
    {
        return $query->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $entityId)
            ->where('dimension_id', $dimensionId);
    }
}
