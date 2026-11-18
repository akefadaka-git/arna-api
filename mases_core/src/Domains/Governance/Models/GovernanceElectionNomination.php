<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;

final class GovernanceElectionNomination extends Model
{
    protected $table = 'governance_election_nominations';

    protected $hidden = ['idempotency_key'];

    protected $fillable = [
        'tenant_id', 'election_id', 'candidate_id', 'nominator_shareholder_id',
        'nominator_user_id', 'nominator_person_id', 'self_nomination', 'statement',
        'status', 'revision', 'idempotency_key', 'supersedes_nomination_id', 'submitted_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer', 'election_id' => 'integer', 'candidate_id' => 'integer',
        'nominator_shareholder_id' => 'integer', 'nominator_user_id' => 'integer',
        'nominator_person_id' => 'integer', 'self_nomination' => 'boolean',
        'revision' => 'integer', 'supersedes_nomination_id' => 'integer',
        'submitted_at' => 'immutable_datetime',
    ];

    public function candidate(): BelongsTo { return $this->belongsTo(GovernanceElectionCandidate::class, 'candidate_id'); }
    public function nominatorShareholder(): BelongsTo { return $this->belongsTo(Shareholder::class, 'nominator_shareholder_id'); }
    public function nominator(): BelongsTo { return $this->belongsTo(User::class, 'nominator_user_id'); }
    public function nominatorPerson(): BelongsTo { return $this->belongsTo(Person::class, 'nominator_person_id'); }
    public function supersedes(): BelongsTo { return $this->belongsTo(self::class, 'supersedes_nomination_id'); }
}
