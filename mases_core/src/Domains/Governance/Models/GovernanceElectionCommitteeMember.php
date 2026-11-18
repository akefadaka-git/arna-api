<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\People\Models\Person;

final class GovernanceElectionCommitteeMember extends Model
{
    protected $table = 'governance_election_committee_members';

    protected $fillable = [
        'tenant_id', 'election_id', 'person_id', 'user_id', 'member_role',
        'ex_officio', 'status', 'appointed_at', 'recused_at', 'recusal_scope',
        'appointment_reference', 'appointment_evidence_id', 'appointed_by',
        'revoked_by', 'revoked_at', 'revocation_reason',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'election_id' => 'integer',
        'person_id' => 'integer',
        'user_id' => 'integer',
        'ex_officio' => 'boolean',
        'appointed_at' => 'immutable_datetime',
        'recused_at' => 'immutable_datetime',
        'appointment_evidence_id' => 'integer',
        'appointed_by' => 'integer',
        'revoked_by' => 'integer',
        'revoked_at' => 'immutable_datetime',
    ];

    public function election(): BelongsTo
    {
        return $this->belongsTo(GovernanceElection::class, 'election_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function appointmentEvidence(): BelongsTo
    {
        return $this->belongsTo(GovernanceElectionAuthorityEvidence::class, 'appointment_evidence_id');
    }

    public function appointer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appointed_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
