<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

final class GovernanceElection extends Model
{
    use SoftDeletes;

    protected $table = 'governance_elections';

    protected $fillable = [
        'tenant_id',
        'governance_body_id',
        'code',
        'title',
        'status',
        'voting_method',
        'authority_status',
        'authority_reference',
        'authority_evidence_id',
        'authority_verified_by',
        'authority_verified_at',
        'authority_verification_reason',
        'voting_rule_version',
        'nominations_open_at',
        'nominations_close_at',
        'record_date',
        'created_by',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'governance_body_id' => 'integer',
        'created_by' => 'integer',
        'authority_evidence_id' => 'integer',
        'authority_verified_by' => 'integer',
        'authority_verified_at' => 'immutable_datetime',
        'nominations_open_at' => 'immutable_datetime',
        'nominations_close_at' => 'immutable_datetime',
        'record_date' => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isAuthorityVerified(): bool
    {
        return $this->authority_status === 'verified' && $this->authority_evidence_id !== null && $this->authority_verified_by !== null && $this->authority_verified_at !== null;
    }

    public function authorityVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authority_verified_by');
    }

    public function verifiedAuthorityEvidence(): BelongsTo
    {
        return $this->belongsTo(GovernanceElectionAuthorityEvidence::class, 'authority_evidence_id');
    }

    public function committeeMembers(): HasMany
    {
        return $this->hasMany(GovernanceElectionCommitteeMember::class, 'election_id');
    }

    public function authorityEvidence(): HasMany
    {
        return $this->hasMany(GovernanceElectionAuthorityEvidence::class, 'election_id');
    }
}
