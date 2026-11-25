<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Audit\Models\AuditLog;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareholderImportDecision;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareholderImportSubmission;
use MaaleArna\MASES\Core\Tenancy\Models\TenantMembership;
use RuntimeException;

final class ShareholderImportReviewService
{
    public function __construct(
        private readonly ShareholderImportReviewPolicy $policy,
        private readonly AuthorizationService $authorization,
    ) {
    }

    /**
     * Persist a clean preview as a tenant-scoped submission.
     *
     * Only validated row data is retained. The uploaded source file itself is
     * never stored by this service. Approval remains separate from execution.
     *
     * @param array<string,mixed> $preview
     */
    public function submit(array $preview, User $actor, int $tenantId): ShareholderImportSubmission
    {
        $this->assertTenantActor($actor, $tenantId);
        $this->authorization->requirePermission('shareholder.import.submit', $actor, $tenantId);

        $packet = $this->policy->evaluateSubmission($preview, (int) $actor->id);
        if (! $packet['allowed']) {
            throw new DomainException('Shareholder import preview cannot be submitted: ' . implode(',', $packet['reason_codes']));
        }

        $payload = [
            'format' => $preview['format'] ?? null,
            'summary' => $preview['summary'],
            'rows' => $preview['rows'],
        ];
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $payloadHash = hash('sha256', $payloadJson);

        return DB::transaction(function () use ($actor, $tenantId, $packet, $payload, $payloadHash): ShareholderImportSubmission {
            $submission = ShareholderImportSubmission::query()->create([
                'tenant_id' => $tenantId,
                'status' => ShareholderImportReviewPolicy::STATUS_SUBMITTED,
                'payload' => $payload,
                'source_references' => $packet['source_references'],
                'row_count' => $packet['row_count'],
                'payload_sha256' => $payloadHash,
                'version' => 1,
                'submitted_by' => $actor->id,
            ]);

            $this->appendDecision(
                $submission,
                1,
                'submitted',
                ShareholderImportReviewPolicy::STATUS_PREVIEW_ONLY,
                ShareholderImportReviewPolicy::STATUS_SUBMITTED,
                $actor,
                null,
                null,
                null,
            );

            $this->appendAudit($submission, 'shareholder.import.submitted', $actor, [
                'status' => $submission->status,
                'version' => $submission->version,
                'row_count' => $submission->row_count,
                'payload_sha256' => $submission->payload_sha256,
            ]);

            return $submission->fresh('decisions');
        });
    }

    /**
     * Record a complete independent review using a row lock and expected version.
     *
     * @param array<string,list<string>> $evidenceBySourceReference
     */
    public function review(
        int $submissionId,
        User $actor,
        int $tenantId,
        int $expectedVersion,
        int $reviewedRowCount,
        string $decision,
        string $reviewNote,
        array $evidenceBySourceReference = [],
    ): ShareholderImportSubmission {
        $this->assertTenantActor($actor, $tenantId);
        $this->authorization->requirePermission('shareholder.import.review', $actor, $tenantId);

        return DB::transaction(function () use (
            $submissionId,
            $actor,
            $tenantId,
            $expectedVersion,
            $reviewedRowCount,
            $decision,
            $reviewNote,
            $evidenceBySourceReference,
        ): ShareholderImportSubmission {
            $submission = $this->lockedSubmission($submissionId, $tenantId, $expectedVersion);
            $packet = $this->policy->evaluateReview(
                $submission->toArray(),
                (int) $actor->id,
                $reviewedRowCount,
                $decision,
                $reviewNote,
                $evidenceBySourceReference,
            );

            if (! $packet['allowed']) {
                throw new DomainException('Shareholder import review is not permitted: ' . implode(',', $packet['reason_codes']));
            }

            $fromStatus = (string) $submission->status;
            $submission->status = $packet['status'];
            $submission->reviewed_by = $actor->id;
            $submission->reviewed_row_count = $reviewedRowCount;
            $submission->review_note = trim($reviewNote);
            $submission->review_evidence = $evidenceBySourceReference;
            $submission->version = $expectedVersion + 1;
            $submission->save();

            $this->appendDecision(
                $submission,
                $submission->version,
                $decision === 'accept' ? 'review_accepted' : 'review_rejected',
                $fromStatus,
                $submission->status,
                $actor,
                trim($reviewNote),
                null,
                $evidenceBySourceReference,
            );

            $this->appendAudit($submission, 'shareholder.import.reviewed', $actor, [
                'status' => $submission->status,
                'version' => $submission->version,
                'reviewed_row_count' => $reviewedRowCount,
                'payload_sha256' => $submission->payload_sha256,
            ]);

            return $submission->fresh('decisions');
        });
    }

    /**
     * Record a third-party approval. This never executes an import.
     */
    public function approve(
        int $submissionId,
        User $actor,
        int $tenantId,
        int $expectedVersion,
        string $approvalAuthorityReference,
        string $approvalNote,
    ): ShareholderImportSubmission {
        $this->assertTenantActor($actor, $tenantId);
        $this->authorization->requirePermission('shareholder.import.approve', $actor, $tenantId);

        if (trim($approvalNote) === '') {
            throw new DomainException('An approval note is required.');
        }
        if (mb_strlen(trim($approvalAuthorityReference)) > 255) {
            throw new DomainException('The approval authority reference exceeds 255 characters.');
        }

        return DB::transaction(function () use (
            $submissionId,
            $actor,
            $tenantId,
            $expectedVersion,
            $approvalAuthorityReference,
            $approvalNote,
        ): ShareholderImportSubmission {
            $submission = $this->lockedSubmission($submissionId, $tenantId, $expectedVersion);
            $reviewState = $submission->toArray();
            $reviewState['evidence_by_source_reference'] = $submission->review_evidence ?? [];

            $packet = $this->policy->evaluateApproval(
                $reviewState,
                (int) $actor->id,
                $approvalAuthorityReference,
            );

            if (! $packet['allowed']) {
                throw new DomainException('Shareholder import approval is not permitted: ' . implode(',', $packet['reason_codes']));
            }

            $fromStatus = (string) $submission->status;
            $submission->status = $packet['status'];
            $submission->approved_by = $actor->id;
            $submission->approval_authority_reference = trim($approvalAuthorityReference);
            $submission->version = $expectedVersion + 1;
            $submission->save();

            $this->appendDecision(
                $submission,
                $submission->version,
                'approved',
                $fromStatus,
                $submission->status,
                $actor,
                trim($approvalNote),
                trim($approvalAuthorityReference),
                $submission->review_evidence,
            );

            $this->appendAudit($submission, 'shareholder.import.approved_for_import', $actor, [
                'status' => $submission->status,
                'version' => $submission->version,
                'approval_authority_reference' => $submission->approval_authority_reference,
                'payload_sha256' => $submission->payload_sha256,
                'import_execution_permitted' => false,
            ]);

            return $submission->fresh('decisions');
        });
    }

    private function assertTenantActor(User $actor, int $tenantId): void
    {
        if ($tenantId < 1 || ! $actor->exists || ! $actor->is_active) {
            throw new AuthorizationException('An active authenticated tenant member is required.');
        }

        $tenantIsActive = DB::table('tenants')
            ->where('id', $tenantId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        $hasMembership = DB::table('tenant_memberships')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $actor->id)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->exists();

        if (! $tenantIsActive || ! $hasMembership) {
            throw new AuthorizationException('Active membership in the selected tenant is required.');
        }
    }

    private function lockedSubmission(
        int $submissionId,
        int $tenantId,
        int $expectedVersion,
    ): ShareholderImportSubmission {
        $submission = ShareholderImportSubmission::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($submissionId)
            ->lockForUpdate()
            ->first();

        if ($submission === null) {
            throw new RuntimeException('Shareholder import submission was not found in this tenant.');
        }

        if ((int) $submission->version !== $expectedVersion) {
            throw new DomainException('The shareholder import submission changed; reload before retrying.');
        }

        return $submission;
    }

    /**
     * Append an immutable decision and a mandatory audit row in the caller's transaction.
     *
     * @param array<string,mixed>|null $evidence
     */
    private function appendDecision(
        ShareholderImportSubmission $submission,
        int $sequence,
        string $decisionType,
        ?string $fromStatus,
        string $toStatus,
        User $actor,
        ?string $note,
        ?string $authorityReference,
        ?array $evidence,
    ): void {
        ShareholderImportDecision::query()->create([
            'tenant_id' => $submission->tenant_id,
            'submission_id' => $submission->id,
            'sequence' => $sequence,
            'decision_type' => $decisionType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => $actor->id,
            'note' => $note,
            'authority_reference' => $authorityReference,
            'source_references' => $submission->source_references,
            'evidence' => $evidence,
            'payload_sha256' => $submission->payload_sha256,
            'created_at' => now(),
        ]);
    }

    /**
     * Bypass the legacy fail-open AuditService: this write must succeed or the
     * surrounding state transition must roll back.
     *
     * @param array<string,mixed> $summary
     */
    private function appendAudit(
        ShareholderImportSubmission $submission,
        string $action,
        User $actor,
        array $summary,
    ): void {
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'tenant_id' => $submission->tenant_id,
            'action' => $action,
            'resource_type' => 'shareholder_import_submission',
            'resource_id' => (string) $submission->id,
            'new_values' => $summary,
            'metadata' => [
                'decision_sequence' => $submission->version,
                'source_references' => $submission->source_references,
            ],
        ]);
    }
}
