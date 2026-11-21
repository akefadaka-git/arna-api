<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Auth\Models\User;

final class GovernanceElectionAuthorityEvidence extends Model
{
    protected $table = 'governance_election_authority_evidence';

    protected $fillable = [
        'tenant_id',
        'election_id',
        'instrument_type',
        'instrument_reference',
        'instrument_date',
        'storage_reference',
        'content_sha256',
        'status',
        'recorded_by',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'election_id' => 'integer',
        'instrument_date' => 'date',
        'recorded_by' => 'integer',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'immutable_datetime',
    ];

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
}
