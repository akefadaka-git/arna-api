<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;

final class GovernanceElectionSourceSnapshotEntry extends Model
{
    public $timestamps = false;

    protected $table = 'governance_election_source_snapshot_entries';
    protected $guarded = ['id', 'created_at'];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'election_id' => 'integer',
            'snapshot_id' => 'integer',
            'source_holding_id' => 'integer',
            'source_entry_id' => 'integer',
            'included_by_business_time' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
