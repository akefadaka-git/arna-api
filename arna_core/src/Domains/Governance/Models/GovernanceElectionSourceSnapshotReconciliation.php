<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;

final class GovernanceElectionSourceSnapshotReconciliation extends Model
{
    public $timestamps = false;

    protected $table = 'governance_election_source_snapshot_reconciliations';
    protected $guarded = ['id', 'created_at'];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'election_id' => 'integer',
            'snapshot_id' => 'integer',
            'reconciled_by' => 'integer',
            'reconciled_at_utc' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
