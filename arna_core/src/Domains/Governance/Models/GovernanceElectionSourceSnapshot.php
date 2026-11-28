<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MaaleArna\Arna\Core\Auth\Models\User;

final class GovernanceElectionSourceSnapshot extends Model
{
    protected $table = 'governance_election_source_snapshots';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'election_id' => 'integer',
            'version' => 'integer',
            'shareholder_count' => 'integer',
            'holding_count' => 'integer',
            'ledger_entry_count' => 'integer',
            'prepared_by' => 'integer',
            'record_date_time_utc' => 'immutable_datetime',
            'captured_at_utc' => 'immutable_datetime',
            'prepared_at_utc' => 'immutable_datetime',
        ];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(GovernanceElection::class, 'election_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function holders(): HasMany
    {
        return $this->hasMany(GovernanceElectionSourceSnapshotHolder::class, 'snapshot_id');
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(GovernanceElectionSourceSnapshotHolding::class, 'snapshot_id');
    }

    public function sourceEntries(): HasMany
    {
        return $this->hasMany(GovernanceElectionSourceSnapshotEntry::class, 'snapshot_id');
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(GovernanceElectionSourceSnapshotReconciliation::class, 'snapshot_id');
    }
}
