<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GovernanceElectionNominationWindow extends Model
{
    protected $table = 'governance_election_nomination_windows';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'opens_at' => 'immutable_datetime',
            'closes_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(GovernanceElection::class, 'election_id');
    }
}
