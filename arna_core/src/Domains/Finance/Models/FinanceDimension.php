<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class FinanceDimension extends Model
{
    protected $table = 'finance_dimensions';

    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'code', 'dimension_type',
        'name_am', 'name_en', 'status', 'is_required_for_post', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'legal_entity_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'is_required_for_post' => 'boolean',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(FinanceDimensionValue::class, 'dimension_id');
    }

    public function scopeForEntity($query, int $tenantId, int $entityId)
    {
        return $query->where('tenant_id', $tenantId)->where('legal_entity_id', $entityId);
    }
}
