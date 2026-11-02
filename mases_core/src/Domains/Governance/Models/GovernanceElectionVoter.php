<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;

final class GovernanceElectionVoter extends Model
{
    protected $table = 'governance_election_voters';

    protected $fillable = [
        'tenant_id', 'election_id', 'snapshot_id', 'shareholder_id',
        'share_class_code', 'share_quantity', 'voting_weight',
        'entitlement_reference', 'proxy_authorized',
    ];

    protected $casts = [
        'share_quantity' => 'decimal:4',
        'voting_weight' => 'decimal:4',
        'proxy_authorized' => 'boolean',
    ];
}
