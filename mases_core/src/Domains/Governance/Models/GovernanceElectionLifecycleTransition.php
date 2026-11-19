<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;

final class GovernanceElectionLifecycleTransition extends Model
{
    protected $table = 'governance_election_lifecycle_transitions';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'evidence_references' => 'array',
            'occurred_at' => 'immutable_datetime',
            'aggregate_version' => 'integer',
            'sequence' => 'integer',
        ];
    }
}
