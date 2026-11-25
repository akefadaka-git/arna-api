<?php

declare(strict_types=1);

namespace Tests\\Unit\\Governance;

use DomainException;
use MaaleArna\\MASES\\Core\\Domains\\Governance\\Services\\GovernanceMeetingLegalGate;
use PHPUnit\\Framework\\TestCase;

final class GovernanceMeetingLegalGateTest extends TestCase
{
    private function meeting(): array
    {
        return [
            'legal_form' => 'share_company',
            'category' => 'ordinary',
            'legal_basis_status' => 'verified',
            'legal_basis_reference' => 'authenticated:memorandum-and-current-register',
            'legal_basis_verified_at' => '2026-11-25T08:00:00Z',
            'legal_basis_verified_by' => 22,
            'created_by' => 11,
        ];
    }

    private function evidence(): array
    {
        return [
            'notice_rule' => $this->verified(),
            'quorum_rule' => $this->verified(),
            'meeting_category_rule' => $this->verified(),
            'voting_rights_rule' => $this->verified(),
            'share_register_basis' => $this->verified(),
            'proxy_formality_rule' => $this->verified(),
            'proxy_authority' => $this->verified(),
            'penalty_authority' => $this->verified(),
            'penalty_due_process' => $this->verified(),
            'minutes_approval_rule' => $this->verified(),
            'minutes_signatory_authority' => $this->verified(),
        ];
    }

    private function verified(): array
    {
        return [
            'status' => 'verified',
            'source_reference' => 'evidence://authenticated-operative-instrument/clause-1',
            'approved_at' => '2026-11-25T08:00:00Z',
            'approved_by' => 33,
        ];
    }

    public function test_blocks_when_company_legal_form_is_not_verified(): void
    {
        $meeting = $this->meeting();
        $meeting['legal_form'] = null;

        $result = (new GovernanceMeetingLegalGate())->evaluate('quorum', $meeting, $this->evidence());

        self::assertFalse($result['allowed']);
        self::assertContains('verified_share_company_legal_form', $result['missing']);
    }

    public function test_blocks_when_evidence_is_only_a_draft_or_unverified(): void
    {
        $evidence = $this->evidence();
        $evidence['quorum_rule']['status'] = 'draft';

        $result = (new GovernanceMeetingLegalGate())->evaluate('quorum', $this->meeting(), $evidence);

        self::assertFalse($result['allowed']);
        self::assertContains('quorum_rule', $result['missing']);
    }

    public function test_blocks_when_creator_is_also_legal_reviewer(): void
    {
        $meeting = $this->meeting();
        $meeting['legal_basis_verified_by'] = $meeting['created_by'];

        $result = (new GovernanceMeetingLegalGate())->evaluate('notice', $meeting, $this->evidence());

        self::assertFalse($result['allowed']);
        self::assertContains('independent_reviewer_separation', $result['missing']);
    }

    public function test_allows_only_complete_verified_evidence(): void
    {
        $result = (new GovernanceMeetingLegalGate())->evaluate('minutes', $this->meeting(), $this->evidence());

        self::assertSame([
            'allowed' => true,
            'code' => 'LEGAL_BASIS_VERIFIED',
            'missing' => [],
        ], $result);
    }

    public function test_unknown_action_is_blocked(): void
    {
        $result = (new GovernanceMeetingLegalGate())->evaluate('automatic_penalty_debit', $this->meeting(), $this->evidence());

        self::assertFalse($result['allowed']);
        self::assertSame('UNKNOWN_LEGAL_ACTION', $result['code']);
    }

    public function test_assert_allowed_throws_when_basis_is_missing(): void
    {
        $this->expectException(DomainException::class);

        (new GovernanceMeetingLegalGate())->assertAllowed('penalty', $this->meeting(), []);
    }
}
