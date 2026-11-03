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
        'candidate_person_id', 'candidate_name_snapshot', 'nomination_source',
        'nominated_by_shareholder_id', 'nomination_statement', 'committee_status',
        'committee_reason', 'committee_decided_at', 'committee_decided_by',
        'final_ballot_approved', 'final_ballot_approved_at',
    ];

    protected $casts = [
        'reviewed_at' => 'immutable_datetime',
        'committee_decided_at' => 'immutable_datetime',
        'final_ballot_approved_at' => 'immutable_datetime',
        'final_ballot_approved' => 'boolean',
    ];
}
