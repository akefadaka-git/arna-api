<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionRegisterService;
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

    public function test_board_election_voting_is_share_weighted_and_holder_type_neutral(): void
    {
        $voting = config('governance.election_voting');

        $this->assertTrue($voting['enabled']);
        $this->assertSame('eligible_share_entitlement', $voting['basis']);
        $this->assertSame(1, $voting['default_votes_per_share']);
        $this->assertFalse($voting['one_holder_one_vote']);
        $this->assertTrue($voting['aggregate_by_registered_holder']);
        $this->assertTrue($voting['holder_type_neutral']);
        $this->assertTrue($voting['record_date_snapshot_required']);
        $this->assertSame('enforce_verified_class_entitlements', $voting['share_class_rights']);
        $this->assertSame('exclude_from_voting_entitlement', $voting['legally_disqualified_shares']);
        $this->assertSame('require_verified_operative_articles', $voting['articles_override']);
    }

    public function test_voting_weight_is_derived_from_whole_share_quantity(): void
    {
        $this->assertSame('40.0000', GovernanceElectionRegisterService::defaultVotingWeight('40'));
        $this->assertSame('10.0000', GovernanceElectionRegisterService::defaultVotingWeight('10.0000'));
    }

    public function test_fractional_share_quantity_cannot_create_voting_entitlement(): void
    {
        $this->expectException(ValidationException::class);
        GovernanceElectionRegisterService::defaultVotingWeight('10.5');
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
