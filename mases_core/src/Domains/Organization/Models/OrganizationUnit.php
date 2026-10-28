<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class OrganizationUnit extends Model
{
    use SoftDeletes;

    protected $table = 'organization_units';

    protected $fillable = [
        'tenant_id', 'parent_id', 'legal_entity_id', 'code', 'unit_type',
        'name_am', 'name_en', 'status', 'lifecycle_reason', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'tenant_id' => 'integer', 'parent_id' => 'integer',
        'legal_entity_id' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(OrganizationAssignment::class, 'organization_unit_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(OrganizationAuditEvent::class, 'subject_id')
            ->where('subject_type', 'unit');
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}