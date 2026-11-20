<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Auth\Models\User;
use RuntimeException;

final class GovernanceElectionCommitteeAppointmentService
{
    public function __construct(
        private readonly GovernanceElectionCommitteeSecurityService $security,
        private readonly AuditService $audit,
    ) {
    }

    public function appoint(
        int $tenantId,
        int $electionId,
        int $personId,
        int $userId,
        int $evidenceId,
        string $appointmentReference,
        string $memberRole,
        bool $exOfficio,
        string $appointedAt,
        User $actor,
    ): object {
        $this->authorize($actor, $tenantId, 'governance.election.committee.appoint');
        if (trim($appointmentReference) === '' || mb_strlen($appointmentReference) > 160) {
            throw ValidationException::withMessages(['appointment_reference' => 'A non-empty appointment reference of at most 160 characters is required.']);
        }
        if (trim($memberRole) === '' || mb_strlen($memberRole) > 32) {
            throw ValidationException::withMessages(['member_role' => 'A non-empty member role of at most 32 characters is required.']);
        }

        return DB::transaction(function () use (
            $tenantId, $electionId, $personId, $userId, $evidenceId,
            $appointmentReference, $memberRole, $exOfficio, $appointedAt, $actor
        ): object {
            $this->lockTenant($tenantId);
            $election = DB::table('governance_elections')
                ->where('tenant_id', $tenantId)->where('id', $electionId)
                ->lockForUpdate()->first();
            if ($election === null) {
                throw new AuthorizationException('Election is not available in this tenant.');
            }
            if (
                $election->status !== 'authorized'
                || $election->authority_evidence_id === null
                || $election->authority_verified_by === null
                || $election->authority_verified_at === null
            ) {
                throw ValidationException::withMessages(['election' => 'Committee appointments require verified election authority.']);
            }

            $person = DB::table('people')->where('id', $personId)
                ->where('user_id', $userId)->where('status', 'active')
                ->whereNull('deleted_at')->first(['id']);
            if ($person === null) {
                throw ValidationException::withMessages(['person_id' => 'The person must be the active canonical Person linked to the supplied account.']);
            }
            $activeUser = DB::table('users')->where('id', $userId)
                ->where('is_active', true)->whereNull('deleted_at')->exists();
            if (!$activeUser) {
                throw ValidationException::withMessages(['user_id' => 'The linked user account must be active.']);
            }
            $tenantAccess = DB::table('person_tenant_accesses')->where('person_id', $personId)
                ->where('tenant_id', $tenantId)->where('status', 'active')->exists();
            if (!$tenantAccess) {
                throw ValidationException::withMessages(['person_id' => 'The canonical Person must have active access to this tenant.']);
            }

            $evidence = DB::table('governance_election_authority_evidence')
                ->where('id', $evidenceId)->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)
                ->where('id', (int) $election->authority_evidence_id)
                ->where('status', 'verified')
                ->where('verified_by', (int) $election->authority_verified_by)
                ->whereNotNull('verified_by')
                ->first(['id']);
            if ($evidence === null) {
                throw ValidationException::withMessages(['appointment_evidence_id' => 'Appointment evidence must be verified and belong to this election and tenant.']);
            }

            $existing = DB::table('governance_election_committee_members')
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)
                ->where('user_id', $userId)->where('status', 'active')
                ->lockForUpdate()->exists();
            if ($existing) {
                throw ValidationException::withMessages(['user_id' => 'This account already has an active committee appointment for the election.']);
            }

            $id = DB::table('governance_election_committee_members')->insertGetId([
                'tenant_id' => $tenantId,
                'election_id' => $electionId,
                'person_id' => $personId,
                'user_id' => $userId,
                'member_role' => trim($memberRole),
                'ex_officio' => $exOfficio,
                'status' => 'active',
                'appointed_at' => $appointedAt,
                'recused_at' => null,
                'recusal_scope' => null,
                'appointment_reference' => trim($appointmentReference),
                'appointment_evidence_id' => $evidenceId,
                'appointed_by' => (int) $actor->getAuthIdentifier(),
                'revoked_by' => null,
                'revoked_at' => null,
                'revocation_reason' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->security->recordMemberAppointment(
                $tenantId, $electionId, $id, $personId, $userId, $evidenceId,
                $appointmentReference, $memberRole, $exOfficio, $appointedAt, $actor,
            );
            $this->auditOrFail('governance.election.committee.member.appointed', $tenantId, $actor, $id, [
                'person_id' => $personId, 'user_id' => $userId,
                'appointment_evidence_id' => $evidenceId,
                'appointment_reference' => trim($appointmentReference),
                'member_role' => trim($memberRole), 'ex_officio' => $exOfficio,
            ]);

            return DB::table('governance_election_committee_members')->where('id', $id)->first();
        }, 3);
    }

    public function revoke(
        int $tenantId,
        int $electionId,
        int $memberId,
        string $reason,
        User $actor,
    ): object {
        $this->authorize($actor, $tenantId, 'governance.election.committee.revoke');
        if (trim($reason) === '' || mb_strlen($reason) < 5 || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'A reason between 5 and 2000 characters is required.']);
        }

        return DB::transaction(function () use ($tenantId, $electionId, $memberId, $reason, $actor): object {
            $this->lockTenant($tenantId);
            $member = DB::table('governance_election_committee_members')
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)
                ->where('id', $memberId)->lockForUpdate()->first();
            if ($member === null) {
                throw new AuthorizationException('Committee appointment is not available in this tenant.');
            }
            if ($member->status !== 'active') {
                throw ValidationException::withMessages(['member' => 'Only an active committee appointment can be revoked.']);
            }

            DB::table('governance_election_committee_members')->where('id', $memberId)->update([
                'status' => 'revoked',
                'revoked_by' => (int) $actor->getAuthIdentifier(),
                'revoked_at' => now(),
                'revocation_reason' => trim($reason),
                'updated_at' => now(),
            ]);

            $this->security->recordMemberRevocation(
                $tenantId, $electionId, $memberId, (int) $member->person_id,
                (int) $member->user_id, $reason, $actor,
            );
            $this->auditOrFail('governance.election.committee.member.revoked', $tenantId, $actor, $memberId, [
                'person_id' => (int) $member->person_id,
                'user_id' => (int) $member->user_id,
                'reason' => trim($reason),
            ]);

            return DB::table('governance_election_committee_members')->where('id', $memberId)->first();
        }, 3);
    }

    public function list(int $tenantId, int $electionId, User $actor): array
    {
        $this->authorize($actor, $tenantId, 'governance.election.committee.view');
        $this->assertElection($tenantId, $electionId);
        return DB::table('governance_election_committee_members')
            ->where('tenant_id', $tenantId)->where('election_id', $electionId)
            ->orderBy('id')->get()->all();
    }

    private function authorize(User $actor, int $tenantId, string $permission): void
    {
        if ($tenantId < 1 || !app(\MaaleArna\MASES\Core\Authorization\Services\AuthorizationService::class)->hasPermission(
            permissionSlug: $permission, user: $actor, tenantId: $tenantId,
        )) {
            throw new AuthorizationException('The required committee permission is not granted in this tenant.');
        }
    }

    private function assertElection(int $tenantId, int $electionId): void
    {
        if (!DB::table('governance_elections')->where('tenant_id', $tenantId)->where('id', $electionId)->exists()) {
            throw new AuthorizationException('Election is not available in this tenant.');
        }
    }

    private function lockTenant(int $tenantId): void
    {
        if (DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first(['id']) === null) {
            throw new AuthorizationException('Tenant is not available.');
        }
    }

    private function auditOrFail(string $action, int $tenantId, User $actor, int $memberId, array $metadata): void
    {
        $entry = $this->audit->log(
            action: $action, data: $metadata, user: $actor, tenantId: $tenantId,
            resourceType: 'governance_election_committee_member', resourceId: (string) $memberId,
        );
        if ($entry === null) {
            throw new RuntimeException('Required committee appointment audit record could not be persisted; transaction rolled back.');
        }
    }
}
