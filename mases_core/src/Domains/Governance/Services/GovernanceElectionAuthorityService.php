<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElectionAuthorityEvidence;
use RuntimeException;

final class GovernanceElectionAuthorityService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function createPlanned(
        int $tenantId,
        string $code,
        string $title,
        ?int $governanceBodyId,
        ?string $recordDate,
        User $actor,
    ): GovernanceElection {
        return DB::transaction(function () use ($tenantId, $code, $title, $governanceBodyId, $recordDate, $actor): GovernanceElection {
            $election = GovernanceElection::query()->create([
                'tenant_id' => $tenantId,
                'governance_body_id' => $governanceBodyId,
                'code' => trim($code),
                'title' => trim($title),
                'status' => 'planned',
                'authority_status' => 'unverified',
                'record_date' => $recordDate,
                'created_by' => (int) $actor->getAuthIdentifier(),
            ]);

            $this->auditOrFail(
                'governance.election.created',
                $tenantId,
                $actor,
                $election,
                ['status' => 'planned', 'authority_status' => 'unverified'],
            );

            return $election;
        });
    }

    public function recordAuthorityEvidence(
        int $tenantId,
        int $electionId,
        string $instrumentType,
        string $instrumentReference,
        ?string $instrumentDate,
        string $storageReference,
        string $contentSha256,
        User $actor,
    ): GovernanceElectionAuthorityEvidence {
        if (
            trim($instrumentType) === ''
            || trim($instrumentReference) === ''
            || trim($storageReference) === ''
            || !preg_match('/^[a-f0-9]{64}$/i', $contentSha256)
        ) {
            throw new DomainException('Authority evidence requires an instrument type, reference, stored-document reference and valid declared SHA-256 digest.');
        }

        return DB::transaction(function () use (
            $tenantId,
            $electionId,
            $instrumentType,
            $instrumentReference,
            $instrumentDate,
            $storageReference,
            $contentSha256,
            $actor,
        ): GovernanceElectionAuthorityEvidence {
            $election = GovernanceElection::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($electionId);

            if ($election->status !== 'planned') {
                throw new DomainException('Authority evidence can only be recorded while the election is planned.');
            }

            if (!preg_match('/^[a-f0-9]{64}$/i', $contentSha256)) {
                throw new DomainException('Authority evidence requires a valid SHA-256 digest.');
            }

            $evidence = GovernanceElectionAuthorityEvidence::query()->create([
                'tenant_id' => $tenantId,
                'election_id' => $electionId,
                'instrument_type' => trim($instrumentType),
                'instrument_reference' => trim($instrumentReference),
                'instrument_date' => $instrumentDate,
                'storage_reference' => trim($storageReference),
                'content_sha256' => strtolower($contentSha256),
                'status' => 'pending_review',
                'recorded_by' => (int) $actor->getAuthIdentifier(),
            ]);

            $this->auditOrFail(
                'governance.election.authority_evidence.recorded',
                $tenantId,
                $actor,
                $election,
                [
                    'evidence_id' => $evidence->id,
                    'instrument_type' => $evidence->instrument_type,
                    'instrument_reference' => $evidence->instrument_reference,
                    'content_sha256' => $evidence->content_sha256,
                    'status' => 'pending_review',
                ],
            );

            return $evidence;
        });
    }

    public function reviewAuthorityEvidence(
        int $tenantId,
        int $electionId,
        int $evidenceId,
        string $decision,
        string $reason,
        User $reviewer,
    ): GovernanceElectionAuthorityEvidence {
        if (!in_array($decision, ['reviewed', 'rejected'], true) || mb_strlen(trim($reason)) < 10) {
            throw new DomainException('A supported evidence-review decision and reason are required.');
        }

        return DB::transaction(function () use (
            $tenantId,
            $electionId,
            $evidenceId,
            $decision,
            $reason,
            $reviewer,
        ): GovernanceElectionAuthorityEvidence {
            $election = GovernanceElection::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($electionId);

            $evidence = GovernanceElectionAuthorityEvidence::query()
                ->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)
                ->lockForUpdate()
                ->findOrFail($evidenceId);

            if ((int) $evidence->recorded_by === (int) $reviewer->getAuthIdentifier()) {
                throw new DomainException('The evidence recorder cannot review the same evidence record.');
            }

            if ($evidence->status !== 'pending_review') {
                throw new DomainException('Only pending authority evidence can be reviewed.');
            }

            $evidence->forceFill([
                'status' => $decision,
                'reviewed_by' => (int) $reviewer->getAuthIdentifier(),
                'reviewed_at' => now(),
                'review_reason' => trim($reason),
            ])->save();

            $this->auditOrFail(
                'governance.election.authority_evidence.reviewed',
                $tenantId,
                $reviewer,
                $election,
                [
                    'evidence_id' => $evidence->id,
                    'decision' => $decision,
                    'reason' => trim($reason),
                ],
            );

            // Evidence review is not a legal finding that the election is
            // authorized. authority_status intentionally remains unverified.
            return $evidence->fresh();
        });
    }

    private function auditOrFail(
        string $action,
        int $tenantId,
        User $actor,
        GovernanceElection $election,
        array $metadata,
    ): void {
        $entry = $this->audit->log(
            action: $action,
            data: $metadata,
            user: $actor,
            tenantId: $tenantId,
            resourceType: 'governance_election',
            resourceId: (string) $election->getKey(),
        );

        if ($entry === null) {
            throw new RuntimeException('Required governance election audit record could not be persisted; transaction rolled back.');
        }
    }
}
