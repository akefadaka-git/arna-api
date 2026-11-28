<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;

final class GovernanceElectionSourceSnapshotHolder extends Model
{
    protected $table = 'governance_election_source_snapshot_holders';
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'election_id' => 'integer',
            'snapshot_id' => 'integer',
            'shareholder_id' => 'integer',
            'person_id' => 'integer',
            'direct_user_id' => 'integer',
            'representative_user_id' => 'integer',
            'holding_count' => 'integer',
        ];
    }
}
