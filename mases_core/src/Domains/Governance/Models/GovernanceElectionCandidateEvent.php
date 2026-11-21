<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GovernanceElectionCandidateEvent extends Model
{
    protected $table = 'governance_election_candidate_events';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(GovernanceElectionCandidate::class, 'candidate_id');
    }
}
