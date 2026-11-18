<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Auth\Models\User;

final class GovernanceElectionCandidateEligibilityDecision extends Model
{
    public $timestamps = false;
    protected $table = 'governance_election_candidate_eligibility_decisions';

    protected $fillable = [
        'tenant_id', 'election_id', 'candidate_id', 'decision', 'reason',
        'evidence_snapshot_sha256', 'decided_by', 'created_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer', 'election_id' => 'integer', 'candidate_id' => 'integer',
        'decided_by' => 'integer', 'created_at' => 'immutable_datetime',
    ];

    public function candidate(): BelongsTo { return $this->belongsTo(GovernanceElectionCandidate::class, 'candidate_id'); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
}
