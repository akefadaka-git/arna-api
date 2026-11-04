<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regression contracts for the ARNA Board-election workflow.
 *
 * These tests deliberately inspect the production route/controller/migration
 * contracts without requiring seeded tenant data. They complement (and do
 * not replace) database-backed HTTP authorization tests.
 */
final class GovernanceElectionWorkflowSecurityContractTest extends TestCase
{
    private function source(string $path): string
    {
        $fullPath = base_path($path);
        self::assertFileExists($fullPath, "Required election source file is missing: {$path}");

        return (string) file_get_contents($fullPath);
    }

    public function test_election_workflow_routes_are_bound_to_the_controller_and_permission_gate(): void
    {
        $routes = $this->source('mases_core/src/Domains/Governance/Routes/api.php');

        self::assertStringContainsString('use MaaleArna\\MASES\\Core\\Domains\\Governance\\Http\\Controllers\\GovernanceElectionWorkflowController;', $routes);
        self::assertStringContainsString('GovernanceElectionWorkflowController::class', $routes);
        foreach ([
            'submitNomination',
            'candidateReviewIndex',
            'storeFeedback',
            'storePreference',
            'appointCommitteeMember',
            'recuseCommitteeMember',
            'committeeQueue',
            'decideNomination',
        ] as $action) {
            self::assertStringContainsString($action, $routes, "Missing election route action: {$action}");
        }
        self::assertStringContainsString('governance.election.manage', $routes);
    }

    public function test_all_workflow_mutations_are_tenant_scoped_and_authorized(): void
    {
        $controller = $this->source('mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionWorkflowController.php');

        foreach ([
            'submitNomination',
            'candidateReviewIndex',
            'storeFeedback',
            'storePreference',
            'appointCommitteeMember',
            'recuseCommitteeMember',
            'committeeQueue',
            'decideNomination',
        ] as $action) {
            $start = strpos($controller, 'function '.$action.'(');
            self::assertNotFalse($start, "Missing controller action: {$action}");
            $next = strpos($controller, "\n    public function ", $start + 1);
            $method = substr($controller, $start, $next === false ? null : $next - $start);

            self::assertStringContainsString('Gate::authorize(', $method, "{$action} must authorize its election resource");
            self::assertStringContainsString('$this->tenantId()', $method, "{$action} must resolve the active tenant");
            self::assertStringContainsString('(int) $governanceElection->tenant_id !== $tenantId', $method, "{$action} must reject cross-tenant elections");
        }
    }

    public function test_shareholder_identity_resolution_fails_closed_on_zero_or_ambiguous_matches(): void
    {
        $controller = $this->source('mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionWorkflowController.php');

        self::assertStringContainsString("->where('tenant_id', " . '$tenantId' . ')', $controller);
        self::assertStringContainsString("->where('user_id', " . '$userId' . ")->orWhere('representative_user_id', " . '$userId' . ')', $controller);
        self::assertStringContainsString('$matches->count() !== 1', $controller);
        self::assertStringContainsString('exactly one shareholder identity', $controller);
    }

    public function test_committee_decisions_require_membership_quorum_and_recusal_clearance(): void
    {
        $controller = $this->source('mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionWorkflowController.php');

        self::assertStringContainsString('$this->assertCommitteeMember($tenantId', $controller);
        self::assertStringContainsString("->where('status', 'active')->whereNull('recused_at')->where('member_role', 'committee_member')->count()", $controller);
        self::assertStringContainsString('$members < 3', $controller);
        self::assertStringContainsString('$committeeMember->recused_at !== null', $controller);
        self::assertStringContainsString('candidate cannot decide their own nomination', $controller);
        self::assertStringContainsString('governance_election_committee_decisions', $controller);
        self::assertStringContainsString("'decided_by' => \$actorId", $controller);
    }

    public function test_shareholder_consultation_is_advisory_and_only_approved_candidates_are_visible(): void
    {
        $controller = $this->source('mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionWorkflowController.php');

        self::assertStringContainsString("->where('final_ballot_approved', true)", $controller);
        self::assertStringContainsString("'binding' => false", $controller);
        self::assertStringContainsString("'rating' => ['required', 'integer', 'between:1,5']", $controller);
        self::assertStringContainsString('approvedCandidate(', $controller);
    }

    public function test_electronic_ballot_capability_is_disabled_by_default_and_entitlement_is_unique(): void
    {
        $migration = $this->source('mases_core/src/Domains/Governance/Database/Migrations/2026_11_02_160000_create_governance_election_hybrid_workflow_tables.php');

        self::assertStringContainsString("electronic_voting_authorized')->default(false)", $migration);
        self::assertStringContainsString("'voter_entitlement_id')->unique()", $migration);
        self::assertStringContainsString("->unique(['tenant_id', 'election_id', 'voter_entitlement_id']", $migration);
        self::assertStringContainsString('governance_election_secret_ballots', $migration);
    }

    public function test_committee_composition_is_bounded_and_legal_advisor_is_not_assumed_to_vote(): void
    {
        $controller = $this->source('mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionWorkflowController.php');
        $config = $this->source('mases_core/src/Domains/Governance/Config/governance.php');

        self::assertStringContainsString("'committee_member' => 3", $controller);
        self::assertStringContainsString("'legal_advisor', 'supervisory_member' => 1", $controller);
        self::assertStringContainsString("Only the Legal Advisor appointment is ex officio", $controller);
        self::assertStringContainsString("Legal Advisor voting and decision eligibility is unresolved", $controller);
        self::assertStringContainsString("'enabled' => false", $config);
        self::assertStringContainsString("'default_votes_per_share' => null", $config);
    }

    public function test_nomination_submission_decisions_are_routed_and_candidate_identity_is_validated(): void
    {
        $routes = $this->source('mases_core/src/Domains/Governance/Routes/api.php');
        $controller = $this->source('mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionWorkflowController.php');

        self::assertStringContainsString('decideNominationSubmission', $routes);
        self::assertStringContainsString('decideNominationSubmission', $controller);
        self::assertStringContainsString("->where('person_id', \$data['candidate_person_id'])", $controller);
        self::assertStringContainsString('Self-nomination must identify the authenticated shareholder person.', $controller);
        self::assertStringContainsString('governance_election_committee_decisions', $controller);
    }

    public function test_unverified_election_voting_rules_fail_closed(): void
    {
        $config = $this->source('mases_core/src/Domains/Governance/Config/governance.php');

        self::assertStringContainsString("'enabled' => false", $config);
        self::assertStringContainsString("'default_votes_per_share' => null", $config);
        self::assertStringContainsString("'electronic_voting_enabled'  => false", $config);
    }

    public function test_nomination_intake_matches_lifecycle_status_and_canonical_shareholder_schema(): void
    {
        $controller = $this->source('mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionWorkflowController.php');
        $service = $this->source('mases_core/src/Domains/Governance/Services/GovernanceElectionLifecycleService.php');

        self::assertStringContainsString("\$governanceElection->status === 'nominating'", $controller);
        self::assertStringContainsString("'status' => 'nominating'", $service);
        self::assertStringContainsString("->where('shareholder_type', 'individual')", $controller);
        self::assertStringContainsString("->where('person_id', ", $controller);
        self::assertStringContainsString("->where('status', 'active')", $controller);
    }

    public function test_committee_appointment_requires_canonical_person_user_and_tenant_access(): void
    {
        $controller = $this->source('mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionWorkflowController.php');

        self::assertStringContainsString("DB::table('people')", $controller);
        self::assertStringContainsString("DB::table('person_tenant_accesses')", $controller);
        self::assertStringContainsString("'status', 'active'", $controller);
        self::assertStringContainsString('Committee user must be the account linked to the canonical Person.', $controller);
    }

    public function test_challenges_and_independent_certification_are_persisted_and_routed(): void
    {
        $migration = $this->source('mases_core/src/Domains/Governance/Database/Migrations/2026_11_02_163000_create_election_challenges_and_certification.php');
        $routes = $this->source('mases_core/src/Domains/Governance/Routes/api.php');
        $controller = $this->source('mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionWorkflowController.php');

        foreach (['governance_election_objections', 'governance_election_appeals', 'governance_election_certifications'] as $table) {
            self::assertStringContainsString($table, $migration);
        }
        foreach (['submitObjection', 'submitAppeal', 'certifyElection', 'ratifyElection'] as $action) {
            self::assertStringContainsString($action, $routes);
            self::assertStringContainsString('function '.$action.'(', $controller);
        }
        self::assertStringContainsString('The tally preparer cannot independently certify the tally.', $controller);
        self::assertStringContainsString('All objections and appeals must receive a recorded outcome before certification.', $controller);
        self::assertStringContainsString('The certifier cannot ratify the same election.', $controller);
    }

    public function test_election_lifecycle_transitions_are_exposed_and_authority_gated(): void
    {
        $routes = $this->source('mases_core/src/Domains/Governance/Routes/api.php');
        $controller = $this->source('mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionWorkflowController.php');

        foreach (['verifyElectionAuthority', 'openElectionNominations', 'openPhysicalElectionVoting', 'closeElectionVoting'] as $action) {
            self::assertStringContainsString($action, $routes);
            self::assertStringContainsString('function '.$action.'(', $controller);
        }
        self::assertStringContainsString('A frozen, approved voter register is required before voting.', $this->source('mases_core/src/Domains/Governance/Services/GovernanceElectionLifecycleService.php'));
    }

    public function test_committee_decisions_require_two_distinct_votes_before_finalizing(): void
    {
        $controller = $this->source('mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionWorkflowController.php');
        $voteMigration = $this->source('mases_core/src/Domains/Governance/Database/Migrations/2026_11_02_164000_add_committee_vote_idempotency.php');
        $ballotMigration = $this->source('mases_core/src/Domains/Governance/Database/Migrations/2026_11_02_165000_add_committee_vote_ballot_flag.php');

        self::assertStringContainsString('already voted on this submission', $controller);
        self::assertStringContainsString('already voted on this candidate', $controller);
        self::assertStringContainsString('$votes < 2', $controller);
        self::assertStringContainsString('awaiting_committee_majority', $controller);
        self::assertStringContainsString('gov_elec_submission_vote_once', $voteMigration);
        self::assertStringContainsString('gov_elec_candidate_vote_once', $voteMigration);
        self::assertStringContainsString("->boolean('final_ballot_approved')->default(false)", $ballotMigration);
    }

    public function test_committee_recusal_and_identity_columns_are_persisted(): void
    {
        $migration = $this->source('mases_core/src/Domains/Governance/Database/Migrations/2026_11_02_160000_create_governance_election_hybrid_workflow_tables.php');
        $identityMigration = $this->source('mases_core/src/Domains/Governance/Database/Migrations/2026_11_02_162000_add_authenticated_committee_identity.php');

        self::assertStringContainsString("->dateTime('recused_at')->nullable()", $migration);
        self::assertStringContainsString("->string('recusal_scope', 80)->nullable()", $migration);
        self::assertStringContainsString("->unsignedBigInteger('user_id')->nullable()", $identityMigration);
    }
}
