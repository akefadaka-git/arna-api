<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Models;

use Illuminate\Database\Eloquent\Model;

final class OrganizationAuditEvent extends Model
{
    public $timestamps = false;

    protected $table = 'organization_audit_events';

    protected $fillable = [
        'tenant_id', 'actor_user_id', 'subject_type', 'subject_id', 'action',
        'reason', 'before_state', 'after_state', 'request_id', 'created_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'actor_user_id' => 'integer',
        'subject_id' => 'integer',
        'before_state' => 'array',
        'after_state' => 'array',
        'created_at' => 'datetime',
    ];
}