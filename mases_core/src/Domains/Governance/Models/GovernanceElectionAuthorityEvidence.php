<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Auth\Models\User;

final class GovernanceElectionAuthorityEvidence extends Model
{
    protected $table = 'governance_election_authority_evidence';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'election_id' => 'integer',
            'document_id' => 'integer',
            'instrument_date' => 'date',
            'recorded_by' => 'integer',
            'reviewed_by' => 'integer',
            'reviewed_at' => 'immutable_datetime',
            'verified_by' => 'integer',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(GovernanceElection::class, 'election_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
