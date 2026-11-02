<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use Tests\TestCase;

final class GovernanceElectionEndpointsTest extends TestCase
{
    public function test_ga_adopted_election_committee_rules_are_loaded_from_governance_config(): void
    {
        $committee = config('governance.election_committee');

        $this->assertSame('ARNA-GOV-ELE-AUTH-001', $committee['authority_record']);
        $this->assertSame('ነሐሴ 01/2018', $committee['adoption_date_ec']);
        $this->assertSame(3, $committee['composition']['general_assembly_non_board_members']);
        $this->assertSame('ex_officio', $committee['composition']['legal_advisor']['capacity']);
        $this->assertSame('unresolved_requires_instrument', $committee['composition']['legal_advisor']['voting_eligibility']);
        $this->assertSame(1, $committee['composition']['supervisory_committee_member']);
        $this->assertSame(3, $committee['quorum']);
        $this->assertTrue($committee['conflict_of_interest']['candidate_member_must_recuse']);
        $this->assertTrue($committee['conflict_of_interest']['immediate_family_of_candidate_must_recuse']);
        $this->assertSame(30, $committee['dissolution_days_after_results_ratified']);
    }

    public function test_election_collection_requires_authenticated_session(): void
    {
        $this->getJson('/api/governance/elections')->assertUnauthorized();
    }

    public function test_election_creation_requires_authenticated_session(): void
    {
        $this->postJson('/api/governance/elections', [
            'code' => 'BOARD-TEST-01',
            'title' => 'Test election',
        ])->assertUnauthorized();
    }
}
