<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\FileManagement\Services\FileService;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElectionAuthorityEvidence;
use MaaleArna\MASES\Core\Exceptions\FileException;
use RuntimeException;

final class GovernanceElectionAuthorityService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditService $audit,
        private readonly FileService $files,
    ) {
    }

    public function listEvidence(int $tenantId, int $electionId, User $actor): array
    {
        $this->authorize($actor, $tenantId, 'governance.election.view');
        $this->election($tenantId, $electionId);

        return GovernanceElectionAuthorityEvidence::query()
            ->where('tenant_id', $tenantId)
            ->where('election_id', $electionId)
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    public function recordAuthorityEvidence(
        int $tenantId,
        int $electionId,
        string $instrumentType,
        string $instrumentReference,
        ?string $instrumentDate,
        int $documentId,
        User $actor,
    ): GovernanceElectionAuthorityEvidence {
        $this->authorize($actor, $tenantId, 'governance.election.manage');

        if (trim($instrumentType) === '' || mb_strlen($instrumentType) > 48
            || trim($instrumentReference) === '' || mb_strlen($instrumentReference) > 180) {
            throw ValidationException::withMessages(['instrument_reference' => 'A valid instrument type and reference are required.']);
        }

        if ($instrumentDate !== null) {
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $instrumentDate)) {
                throw ValidationException::withMessages(['instrument_date' => 'Use a valid ISO calendar date (YYYY-MM-DD).']);
            }
            [$year, $month, $day] = array_map('intval', explode('-', $instrumentDate));
            if (!checkdate($month, $day, $year)) {
                throw ValidationException::withMessages(['instrument_date' => 'The instrument date is not a valid calendar date.']);
            }
        }

        [$document, $digest] = $this->publishedDocument($tenantId, $documentId, $actor);

        return DB::transaction(function () use (
            $tenantId, $electionId, $instrumentType, $instrumentReference,
            $instrumentDate, $document, $digest, $actor
        ): GovernanceElectionAuthorityEvidence {
            $election = $this->election($tenantId, $electionId, true);
            if ($election->status !== 'draft' || $election->authority_verified_at !== null) {
                throw ValidationException::withMessages(['election' => 'Authority evidence can only be recorded before the election is authorized.']);
            }

            $evidence = GovernanceElectionAuthorityEvidence::query()->create([
                'tenant_id' => $tenantId,
                'election_id' => $electionId,
                'instrument_type' => trim($instrumentType),
                'instrument_reference' => trim($instrumentReference),
                'instrument_date' => $instrumentDate,
                'document_id' => (int) $document->getKey(),
                'storage_reference' => 'file-management:' . (int) $document->getKey(),
                'content_sha256' => $digest,
                'status' => 'pending_review',
                'recorded_by' => (int) $actor->getAuthIdentifier(),
            ]);

            $this->auditOrFail('governance.election.authority_evidence.recorded', $tenantId, $actor, $election, [
                'evidence_id' => (int) $evidence->getKey(),
                'document_id' => (int) $document->getKey(),
                'content_sha256' => $digest,
                'instrument_reference' => trim($instrumentReference),
                'status' => 'pending_review',
            ]);

            return $evidence->fresh();
        }, 3);
    }

    public function reviewAuthorityEvidence(
        int $tenantId,
        int $electionId,
        int $evidenceId,
        string $decision,
        string $reason,
        User $reviewer,
    ): GovernanceElectionAuthorityEvidence {
        $this->authorize($reviewer, $tenantId, 'governance.election.authority.review');
        if (!in_array($decision, ['reviewed', 'rejected'], true) || mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 4000) {
            throw ValidationException::withMessages(['reason' => 'A supported review decision and a reason of at least 10 characters are required.']);
        }

        return DB::transaction(function () use ($tenantId, $electionId, $evidenceId, $decision, $reason, $reviewer): GovernanceElectionAuthorityEvidence {
            $election = $this->election($tenantId, $electionId, true);
            if ($election->status !== 'draft' || $election->authority_verified_at !== null) {
                throw ValidationException::withMessages(['election' => 'Authority evidence cannot be reviewed after election authorization.']);
            }

            $evidence = GovernanceElectionAuthorityEvidence::query()
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)
                ->lockForUpdate()->findOrFail($evidenceId);

            if ((int) $evidence->recorded_by === (int) $reviewer->getAuthIdentifier()) {
                throw ValidationException::withMessages(['reviewer' => 'The evidence recorder cannot review their own evidence.']);
            }
            if ($evidence->status !== 'pending_review') {
                throw ValidationException::withMessages(['status' => 'Only pending authority evidence can be reviewed.']);
            }

            $evidence->forceFill([
                'status' => $decision,
                'reviewed_by' => (int) $reviewer->getAuthIdentifier(),
                'reviewed_at' => now(),
                'review_reason' => trim($reason),
            ])->save();

            $this->auditOrFail('governance.election.authority_evidence.reviewed', $tenantId, $reviewer, $election, [
                'evidence_id' => (int) $evidence->getKey(),
                'decision' => $decision,
                'reason' => trim($reason),
            ]);

            return $evidence->fresh();
        }, 3);
    }

    public function verifyAuthority(
        int $tenantId,
        int $electionId,
        int $evidenceId,
        string $reason,
        User $verifier,
    ): GovernanceElection {
        $this->authorize($verifier, $tenantId, 'governance.election.authority.verify');
        if (mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 4000) {
            throw ValidationException::withMessages(['reason' => 'An authority-verification reason of at least 10 characters is required.']);
        }

        return DB::transaction(function () use ($tenantId, $electionId, $evidenceId, $reason, $verifier): GovernanceElection {
            $election = $this->election($tenantId, $electionId, true);
            if ($election->status !== 'draft' || $election->authority_verified_at !== null) {
                throw ValidationException::withMessages(['election' => 'Authority can only be verified once while the election is in draft.']);
            }

            $evidence = GovernanceElectionAuthorityEvidence::query()
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)
                ->lockForUpdate()->findOrFail($evidenceId);

            if ($evidence->status !== 'reviewed' || $evidence->reviewed_by === null
                || (int) $evidence->recorded_by === (int) $evidence->reviewed_by
                || trim((string) $evidence->review_reason) === ''
                || (int) $evidence->recorded_by === (int) $verifier->getAuthIdentifier()) {
                throw ValidationException::withMessages(['evidence_id' => 'Authority verification requires reviewed evidence and a verifier distinct from its recorder.']);
            }

            [, $actualDigest] = $this->publishedDocument($tenantId, (int) $evidence->document_id, $verifier);
            if (!hash_equals(strtolower((string) $evidence->content_sha256), $actualDigest)) {
                throw ValidationException::withMessages(['evidence_id' => 'The stored authority document has changed since evidence recording.']);
            }

            $verifiedAt = now();
            $evidence->forceFill([
                'status' => 'verified',
                'verified_by' => (int) $verifier->getAuthIdentifier(),
                'verified_at' => $verifiedAt,
                'verification_reason' => trim($reason),
            ])->save();

            $election->forceFill([
                'authority_evidence_id' => (int) $evidence->getKey(),
                'authority_verified_by' => (int) $verifier->getAuthIdentifier(),
                'authority_verified_at' => $verifiedAt,
                'authority_verification_reason' => trim($reason),
                'authority_reference' => $evidence->instrument_reference,
            ])->save();

            $this->auditOrFail('governance.election.authority.verified', $tenantId, $verifier, $election, [
                'evidence_id' => (int) $evidence->getKey(),
                'document_id' => (int) $evidence->document_id,
                'content_sha256' => $actualDigest,
                'authority_reference' => $evidence->instrument_reference,
                'reason' => trim($reason),
            ]);

            return $election->fresh();
        }, 3);
    }

    private function publishedDocument(int $tenantId, int $documentId, User $actor): array
    {
        try {
            $document = $this->files->find($documentId, false, $actor);
        } catch (FileException) {
            $document = null;
        }

        if (!$document || (int) $document->tenant_id !== $tenantId || $document->status !== 'published') {
            throw ValidationException::withMessages(['document_id' => 'Authority evidence must be a published, non-deleted document accessible in this tenant.']);
        }

        try {
            $response = $this->files->download($document, $actor);
            $bytes = $response->getContent();
        } catch (FileException) {
            $bytes = false;
        }

        if (!is_string($bytes) || $bytes === '') {
            throw ValidationException::withMessages(['document_id' => 'The published authority document bytes are unavailable.']);
        }

        return [$document, hash('sha256', $bytes)];
    }

    private function election(int $tenantId, int $electionId, bool $lock = false): GovernanceElection
    {
        $query = GovernanceElection::query()->forTenant($tenantId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($electionId);
    }

    private function authorize(User $actor, int $tenantId, string $permission): void
    {
        if ($tenantId < 1 || !$this->authorization->hasPermission(
            permissionSlug: $permission,
            user: $actor,
            tenantId: $tenantId,
        )) {
            throw new \Illuminate\Auth\Access\AuthorizationException('The required election authority permission is not granted in this tenant.');
        }
    }

    private function auditOrFail(string $action, int $tenantId, User $actor, GovernanceElection $election, array $metadata): void
    {
        $entry = $this->audit->log(
            action: $action,
            data: $metadata,
            user: $actor,
            tenantId: $tenantId,
            resourceType: 'governance_election',
            resourceId: (string) $election->getKey(),
        );

        if ($entry === null) {
            throw new RuntimeException('Required election authority audit record could not be persisted; transaction rolled back.');
        }
    }
}
