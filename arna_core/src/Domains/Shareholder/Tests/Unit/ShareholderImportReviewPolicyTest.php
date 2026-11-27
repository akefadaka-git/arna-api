<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Shareholder\Tests\Unit;

use MaaleArna\Arna\Core\Domains\Shareholder\Services\ShareholderImportReviewPolicy;
use PHPUnit\Framework\TestCase;

final class ShareholderImportReviewPolicyTest extends TestCase
{
    private ShareholderImportReviewPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ShareholderImportReviewPolicy();
    }

    public function test_only_a_clean_non_persistent_preview_can_be_submitted(): void
    {
        $decision = $this->policy->evaluateSubmission($this->validPreview(), 10);

        self::assertTrue($decision['allowed']);
        self::assertSame('submitted_for_review', $decision['status']);
        self::assertSame(10, $decision['submitted_by']);
        self::assertSame(['SRC-001', 'SRC-002'], $decision['source_references']);
        self::assertFalse($decision['import_execution_permitted']);
        self::assertFalse($decision['writes_performed']);
    }

    public function test_invalid_preview_cannot_be_submitted(): void
    {
        $preview = $this->validPreview();
        $preview['summary'] = ['total' => 2, 'valid' => 1, 'invalid' => 1];
        $preview['rows'][1]['valid'] = false;

        $decision = $this->policy->evaluateSubmission($preview, 10);

        self::assertFalse($decision['allowed']);
        self::assertContains('all_rows_must_be_valid', $decision['reason_codes']);
    }

    public function test_preview_with_persistence_side_effect_is_rejected(): void
    {
        $preview = $this->validPreview();
        $preview['writes_performed'] = true;

        $decision = $this->policy->evaluateSubmission($preview, 10);

        self::assertFalse($decision['allowed']);
        self::assertContains('preview_must_be_non_persistent', $decision['reason_codes']);
    }

    public function test_submitter_cannot_review_own_submission(): void
    {
        $decision = $this->policy->evaluateReview(
            $this->validSubmission(),
            10,
            2,
            'accept',
            'Reviewed against source material.',
            $this->completeEvidence(),
        );

        self::assertFalse($decision['allowed']);
        self::assertContains('submitter_cannot_review_own_import', $decision['reason_codes']);
    }

    public function test_every_row_must_be_reviewed_before_acceptance(): void
    {
        $decision = $this->policy->evaluateReview(
            $this->validSubmission(),
            20,
            1,
            'accept',
            'Reviewed one of two rows.',
            $this->completeEvidence(),
        );

        self::assertFalse($decision['allowed']);
        self::assertContains('every_row_must_be_reviewed', $decision['reason_codes']);
    }

    public function test_acceptance_requires_evidence_for_each_source_row(): void
    {
        $decision = $this->policy->evaluateReview(
            $this->validSubmission(),
            20,
            2,
            'accept',
            'Review complete.',
            ['SRC-001' => ['DOC-001']],
        );

        self::assertFalse($decision['allowed']);
        self::assertContains('row_level_evidence_required', $decision['reason_codes']);
    }

    public function test_reviewer_can_reject_with_reason_without_approving_import(): void
    {
        $decision = $this->policy->evaluateReview(
            $this->validSubmission(),
            20,
            2,
            'reject',
            'Source identity conflict requires correction.',
        );

        self::assertTrue($decision['allowed']);
        self::assertSame('review_rejected', $decision['status']);
        self::assertFalse($decision['import_execution_permitted']);
    }

    public function test_approval_requires_distinct_submitter_reviewer_and_approver(): void
    {
        $decision = $this->policy->evaluateApproval($this->validAcceptedReview(), 20, 'AUTH-RES-001');

        self::assertFalse($decision['allowed']);
        self::assertContains('submitter_reviewer_approver_must_be_distinct', $decision['reason_codes']);
    }

    public function test_approval_requires_authority_reference(): void
    {
        $decision = $this->policy->evaluateApproval($this->validAcceptedReview(), 30, '');

        self::assertFalse($decision['allowed']);
        self::assertContains('approval_authority_reference_required', $decision['reason_codes']);
    }

    public function test_approved_review_still_does_not_execute_import(): void
    {
        $decision = $this->policy->evaluateApproval($this->validAcceptedReview(), 30, 'AUTH-RES-001');

        self::assertTrue($decision['allowed']);
        self::assertSame('approved_for_import', $decision['status']);
        self::assertSame(30, $decision['approved_by']);
        self::assertFalse($decision['writes_performed']);
        self::assertFalse($decision['import_execution_permitted']);
    }

    public function test_review_cannot_be_repeated_after_rejection(): void
    {
        $rejected = $this->policy->evaluateReview(
            $this->validSubmission(),
            20,
            2,
            'reject',
            'Conflicting identity evidence.',
        );
        $attempt = $this->policy->evaluateReview(
            $rejected,
            30,
            2,
            'accept',
            'Attempt to override rejection.',
            $this->completeEvidence(),
        );

        self::assertFalse($attempt['allowed']);
        self::assertContains('submission_not_pending_review', $attempt['reason_codes']);
    }

    public function test_end_to_end_review_packet_preserves_source_provenance_and_separation_of_duties(): void
    {
        $submission = $this->policy->evaluateSubmission($this->validPreview(), 10);
        $review = $this->policy->evaluateReview(
            $submission,
            20,
            2,
            'accept',
            'All rows matched to their source references.',
            $this->completeEvidence(),
        );
        $approval = $this->policy->evaluateApproval($review, 30, 'AUTH-RES-001');

        self::assertTrue($submission['allowed']);
        self::assertTrue($review['allowed']);
        self::assertTrue($approval['allowed']);
        self::assertSame(['SRC-001', 'SRC-002'], $approval['source_references']);
        self::assertSame([10, 20, 30], [
            $approval['submitted_by'],
            $approval['reviewed_by'],
            $approval['approved_by'],
        ]);
        self::assertFalse($approval['import_execution_permitted']);
    }

    private function validPreview(): array
    {
        return [
            'mode' => 'preview_only',
            'writes_performed' => false,
            'source_file_stored' => false,
            'summary' => ['total' => 2, 'valid' => 2, 'invalid' => 0],
            'rows' => [
                ['valid' => true, 'source_reference' => 'SRC-001'],
                ['valid' => true, 'source_reference' => 'SRC-002'],
            ],
        ];
    }

    private function validSubmission(): array
    {
        return [
            'status' => 'submitted_for_review',
            'submitted_by' => 10,
            'row_count' => 2,
            'source_references' => ['SRC-001', 'SRC-002'],
        ];
    }

    private function completeEvidence(): array
    {
        return [
            'SRC-001' => ['DOC-001'],
            'SRC-002' => ['DOC-002'],
        ];
    }

    private function validAcceptedReview(): array
    {
        return $this->policy->evaluateReview(
            $this->validSubmission(),
            20,
            2,
            'accept',
            'Both rows reviewed.',
            $this->completeEvidence(),
        );
    }
}
