<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

final class GovernanceElectionCandidate extends Model
{
    protected $table = 'governance_election_candidates';

    protected $fillable = [
        'tenant_id', 'election_id', 'candidate_person_id', 'candidate_type',
        'intake_status', 'eligibility_status',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'election_id' => 'integer',
        'candidate_person_id' => 'integer',
    ];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class, 'tenant_id'); }
    public function election(): BelongsTo { return $this->belongsTo(GovernanceElection::class, 'election_id'); }
    public function person(): BelongsTo { return $this->belongsTo(Person::class, 'candidate_person_id'); }
    public function nominations(): HasMany { return $this->hasMany(GovernanceElectionNomination::class, 'candidate_id'); }
    public function evidence(): HasMany { return $this->hasMany(GovernanceElectionCandidateEvidence::class, 'candidate_id'); }
    public function eligibilityDecisions(): HasMany { return $this->hasMany(GovernanceElectionCandidateEligibilityDecision::class, 'candidate_id'); }
}
