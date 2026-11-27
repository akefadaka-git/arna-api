<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Governance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceElection;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceElectionLifecycleTransition;

final class GovernanceElectionLifecycleChanged
{
    use Dispatchable;

    public function __construct(
        public readonly GovernanceElection $election,
        public readonly GovernanceElectionLifecycleTransition $transition,
    ) {
    }
}
