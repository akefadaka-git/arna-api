<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

final class GovernanceAuthorityGrant extends Model
{
    protected $table = 'governance_authority_grants';

    protected $fillable = [
        'tenant_id',
        'governance_body_id',
        'governance_position_id',
        'grantee_person_id',
        'grantor_person_id',
        'created_by_user_id',
        'action',
        'effective_from',
        'effective_until',
        'reason',
        'revoked_at',
        'revoked_by_user_id',
        'revocation_reason',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'governance_body_id' => 'integer',
        'governance_position_id' => 'integer',
        'grantee_person_id' => 'integer',
        'grantor_person_id' => 'integer',
        'created_by_user_id' => 'integer',
        'revoked_by_user_id' => 'integer',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'revoked_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function body(): BelongsTo
    {
        return $this->belongsTo(GovernanceBody::class, 'governance_body_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(GovernancePosition::class, 'governance_position_id');
    }

    public function grantee(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'grantee_person_id');
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'grantor_person_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
