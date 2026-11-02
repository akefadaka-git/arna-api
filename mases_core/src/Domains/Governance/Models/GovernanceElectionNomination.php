<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;

final class GovernanceElectionNomination extends Model
{
    protected $table = 'governance_election_nominations';

    protected $fillable = [
        'tenant_id', 'election_id', 'shareholder_id', 'status',
        'evidence_reference', 'eligibility_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = ['reviewed_at' => 'immutable_datetime'];
}
