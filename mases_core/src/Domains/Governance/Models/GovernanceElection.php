<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class GovernanceElection extends Model
{
    use SoftDeletes;

    protected $table = 'governance_elections';

    protected $fillable = [
        'tenant_id', 'governance_body_id', 'code', 'title', 'status',
        'record_date', 'nominations_open_at', 'nominations_close_at',
        'poll_open_at', 'poll_close_at', 'authority_reference',
        'authority_verified_at', 'authority_verified_by',
        'voting_rule_version', 'voting_method', 'metadata',
    ];

    protected $casts = [
        'record_date' => 'date',
        'nominations_open_at' => 'immutable_datetime',
        'nominations_close_at' => 'immutable_datetime',
        'poll_open_at' => 'immutable_datetime',
        'poll_close_at' => 'immutable_datetime',
        'authority_verified_at' => 'immutable_datetime',
        'metadata' => 'array',
    ];

    public function voters(): HasMany
    {
        return $this->hasMany(GovernanceElectionVoter::class, 'election_id');
    }

    public function nominations(): HasMany
    {
        return $this->hasMany(GovernanceElectionNomination::class, 'election_id');
    }

    public function ballots(): HasMany
    {
        return $this->hasMany(GovernanceElectionBallot::class, 'election_id');
    }

    public function isAuthorityVerified(): bool
    {
        return $this->authority_reference !== null
            && $this->authority_verified_at !== null
            && $this->authority_verified_by !== null;
    }
}
