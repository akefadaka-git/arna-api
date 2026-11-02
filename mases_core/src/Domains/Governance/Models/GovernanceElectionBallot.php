<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;

final class GovernanceElectionBallot extends Model
{
    protected $table = 'governance_election_ballots';

    protected $fillable = [
        'tenant_id', 'election_id', 'voter_id', 'ballot_reference',
        'channel', 'status', 'custody_reference', 'issued_at',
        'received_at', 'issued_by',
    ];

    protected $casts = [
        'issued_at' => 'immutable_datetime',
        'received_at' => 'immutable_datetime',
    ];
}
