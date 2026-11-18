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
        $this->assertElectionTenant($tenantId, $electionId);
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

        $targetUser = DB::table('users')->where('id', $userId)->where('is_active', true)->whereNull('deleted_at')->exists();
        if (!$targetUser) {
            throw ValidationException::withMessages(['user_id' => 'The linked user account must be active.']);
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
        $this->assertElectionTenant($tenantId, $electionId);
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
        $this->assertElectionTenant($tenantId, $electionId);
        if ($tenantId < 1 || $electionId < 1 || !$this->authorization->hasPermission(
            permissionSlug: 'governance.election.committee.conflict.declare',
            user: $actor,
            tenantId: $tenantId,
        )) {
            throw new AuthorizationException('Committee conflict-declaration permission is required in this tenant.');
        }

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

    public function recordMemberAppointment(
        int $tenantId,
        int $electionId,
        int $memberId,
        int $personId,
        int $userId,
        int $evidenceId,
        string $appointmentReference,
        string $memberRole,
        bool $exOfficio,
        string $appointedAt,
        User $actor,
    ): void {
        $this->assertElectionTenant($tenantId, $electionId);
        if (!$this->authorization->hasPermission(
            permissionSlug: 'governance.election.committee.appoint',
            user: $actor,
            tenantId: $tenantId,
        )) {
            throw new AuthorizationException('Committee appointment permission is required in this tenant.');
        }

        $this->lockTenant($tenantId);
        $this->appendEvent(
            $tenantId,
            $electionId,
            (int) $actor->getAuthIdentifier(),
            'committee_member.appointed',
            [
                'committee_member_id' => $memberId,
                'appointment_evidence_id' => $evidenceId,
                'appointment_reference' => trim($appointmentReference),
                'member_role' => trim($memberRole),
                'ex_officio' => $exOfficio,
                'appointed_at' => $appointedAt,
            ],
            $userId,
            $personId,
            trim($appointmentReference),
        );
    }

    public function recordMemberRevocation(
        int $tenantId,
        int $electionId,
        int $memberId,
        int $personId,
        int $userId,
        string $reason,
        User $actor,
    ): void {
        $this->assertElectionTenant($tenantId, $electionId);
        if (!$this->authorization->hasPermission(
            permissionSlug: 'governance.election.committee.revoke',
            user: $actor,
            tenantId: $tenantId,
        )) {
            throw new AuthorizationException('Committee appointment-revocation permission is required in this tenant.');
        }

        $this->lockTenant($tenantId);
        $this->appendEvent(
            $tenantId,
            $electionId,
            (int) $actor->getAuthIdentifier(),
            'committee_member.revoked',
            [
                'committee_member_id' => $memberId,
                'reason' => trim($reason),
            ],
            $userId,
            $personId,
            'committee-member:' . $memberId,
        );
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
        $this->assertElectionTenant($tenantId, $electionId);
        if ($tenantId < 1 || $electionId < 1 || $candidatePersonId < 1 || !$this->authorization->hasPermission(
            permissionSlug: 'governance.election.committee.decide',
            user: $actor,
            tenantId: $tenantId,
        )) {
            throw new AuthorizationException('Committee decision permission is required in this tenant.');
        }

        $member = DB::table('governance_election_committee_members')
            ->where('tenant_id', $tenantId)
            ->where('election_id', $electionId)
            ->where('user_id', $actorId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if ($member === null || $member->member_role !== 'committee_member') {
            throw new AuthorizationException('An active voting committee-member appointment is required.');
        }

        $canonicalPerson = DB::table('people')
            ->where('id', $member->person_id)
            ->where('user_id', $actorId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->exists();
        $tenantAccess = DB::table('person_tenant_accesses')
            ->where('person_id', $member->person_id)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->exists();
        if (!$canonicalPerson || !$tenantAccess) {
            throw new AuthorizationException('Committee member identity or tenant access is no longer valid.');
        }

        if ((int) ($member->person_id ?? 0) === $candidatePersonId) {
            throw new AuthorizationException('A candidate cannot participate in the decision concerning their own candidacy.');
        }

        $recusal = (string) ($member->recusal_scope ?? '');
        if ($member->recused_at !== null && (
            preg_match('/^candidate:[1-9][0-9]*$/', $recusal) !== 1
            || $recusal === 'candidate:' . $candidatePersonId
        )) {
            // Legacy or incomplete recusal records with no scope fail closed.
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

    /**
     * Verify the complete per-election event chain from sequence 1.
     *
     * A successful result proves consistency with the stored chain, not
     * protection against a database owner who can rewrite every row and hash.
     *
     * @return array{valid: bool, verified_events: int, first_invalid_sequence: int|null}
     */
    public function verifySecurityEventChain(int $tenantId, int $electionId): array
    {
        if ($tenantId < 1 || $electionId < 1) {
            throw new RuntimeException('A valid tenant and election are required to verify the security event chain.');
        }

        $events = DB::table('governance_election_committee_security_events')
            ->where('tenant_id', $tenantId)
            ->where('election_id', $electionId)
            ->orderBy('sequence')
            ->get();

        $expectedSequence = 1;
        $previousHash = null;

        foreach ($events as $event) {
            if ((int) $event->sequence !== $expectedSequence || $event->previous_hash !== $previousHash) {
                return [
                    'valid' => false,
                    'verified_events' => $expectedSequence - 1,
                    'first_invalid_sequence' => (int) $event->sequence,
                ];
            }

            $payload = json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR);
            $hashInput = [
                'tenant_id' => (int) $event->tenant_id,
                'election_id' => (int) $event->election_id,
                'sequence' => (int) $event->sequence,
                'event_type' => (string) $event->event_type,
                'actor_user_id' => (int) $event->actor_user_id,
                'subject_user_id' => $event->subject_user_id === null ? null : (int) $event->subject_user_id,
                'subject_person_id' => $event->subject_person_id === null ? null : (int) $event->subject_person_id,
                'reference' => $event->reference,
                'payload' => $this->canonicalize($payload),
                'previous_hash' => $event->previous_hash,
                'occurred_at' => (string) $event->occurred_at,
            ];
            $expectedHash = hash('sha256', json_encode(
                $hashInput,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));

            if (!hash_equals((string) $event->event_hash, $expectedHash)) {
                return [
                    'valid' => false,
                    'verified_events' => $expectedSequence - 1,
                    'first_invalid_sequence' => (int) $event->sequence,
                ];
            }

            $previousHash = (string) $event->event_hash;
            $expectedSequence++;
        }

        return [
            'valid' => true,
            'verified_events' => $expectedSequence - 1,
            'first_invalid_sequence' => null,
        ];
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

    private function assertElectionTenant(int $tenantId, int $electionId): void
    {
        if ($tenantId < 1 || $electionId < 1 || !DB::table('governance_elections')
            ->where('tenant_id', $tenantId)
            ->where('id', $electionId)
            ->exists()) {
            throw new AuthorizationException('The election is not available in the resolved tenant.');
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
    /**
     * Append candidate screening to the existing committee security chain.
     * The caller invokes this inside the candidate-decision transaction.
     */
    public function recordCandidateScreeningDecision(
        int $tenantId,
        int $electionId,
        int $candidatePersonId,
        int $decisionId,
        string $reason,
        User $actor,
    ): void {
        $this->assertElectionTenant($tenantId, $electionId);
        $this->lockTenant($tenantId);
        $this->assertMayDecide($tenantId, $electionId, $candidatePersonId, $actor);

        $this->appendEvent(
            $tenantId,
            $electionId,
            (int) $actor->getAuthIdentifier(),
            'candidate.screening.deferred_unresolved',
            [
                'decision_id' => $decisionId,
                'decision' => 'deferred_unresolved',
                'reason_sha256' => hash('sha256', $reason),
            ],
            null,
            $candidatePersonId,
            (string) $decisionId,
        );
    }

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
