<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use MaaleArna\MASES\Core\Domains\People\Models\Person;

final class OrganizationAssignment extends Model
{
    use SoftDeletes;

    protected $table = 'organization_assignments';

    protected $fillable = [
        'tenant_id', 'organization_unit_id', 'person_id', 'assignment_type',
        'title_am', 'title_en', 'starts_on', 'ends_on', 'status',
        'lifecycle_reason', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'tenant_id' => 'integer', 'organization_unit_id' => 'integer', 'person_id' => 'integer',
        'starts_on' => 'date', 'ends_on' => 'date', 'created_by' => 'integer', 'updated_by' => 'integer',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }
}