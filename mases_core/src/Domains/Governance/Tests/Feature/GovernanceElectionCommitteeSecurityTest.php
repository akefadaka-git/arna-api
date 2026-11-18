<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionCommitteeSecurityService;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class GovernanceElectionCommitteeSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $administrator;
    private User $committeeMember;
    private Person $administratorPerson;
    private Person $committeePerson;
    private Person $candidate;
    private int $electionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create([
            'name_am' => 'የምርጫ ሙከራ',
            'name_en' => 'Election Security Test',
            'slug' => 'election-security-test',
            'domain' => 'election-security-test.example.test',
            'is_active' => true,
        ]);
        $this->withHeader('X-Tenant-ID', (string) $this->tenant->id);

        $this->administrator = $this->makeUser('election-admin@example.test');
        $this->committeeMember = $this->makeUser('committee-member@example.test');
        $this->administratorPerson = $this->makePerson($this->administrator);
        $this->committeePerson = $this->makePerson($this->committeeMember);
        $this->candidate = $this->makePerson($this->makeUser('candidate@example.test'));

        $this->grantPermission($this->administrator, 'governance.election.committee.administer');
        $this->grantPermission($this->committeeMember, 'governance.election.committee.conflict.declare');
        $this->grantPermission($this->committeeMember, 'governance.election.committee.decide');

        $this->createElectionFixtures();
    }

    public function test_administrator_grant_and_revocation_are_tenant_scoped_and_audited(): void
    {
        Sanctum::actingAs($this->administrator, ['*']);

        $this->postJson(route('api.governance.elections.committee.admins.store', ['election' => $this->electionId]), [
            'person_id' => $this->committeePerson->id,
            'user_id' => $this->committeeMember->id,
            'appointment_reference' => 'GA-RESOLUTION-TEST-001',
        ])->assertCreated()->assertJsonPath('data.status', 'active');

        $this->patchJson(route('api.governance.elections.committee.admins.revoke', [
            'election' => $this->electionId,
            'userId' => $this->committeeMember->id,
        ]), ['reason' => 'Committee administrator duties concluded'])
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        $events = DB::table('governance_election_committee_security_events')
            ->where('tenant_id', $this->tenant->id)
            ->where('election_id', $this->electionId)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $events);
        $this->assertSame('committee_admin.granted', $events[0]->event_type);
        $this->assertSame('committee_admin.revoked', $events[1]->event_type);
        $this->assertSame(1, (int) $events[0]->sequence);
        $this->assertSame(2, (int) $events[1]->sequence);
        $this->assertSame($events[0]->event_hash, $events[1]->previous_hash);
        $this->assertSame(64, strlen($events[0]->event_hash));
        $this->assertSame(64, strlen($events[1]->event_hash));

        $integrity = app(GovernanceElectionCommitteeSecurityService::class)
            ->verifySecurityEventChain((int) $this->tenant->id, $this->electionId);
        $this->assertTrue($integrity['valid']);
        $this->assertSame(2, $integrity['verified_events']);
        $this->assertNull($integrity['first_invalid_sequence']);
    }

    public function test_immediate_family_conflict_is_recorded_and_blocks_candidate_decision(): void
    {
        DB::table('governance_election_committee_members')->insert([
            'tenant_id' => $this->tenant->id,
            'election_id' => $this->electionId,
            'person_id' => $this->committeePerson->id,
            'user_id' => $this->committeeMember->id,
            'member_role' => 'committee_member',
            'ex_officio' => false,
            'status' => 'active',
            'appointed_at' => now(),
            'recused_at' => null,
            'recusal_scope' => null,
            'appointment_reference' => 'GA-RESOLUTION-TEST-002',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->committeeMember, ['*']);

        $this->postJson(route('api.governance.elections.committee.conflicts.store', ['election' => $this->electionId]), [
            'subject_person_id' => $this->candidate->id,
            'conflict_type' => 'immediate_family',
            'scope' => 'candidate',
            'disclosure' => 'The candidate is an immediate family member.',
            'evidence_reference' => 'DECLARATION-TEST-001',
        ])->assertCreated()->assertJsonPath('data.conflict_type', 'immediate_family');

        $this->expectException(AuthorizationException::class);
        app(GovernanceElectionCommitteeSecurityService::class)->assertMayDecide(
            (int) $this->tenant->id,
            $this->electionId,
            (int) $this->candidate->id,
            $this->committeeMember,
        );
    }

    public function test_unscoped_legacy_recusal_fails_closed(): void
    {
        DB::table('governance_election_committee_members')->insert([
            'tenant_id' => $this->tenant->id,
            'election_id' => $this->electionId,
            'person_id' => $this->committeePerson->id,
            'user_id' => $this->committeeMember->id,
            'member_role' => 'committee_member',
            'ex_officio' => false,
            'status' => 'active',
            'appointed_at' => now(),
            'recused_at' => now(),
            'recusal_scope' => null,
            'appointment_reference' => 'GA-RESOLUTION-TEST-003',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(AuthorizationException::class);
        app(GovernanceElectionCommitteeSecurityService::class)->assertMayDecide(
            (int) $this->tenant->id,
            $this->electionId,
            (int) $this->candidate->id,
            $this->committeeMember,
        );
    }

    private function createElectionFixtures(): void
    {
        if (!Schema::hasTable('governance_elections')) {
            Schema::create('governance_elections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('code', 80);
                $table->string('title');
                $table->string('status', 24)->default('planned');
                $table->string('voting_method', 32)->default('physical');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('governance_election_committee_members')) {
            Schema::create('governance_election_committee_members', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('election_id')->index();
                $table->unsignedBigInteger('person_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('member_role', 32);
                $table->boolean('ex_officio')->default(false);
                $table->string('status', 20)->default('active');
                $table->dateTime('appointed_at');
                $table->dateTime('recused_at')->nullable();
                $table->string('recusal_scope', 80)->nullable();
                $table->string('appointment_reference', 160);
                $table->timestamps();
            });
        }

        $this->electionId = (int) DB::table('governance_elections')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'code' => 'SECURITY-TEST-' . $this->tenant->id,
            'title' => 'Committee Security Test',
            'status' => 'planned',
            'voting_method' => 'physical',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function grantPermission(User $user, string $slug): void
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
            ]
        );
        $user->permissions()->attach($permission->id, ['assigned_at' => now()]);
    }

    private function makeUser(string $email): User
    {
        $user = User::query()->create([
            'first_name_en' => 'Election',
            'father_name_en' => 'Security',
            'email' => $email,
            'password' => bcrypt('not-used-in-test'),
            'locale' => 'am',
            'is_active' => true,
            'default_tenant_id' => $this->tenant->id,
        ]);
        $user->tenants()->attach($this->tenant->id, ['joined_at' => now()]);
        return $user;
    }

    private function makePerson(User $user): Person
    {
        $person = new Person([
            'first_name_en' => 'Election',
            'father_name_en' => 'Security',
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

    public function test_candidate_scoped_recusal_only_blocks_the_affected_candidate(): void
    {
        DB::table('governance_election_committee_members')->insert([
            'tenant_id' => $this->tenant->id,
            'election_id' => $this->electionId,
            'person_id' => $this->committeePerson->id,
            'user_id' => $this->committeeMember->id,
            'member_role' => 'committee_member',
            'ex_officio' => false,
            'status' => 'active',
            'appointed_at' => now(),
            'recused_at' => null,
            'recusal_scope' => null,
            'appointment_reference' => 'GA-RESOLUTION-TEST-004',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('governance_election_committee_members')
            ->where('tenant_id', $this->tenant->id)
            ->where('election_id', $this->electionId)
            ->where('user_id', $this->committeeMember->id)
            ->update([
                'recused_at' => now(),
                'recusal_scope' => 'candidate:' . $this->candidate->id,
            ]);

        $security = app(GovernanceElectionCommitteeSecurityService::class);
        $unaffectedCandidate = $this->makePerson($this->makeUser('unaffected-candidate@example.test'));

        $security->assertMayDecide(
            (int) $this->tenant->id,
            $this->electionId,
            (int) $unaffectedCandidate->id,
            $this->committeeMember,
        );

        $this->expectException(AuthorizationException::class);
        $security->assertMayDecide(
            (int) $this->tenant->id,
            $this->electionId,
            (int) $this->candidate->id,
            $this->committeeMember,
        );
    }


    public function test_malformed_candidate_recusal_scope_fails_closed(): void
    {
        DB::table('governance_election_committee_members')->insert([
            'tenant_id' => $this->tenant->id,
            'election_id' => $this->electionId,
            'person_id' => $this->committeePerson->id,
            'user_id' => $this->committeeMember->id,
            'member_role' => 'committee_member',
            'ex_officio' => false,
            'status' => 'active',
            'appointed_at' => now(),
            'recused_at' => now(),
            'recusal_scope' => 'candidate:not-a-person-id',
            'appointment_reference' => 'GA-RESOLUTION-TEST-005',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(AuthorizationException::class);
        app(GovernanceElectionCommitteeSecurityService::class)->assertMayDecide(
            (int) $this->tenant->id,
            $this->electionId,
            (int) $this->candidate->id,
            $this->committeeMember,
        );
    }

}
