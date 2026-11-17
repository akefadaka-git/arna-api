<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use RuntimeException;

/**
 * Security controls for election committee administration.
 *
 * The service is tenant-scoped, derives actors from the authenticated User
 * supplied by the controller, and writes a hash-chained security event in
 * the same transaction as each sensitive mutation. It never determines
 * shareholder voting weight, candidate legal qualification, appeal merits,
 * election tally, certification, or ratification.
 */
final class GovernanceElectionCommitteeSecurityService
{
    private const ADMIN_PERMISSION = 'governance.election.committee.administer';

    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * Grant election-scoped committee-administrator authority.
     *
     * The target must resolve to one canonical active Person/account link and
     * active tenant access. No role or global administrator grant is created.
     */
    public function grantAdministrator(
        int $tenantId,
        int $electionId,
        int $personId,
        int $userId,
        string $appointmentReference,
        User $actor,
    ): object {
        $this->assertAdministrator($actor, $tenantId);
        $actorId = (int) $actor->getAuthIdentifier();

        if ($tenantId < 1 || $electionId < 1 || $personId < 1 || $userId < 1) {
            throw ValidationException::withMessages(['identity' => 'Tenant, election, Person and user identifiers must be positive.']);
        }

        if (trim($appointmentReference) === '' || mb_strlen($appointmentReference) > 160) {
            throw ValidationException::withMessages(['appointment_reference' => 'A valid appointment reference is required.']);
        }

        $person = DB::table('people')->where('id', $personId)->whereNull('deleted_at')->first(['id', 'user_id']);
        if ($person === null || (int) ($person->user_id ?? 0) !== $userId) {
            throw ValidationException::withMessages(['person_id' => 'The administrator must resolve to the canonical Person linked to the supplied account.']);
        }

        $hasTenantAccess = DB::table('person_tenant_accesses')
            ->where('person_id', $personId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->exists();

        if (!$hasTenantAccess) {
            throw ValidationException::withMessages(['person_id' => 'The canonical Person must have active access to this tenant.']);
        }

        return DB::transaction(function () use (
            $tenantId, $electionId, $personId, $userId, $appointmentReference, $actorId
        ): object {
            $this->lockTenant($tenantId);

            $existing = DB::table('governance_election_committee_admins')
                ->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->status === 'active') {
                throw ValidationException::withMessages(['user_id' => 'This account already has active administrator authority for the election.']);
            }

            if ($existing === null) {
                $id = DB::table('governance_election_committee_admins')->insertGetId([
                    'tenant_id' => $tenantId,
                    'election_id' => $electionId,
                    'person_id' => $personId,
                    'user_id' => $userId,
                    'appointment_reference' => trim($appointmentReference),
                    'granted_by' => $actorId,
                    'granted_at' => now(),
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $id = (int) $existing->id;
                DB::table('governance_election_committee_admins')->where('id', $id)->update([
                    'person_id' => $personId,
                    'appointment_reference' => trim($appointmentReference),
                    'granted_by' => $actorId,
                    'granted_at' => now(),
                    'revoked_by' => null,
                    'revoked_at' => null,
                    'status' => 'active',
                    'updated_at' => now(),
                ]);
            }

            $this->appendEvent($tenantId, $electionId, $actorId, 'committee_admin.granted', [
                'subject_user_id' => $userId,
                'subject_person_id' => $personId,
                'appointment_reference' => trim($appointmentReference),
            ], $userId, $personId, trim($appointmentReference));

            return DB::table('governance_election_committee_admins')->where('id', $id)->first();
        }, 3);
    }

    public function revokeAdministrator(
        int $tenantId,
        int $electionId,
        int $userId,
        User $actor,
        string $reason,
    ): void {
        $this->assertAdministrator($actor, $tenantId);
        $actorId = (int) $actor->getAuthIdentifier();

        if (trim($reason) === '' || mb_strlen($reason) < 5 || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'A reason between 5 and 2000 characters is required.']);
        }

        DB::transaction(function () use ($tenantId, $electionId, $userId, $actorId, $reason): void {
            $this->lockTenant($tenantId);
            $admin = DB::table('governance_election_committee_admins')
                ->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($admin === null || $admin->status !== 'active') {
                throw ValidationException::withMessages(['user_id' => 'No active committee-administrator authority exists for this account.']);
            }

            DB::table('governance_election_committee_admins')->where('id', $admin->id)->update([
                'status' => 'revoked',
                'revoked_by' => $actorId,
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);

            $this->appendEvent($tenantId, $electionId, $actorId, 'committee_admin.revoked', [
                'subject_user_id' => $userId,
                'reason' => trim($reason),
            ], $userId, (int) $admin->person_id, null);
        }, 3);
    }

    /**
     * Record a declared conflict and immediately apply a scoped recusal.
     *
     * Immediate-family conflicts require a named subject Person. A conflict
     * declared for the whole election is scoped to "all"; otherwise it is
     * limited to the identified candidate. The method does not decide the
     * legal consequence of the conflict beyond preventing participation in
     * the declared scope.
     */
    public function declareConflict(
        int $tenantId,
        int $electionId,
        int $subjectPersonId,
        string $conflictType,
        string $scope,
        string $disclosure,
        ?string $evidenceReference,
        User $actor,
    ): object {
        $actorId = (int) $actor->getAuthIdentifier();

        if (!in_array($conflictType, ['self', 'immediate_family', 'financial', 'professional', 'personal', 'other'], true)) {
            throw ValidationException::withMessages(['conflict_type' => 'Unsupported conflict category.']);
        }
        if (!in_array($scope, ['candidate', 'all'], true)) {
            throw ValidationException::withMessages(['scope' => 'Conflict scope must be candidate or all.']);
        }
        if ($scope === 'candidate' && $subjectPersonId < 1) {
            throw ValidationException::withMessages(['subject_person_id' => 'A candidate Person is required for candidate-scoped conflicts.']);
        }
        if ($conflictType === 'immediate_family' && $subjectPersonId < 1) {
            throw ValidationException::withMessages(['subject_person_id' => 'Immediate-family conflicts require the affected candidate Person.']);
        }
        if (trim($disclosure) === '' || mb_strlen($disclosure) < 10 || mb_strlen($disclosure) > 5000) {
            throw ValidationException::withMessages(['disclosure' => 'A reasoned disclosure between 10 and 5000 characters is required.']);
        }
        if ($evidenceReference !== null && mb_strlen($evidenceReference) > 160) {
            throw ValidationException::withMessages(['evidence_reference' => 'Evidence reference may not exceed 160 characters.']);
        }

        return DB::transaction(function () use (
            $tenantId, $electionId, $subjectPersonId, $conflictType, $scope,
            $disclosure, $evidenceReference, $actorId
        ): object {
            $this->lockTenant($tenantId);

            $member = DB::table('governance_election_committee_members')
                ->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)
                ->where('user_id', $actorId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($member === null || $member->member_role !== 'committee_member') {
                throw new AuthorizationException('An active voting committee-member appointment is required to declare this conflict.');
            }

            $recusalScope = $scope === 'all' ? 'all' : 'candidate:' . $subjectPersonId;
            if (mb_strlen($recusalScope) > 80) {
                throw ValidationException::withMessages(['scope' => 'The requested recusal scope cannot be represented safely.']);
            }

            $id = DB::table('governance_election_committee_conflicts')->insertGetId([
                'tenant_id' => $tenantId,
                'election_id' => $electionId,
                'committee_user_id' => $actorId,
                'committee_person_id' => (int) $member->person_id,
                'subject_person_id' => $scope === 'all' ? null : $subjectPersonId,
                'conflict_type' => $conflictType,
                'scope' => $scope,
                'disclosure' => trim($disclosure),
                'evidence_reference' => $evidenceReference,
                'declared_at' => now(),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('governance_election_committee_members')
                ->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)
                ->where('id', $member->id)
                ->update([
                    'recused_at' => now(),
                    'recusal_scope' => $recusalScope,
                    'updated_at' => now(),
                ]);

            $this->appendEvent($tenantId, $electionId, $actorId, 'committee_conflict.declared', [
                'conflict_id' => $id,
                'committee_person_id' => (int) $member->person_id,
                'subject_person_id' => $scope === 'all' ? null : $subjectPersonId,
                'conflict_type' => $conflictType,
                'scope' => $scope,
                'evidence_reference' => $evidenceReference,
            ], $actorId, (int) $member->person_id, $evidenceReference);

            return DB::table('governance_election_committee_conflicts')->where('id', $id)->first();
        }, 3);
    }

    /**
     * Fail closed when the actor has an all-election or candidate-scoped
     * active recusal/conflict. Controllers call this before recording votes.
     */
    public function assertMayDecide(
        int $tenantId,
        int $electionId,
        int $candidatePersonId,
        User $actor,
    ): void {
        $actorId = (int) $actor->getAuthIdentifier();
        $member = DB::table('governance_election_committee_members')
            ->where('tenant_id', $tenantId)
            ->where('election_id', $electionId)
            ->where('user_id', $actorId)
            ->where('status', 'active')
            ->first();

        if ($member === null || $member->member_role !== 'committee_member') {
            throw new AuthorizationException('An active voting committee-member appointment is required.');
        }

        $recusal = (string) ($member->recusal_scope ?? '');
        if ($member->recused_at !== null && ($recusal === 'all' || $recusal === 'candidate:' . $candidatePersonId)) {
            throw new AuthorizationException('The committee member is recused from this decision.');
        }

        $conflict = DB::table('governance_election_committee_conflicts')
            ->where('tenant_id', $tenantId)
            ->where('election_id', $electionId)
            ->where('committee_user_id', $actorId)
            ->where('status', 'active')
            ->where(function ($query) use ($candidatePersonId): void {
                $query->where('scope', 'all')
                    ->orWhere(function ($candidate) use ($candidatePersonId): void {
                        $candidate->where('scope', 'candidate')
                            ->where('subject_person_id', $candidatePersonId);
                    });
            })
            ->exists();

        if ($conflict) {
            throw new AuthorizationException('An active conflict of interest prevents this committee decision.');
        }
    }

    private function assertAdministrator(User $actor, int $tenantId): void
    {
        if ($tenantId < 1 || !$this->authorization->hasPermission(
            permissionSlug: self::ADMIN_PERMISSION,
            user: $actor,
            tenantId: $tenantId,
        )) {
            throw new AuthorizationException('Election committee administrator authority is required in this tenant.');
        }
    }

    private function lockTenant(int $tenantId): void
    {
        $tenant = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first(['id']);
        if ($tenant === null) {
            throw new RuntimeException('Committee security operation has no valid tenant row.');
        }
    }

    /**
     * Append a tamper-evident event in the caller's transaction.
     * Locking the tenant row serializes sequence allocation for all election
     * security events within that tenant.
     *
     * @param array<string, mixed> $payload
     */
    private function appendEvent(
        int $tenantId,
        int $electionId,
        int $actorId,
        string $eventType,
        array $payload,
        ?int $subjectUserId = null,
        ?int $subjectPersonId = null,
        ?string $reference = null,
    ): void {
        $last = DB::table('governance_election_committee_security_events')
            ->where('tenant_id', $tenantId)
            ->where('election_id', $electionId)
            ->orderByDesc('sequence')
            ->first(['sequence', 'event_hash']);

        $sequence = $last === null ? 1 : ((int) $last->sequence + 1);
        $previousHash = $last->event_hash ?? null;
        $occurredAt = now()->toDateTimeString();
        $canonicalPayload = $this->canonicalize($payload);

        $hashInput = [
            'tenant_id' => $tenantId,
            'election_id' => $electionId,
            'sequence' => $sequence,
            'event_type' => $eventType,
            'actor_user_id' => $actorId,
            'subject_user_id' => $subjectUserId,
            'subject_person_id' => $subjectPersonId,
            'reference' => $reference,
            'payload' => $canonicalPayload,
            'previous_hash' => $previousHash,
            'occurred_at' => $occurredAt,
        ];
        $eventHash = hash('sha256', json_encode(
            $hashInput,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        DB::table('governance_election_committee_security_events')->insert([
            'tenant_id' => $tenantId,
            'election_id' => $electionId,
            'sequence' => $sequence,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => $eventType,
            'actor_user_id' => $actorId,
            'subject_user_id' => $subjectUserId,
            'subject_person_id' => $subjectPersonId,
            'reference' => $reference,
            'payload' => json_encode($canonicalPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'previous_hash' => $previousHash,
            'event_hash' => $eventHash,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ]);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
