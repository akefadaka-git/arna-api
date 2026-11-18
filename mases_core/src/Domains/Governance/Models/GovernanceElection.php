<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

final class GovernanceElection extends Model
{
    use SoftDeletes;

    protected $table = 'governance_elections';

    protected $fillable = [
        'tenant_id',
        'governance_body_id',
        'code',
        'title',
        'status',
        'voting_method',
        'authority_status',
        'record_date',
        'created_by',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'governance_body_id' => 'integer',
        'created_by' => 'integer',
        'record_date' => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function authorityEvidence(): HasMany
    {
        return $this->hasMany(GovernanceElectionAuthorityEvidence::class, 'election_id');
    }
}
