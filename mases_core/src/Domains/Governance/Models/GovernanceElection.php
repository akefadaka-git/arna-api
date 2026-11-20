<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;

final class GovernanceElection extends Model
{
    protected $table = 'governance_elections';

    protected $guarded = ['id', 'version', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'authority_snapshot' => 'array',
            'authority_evidence_id' => 'integer',
            'authority_verified_by' => 'integer',
            'authority_verified_at' => 'immutable_datetime',
            'scheduled_start_at' => 'immutable_datetime',
            'scheduled_end_at' => 'immutable_datetime',
            'authorized_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function governanceBody(): BelongsTo
    {
        return $this->belongsTo(GovernanceBody::class, 'governance_body_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(GovernanceElectionLifecycleTransition::class, 'election_id');
    }

    public function authorityEvidence(): BelongsTo
    {
        return $this->belongsTo(GovernanceElectionAuthorityEvidence::class, 'authority_evidence_id');
    }

    public function committeeMembers(): HasMany
    {
        return $this->hasMany(GovernanceElectionCommitteeMember::class, 'election_id');
    }

    public function isAuthorityVerified(): bool
    {
        return $this->authority_evidence_id !== null && $this->authority_verified_at !== null;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['cancelled', 'archived'], true);
    }
}
