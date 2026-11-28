<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;

final class GovernanceElectionSourceSnapshotHolding extends Model
{
    protected $table = 'governance_election_source_snapshot_holdings';
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'election_id' => 'integer',
            'snapshot_id' => 'integer',
            'shareholder_id' => 'integer',
            'source_holding_id' => 'integer',
            'share_class_id' => 'integer',
            'quantity_at_cutoff' => 'decimal:4',
            'current_projection' => 'decimal:4',
            'ledger_entry_count' => 'integer',
        ];
    }
}
