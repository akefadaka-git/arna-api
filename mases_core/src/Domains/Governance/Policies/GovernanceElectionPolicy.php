<?php
declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;

final class GovernanceElectionPolicy
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function viewAny(User $user): bool
    {
        $tenantId = $this->tenantId();
        return $tenantId !== null && $this->authorization->hasPermission(
            permissionSlug: 'governance.election.view',
            user: $user,
            tenantId: $tenantId,
        );
    }

    public function view(User $user, GovernanceElection $election): bool
    {
        $tenantId = $this->tenantId();
        return $tenantId !== null
            && (int) $election->tenant_id === $tenantId
            && $this->authorization->hasPermission(
                permissionSlug: 'governance.election.view',
                user: $user,
                tenantId: $tenantId,
            );
    }

    public function create(User $user): bool
    {
        $tenantId = $this->tenantId();
        return $tenantId !== null && $this->authorization->hasPermission(
            permissionSlug: 'governance.election.manage',
            user: $user,
            tenantId: $tenantId,
        );
    }

    public function manage(User $user, GovernanceElection $election): bool
    {
        $tenantId = $this->tenantId();
        return $tenantId !== null
            && (int) $election->tenant_id === $tenantId
            && $this->authorization->hasPermission(
                permissionSlug: 'governance.election.manage',
                user: $user,
                tenantId: $tenantId,
            );
    }

    private function tenantId(): ?int
    {
        if (!function_exists('mases_tenant_id')) {
            return null;
        }
        $tenantId = mases_tenant_id();
        return $tenantId === null ? null : (int) $tenantId;
    }
}
