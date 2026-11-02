<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Governance\\Events;

use MaaleArna\\MASES\\Core\\Domains\\Governance\\Models\\GovernanceElection;

final class GovernanceElectionLifecycleChanged
{
    /** @param array<string,mixed>|null $before */
    public function __construct(
        public readonly GovernanceElection $election,
        public readonly string $action,
        public readonly ?array $before,
        public readonly ?int $actorId,
    ) {
    }
}
