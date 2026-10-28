<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Model;

final class SourceLegalEntityMapping extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REVOKED = 'revoked';

    protected $table = 'finance_source_entity_mappings';

    // Lifecycle fields can only be changed by the controlled workflow.
    protected $fillable = [
        'tenant_id', 'source_type', 'source_id', 'legal_entity_id',
        'created_by', 'reason',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'legal_entity_id' => 'integer',
        'created_by' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
