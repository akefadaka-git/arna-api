<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Models;

use Illuminate\Database\Eloquent\Model;

final class OrganizationDepartmentNumberCode extends Model
{
    protected $table = 'organization_department_number_codes';

    protected $guarded = ['id'];

    protected $casts = [
        'tenant_id' => 'integer',
        'organization_unit_id' => 'integer',
        'legal_entity_id' => 'integer',
        'created_by' => 'integer',
        'effective_from' => 'date',
        'effective_until' => 'date',
    ];
}
