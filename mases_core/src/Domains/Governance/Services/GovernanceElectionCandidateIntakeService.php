<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElectionCandidate;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElectionCandidateEvidence;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElectionCandidateEligibilityDecision;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElectionNomination;
use MaaleArna\MASES\Core\Files\Models\File;
use MaaleArna\MASES\Core\Files\Services\FileService;
use RuntimeException;

final class GovernanceElectionCandidateIntakeService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditService $audit,
        private readonly FileService $files,
        private readonly GovernanceElectionCommitteeSecurityService $committeeSecurity,
    ) {
    }

    /** @return array{candidate: GovernanceElectionCandidate, nomination: GovernanceElectionNomination} */
    public function submitNomination(
        int $tenantId,
        int $electionId,
        int $candidatePersonId,
        ?int $nominatorShareholderId,
        string $idempotencyKey,
        ?string $statement,
        User $actor,
    ): array {
        $this->authorize($tenantId, $actor, 'governance.election.nomination.submit');
        $this->assertIdempotencyKey($idempotencyKey);

        return DB::transaction(function () use (
            $tenantId, $electionId, $candidatePersonId, $nominatorShareholderId,
            $idempotencyKey, $statement, $actor,
        ): array {
            $election = $this->lockElection($tenantId, $electionId);

            // Idempotent retries return the original accepted record even if
            // the nomination window has since closed.
            $existingKey = GovernanceElectionNomination::query()
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($existingKey !== null) {
                if ((int) $existingKey->candidate->candidate_person_id !== $candidatePersonId
                    || (int) $existingKey->nominator_user_id !== (int) $actor->id
                    || ($nominatorShareholderId !== null && (int) $existingKey->nominator_shareholder_id !== $nominatorShareholderId)
                    || (string) ($existingKey->statement ?? '') !== (string) ($statement ?? '')) {
                    throw ValidationException::withMessages(['idempotency_key' => ['This idempotency key was already used for a different nomination payload.']]);
                }

                return ['candidate' => $existingKey->candidate, 'nomination' => $existingKey];
            }

            $this->assertNominationWindow($election);
            $actorPersonId = $this->resolveActorPerson($tenantId, $actor);
            $shareholder = $this->resolveNominator($tenantId, $actor, $nominatorShareholderId);
            [$candidateType] = $this->resolveCandidatePerson($tenantId, $candidatePersonId);

            $candidate = GovernanceElectionCandidate::query()
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)
                ->where('candidate_person_id', $candidatePersonId)->lockForUpdate()->first();

            if ($candidate === null) {
                $candidate = GovernanceElectionCandidate::query()->create([
                    'tenant_id' => $tenantId, 'election_id' => $electionId,
                    'candidate_person_id' => $candidatePersonId, 'candidate_type' => $candidateType,
                    'intake_status' => 'submitted', 'eligibility_status' => 'unassessed',
                ]);
            }

            if ($candidate->intake_status === 'withdrawn') {
                throw ValidationException::withMessages(['candidate_person_id' => ['A withdrawn candidate cannot receive a new nomination in this election.']]);
            }

            $priorNomination = GovernanceElectionNomination::query()
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)
                ->where('candidate_id', $candidate->id)->where('nominator_shareholder_id', $shareholder->id)
                ->orderByDesc('revision')->lockForUpdate()->first();
            if ($priorNomination !== null) {
                $message = $priorNomination->status === 'withdrawn'
                    ? 'A withdrawn nomination cannot be resubmitted by the same shareholder.'
                    : 'This shareholder already has a nomination for this candidate. Use the amendment action to correct it.';
                throw ValidationException::withMessages(['nomination' => [$message]]);
            }

            $selfNomination = $shareholder->shareholder_type === 'individual'
                && (int) ($shareholder->person_id ?? 0) === $candidatePersonId
                && (int) ($shareholder->user_id ?? 0) === (int) $actor->id
                && $actorPersonId === $candidatePersonId;

            $nomination = GovernanceElectionNomination::query()->create([
                'tenant_id' => $tenantId, 'election_id' => $electionId, 'candidate_id' => $candidate->id,
                'nominator_shareholder_id' => $shareholder->id, 'nominator_user_id' => $actor->id,
                'nominator_person_id' => $actorPersonId, 'self_nomination' => $selfNomination,
                'statement' => $statement, 'status' => 'submitted', 'revision' => 1,
                'idempotency_key' => $idempotencyKey, 'submitted_at' => now(),
            ]);

            $this->auditOrFail('governance.election.nomination.submitted', $tenantId, $actor, 'governance_election_nomination', $nomination->id, [
                'election_id' => $electionId, 'candidate_person_id' => $candidatePersonId,
                'nominator_shareholder_id' => (int) $shareholder->id, 'self_nomination' => $selfNomination, 'revision' => 1,
            ]);

            return ['candidate' => $candidate->fresh(), 'nomination' => $nomination->fresh()];
        }, 3);
    }

    public function amendNomination(
        int $tenantId, int $electionId, int $nominationId, string $idempotencyKey, ?string $statement, User $actor,
    ): GovernanceElectionNomination {
        $this->authorize($tenantId, $actor, 'governance.election.nomination.submit');
        $this->assertIdempotencyKey($idempotencyKey);

        return DB::transaction(function () use ($tenantId, $electionId, $nominationId, $idempotencyKey, $statement, $actor): GovernanceElectionNomination {
            $election = $this->lockElection($tenantId, $electionId);
            $existing = GovernanceElectionNomination::query()
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if ((int) $existing->supersedes_nomination_id === $nominationId
                    && (string) ($existing->statement ?? '') === (string) ($statement ?? '')
                    && (int) $existing->nominator_user_id === (int) $actor->id) {
                    return $existing;
                }
                throw ValidationException::withMessages(['idempotency_key' => ['This idempotency key was already used.']]);
            }

            $this->assertNominationWindow($election);
            $actorPersonId = $this->resolveActorPerson($tenantId, $actor);
            $nomination = GovernanceElectionNomination::query()
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)->lockForUpdate()->findOrFail($nominationId);
            $shareholder = $this->resolveNominator($tenantId, $actor, (int) $nomination->nominator_shareholder_id);

            if ((int) $nomination->nominator_user_id !== (int) $actor->id
                || (int) $nomination->nominator_person_id !== $actorPersonId
                || (int) $nomination->nominator_shareholder_id !== (int) $shareholder->id
                || $nomination->status !== 'submitted') {
                throw new AuthorizationException('Only the original active nominator may amend this nomination.');
            }

            $candidate = GovernanceElectionCandidate::query()
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)
                ->lockForUpdate()->findOrFail((int) $nomination->candidate_id);
            $replacement = GovernanceElectionNomination::query()->create([
                'tenant_id' => $tenantId, 'election_id' => $electionId, 'candidate_id' => $candidate->id,
                'nominator_shareholder_id' => $nomination->nominator_shareholder_id,
                'nominator_user_id' => $actor->id, 'nominator_person_id' => $actorPersonId,
                'self_nomination' => $nomination->self_nomination, 'statement' => $statement,
                'status' => 'submitted', 'revision' => (int) $nomination->revision + 1,
                'idempotency_key' => $idempotencyKey, 'supersedes_nomination_id' => $nomination->id,
                'submitted_at' => now(),
            ]);
            $nomination->forceFill(['status' => 'superseded'])->save();

            $this->auditOrFail('governance.election.nomination.amended', $tenantId, $actor, 'governance_election_nomination', $replacement->id, [
                'supersedes_nomination_id' => (int) $nomination->id,
                'revision' => (int) $replacement->revision, 'candidate_person_id' => (int) $candidate->candidate_person_id,
            ]);

            return $replacement->fresh();
        }, 3);
    }

    public function withdrawNomination(int $tenantId, int $electionId, int $nominationId, string $reason, User $actor): GovernanceElectionNomination
    {
        $this->authorize($tenantId, $actor, 'governance.election.nomination.submit');

        return DB::transaction(function () use ($tenantId, $electionId, $nominationId, $reason, $actor): GovernanceElectionNomination {
            $election = $this->lockElection($tenantId, $electionId);
            $this->assertNominationWindow($election);
            $actorPersonId = $this->resolveActorPerson($tenantId, $actor);
            $nomination = GovernanceElectionNomination::query()
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)->lockForUpdate()->findOrFail($nominationId);

            if ((int) $nomination->nominator_user_id !== (int) $actor->id
                || (int) $nomination->nominator_person_id !== $actorPersonId
                || $nomination->status !== 'submitted') {
                throw new AuthorizationException('Only the original active nominator may withdraw this nomination.');
            }
            if (mb_strlen(trim($reason)) < 8) {
                throw ValidationException::withMessages(['reason' => ['A withdrawal reason of at least 8 characters is required.']]);
            }

            $nomination->forceFill(['status' => 'withdrawn'])->save();
            $this->auditOrFail('governance.election.nomination.withdrawn', $tenantId, $actor, 'governance_election_nomination', $nomination->id, [
                'reason' => $reason, 'candidate_id' => (int) $nomination->candidate_id,
            ]);

            return $nomination->fresh();
        }, 3);
    }

    public function attachEvidence(
        int $tenantId, int $electionId, int $candidateId, int $fileId, string $evidenceCategory, User $actor,
    ): GovernanceElectionCandidateEvidence {
        $this->authorize($tenantId, $actor, 'governance.election.candidate.evidence.submit');

        return DB::transaction(function () use ($tenantId, $electionId, $candidateId, $fileId, $evidenceCategory, $actor): GovernanceElectionCandidateEvidence {
            $candidate = GovernanceElectionCandidate::query()
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)->lockForUpdate()->findOrFail($candidateId);
            if ($candidate->intake_status === 'withdrawn' || mb_strlen(trim($evidenceCategory)) < 2 || mb_strlen($evidenceCategory) > 64) {
                throw ValidationException::withMessages(['evidence_category' => ['A valid evidence category is required for an active candidate.']]);
            }

            $file = $this->files->find($fileId);
            if (!$file instanceof File || (int) $file->tenant_id !== $tenantId
                || $file->is_public || $file->status !== 'published' || $file->deleted_at !== null) {
                throw ValidationException::withMessages(['file_id' => ['Evidence must reference a published, private, non-deleted file in this tenant.']]);
            }

            $digest = $this->sha256File($file);
            $evidence = GovernanceElectionCandidateEvidence::query()->create([
                'tenant_id' => $tenantId, 'election_id' => $electionId, 'candidate_id' => $candidateId,
                'file_id' => $fileId, 'evidence_category' => trim($evidenceCategory),
                'content_sha256' => $digest, 'submitted_by' => $actor->id, 'status' => 'pending_review',
            ]);
            $this->auditOrFail('governance.election.candidate.evidence.submitted', $tenantId, $actor, 'governance_election_candidate_evidence', $evidence->id, [
                'candidate_id' => $candidateId, 'file_id' => $fileId,
                'evidence_category' => trim($evidenceCategory), 'content_sha256' => $digest,
            ]);

            return $evidence->fresh();
        }, 3);
    }

    public function reviewEvidence(
        int $tenantId, int $electionId, int $candidateId, int $evidenceId, string $decision, string $reason, User $actor,
    ): GovernanceElectionCandidateEvidence {
        $this->authorize($tenantId, $actor, 'governance.election.candidate.evidence.review');

        return DB::transaction(function () use ($tenantId, $electionId, $candidateId, $evidenceId, $decision, $reason, $actor): GovernanceElectionCandidateEvidence {
            $evidence = GovernanceElectionCandidateEvidence::query()
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)->where('candidate_id', $candidateId)
                ->lockForUpdate()->findOrFail($evidenceId);
            if ($evidence->status !== 'pending_review' || (int) $evidence->submitted_by === (int) $actor->id) {
                throw new AuthorizationException('Evidence review requires a pending record and a reviewer distinct from its submitter.');
            }
            if (!in_array($decision, ['accepted', 'rejected'], true) || mb_strlen(trim($reason)) < 10) {
                throw ValidationException::withMessages(['decision' => ['A valid evidence decision and reason are required.']]);
            }

            $evidence->forceFill(['status' => $decision, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_reason' => $reason])->save();
            $this->auditOrFail('governance.election.candidate.evidence.reviewed', $tenantId, $actor, 'governance_election_candidate_evidence', $evidence->id, [
                'candidate_id' => $candidateId, 'decision' => $decision, 'submitted_by' => (int) $evidence->submitted_by,
            ]);

            return $evidence->fresh();
        }, 3);
    }

    public function deferEligibility(int $tenantId, int $electionId, int $candidateId, string $reason, User $actor): GovernanceElectionCandidateEligibilityDecision
    {
        $this->authorize($tenantId, $actor, 'governance.election.candidate.screen');

        return DB::transaction(function () use ($tenantId, $electionId, $candidateId, $reason, $actor): GovernanceElectionCandidateEligibilityDecision {
            $candidate = GovernanceElectionCandidate::query()
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)->lockForUpdate()->findOrFail($candidateId);
            if (mb_strlen(trim($reason)) < 10 || $candidate->intake_status === 'withdrawn') {
                throw ValidationException::withMessages(['reason' => ['A reason of at least 10 characters is required and the candidate must remain active.']]);
            }

            $digests = GovernanceElectionCandidateEvidence::query()
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)
                ->where('candidate_id', $candidateId)->where('status', 'accepted')
                ->orderBy('id')->pluck('content_sha256')->all();
            $snapshotDigest = hash('sha256', json_encode($digests, JSON_THROW_ON_ERROR));

            $decision = GovernanceElectionCandidateEligibilityDecision::query()->create([
                'tenant_id' => $tenantId, 'election_id' => $electionId, 'candidate_id' => $candidateId,
                'decision' => 'deferred_unresolved', 'reason' => $reason,
                'evidence_snapshot_sha256' => $snapshotDigest, 'decided_by' => $actor->id, 'created_at' => now(),
            ]);
            $candidate->forceFill(['eligibility_status' => 'deferred_unresolved'])->save();

            $this->committeeSecurity->recordCandidateScreeningDecision(
                $tenantId, $electionId, (int) $candidate->candidate_person_id, (int) $decision->id, $reason, $actor,
            );
            $this->auditOrFail('governance.election.candidate.eligibility.deferred', $tenantId, $actor, 'governance_election_candidate', $candidate->id, [
                'decision_id' => (int) $decision->id, 'decision' => 'deferred_unresolved',
                'evidence_snapshot_sha256' => $snapshotDigest,
            ]);

            return $decision->fresh();
        }, 3);
    }

    private function lockElection(int $tenantId, int $electionId): GovernanceElection
    {
        return GovernanceElection::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($electionId);
    }

    private function assertNominationWindow(GovernanceElection $election): void
    {
        if (!$election->isAuthorityVerified() || $election->status !== 'nominating'
            || $election->nominations_open_at === null || $election->nominations_close_at === null) {
            throw ValidationException::withMessages(['election' => ['Verified authority and an open nomination window are required.']]);
        }
        $now = now();
        if ($now->lt(Carbon::parse($election->nominations_open_at)) || $now->gt(Carbon::parse($election->nominations_close_at))) {
            throw ValidationException::withMessages(['election' => ['The nomination window is not currently open.']]);
        }
    }

    private function resolveActorPerson(int $tenantId, User $actor): int
    {
        $people = DB::table('people')->where('user_id', $actor->id)->where('status', 'active')
            ->whereNull('deleted_at')->limit(2)->get(['id']);
        if ($people->count() !== 1) {
            throw ValidationException::withMessages(['nominator' => ['The authenticated account must resolve to exactly one active canonical Person.']]);
        }
        $personId = (int) $people->first()->id;
        $access = DB::table('person_tenant_accesses')->where('person_id', $personId)
            ->where('tenant_id', $tenantId)->where('status', 'active')->exists();
        $holder = DB::table('shareholders')->where('tenant_id', $tenantId)->where('person_id', $personId)
            ->where('shareholder_type', 'individual')->where('is_active', true)->whereNull('deleted_at')->exists();
        if (!$access && !$holder) {
            throw ValidationException::withMessages(['nominator' => ['The authenticated Person has no active access or shareholder relationship in this tenant.']]);
        }
        return $personId;
    }

    private function resolveNominator(int $tenantId, User $actor, ?int $shareholderId): object
    {
        $query = DB::table('shareholders')->where('tenant_id', $tenantId)->where('is_active', true)
            ->whereNull('deleted_at')->where(function ($builder) use ($actor): void {
                $builder->where('user_id', $actor->id)->orWhere('representative_user_id', $actor->id);
            });
        if ($shareholderId !== null) $query->where('id', $shareholderId);
        $matches = $query->limit(2)->get(['id', 'shareholder_type', 'person_id', 'user_id', 'representative_user_id']);
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['nominator_shareholder_id' => ['The authenticated account must resolve to exactly one active shareholder identity; select one explicitly if it represents multiple holders.']]);
        }
        return $matches->first();
    }

    /** @return array{0: string} */
    private function resolveCandidatePerson(int $tenantId, int $personId): array
    {
        $person = DB::table('people')->where('id', $personId)->where('status', 'active')->whereNull('deleted_at')->first(['id']);
        if ($person === null) {
            throw ValidationException::withMessages(['candidate_person_id' => ['Candidate must resolve to an active canonical Person.']]);
        }
        $holder = DB::table('shareholders')->where('tenant_id', $tenantId)->where('person_id', $personId)
            ->where('shareholder_type', 'individual')->where('is_active', true)->whereNull('deleted_at')->exists();
        $access = DB::table('person_tenant_accesses')->where('person_id', $personId)
            ->where('tenant_id', $tenantId)->where('status', 'active')->exists();
        if (!$holder && !$access) {
            throw ValidationException::withMessages(['candidate_person_id' => ['Candidate must have an active shareholder relationship or active Person tenant access.']]);
        }
        return [$holder ? 'shareholder' : 'non_shareholder'];
    }

    private function authorize(int $tenantId, User $actor, string $permission): void
    {
        $member = $actor->activeTenants()->where('tenant_id', $tenantId)->exists();
        if ($tenantId < 1 || !$actor->isActive() || !$member
            || !$this->authorization->hasPermission(permissionSlug: $permission, user: $actor, tenantId: $tenantId)) {
            throw new AuthorizationException('The requested candidate workflow permission is not available in this tenant.');
        }
    }

    private function assertIdempotencyKey(string $key): void
    {
        if (trim($key) === '' || strlen($key) > 128) {
            throw ValidationException::withMessages(['idempotency_key' => ['A non-empty idempotency key of at most 128 characters is required.']]);
        }
    }

    private function sha256File(File $file): string
    {
        $stream = Storage::disk($file->disk)->readStream($file->path);
        if (!is_resource($stream)) throw new RuntimeException('The candidate evidence file could not be read for integrity verification.');
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);
        return hash_final($context);
    }

    private function auditOrFail(string $action, int $tenantId, User $actor, string $resourceType, int $resourceId, array $metadata): void
    {
        $entry = $this->audit->log(
            action: $action, data: $metadata, user: $actor, tenantId: $tenantId,
            resourceType: $resourceType, resourceId: (string) $resourceId,
        );
        if ($entry === null) {
            throw new RuntimeException('Required candidate workflow audit record could not be persisted; transaction rolled back.');
        }
    }
}
