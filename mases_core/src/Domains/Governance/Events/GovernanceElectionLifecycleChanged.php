<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElectionLifecycleTransition;

final class GovernanceElectionLifecycleChanged
{
    use Dispatchable;

    public function __construct(
        public readonly GovernanceElection $election,
        public readonly GovernanceElectionLifecycleTransition $transition,
    ) {
    }
}
