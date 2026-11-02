<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\DB;
use Laravel\\Sanctum\\Sanctum;
use MaaleArna\\MASES\\Core\\Auth\\Models\\User;
use MaaleArna\\MASES\\Core\\Authorization\\Models\\Permission;
use MaaleArna\\MASES\\Core\\Authorization\\Models\\Role;
use MaaleArna\\MASES\\Core\\Tenancy\\Models\\Tenant;
use Tests\\TestCase;

final class GovernanceElectionWorkflowHttpTest extends TestCase
{
    use RefreshDatabase;

    private function actor(Tenant $tenant, array $permissions): User
    {
        $user = User::factory()->create();
        DB::table('tenant_memberships')->insert([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $role = Role::query()->create([
            'slug' => 'election-http-' . $tenant->id . '-' . $user->id,
            'name_en' => 'Election HTTP test role', 'scope' => 'tenant', 'tenant_id' => $tenant->id,
        ]);
        foreach ($permissions as $slug) {
            $permission = Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name_en' => $slug, 'scope' => 'tenant', 'tenant_id' => $tenant->id, 'category' => 'governance'],
            );
            DB::table('role_has_permissions')->insert([
                'role_id' => $role->id, 'permission_id' => $permission->id,
            ]);
        }
        DB::table('user_has_roles')->insert([
            'user_id' => $user->id, 'role_id' => $role->id,
            'assigned_at' => now(), 'assigned_by' => $user->id,
        ]);

        return $user;
    }

    private function election(Tenant $tenant, string $status = 'nominations_open'): int
    {
        return DB::table('governance_elections')->insertGetId([
            'tenant_id' => $tenant->id, 'code' => 'E-' . $tenant->id . '-' . uniqid(),
            'title' => 'Board election test', 'status' => $status,
            'voting_method' => 'physical', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function headers(Tenant $tenant): array
    {
        return ['X-Tenant-ID' => (string) $tenant->id];
    }

    public function test_workflow_endpoints_reject_unauthenticated_and_unprivileged_users(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $electionId = $this->election($tenant);

        $this->withHeaders($this->headers($tenant))
            ->getJson('/api/governance/elections/' . $electionId . '/committee/queue')
            ->assertUnauthorized();

        $reader = $this->actor($tenant, ['governance.election.view']);
        Sanctum::actingAs($reader);
        $this->withHeaders($this->headers($tenant))
            ->getJson('/api/governance/elections/' . $electionId . '/committee/queue')
            ->assertForbidden();
    }

    public function test_election_resource_is_not_visible_across_tenants(): void
    {
        $tenantA = Tenant::factory()->create(['is_active' => true]);
        $tenantB = Tenant::factory()->create(['is_active' => true]);
        $electionId = $this->election($tenantB);
        $reader = $this->actor($tenantA, ['governance.election.view']);
        Sanctum::actingAs($reader);

        $this->withHeaders($this->headers($tenantA))
            ->getJson('/api/governance/elections/' . $electionId)
            ->assertForbidden();
    }

    public function test_nomination_submission_requires_one_tenant_scoped_shareholder_identity(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $electionId = $this->election($tenant);
        $shareholder = $this->actor($tenant, ['governance.election.view']);
        Sanctum::actingAs($shareholder);
        $url = '/api/governance/elections/' . $electionId . '/nominations';
        $payload = ['candidate_person_id' => 91001, 'self_nomination' => true, 'statement' => 'Candidate statement for testing.'];

        $this->withHeaders($this->headers($tenant))->postJson($url, $payload)->assertStatus(409);

        DB::table('shareholders')->insert([
            'tenant_id' => $tenant->id, 'shareholder_type' => 'individual',
            'first_name_en' => 'Election', 'father_name_en' => 'Test',
            'user_id' => $shareholder->id, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeaders($this->headers($tenant))->postJson($url, $payload)
            ->assertCreated()->assertJsonPath('data.submitted', true);

        $this->assertDatabaseHas('governance_election_nomination_submissions', [
            'tenant_id' => $tenant->id, 'election_id' => $electionId,
            'nominated_by_shareholder_id' => DB::table('shareholders')->where('user_id', $shareholder->id)->value('id'),
            'candidate_person_id' => 91001,
        ]);
    }

    public function test_committee_decision_requires_active_membership_quorum_and_writes_audit_record(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $electionId = $this->election($tenant, 'candidate_review');
        $manager = $this->actor($tenant, ['governance.election.manage']);
        Sanctum::actingAs($manager);

        $nominationId = DB::table('governance_election_nominations')->insertGetId([
            'tenant_id' => $tenant->id, 'election_id' => $electionId, 'shareholder_id' => 70001,
            'status' => 'submitted', 'candidate_person_id' => 81001,
            'candidate_name_snapshot' => 'Test Candidate', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $headers = $this->headers($tenant);
        $decisionUrl = '/api/governance/elections/' . $electionId . '/committee/candidates/' . $nominationId . '/decision';
        $payload = ['decision' => 'approved', 'reason' => 'Evidence reviewed and eligibility confirmed.', 'final_ballot_approved' => true];

        $this->withHeaders($headers)->postJson($decisionUrl, $payload)->assertForbidden();

        DB::table('governance_election_committee_members')->insert([
            'tenant_id' => $tenant->id, 'election_id' => $electionId, 'person_id' => 81001,
            'user_id' => $manager->id, 'member_role' => 'committee_member', 'ex_officio' => false,
            'status' => 'active', 'appointed_at' => now(), 'appointment_reference' => 'GA-MIN-TEST',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withHeaders($headers)->postJson($decisionUrl, $payload)->assertStatus(409);

        DB::table('governance_election_committee_members')->insert([
            ['tenant_id' => $tenant->id, 'election_id' => $electionId, 'person_id' => 81002, 'user_id' => null, 'member_role' => 'committee_member', 'ex_officio' => false, 'status' => 'active', 'appointed_at' => now(), 'appointment_reference' => 'GA-MIN-TEST-2', 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenant->id, 'election_id' => $electionId, 'person_id' => 81003, 'user_id' => null, 'member_role' => 'committee_member', 'ex_officio' => false, 'status' => 'active', 'appointed_at' => now(), 'appointment_reference' => 'GA-MIN-TEST-3', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->withHeaders($headers)->postJson($decisionUrl, $payload)->assertOk();
        $this->assertDatabaseHas('governance_election_committee_decisions', [
            'tenant_id' => $tenant->id, 'election_id' => $electionId,
            'nomination_id' => $nominationId, 'decided_by' => $manager->id, 'decision' => 'approved',
        ]);
    }
}
