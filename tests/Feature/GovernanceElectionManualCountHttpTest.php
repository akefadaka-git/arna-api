<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class GovernanceElectionManualCountHttpTest extends TestCase
{
    use RefreshDatabase;

    private function actor(Tenant $tenant): User
    {
        $user = User::factory()->create();
        DB::table('tenant_memberships')->insert([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $role = Role::query()->create([
            'slug' => 'age-count-' . $tenant->id . '-' . $user->id,
            'name_en' => 'AGE count test role',
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
        ]);
        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'governance.election.manage'],
            ['name_en' => 'Manage elections', 'scope' => 'tenant', 'tenant_id' => $tenant->id, 'category' => 'governance'],
        );
        DB::table('role_has_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ]);
        DB::table('user_has_roles')->insert([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'assigned_at' => now(),
            'assigned_by' => $user->id,
        ]);

        return $user;
    }

    private function election(Tenant $tenant, string $status = 'counting'): int
    {
        return DB::table('governance_elections')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'AGE-COUNT-' . $tenant->id . '-' . uniqid(),
            'title' => 'Manual count test election',
            'status' => $status,
            'voting_method' => 'physical',
            'authority_reference' => 'AUTH-COUNT-TEST',
            'authority_verified_at' => now(),
            'authority_verified_by' => 700,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function appoint(Tenant $tenant, int $electionId, User $user, int $personId): void
    {
        DB::table('governance_election_committee_members')->insert([
            'tenant_id' => $tenant->id,
            'election_id' => $electionId,
            'person_id' => $personId,
            'user_id' => $user->id,
            'member_role' => 'committee_member',
            'ex_officio' => false,
            'status' => 'active',
            'appointed_at' => now(),
            'appointment_reference' => 'AGE-COUNT-APPOINTMENT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function nomination(int $tenantId, int $electionId, bool $approved = true): int
    {
        return DB::table('governance_election_nominations')->insertGetId([
            'tenant_id' => $tenantId,
            'election_id' => $electionId,
            'shareholder_id' => 901,
            'status' => 'approved',
            'candidate_person_id' => 902,
            'candidate_name_snapshot' => 'Count Test Candidate',
            'committee_status' => 'approved',
            'final_ballot_approved' => $approved,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function headers(Tenant $tenant): array
    {
        return ['X-Tenant-ID' => (string) $tenant->id];
    }

    private function payload(int $nominationId): array
    {
        return [
            'batch_reference' => 'COUNT-BATCH-001',
            'polling_station' => 'Koybe Station 01',
            'ballots_received' => 10,
            'ballots_valid' => 9,
            'ballots_rejected' => 1,
            'sealed_box_reference' => 'SEALED-BOX-001',
            'count_sheet_reference' => 'COUNT-SHEET-001',
            'lines' => [[
                'candidate_nomination_id' => $nominationId,
                'ballot_count' => 6,
                'share_vote_weight' => '18.0000',
            ]],
        ];
    }

    public function test_manual_count_batch_requires_two_distinct_active_committee_members_and_persists_aggregate_evidence(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $electionId = $this->election($tenant);
        $recorder = $this->actor($tenant);
        $witness = $this->actor($tenant);
        $this->appoint($tenant, $electionId, $recorder, 8101);
        $this->appoint($tenant, $electionId, $witness, 8102);
        $nominationId = $this->nomination((int) $tenant->id, $electionId);

        $payload = $this->payload($nominationId);
        Sanctum::actingAs($recorder);

        $response = $this->withHeaders($this->headers($tenant))
            ->postJson('/api/governance/elections/' . $electionId . '/count-batches', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.batch.batch_reference', 'COUNT-BATCH-001')
            ->assertJsonPath('data.batch.entered_by', $recorder->id)
            ->assertJsonPath('data.batch.witnessed_by', null)
            ->assertJsonPath('data.batch.status', 'pending_witness')
            ->assertJsonPath('data.lines.0.candidate_nomination_id', $nominationId);

        $batchId = (int) $response->json('data.batch.id');
        $this->assertDatabaseHas('governance_election_manual_count_batches', [
            'tenant_id' => $tenant->id,
            'election_id' => $electionId,
            'batch_reference' => 'COUNT-BATCH-001',
            'ballots_received' => 10,
            'ballots_valid' => 9,
            'ballots_rejected' => 1,
            'entered_by' => $recorder->id,
            'witnessed_by' => null,
            'status' => 'pending_witness',
        ]);

        Sanctum::actingAs($witness);
        $this->withHeaders($this->headers($tenant))
            ->postJson('/api/governance/elections/' . $electionId . '/count-batches/' . $batchId . '/witness')
            ->assertOk()
            ->assertJsonPath('data.status', 'witnessed')
            ->assertJsonPath('data.witnessed_by', $witness->id);

        $this->assertDatabaseHas('governance_election_manual_count_batches', [
            'id' => $batchId,
            'witnessed_by' => $witness->id,
            'status' => 'witnessed',
        ]);
        self::assertNotNull(DB::table('governance_election_manual_count_batches')->where('id', $batchId)->value('witnessed_at'));
        $this->assertDatabaseHas('governance_election_manual_count_lines', [
            'tenant_id' => $tenant->id,
            'election_id' => $electionId,
            'candidate_nomination_id' => $nominationId,
            'ballot_count' => 6,
            'share_vote_weight' => '18.0000',
        ]);
        $this->assertDatabaseCount('governance_election_tallies', 0);
    }

    public function test_manual_count_rejects_same_recorder_and_witness_or_unbalanced_batch_totals(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $electionId = $this->election($tenant);
        $recorder = $this->actor($tenant);
        $witness = $this->actor($tenant);
        $this->appoint($tenant, $electionId, $recorder, 8201);
        $this->appoint($tenant, $electionId, $witness, 8202);
        $nominationId = $this->nomination((int) $tenant->id, $electionId);
        $payload = $this->payload($nominationId);
        Sanctum::actingAs($recorder);

        $created = $this->withHeaders($this->headers($tenant))
            ->postJson('/api/governance/elections/' . $electionId . '/count-batches', $payload)
            ->assertCreated();
        $batchId = (int) $created->json('data.batch.id');

        Sanctum::actingAs($recorder);
        $this->withHeaders($this->headers($tenant))
            ->postJson('/api/governance/elections/' . $electionId . '/count-batches/' . $batchId . '/witness')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('batch');

        $payload['batch_reference'] = 'COUNT-BATCH-002';
        $payload['ballots_valid'] = 8;
        $this->withHeaders($this->headers($tenant))
            ->postJson('/api/governance/elections/' . $electionId . '/count-batches', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ballots_received');

        $this->assertDatabaseCount('governance_election_manual_count_batches', 1);
    }

    public function test_manual_count_rejects_unapproved_candidates_and_non_counting_elections(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $electionId = $this->election($tenant, 'voting');
        $recorder = $this->actor($tenant);
        $witness = $this->actor($tenant);
        $this->appoint($tenant, $electionId, $recorder, 8301);
        $this->appoint($tenant, $electionId, $witness, 8302);
        $nominationId = $this->nomination((int) $tenant->id, $electionId, false);
        $payload = $this->payload($nominationId);
        Sanctum::actingAs($recorder);

        $this->withHeaders($this->headers($tenant))
            ->postJson('/api/governance/elections/' . $electionId . '/count-batches', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('election');

        DB::table('governance_elections')->where('id', $electionId)->update(['status' => 'counting']);
        $this->withHeaders($this->headers($tenant))
            ->postJson('/api/governance/elections/' . $electionId . '/count-batches', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines');

        $this->assertDatabaseCount('governance_election_manual_count_batches', 0);
    }
}
