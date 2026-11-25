<?php

declare(strict_types=1);

namespace Tests\Unit\Governance;

use DomainException;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceMeetingLegalGate;
use PHPUnit\Framework\TestCase;

final class GovernanceMeetingLegalGateTest extends TestCase
{
    private function meeting(): array
    {
        return [
            'legal_form' => 'share_company', 'category' => 'ordinary',
            'legal_basis_status' => 'verified', 'legal_basis_reference' => 'authenticated:company-instrument',
            'legal_basis_verified_at' => '2026-11-25T08:00:00Z',
            'legal_basis_verified_by' => 22, 'created_by' => 11,
            'external_review_status' => 'verified',
            'external_counsel_name' => 'Independent Ethiopian Counsel',
            'external_counsel_firm' => 'Test Legal Chambers',
            'external_counsel_license_reference' => 'TEST-LICENSE-001',
            'external_opinion_reference' => 'test:external-opinion',
            'external_opinion_sha256' => str_repeat('d', 64),
            'external_reviewed_at' => '2026-11-25T08:00:00Z',
        ];
    }

    private function evidence(): array
    {
        $verified = ['status' => 'verified', 'source_reference' => 'evidence://instrument/clause-1',
            'approved_at' => '2026-11-25T08:00:00Z', 'approved_by' => 33];
        return array_fill_keys([
            'notice_rule', 'quorum_rule', 'meeting_category_rule', 'voting_rights_rule',
            'share_register_basis', 'proxy_formality_rule', 'proxy_authority',
            'penalty_authority', 'penalty_due_process', 'minutes_approval_rule',
            'minutes_signatory_authority',
        ], $verified);
    }

    public function test_blocks_missing_legal_form(): void
    {
        $meeting = $this->meeting();
        $meeting['legal_form'] = null;
        $result = (new GovernanceMeetingLegalGate())->evaluate('quorum', $meeting, $this->evidence());
        self::assertFalse($result['allowed']);
        self::assertContains('verified_share_company_legal_form', $result['missing']);
    }

    public function test_blocks_draft_evidence(): void
    {
        $evidence = $this->evidence();
        $evidence['quorum_rule']['status'] = 'draft';
        $result = (new GovernanceMeetingLegalGate())->evaluate('quorum', $this->meeting(), $evidence);
        self::assertFalse($result['allowed']);
        self::assertContains('quorum_rule', $result['missing']);
    }

    public function test_blocks_creator_as_reviewer(): void
    {
        $meeting = $this->meeting();
        $meeting['legal_basis_verified_by'] = $meeting['created_by'];
        $result = (new GovernanceMeetingLegalGate())->evaluate('notice', $meeting, $this->evidence());
        self::assertFalse($result['allowed']);
        self::assertContains('independent_reviewer_separation', $result['missing']);
    }

    public function test_allows_complete_verified_evidence(): void
    {
        $result = (new GovernanceMeetingLegalGate())->evaluate('minutes', $this->meeting(), $this->evidence());
        self::assertSame(['allowed' => true, 'code' => 'LEGAL_BASIS_VERIFIED', 'missing' => []], $result);
    }

    public function test_blocks_missing_independent_ethiopian_counsel_review(): void
    {
        $meeting = $this->meeting();
        $meeting['external_review_status'] = 'pending';
        $result = (new GovernanceMeetingLegalGate())->evaluate('notice', $meeting, $this->evidence());
        self::assertFalse($result['allowed']);
        self::assertContains('independent_ethiopian_counsel_review', $result['missing']);
    }

    public function test_blocks_unknown_action(): void
    {
        $result = (new GovernanceMeetingLegalGate())->evaluate('automatic_penalty_debit', $this->meeting(), $this->evidence());
        self::assertFalse($result['allowed']);
        self::assertSame('UNKNOWN_LEGAL_ACTION', $result['code']);
    }

    public function test_assertion_throws_when_evidence_is_missing(): void
    {
        $this->expectException(DomainException::class);
        (new GovernanceMeetingLegalGate())->assertAllowed('penalty', $this->meeting(), []);
    }
}
