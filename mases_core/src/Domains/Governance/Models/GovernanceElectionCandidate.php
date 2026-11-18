<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MaaleArna\MASES\Core\Domains\People\Models\Person;

final class GovernanceElectionCandidate extends Model
{
    protected $table = 'governance_election_candidates';

    protected $guarded = ['id', 'tenant_id', 'election_id', 'person_id', 'version', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'declarations' => 'array',
            'consented_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function scopeForElection(Builder $query, int $tenantId, int $electionId): Builder
    {
        return $query->where('tenant_id', $tenantId)->where('election_id', $electionId);
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(GovernanceElection::class, 'election_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    public function nominations(): HasMany
    {
        return $this->hasMany(GovernanceElectionNominationSubmission::class, 'candidate_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(GovernanceElectionCandidateEvidence::class, 'candidate_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(GovernanceElectionCandidateDecision::class, 'candidate_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(GovernanceElectionCandidateEvent::class, 'candidate_id');
    }
}
