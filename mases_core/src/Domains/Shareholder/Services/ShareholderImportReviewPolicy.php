<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Services;

/**
 * Fail-closed decision rules for a controlled shareholder identity import.
 *
 * This policy creates decision packets only. It does not persist submissions,
 * authenticate actors, grant permissions, validate legal instruments, or
 * execute imports. HTTP/application callers must enforce tenant-scoped
 * permissions through the authorization layer and persist decisions with
 * mandatory audit evidence before exposing any state transition.
 */
final class ShareholderImportReviewPolicy
{
    public const STATUS_PREVIEW_ONLY = 'preview_only';
    public const STATUS_SUBMITTED = 'submitted_for_review';
    public const STATUS_REVIEW_ACCEPTED = 'review_accepted';
    public const STATUS_REVIEW_REJECTED = 'review_rejected';
    public const STATUS_APPROVED = 'approved_for_import';

    /**
     * Validate that a preview may enter the controlled review queue.
     *
     * @param array<string,mixed> $preview
     * @return array<string,mixed>
     */
    public function evaluateSubmission(array $preview, int $submitterId): array
    {
        $reasons = [];

        if ($submitterId < 1) {
            $reasons[] = 'invalid_submitter';
        }
        if (($preview['mode'] ?? null) !== self::STATUS_PREVIEW_ONLY) {
            $reasons[] = 'preview_mode_required';
        }
        if (($preview['writes_performed'] ?? null) !== false) {
            $reasons[] = 'preview_must_be_non_persistent';
        }
        if (($preview['source_file_stored'] ?? null) !== false) {
            $reasons[] = 'source_file_must_not_be_stored_by_preview';
        }
        if (! empty($preview['file_errors'])) {
            $reasons[] = 'preview_has_file_errors';
        }

        $summary = is_array($preview['summary'] ?? null) ? $preview['summary'] : [];
        $total = (int) ($summary['total'] ?? 0);
        $valid = (int) ($summary['valid'] ?? -1);
        $invalid = (int) ($summary['invalid'] ?? -1);
        $rows = is_array($preview['rows'] ?? null) ? $preview['rows'] : [];

        if ($total < 1 || count($rows) !== $total) {
            $reasons[] = 'preview_row_count_mismatch';
        }
        if ($valid !== $total || $invalid !== 0) {
            $reasons[] = 'all_rows_must_be_valid';
        }

        foreach ($rows as $row) {
            if (! is_array($row)
                || ($row['valid'] ?? false) !== true
                || ! is_string($row['source_reference'] ?? null)
                || trim($row['source_reference']) === '') {
                $reasons[] = 'row_identity_or_validation_incomplete';
                break;
            }
        }

        $allowed = $reasons === [];

        return [
            'allowed' => $allowed,
            'status' => $allowed ? self::STATUS_SUBMITTED : self::STATUS_PREVIEW_ONLY,
            'submitted_by' => $allowed ? $submitterId : null,
            'row_count' => $allowed ? $total : 0,
            'reason_codes' => array_values(array_unique($reasons)),
            'writes_performed' => false,
            'import_execution_permitted' => false,
        ];
    }

    /**
     * Evaluate a complete independent human review.
     *
     * Evidence is keyed by the exact source_reference for each preview row.
     * An accepted review requires at least one non-empty evidence reference
     * for every row. A rejected review requires a non-empty explanation.
     *
     * @param array<string,mixed> $submission
     * @param array<string,list<string>> $evidenceBySourceReference
     * @return array<string,mixed>
     */
    public function evaluateReview(
        array $submission,
        int $reviewerId,
        int $reviewedRowCount,
        string $decision,
        string $reviewNote,
        array $evidenceBySourceReference = [],
    ): array {
        $reasons = [];
        $submitterId = (int) ($submission['submitted_by'] ?? 0);
        $rowCount = (int) ($submission['row_count'] ?? 0);

        if (($submission['status'] ?? null) !== self::STATUS_SUBMITTED) {
            $reasons[] = 'submission_not_pending_review';
        }
        if ($submitterId < 1 || $reviewerId < 1) {
            $reasons[] = 'invalid_actor';
        } elseif ($reviewerId === $submitterId) {
            $reasons[] = 'submitter_cannot_review_own_import';
        }
        if ($rowCount < 1 || $reviewedRowCount !== $rowCount) {
            $reasons[] = 'every_row_must_be_reviewed';
        }
        if (! in_array($decision, ['accept', 'reject'], true)) {
            $reasons[] = 'invalid_review_decision';
        }
        if (trim($reviewNote) === '') {
            $reasons[] = 'review_note_required';
        }

        if ($decision === 'accept') {
            $covered = $this->hasCompleteEvidenceCoverage(
                $submission['source_references'] ?? [],
                $evidenceBySourceReference,
            );
            if (! $covered) {
                $reasons[] = 'row_level_evidence_required';
            }
        }

        $allowed = $reasons === [];
        $status = ! $allowed
            ? self::STATUS_SUBMITTED
            : ($decision === 'accept' ? self::STATUS_REVIEW_ACCEPTED : self::STATUS_REVIEW_REJECTED);

        return [
            'allowed' => $allowed,
            'status' => $status,
            'submitted_by' => $submitterId > 0 ? $submitterId : null,
            'reviewed_by' => $allowed ? $reviewerId : null,
            'row_count' => $rowCount,
            'reviewed_row_count' => $allowed ? $reviewedRowCount : 0,
            'review_note' => $allowed ? trim($reviewNote) : null,
            'evidence_by_source_reference' => $allowed ? $evidenceBySourceReference : [],
            'reason_codes' => array_values(array_unique($reasons)),
            'writes_performed' => false,
            'import_execution_permitted' => false,
        ];
    }

    /**
     * Evaluate a separate approval after an accepted, evidence-complete review.
     *
     * The authority reference must be resolved and verified by the application
     * against its authoritative instrument repository. A non-empty string here
     * is only a structural requirement, not proof of legal authority.
     *
     * @param array<string,mixed> $review
     * @return array<string,mixed>
     */
    public function evaluateApproval(
        array $review,
        int $approverId,
        string $approvalAuthorityReference,
    ): array {
        $reasons = [];
        $submitterId = (int) ($review['submitted_by'] ?? 0);
        $reviewerId = (int) ($review['reviewed_by'] ?? 0);
        $rowCount = (int) ($review['row_count'] ?? 0);
        $reviewedRowCount = (int) ($review['reviewed_row_count'] ?? 0);

        if (($review['status'] ?? null) !== self::STATUS_REVIEW_ACCEPTED) {
            $reasons[] = 'accepted_review_required';
        }
        if ($submitterId < 1 || $reviewerId < 1 || $approverId < 1) {
            $reasons[] = 'invalid_actor';
        } elseif (count(array_unique([$submitterId, $reviewerId, $approverId])) !== 3) {
            $reasons[] = 'submitter_reviewer_approver_must_be_distinct';
        }
        if ($rowCount < 1 || $reviewedRowCount !== $rowCount) {
            $reasons[] = 'complete_review_required';
        }
        if (trim($approvalAuthorityReference) === '') {
            $reasons[] = 'approval_authority_reference_required';
        }
        if (! $this->hasCompleteEvidenceCoverage(
            $review['source_references'] ?? [],
            $review['evidence_by_source_reference'] ?? [],
        )) {
            $reasons[] = 'row_level_evidence_required';
        }

        $allowed = $reasons === [];

        return [
            'allowed' => $allowed,
            'status' => $allowed ? self::STATUS_APPROVED : ($review['status'] ?? self::STATUS_REVIEW_ACCEPTED),
            'submitted_by' => $submitterId > 0 ? $submitterId : null,
            'reviewed_by' => $reviewerId > 0 ? $reviewerId : null,
            'approved_by' => $allowed ? $approverId : null,
            'approval_authority_reference' => $allowed ? trim($approvalAuthorityReference) : null,
            'row_count' => $rowCount,
            'reviewed_row_count' => $reviewedRowCount,
            'evidence_by_source_reference' => $review['evidence_by_source_reference'] ?? [],
            'reason_codes' => array_values(array_unique($reasons)),
            'writes_performed' => false,
            'import_execution_permitted' => false,
        ];
    }

    /**
     * @param mixed $sourceReferences
     * @param mixed $evidenceBySourceReference
     */
    private function hasCompleteEvidenceCoverage(mixed $sourceReferences, mixed $evidenceBySourceReference): bool
    {
        if (! is_array($sourceReferences) || $sourceReferences === [] || ! is_array($evidenceBySourceReference)) {
            return false;
        }

        foreach ($sourceReferences as $sourceReference) {
            if (! is_string($sourceReference) || trim($sourceReference) === '') {
                return false;
            }

            $evidence = $evidenceBySourceReference[$sourceReference] ?? null;
            if (! is_array($evidence) || $evidence === []) {
                return false;
            }

            foreach ($evidence as $reference) {
                if (! is_string($reference) || trim($reference) === '') {
                    return false;
                }
            }
        }

        return true;
    }
}
