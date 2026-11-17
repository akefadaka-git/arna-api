<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class GovernanceElectionCurrentMainIntegrationTest extends TestCase
{
    public function test_election_provider_registers_policy_services_and_audit_listener(): void
    {
        $provider = file_get_contents(base_path('mases_core/src/Domains/Governance/Providers/GovernanceServiceProvider.php'));
        self::assertStringContainsString('Gate::policy(GovernanceElection::class, GovernanceElectionPolicy::class)', $provider);
        self::assertStringContainsString('GovernanceElectionLifecycleService::class', $provider);
        self::assertStringContainsString('GovernanceElectionRegisterService::class', $provider);
        self::assertStringContainsString('GovernanceElectionCommitteeSecurityService::class', $provider);
        self::assertStringContainsString('GovernanceElectionLifecycleChanged::class', $provider);
    }

    public function test_committee_decision_permission_is_seeded_without_role_grants(): void
    {
        $seeder = file_get_contents(base_path('mases_core/src/Domains/Governance/Database/Seeders/GovernanceElectionCommitteePermissionsSeeder.php'));
        $orchestrator = file_get_contents(base_path('database/seeders/MasesCoreSeeder.php'));
        self::assertStringContainsString('governance.election.committee.decide', $seeder);
        self::assertStringContainsString('GovernanceElectionCommitteePermissionsSeeder::class', $orchestrator);
        self::assertStringContainsString('no role grants changed', $seeder);
    }

    public function test_unverified_decision_threshold_and_share_class_entitlements_remain_disabled(): void
    {
        self::assertFalse((bool) config('governance.election_committee.decision_rule.verified'));
        self::assertNull(config('governance.election_committee.decision_rule.minimum_votes'));
        self::assertFalse((bool) config('governance.election_voting.enabled'));
        self::assertNull(config('governance.election_voting.default_votes_per_share'));
        self::assertSame([], config('governance.election_voting.verified_class_entitlements'));
    }
}
