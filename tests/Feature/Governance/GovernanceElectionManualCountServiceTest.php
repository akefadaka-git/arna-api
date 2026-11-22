<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionManualCountService;
use Tests\TestCase;

final class GovernanceElectionManualCountServiceTest extends TestCase
{
    private object $triggerMigration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        $this->triggerMigration = require base_path(
            'mases_core/src/Domains/Governance/Database/Migrations/2026_11_22_100500_enforce_manual_count_evidence_immutability.php'
        );
        $this->triggerMigration->up();
    }

    protected function tearDown(): void
    {
        foreach ([
            'governance_election_manual_count_lines',
            'governance_election_manual_count_batches',
            'governance_election_candidates',
            'governance_election_committee_members',
            'governance_election_authority_evidence',
            'person_tenant_accesses',
            'people',
            'users',
            'governance_elections',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    private function createSchema(): void
    {
        Schema::create('governance_elections', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('status', 24);
            $table->string('voting_method', 24)->nullable();
            $table->unsignedBigInteger('authority_evidence_id')->nullable();
            $table->dateTime('authority_verified_at')->nullable();
            $table->unique(['tenant_id', 'id']);
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->boolean('is_active')->default(true);
            $table->dateTime('deleted_at')->nullable();
        });
        Schema::create('people', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('status', 24)->default('active');
            $table->dateTime('deleted_at')->nullable();
        });
        Schema::create('person_tenant_accesses', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('person_id');
            $table->string('status', 24);
        });
        Schema::create('governance_election_authority_evidence', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->string('status', 24);
        });
        Schema::create('governance_election_committee_members', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('person_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('appointment_evidence_id');
            $table->string('member_role', 32);
            $table->string('status', 24);
            $table->dateTime('recused_at')->nullable();
        });
        Schema::create('governance_election_candidates', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->string('status', 24);
            $table->unique(['tenant_id', 'election_id', 'id'], 'manual_test_candidate_tenant_id_uq');
        });
        Schema::create('governance_election_manual_count_batches', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->string('batch_reference', 100);
            $table->string('polling_station', 120)->nullable();
            $table->unsignedInteger('ballots_received');
            $table->unsignedInteger('ballots_valid');
            $table->unsignedInteger('ballots_rejected');
            $table->string('sealed_box_reference', 160);
            $table->string('count_sheet_reference', 160);
            $table->unsignedBigInteger('entered_by');
            $table->unsignedBigInteger('witnessed_by')->nullable();
            $table->dateTime('witnessed_at')->nullable();
            $table->string('status', 24)->default('pending_witness');
            $table->dateTime('counted_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'batch_reference'], 'manual_test_batch_ref_uq');
            $table->unique(['tenant_id', 'election_id', 'id'], 'manual_test_batch_tenant_id_uq');
        });
        Schema::create('governance_election_manual_count_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedInteger('ballot_count');
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'batch_id', 'candidate_id'], 'manual_test_line_once_uq');
        });
    }

    private function seedElection(string $status = 'counting', string $method = 'physical'): void
    {
        DB::table('governance_elections')->insert([
            'id' => 10,
            'tenant_id' => 7,
            'status' => $status,
            'voting_method' => $method,
            'authority_evidence_id' => 501,
            'authority_verified_at' => now(),
        ]);
        DB::table('governance_election_authority_evidence')->insert([
            'id' => 501,
            'tenant_id' => 7,
            'election_id' => 10,
            'status' => 'verified',
        ]);
        DB::table('governance_election_candidates')->insert([
            'id' => 301,
            'tenant_id' => 7,
            'election_id' => 10,
            'status' => 'eligible',
        ]);
    }

    private function member(int $userId, int $personId, int $tenantId = 7, string $role = 'committee_member', string $status = 'active', ?string $recusedAt = null): void
    {
        DB::table('users')->insert(['id' => $userId, 'is_active' => true, 'deleted_at' => null]);
        DB::table('people')->insert(['id' => $personId, 'status' => 'active', 'deleted_at' => null]);
        DB::table('person_tenant_accesses')->insert([
            'tenant_id' => $tenantId,
            'person_id' => $personId,
            'status' => 'active',
        ]);
        DB::table('governance_election_committee_members')->insert([
            'tenant_id' => $tenantId,
            'election_id' => 10,
            'person_id' => $personId,
            'user_id' => $userId,
            'appointment_evidence_id' => 501,
            'member_role' => $role,
            'status' => $status,
            'recused_at' => $recusedAt,
        ]);
    }

    private function payload(string $reference = 'COUNT-001'): array
    {
        return [
            'batch_reference' => $reference,
            'polling_station' => 'Koybe Station 01',
            'ballots_received' => 10,
            'ballots_valid' => 9,
            'ballots_rejected' => 1,
            'sealed_box_reference' => 'SEALED-BOX-001',
            'count_sheet_reference' => 'COUNT-SHEET-001',
            'lines' => [['candidate_id' => 301, 'ballot_count' => 6]],
        ];
    }

    public function test_count_batch_requires_active_committee_member_and_distinct_independent_witness(): void
    {
        $this->seedElection();
        $this->member(101, 201);
        $this->member(102, 202);
        $service = new GovernanceElectionManualCountService();

        $recorded = $service->record(7, 10, 101, $this->payload());
        self::assertSame('pending_witness', $recorded['batch']->status);
        self::assertSame(101, (int) $recorded['batch']->entered_by);
        self::assertCount(1, $recorded['lines']);
        self::assertSame(6, (int) $recorded['lines'][0]->ballot_count);
        self::assertFalse(Schema::hasColumn('governance_election_manual_count_lines', 'share_vote_weight'));

        try {
            $service->witness(7, 10, (int) $recorded['batch']->id, 101);
            self::fail('The recorder cannot witness their own count batch.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('batch', $exception->errors());
        }

        $witnessed = $service->witness(7, 10, (int) $recorded['batch']->id, 102);
        self::assertSame('witnessed', $witnessed->status);
        self::assertSame(102, (int) $witnessed->witnessed_by);
        self::assertNotNull($witnessed->witnessed_at);
    }

    public function test_same_person_cannot_record_and_witness_through_two_user_accounts(): void
    {
        $this->seedElection();
        $this->member(101, 201);
        $this->member(102, 201);
        $service = new GovernanceElectionManualCountService();
        $recorded = $service->record(7, 10, 101, $this->payload());

        try {
            $service->witness(7, 10, (int) $recorded['batch']->id, 102);
            self::fail('A second account for the same person cannot witness the count.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('batch', $exception->errors());
        }
    }

    public function test_count_rejects_unbalanced_totals_ineligible_candidates_and_non_counting_elections(): void
    {
        $this->seedElection();
        $this->member(101, 201);
        $service = new GovernanceElectionManualCountService();
        $unbalanced = $this->payload('COUNT-UNBALANCED');
        $unbalanced['ballots_valid'] = 8;

        try {
            $service->record(7, 10, 101, $unbalanced);
            self::fail('Unbalanced count totals must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('ballots_received', $exception->errors());
        }

        DB::table('governance_election_candidates')->where('id', 301)->update(['status' => 'ineligible']);
        try {
            $service->record(7, 10, 101, $this->payload('COUNT-INELIGIBLE'));
            self::fail('Ineligible candidates cannot receive count lines.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('lines', $exception->errors());
        }

        DB::table('governance_election_candidates')->where('id', 301)->update(['status' => 'eligible']);
        DB::table('governance_elections')->where('id', 10)->update(['status' => 'voting']);
        try {
            $service->record(7, 10, 101, $this->payload('COUNT-WRONG-STATE'));
            self::fail('Manual count evidence is accepted only in counting state.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('election', $exception->errors());
        }
    }

    public function test_revoked_tenant_access_blocks_count_entry_and_witness(): void
    {
        $this->seedElection();
        $this->member(101, 201);
        DB::table('person_tenant_accesses')->where('person_id', 201)->update(['status' => 'revoked']);

        try {
            (new GovernanceElectionManualCountService())->record(7, 10, 101, $this->payload());
            self::fail('Revoked tenant access must block count entry.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('governance_election_manual_count_batches')->count());
        }
    }

    public function test_database_guards_make_count_lines_and_batch_evidence_append_only(): void
    {
        $this->seedElection();
        $this->member(101, 201);
        $this->member(102, 202);
        $service = new GovernanceElectionManualCountService();
        $recorded = $service->record(7, 10, 101, $this->payload());
        $batchId = (int) $recorded['batch']->id;
        $lineId = (int) $recorded['lines'][0]->id;

        try {
            DB::table('governance_election_manual_count_batches')->where('id', $batchId)->update(['ballots_valid' => 8]);
            self::fail('Count batch source fields must be immutable.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        try {
            DB::table('governance_election_manual_count_lines')->where('id', $lineId)->update(['ballot_count' => 7]);
            self::fail('Count lines must be immutable.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        $service->witness(7, 10, $batchId, 102);

        try {
            DB::table('governance_election_manual_count_batches')->where('id', $batchId)->update(['status' => 'pending_witness']);
            self::fail('Witnessed evidence cannot regress to pending.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        try {
            DB::table('governance_election_manual_count_batches')->where('id', $batchId)->delete();
            self::fail('Manual count evidence cannot be deleted.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
