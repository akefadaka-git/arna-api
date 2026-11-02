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

    public function update(User $user, GovernanceElection $election): bool
    {
        return $this->manage($user, $election);
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

    /**
     * Committee membership, appointment and conflict-of-interest administration.
     */
    public function manageCommittee(User $user, GovernanceElection $election): bool
    {
        return $this->allowsElectionPermission($user, $election, 'governance.election.committee.manage');
    }

    /**
     * Freeze and administer the election-specific shareholder voter register.
     */
    public function manageRegister(User $user, GovernanceElection $election): bool
    {
        return $this->allowsElectionPermission($user, $election, 'governance.election.register.manage');
    }

    /**
     * Review nominations, candidate eligibility and objections.
     */
    public function manageNominations(User $user, GovernanceElection $election): bool
    {
        return $this->allowsElectionPermission($user, $election, 'governance.election.nomination.manage');
    }

    /**
     * Issue and custody physical ballots. Electronic voting is not covered.
     */
    public function issuePhysicalBallot(User $user, GovernanceElection $election): bool
    {
        return $this->allowsElectionPermission($user, $election, 'governance.election.ballot.issue');
    }

    /**
     * Record independent counts and reconciliation evidence.
     */
    public function manageCount(User $user, GovernanceElection $election): bool
    {
        return $this->allowsElectionPermission($user, $election, 'governance.election.count.manage');
    }

    /**
     * Administer complaints and appeals without granting result-certification power.
     */
    public function manageComplaints(User $user, GovernanceElection $election): bool
    {
        return $this->allowsElectionPermission($user, $election, 'governance.election.complaint.manage');
    }

    /**
     * Certify a result only for an explicitly authorized certification role.
     */
    public function certify(User $user, GovernanceElection $election): bool
    {
        return $this->allowsElectionPermission($user, $election, 'governance.election.certify');
    }

    private function allowsElectionPermission(User $user, GovernanceElection $election, string $permission): bool
    {
        $tenantId = $this->tenantId();

        return $tenantId !== null
            && (int) $election->tenant_id === $tenantId
            && $this->authorization->hasPermission(
                permissionSlug: $permission,
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
