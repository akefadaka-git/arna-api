<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionRegisterService;
use RuntimeException;
use Tests\TestCase;

/**
 * Database-backed SQLite coverage for the AGE voter-register lifecycle.
 *
 * These tests create only the minimal relational schema required by the
 * production service. They exercise real query/transaction behavior, but
 * do not replace full-migration MySQL, HTTP authorization, or concurrency tests.
 */
final class AgeVoterRegisterDatabaseTest extends TestCase
{
    private GovernanceElectionRegisterService $service;
    private bool $immutabilityMigrationApplied = false;
    private bool $attestationMigrationApplied = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        $migration = require base_path('mases_core/src/Domains/Governance/Database/Migrations/2026_11_15_130000_enforce_frozen_voter_register_immutability.php');
        $migration->up();
        $this->immutabilityMigrationApplied = true;
        $attestationMigration = require base_path('mases_core/src/Domains/Governance/Database/Migrations/2026_11_15_140000_add_voter_register_attestations.php');
        $attestationMigration->up();
        $this->attestationMigrationApplied = true;
        config()->set('age.register_attestation.active_key_id', 'test-key-v1');
        config()->set('age.register_attestation.keys', ['test-key-v1' => base64_encode(str_repeat('k', 32))]);
        config()->set('governance.election_voting.verified_class_entitlements', [
            'ORDINARY' => [
                'verified' => true,
                'eligible' => true,
                'votes_per_share' => '1',
                'evidence_reference' => 'LEGAL-RULE-ORDINARY',
            ],
        ]);

        $this->service = new GovernanceElectionRegisterService();
        DB::table('governance_elections')->insert([
            'id' => 10,
            'tenant_id' => 7,
            'code' => 'AGE-TEST-01',
            'title' => 'AGE database test',
            'status' => 'planned',
            'authority_reference' => 'AUTH-TEST',
            'authority_verified_at' => now(),
            'authority_verified_by' => 90,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('shareholders')->insert([
            ['id' => 21, 'tenant_id' => 7, 'deleted_at' => null],
            ['id' => 22, 'tenant_id' => 7, 'deleted_at' => null],
        ]);
    }

    public function test_register_requires_three_distinct_actors_and_freezes_only_after_reconciliation(): void
    {
        $snapshotId = $this->prepareRegister();

        try {
            $this->service->reconcile(7, 10, $snapshotId, 100);
            self::fail('The preparer must not reconcile their own register.');
        } catch (RuntimeException $exception) {
            self::assertSame('The register preparer cannot independently reconcile the register.', $exception->getMessage());
        }

        $hash = $this->service->reconcile(7, 10, $snapshotId, 101);
        self::assertSame(64, strlen($hash));
        self::assertSame('reconciled', DB::table('governance_election_register_snapshots')->where('id', $snapshotId)->value('register_status'));

        try {
            $this->service->approveAndFreeze(7, 10, $snapshotId, 101);
            self::fail('The reconciler must not approve their own reconciliation.');
        } catch (RuntimeException $exception) {
            self::assertSame('The approver must be independent of both preparation and reconciliation.', $exception->getMessage());
        }

        $this->service->approveAndFreeze(7, 10, $snapshotId, 102);
        $snapshot = DB::table('governance_election_register_snapshots')->where('id', $snapshotId)->first();

        self::assertSame('frozen', $snapshot->register_status);
        self::assertSame(100, (int) $snapshot->prepared_by);
        self::assertSame(101, (int) $snapshot->reconciled_by);
        self::assertSame(102, (int) $snapshot->approved_by);
        self::assertNotNull($snapshot->frozen_at);
        self::assertTrue($this->service->hasValidSnapshotIntegrity(7, 10, $snapshotId));
    }

    public function test_tampered_register_rows_fail_integrity_and_cannot_be_frozen(): void
    {
        $snapshotId = $this->prepareRegister();
        $this->service->reconcile(7, 10, $snapshotId, 101);

        DB::table('governance_election_voters')
            ->where('snapshot_id', $snapshotId)
            ->where('shareholder_id', 21)
            ->update(['share_quantity' => '99.0000']);

        self::assertFalse($this->service->hasValidSnapshotIntegrity(7, 10, $snapshotId));

        try {
            $this->service->approveAndFreeze(7, 10, $snapshotId, 102);
            self::fail('Tampered voter rows must prevent approval and freeze.');
        } catch (RuntimeException $exception) {
            self::assertSame('Register content changed after reconciliation; approval is blocked.', $exception->getMessage());
        }

        self::assertSame('reconciled', DB::table('governance_election_register_snapshots')->where('id', $snapshotId)->value('register_status'));
        self::assertNull(DB::table('governance_election_register_snapshots')->where('id', $snapshotId)->value('frozen_at'));
    }

    public function test_record_date_entitlements_are_reconstructed_from_ledger_and_projection_mismatch_fails_closed(): void
    {
        DB::table('share_classes')->insert(['id' => 3, 'code' => 'ORDINARY']);
        DB::table('share_holdings')->insert([
            'id' => 31, 'shareholder_id' => 21, 'share_class_id' => 3, 'current_quantity' => '15.0000',
        ]);
        DB::table('share_holding_entries')->insert([
            [
                'id' => 41, 'share_holding_id' => 31, 'quantity' => '10.0000',
                'balance_before' => '0.0000', 'balance_after' => '10.0000',
                'occurred_at' => '2026-01-01 09:00:00',
            ],
            [
                'id' => 42, 'share_holding_id' => 31, 'quantity' => '5.0000',
                'balance_before' => '10.0000', 'balance_after' => '15.0000',
                'occurred_at' => '2026-02-01 09:00:00',
            ],
        ]);

        $rows = $this->service->reconstructAsOf(7, '2026-01-15', 'LEDGER-EVIDENCE-01');
        self::assertCount(1, $rows);
        self::assertSame(21, $rows[0]['shareholder_id']);
        self::assertSame('10.0000', $rows[0]['share_quantity']);
        self::assertSame('10.0000', $rows[0]['voting_weight']);

        DB::table('share_holdings')->where('id', 31)->update(['current_quantity' => '14.0000']);
        try {
            $this->service->reconstructAsOf(7, '2026-01-15', 'LEDGER-EVIDENCE-01');
            self::fail('A current projection that disagrees with the full ledger must be rejected.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            self::assertArrayHasKey('share_projection', $exception->errors());
        }
    }

    public function test_voter_rows_reject_missing_or_cross_tenant_snapshot_references(): void
    {
        $snapshotId = $this->prepareRegister();
        $voterId = (int) DB::table('governance_election_voters')
            ->where('snapshot_id', $snapshotId)->value('id');

        $this->assertFrozenWriteRejected(function () use ($snapshotId): void {
            DB::table('governance_election_voters')->insert([
                'tenant_id' => 8,
                'election_id' => 10,
                'snapshot_id' => $snapshotId,
                'shareholder_id' => 21,
                'share_class_code' => 'ORDINARY',
                'share_quantity' => '1.0000',
                'voting_weight' => '1.0000',
                'entitlement_reference' => 'CROSS-TENANT-ATTEMPT',
                'proxy_authorized' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertFrozenWriteRejected(function () use ($voterId): void {
            DB::table('governance_election_voters')->where('id', $voterId)
                ->update(['tenant_id' => 8]);
        });

        $this->assertFrozenWriteRejected(function () use ($voterId): void {
            DB::table('governance_election_voters')->where('id', $voterId)
                ->update(['snapshot_id' => 999999]);
        });

        $this->assertFrozenWriteRejected(function () use ($snapshotId): void {
            DB::table('governance_election_voters')->insert([
                'tenant_id' => 7,
                'election_id' => 10,
                'snapshot_id' => 999999,
                'shareholder_id' => 21,
                'share_class_code' => 'ORDINARY',
                'share_quantity' => '1.0000',
                'voting_weight' => '1.0000',
                'entitlement_reference' => 'MISSING-SNAPSHOT-ATTEMPT',
                'proxy_authorized' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        self::assertSame(2, DB::table('governance_election_voters')->where('snapshot_id', $snapshotId)->count());
        self::assertSame(7, (int) DB::table('governance_election_voters')->where('id', $voterId)->value('tenant_id'));
    }

    public function test_database_rejects_freeze_transition_without_independent_approval(): void
    {
        $snapshotId = $this->prepareRegister();

        try {
            DB::table('governance_election_register_snapshots')->where('id', $snapshotId)->update([
                'register_status' => 'frozen',
                'frozen_at' => now(),
            ]);
            self::fail('A draft register must not be frozen without reconciliation, independent approval and integrity evidence.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertTrue(str_contains(strtolower($exception->getMessage()), 'cannot freeze') || str_contains(strtolower($exception->getMessage()), 'lifecycle'));
        }

        $snapshot = DB::table('governance_election_register_snapshots')->where('id', $snapshotId)->first();
        self::assertSame('draft', $snapshot->register_status);
        self::assertNull($snapshot->frozen_at);
    }

    public function test_database_rejects_freeze_timestamp_without_frozen_status(): void
    {
        $snapshotId = $this->prepareRegister();

        try {
            DB::table('governance_election_register_snapshots')->where('id', $snapshotId)
                ->update(['frozen_at' => now()]);
            self::fail('A draft register must not receive a freeze timestamp before its status is frozen.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertTrue(str_contains(strtolower($exception->getMessage()), 'cannot freeze') || str_contains(strtolower($exception->getMessage()), 'lifecycle'));
        }

        $snapshot = DB::table('governance_election_register_snapshots')->where('id', $snapshotId)->first();
        self::assertSame('draft', $snapshot->register_status);
        self::assertNull($snapshot->frozen_at);
    }

    public function test_frozen_register_and_snapshot_reject_direct_database_mutations(): void
    {
        $snapshotId = $this->prepareRegister();
        $this->service->reconcile(7, 10, $snapshotId, 101);
        $this->service->approveAndFreeze(7, 10, $snapshotId, 102);

        $voterId = (int) DB::table('governance_election_voters')
            ->where('snapshot_id', $snapshotId)->value('id');

        $this->assertFrozenWriteRejected(function () use ($snapshotId): void {
            DB::table('governance_election_voters')->insert([
                'tenant_id' => 7,
                'election_id' => 10,
                'snapshot_id' => $snapshotId,
                'shareholder_id' => 21,
                'share_class_code' => 'ORDINARY',
                'share_quantity' => '1.0000',
                'voting_weight' => '1.0000',
                'entitlement_reference' => 'DIRECT-SQL-ATTEMPT',
                'proxy_authorized' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertFrozenWriteRejected(function () use ($voterId): void {
            DB::table('governance_election_voters')->where('id', $voterId)
                ->update(['share_quantity' => '999.0000']);
        });

        $this->assertFrozenWriteRejected(function () use ($voterId): void {
            DB::table('governance_election_voters')->where('id', $voterId)->delete();
        });

        $this->assertFrozenWriteRejected(function () use ($snapshotId): void {
            DB::table('governance_election_register_snapshots')->where('id', $snapshotId)
                ->update(['source_reference' => 'ALTERED-AFTER-FREEZE']);
        });

        $this->assertFrozenWriteRejected(function () use ($snapshotId): void {
            DB::table('governance_election_register_snapshots')->where('id', $snapshotId)->delete();
        });

        self::assertSame(2, DB::table('governance_election_voters')->where('snapshot_id', $snapshotId)->count());
        self::assertTrue($this->service->hasValidSnapshotIntegrity(7, 10, $snapshotId));
    }

    private function assertFrozenWriteRejected(callable $write): void
    {
        try {
            $write();
            self::fail('A direct database mutation of a frozen register must be rejected.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('Frozen voter', $exception->getMessage());
        }
    }

    public function test_snapshot_lifecycle_transitions_are_monotonic_and_metadata_is_immutable(): void
    {
        $snapshotId = $this->prepareRegister();

        try {
            DB::table('governance_election_register_snapshots')->where('id', $snapshotId)->update([
                'register_status' => 'reconciled',
                'reconciled_by' => 100,
                'reconciled_at' => now(),
                'integrity_verified_at' => now(),
            ]);
            self::fail('The preparer must not be recorded as an independent reconciler by direct database update.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('lifecycle', strtolower($exception->getMessage()));
        }

        try {
            DB::table('governance_election_register_snapshots')->where('id', $snapshotId)
                ->update(['content_sha256' => str_repeat('b', 64)]);
            self::fail('Prepared governing metadata must not be rewritten after snapshot creation.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('governing metadata', strtolower($exception->getMessage()));
        }

        $this->service->reconcile(7, 10, $snapshotId, 101);

        try {
            DB::table('governance_election_register_snapshots')->where('id', $snapshotId)
                ->update(['register_status' => 'draft']);
            self::fail('A reconciled register must not move backward to draft.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('lifecycle', strtolower($exception->getMessage()));
        }

        try {
            DB::table('governance_election_register_snapshots')->where('id', $snapshotId)
                ->update(['reconciled_by' => 103]);
            self::fail('Reconciliation identity must not change after reconciliation.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('lifecycle', strtolower($exception->getMessage()));
        }

        $this->service->approveAndFreeze(7, 10, $snapshotId, 102);
        self::assertSame('frozen', DB::table('governance_election_register_snapshots')
            ->where('id', $snapshotId)->value('register_status'));
    }

    public function test_prepared_attestation_detects_privileged_database_tampering_after_trigger_bypass(): void
    {
        $snapshotId = $this->prepareRegister();

        foreach ([
            'age_register_snapshot_initial_state_guard',
            'age_register_snapshot_lifecycle_transition_guard',
            'age_register_snapshot_freeze_transition_guard',
            'age_register_snapshot_frozen_update',
            'age_register_snapshot_frozen_delete',
            'age_voter_register_frozen_insert',
            'age_voter_register_frozen_update',
            'age_voter_register_frozen_delete',
            'age_register_attestation_insert_guard',
            'age_register_attestation_update_guard',
        ] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }

        DB::table('governance_election_register_snapshots')->where('id', $snapshotId)
            ->update(['content_sha256' => str_repeat('b', 64)]);
        DB::table('governance_election_voters')->where('snapshot_id', $snapshotId)
            ->where('shareholder_id', 21)->update(['share_quantity' => '999.0000']);

        try {
            $this->service->reconcile(7, 10, $snapshotId, 101);
            self::fail('A database writer without the application attestation key must not replace the prepared digest and voter rows.');
        } catch (RuntimeException $exception) {
            self::assertSame('Prepared register attestation is missing or invalid; reconciliation failed closed.', $exception->getMessage());
        }

        self::assertSame('draft', DB::table('governance_election_register_snapshots')
            ->where('id', $snapshotId)->value('register_status'));
    }

    public function test_register_preparation_fails_closed_without_an_attestation_key(): void
    {
        config()->set('age.register_attestation.active_key_id', null);

        try {
            $this->prepareRegister();
            self::fail('Register preparation must fail when the application-held attestation key is unavailable.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('attestation signing is not configured', $exception->getMessage());
        }

        self::assertSame(0, DB::table('governance_election_register_snapshots')->count());
    }

    public function test_snapshot_insert_requires_a_prepared_application_attestation(): void
    {
        try {
            DB::table('governance_election_register_snapshots')->insert([
                'tenant_id' => 7,
                'election_id' => 10,
                'record_date' => '2026-01-15',
                'source_reference' => 'UNATTESTED-DIRECT-INSERT',
                'rule_version' => 'AGE-RULES-1',
                'content_sha256' => str_repeat('a', 64),
                'voter_count' => 1,
                'created_by' => 100,
                'prepared_by' => 100,
                'register_status' => 'draft',
                'frozen_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            self::fail('Direct database inserts without an application-held prepared attestation must be rejected.');
        } catch (\Illuminate\\Database\\QueryException $exception) {
            self::assertStringContainsString('prepared application attestation only', strtolower($exception->getMessage()));
        }

        self::assertSame(0, DB::table('governance_election_register_snapshots')->count());
    }

    public function test_snapshot_insert_must_begin_in_prepared_draft_state(): void
    {
        $insertSnapshot = static function (array $overrides): void {
            DB::table('governance_election_register_snapshots')->insert(array_replace([
                'tenant_id' => 7,
                'election_id' => 10,
                'record_date' => '2026-01-15',
                'source_reference' => 'DIRECT-INSERT-TEST',
                'rule_version' => 'AGE-RULES-1',
                'content_sha256' => str_repeat('a', 64),
                'snapshot_uuid' => Str::uuid()->toString(),
                'prepared_attestation_sha256' => str_repeat('a', 64),
                'prepared_attestation_key_id' => 'test-key-v1',
                'voter_count' => 1,
                'created_by' => 100,
                'prepared_by' => 100,
                'register_status' => 'draft',
                'frozen_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ], $overrides));
        };

        try {
            $insertSnapshot([
                'register_status' => 'frozen',
                'frozen_at' => now(),
                'reconciled_by' => 101,
                'reconciled_at' => now(),
                'approved_by' => 102,
                'approved_at' => now(),
                'integrity_verified_at' => now(),
            ]);
            self::fail('A snapshot must not be inserted directly in the frozen state.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('initial prepared draft state', strtolower($exception->getMessage()));
        }

        try {
            $insertSnapshot([
                'register_status' => 'draft',
                'frozen_at' => now(),
            ]);
            self::fail('A draft snapshot must not be inserted with a freeze timestamp.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('initial prepared draft state', strtolower($exception->getMessage()));
        }

        self::assertSame(0, DB::table('governance_election_register_snapshots')->count());
    }

    public function test_register_snapshot_is_not_accessible_across_tenant_boundaries(): void
    {
        $snapshotId = $this->prepareRegister();

        self::assertFalse($this->service->hasValidSnapshotIntegrity(8, 10, $snapshotId));

        try {
            $this->service->reconcile(8, 10, $snapshotId, 101);
            self::fail('A tenant must not reconcile another tenant election register.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            self::assertTrue(true);
        }

        try {
            $this->service->approveAndFreeze(8, 10, $snapshotId, 102);
            self::fail('A tenant must not approve another tenant election register.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            self::assertTrue(true);
        }

        self::assertSame('draft', DB::table('governance_election_register_snapshots')
            ->where('id', $snapshotId)->value('register_status'));
    }

    protected function tearDown(): void
    {
        if ($this->attestationMigrationApplied) {
            $attestationMigration = require base_path('mases_core/src/Domains/Governance/Database/Migrations/2026_11_15_140000_add_voter_register_attestations.php');
            $attestationMigration->down();
        }
        if ($this->immutabilityMigrationApplied) {
            $migration = require base_path('mases_core/src/Domains/Governance/Database/Migrations/2026_11_15_130000_enforce_frozen_voter_register_immutability.php');
            $migration->down();
        }

        parent::tearDown();
    }

    private function prepareRegister(): int
    {
        return $this->service->prepare(
            7,
            10,
            '2026-01-15',
            'LEDGER-EVIDENCE-01',
            'AGE-RULES-1',
            100,
            [
                [
                    'shareholder_id' => 21,
                    'share_class_code' => 'ORDINARY',
                    'share_quantity' => '10.0000',
                    'voting_weight' => '10.0000',
                    'entitlement_reference' => 'LEDGER-EVIDENCE-01;class:LEGAL-RULE-ORDINARY',
                ],
                [
                    'shareholder_id' => 22,
                    'share_class_code' => 'ORDINARY',
                    'share_quantity' => '5.0000',
                    'voting_weight' => '5.0000',
                    'entitlement_reference' => 'LEDGER-EVIDENCE-01;class:LEGAL-RULE-ORDINARY',
                ],
            ],
        );
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('share_holding_entries');
        Schema::dropIfExists('share_holdings');
        Schema::dropIfExists('share_classes');
        Schema::dropIfExists('governance_election_voters');
        Schema::dropIfExists('governance_election_register_snapshots');
        Schema::dropIfExists('governance_elections');
        Schema::dropIfExists('shareholders');

        Schema::create('shareholders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->softDeletes();
        });
        Schema::create('governance_elections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('code');
            $table->string('title');
            $table->string('status');
            $table->date('record_date')->nullable();
            $table->string('authority_reference')->nullable();
            $table->dateTime('authority_verified_at')->nullable();
            $table->unsignedBigInteger('authority_verified_by')->nullable();
            $table->string('voting_rule_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('governance_election_register_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->date('record_date');
            $table->string('source_reference');
            $table->string('rule_version');
            $table->string('content_sha256', 64);
            $table->string('snapshot_uuid', 36)->nullable()->unique();
            $table->string('prepared_attestation_sha256', 64)->nullable();
            $table->string('prepared_attestation_key_id', 64)->nullable();
            $table->string('reconciliation_attestation_sha256', 64)->nullable();
            $table->string('reconciliation_attestation_key_id', 64)->nullable();
            $table->string('approval_attestation_sha256', 64)->nullable();
            $table->string('approval_attestation_key_id', 64)->nullable();
            $table->unsignedInteger('voter_count');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('prepared_by')->nullable();
            $table->unsignedBigInteger('reconciled_by')->nullable();
            $table->dateTime('reconciled_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('integrity_verified_at')->nullable();
            $table->string('register_status', 24)->default('draft');
            $table->dateTime('frozen_at')->nullable();
            $table->timestamps();
        });
        Schema::create('governance_election_voters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('snapshot_id');
            $table->unsignedBigInteger('shareholder_id');
            $table->string('share_class_code');
            $table->decimal('share_quantity', 20, 4);
            $table->decimal('voting_weight', 20, 4);
            $table->text('entitlement_reference');
            $table->boolean('proxy_authorized')->default(false);
            $table->timestamps();
        });
        Schema::create('share_classes', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
        });
        Schema::create('share_holdings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shareholder_id');
            $table->unsignedBigInteger('share_class_id');
            $table->decimal('current_quantity', 20, 4);
        });
        Schema::create('share_holding_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('share_holding_id');
            $table->decimal('quantity', 20, 4);
            $table->decimal('balance_before', 20, 4);
            $table->decimal('balance_after', 20, 4);
            $table->dateTime('occurred_at');
        });
    }
}
