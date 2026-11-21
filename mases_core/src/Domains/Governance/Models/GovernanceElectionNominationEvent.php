<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GovernanceElectionNominationEvent extends Model
{
    protected $table = 'governance_election_nomination_events';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'immutable_datetime', 'sequence' => 'integer'];
    }

    public function nomination(): BelongsTo
    {
        return $this->belongsTo(GovernanceElectionNominationSubmission::class, 'nomination_submission_id');
    }
}
