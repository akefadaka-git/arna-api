<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class GovernanceElectionCommitteeDecisionHttpTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private int $electionId;
    private User $decisionMaker;
    private Person $decisionMakerPerson;
    private Person $candidate;
    private User $committeeMemberWithoutDecision;
    private int $nominationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['is_active' => true]);
        $this->electionId = (int) DB::table('governance_elections')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'code' => 'DECISION-HTTP-' . $this->tenant->id,
            'title' => 'Committee decision HTTP test',
            'status' => 'candidate_review',
            'voting_method' => 'physical',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$this->decisionMaker, $this->decisionMakerPerson] = $this->createMember(
            'decision-maker@example.test',
            ['governance.election.manage', 'governance.election.committee.decide'],
        );
        [$this->committeeMemberWithoutDecision] = $this->createMember('committee-two@example.test', ['governance.election.manage']);
        $this->createMember('committee-three@example.test', []);

        $this->candidate = $this->createCandidate('candidate@example.test');

        $this->nominationId = (int) DB::table('governance_election_nominations')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'election_id' => $this->electionId,
            'shareholder_id' => 70001,
            'status' => 'submitted',
            'candidate_person_id' => $this->candidate->id,
            'candidate_name_snapshot' => 'Candidate Person',
            'nomination_source' => 'shareholder',
            'nominated_by_shareholder_id' => 70002,
            'nomination_statement' => 'Nomination statement for HTTP security testing.',
            'committee_status' => 'pending',
            'final_ballot_approved' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_real_nomination_decision_route_denies_member_without_dedicated_decision_permission(): void
    {
        $actor = $this->committeeMemberWithoutDecision;
        Sanctum::actingAs($actor, ['*']);
        $this->decisionRequest()->assertForbidden();

        $this->assertDatabaseMissing('governance_election_committee_decisions', [
            'tenant_id' => $this->tenant->id,
            'election_id' => $this->electionId,
            'nomination_id' => $this->nominationId,
            'decided_by' => $actor->id,
        ]);
    }

    public function test_real_nomination_decision_route_denies_an_active_candidate_conflict(): void
    {
        DB::table('governance_election_committee_conflicts')->insert([
            'tenant_id' => $this->tenant->id,
            'election_id' => $this->electionId,
            'committee_user_id' => $this->decisionMaker->id,
            'committee_person_id' => $this->decisionMakerPerson->id,
            'subject_person_id' => $this->candidate->id,
            'conflict_type' => 'immediate_family',
            'scope' => 'candidate',
            'disclosure' => 'The candidate is an immediate family member.',
            'declared_at' => now(),
            'status' => 'active',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->decisionMaker, ['*']);
        $this->decisionRequest()->assertForbidden();

        $this->assertDatabaseMissing('governance_election_committee_decisions', [
            'tenant_id' => $this->tenant->id,
            'election_id' => $this->electionId,
            'nomination_id' => $this->nominationId,
            'decided_by' => $this->decisionMaker->id,
        ]);
    }

    public function test_real_nomination_decision_route_allows_unaffected_candidate_recusal_but_does_not_finalize_without_authority_rule(): void
    {
        $otherCandidate = $this->createCandidate('other-candidate@example.test');
        DB::table('governance_election_committee_members')
            ->where('tenant_id', $this->tenant->id)
            ->where('election_id', $this->electionId)
            ->where('user_id', $this->decisionMaker->id)
            ->update([
                'recused_at' => now(),
                'recusal_scope' => 'candidate:' . $otherCandidate->id,
            ]);

        Sanctum::actingAs($this->decisionMaker, ['*']);
        $response = $this->decisionRequest()->assertOk()
            ->assertJsonPath('data.vote_recorded', true)
            ->assertJsonPath('data.status', 'awaiting_authority_defined_decision_rule');

        $this->assertDatabaseHas('governance_election_committee_decisions', [
            'tenant_id' => $this->tenant->id,
            'election_id' => $this->electionId,
            'nomination_id' => $this->nominationId,
            'decision_type' => 'candidate_review',
            'decided_by' => $this->decisionMaker->id,
        ]);
        $this->assertDatabaseHas('governance_election_nominations', [
            'id' => $this->nominationId,
            'committee_status' => 'pending',
            'final_ballot_approved' => false,
        ]);
    }

    public function test_real_nomination_decision_route_blocks_self_candidacy(): void
    {
        DB::table('governance_election_nominations')->where('id', $this->nominationId)
            ->update(['candidate_person_id' => $this->decisionMakerPerson->id]);

        Sanctum::actingAs($this->decisionMaker, ['*']);
        $this->decisionRequest()->assertForbidden();

        $this->assertDatabaseMissing('governance_election_committee_decisions', [
            'tenant_id' => $this->tenant->id,
            'election_id' => $this->electionId,
            'nomination_id' => $this->nominationId,
            'decided_by' => $this->decisionMaker->id,
        ]);
    }

    private function decisionRequest()
    {
        return $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson(
                '/api/governance/elections/' . $this->electionId
                . '/committee/candidates/' . $this->nominationId . '/decision',
                [
                    'decision' => 'approved',
                    'reason' => 'Evidence reviewed for committee decision security test.',
                    'final_ballot_approved' => true,
                ],
            );
    }

    private function createMember(string $email, array $permissions): array
    {
        $user = User::query()->create([
            'first_name_en' => 'Election',
            'father_name_en' => 'Committee',
            'email' => $email,
            'password' => bcrypt('not-used-in-test'),
            'locale' => 'am',
            'is_active' => true,
            'default_tenant_id' => $this->tenant->id,
        ]);
        DB::table('tenant_memberships')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $person = $this->makePerson($user);
        DB::table('governance_election_committee_members')->insert([
            'tenant_id' => $this->tenant->id,
            'election_id' => $this->electionId,
            'person_id' => $person->id,
            'user_id' => $user->id,
            'member_role' => 'committee_member',
            'ex_officio' => false,
            'status' => 'active',
            'appointed_at' => now(),
            'appointment_reference' => 'GA-HTTP-TEST-' . $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($permissions as $slug) {
            $this->grant($user, $slug);
        }

        return [$user, $person];
    }

    private function createCandidate(string $email): Person
    {
        [$user, $person] = $this->createMember($email, []);
        DB::table('governance_election_committee_members')
            ->where('tenant_id', $this->tenant->id)
            ->where('election_id', $this->electionId)
            ->where('user_id', $user->id)
            ->delete();

        return $person;
    }

    private function makePerson(User $user): Person
    {
        $person = new Person([
            'first_name_en' => 'Election',
            'father_name_en' => 'Person',
        ]);
        $person->user_id = $user->id;
        $person->status = 'active';
        $person->save();

        PersonTenantAccess::query()->create([
            'person_id' => $person->id,
            'tenant_id' => $this->tenant->id,
            'created_by' => $user->id,
            'status' => 'active',
        ]);

        return $person;
    }

    private function grant(User $user, string $slug): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name_am' => $slug,
                'name_en' => $slug,
                'description_am' => $slug,
                'description_en' => $slug,
                'category' => 'governance',
                'scope' => 'tenant',
                'tenant_id' => null,
                'application_id' => null,
                'is_active' => true,
                'is_system' => true,
            ],
        );
        $user->permissions()->attach($permission->id, ['assigned_at' => now()]);
    }
}
