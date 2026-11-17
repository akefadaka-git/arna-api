<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionDisputeEvidenceService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionDisputeEvidenceIntegrityService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionDisputeEventLedger;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class GovernanceElectionDisputeFoundationSchemaTest extends TestCase
{
    public function test_legacy_objection_and_appeal_records_have_additive_receipt_metadata(): void
    {
        $this->assertTrue(Schema::hasColumns('governance_election_objections', [
            'receipt_reference',
            'submitted_at',
            'submission_sha256',
        ]));

        $this->assertTrue(Schema::hasColumns('governance_election_appeals', [
            'receipt_reference',
            'submitted_at',
            'submission_sha256',
        ]));
    }

    public function test_private_evidence_metadata_and_dispute_event_ledger_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('governance_election_dispute_evidence', [
            'tenant_id',
            'election_id',
            'subject_type',
            'subject_id',
            'storage_disk',
            'storage_key',
            'byte_size',
            'content_sha256',
            'verification_status',
            'uploaded_by',
            'uploaded_at',
        ]));

        $this->assertTrue(Schema::hasColumns('governance_election_dispute_events', [
            'tenant_id',
            'election_id',
            'subject_type',
            'subject_id',
            'event_type',
            'actor_user_id',
            'previous_event_sha256',
            'event_sha256',
            'occurred_at',
        ]));
    }

    public function test_evidence_storage_identity_is_unique_within_a_tenant_only(): void
    {
        $now = now();
        $base = [
            'election_id' => 900001,
            'subject_type' => 'objection',
            'subject_id' => 900001,
            'storage_disk' => 'private',
            'storage_key' => 'dispute-evidence/schema-test.bin',
            'original_filename' => 'schema-test.bin',
            'detected_mime_type' => 'application/octet-stream',
            'byte_size' => 4,
            'content_sha256' => str_repeat('a', 64),
            'verification_status' => 'pending',
            'uploaded_by' => 900001,
            'uploaded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $firstId = DB::table('governance_election_dispute_evidence')->insertGetId($base + ['tenant_id' => 900001]);
        $otherTenantId = null;

        try {
            try {
                DB::table('governance_election_dispute_evidence')->insert($base + ['tenant_id' => 900001]);
                $this->fail('A duplicate private storage identity in one tenant must be rejected.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            $otherTenantId = DB::table('governance_election_dispute_evidence')
                ->insertGetId($base + ['tenant_id' => 900002]);
            $this->assertGreaterThan(0, $otherTenantId);
        } finally {
            DB::table('governance_election_dispute_evidence')->whereIn('id', array_filter([$firstId, $otherTenantId]))->delete();
        }
    }

    public function test_dispute_event_digest_is_unique_within_a_tenant_only(): void
    {
        $now = now();
        $base = [
            'election_id' => 900001,
            'subject_type' => 'appeal',
            'subject_id' => 900001,
            'event_type' => 'evidence.received',
            'actor_user_id' => 900001,
            'event_payload' => json_encode(['fixture' => 'schema-test'], JSON_THROW_ON_ERROR),
            'previous_event_sha256' => null,
            'event_sha256' => str_repeat('b', 64),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $firstId = DB::table('governance_election_dispute_events')->insertGetId($base + ['tenant_id' => 900001]);
        $otherTenantId = null;

        try {
            try {
                DB::table('governance_election_dispute_events')->insert($base + ['tenant_id' => 900001]);
                $this->fail('A duplicate event digest in one tenant must be rejected.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            $otherTenantId = DB::table('governance_election_dispute_events')
                ->insertGetId($base + ['tenant_id' => 900002]);
            $this->assertGreaterThan(0, $otherTenantId);
        } finally {
            $this->withoutDisputeEventAppendOnlyTriggers(function () use ($firstId, $otherTenantId): void {
                DB::table('governance_election_dispute_events')->whereIn('id', array_filter([$firstId, $otherTenantId]))->delete();
            });
        }
    }

    public function test_evidence_upload_is_private_hashed_tenant_scoped_and_audited_in_the_chain(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $now = now();
        $personId = DB::table('people')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('person_tenant_accesses')->insert([
            'person_id' => $personId,
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $electionId = DB::table('governance_elections')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'DISPUTE-EVIDENCE-' . Str::upper(Str::random(8)),
            'title' => 'Dispute evidence service test',
            'status' => 'planned',
            'voting_method' => 'physical',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $objectionId = DB::table('governance_election_objections')->insertGetId([
            'tenant_id' => $tenant->id,
            'election_id' => $electionId,
            'submitted_by_shareholder_id' => 900001,
            'category' => 'procedural',
            'grounds' => 'Evidence service integration fixture.',
            'status' => 'submitted',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Storage::fake('local');
        config(['mases.governance.dispute_evidence_disk' => 'local']);
        $bytes = 'ARNA election evidence fixture';
        $file = UploadedFile::fake()->createWithContent('evidence.txt', $bytes);
        $evidenceId = null;
        
        try {
            $evidence = app(GovernanceElectionDisputeEvidenceService::class)->store(
                $file,
                (int) $tenant->id,
                (int) $electionId,
                'objection',
                (int) $objectionId,
                (int) $user->id,
            );
            $evidenceId = (int) $evidence->id;

            $this->assertSame('pending', $evidence->verification_status);
            $this->assertSame(hash('sha256', $bytes), $evidence->content_sha256);
            $this->assertSame('text/plain', $evidence->detected_mime_type);
            $this->assertSame((int) $tenant->id, (int) $evidence->tenant_id);
            $this->assertTrue(Storage::disk('local')->exists($evidence->storage_key));
            $this->assertSame('private', Storage::disk('local')->getVisibility($evidence->storage_key));

            $integrityService = app(GovernanceElectionDisputeEvidenceIntegrityService::class);
            $inspection = $integrityService->inspect((int) $tenant->id, $evidenceId);
            $this->assertSame('intact', $inspection['integrity_state']);
            $this->assertTrue($inspection['private_visibility']);
            $this->assertSame(strlen($bytes), $inspection['actual_byte_size']);

            // Simulate storage corruption. The inspector must report it without
            // mutating the evidence workflow state or repairing/deleting bytes.
            Storage::disk('local')->put($evidence->storage_key, 'altered evidence bytes', ['visibility' => 'private']);
            $inspection = $integrityService->inspect((int) $tenant->id, $evidenceId);
            $this->assertSame('mismatch', $inspection['integrity_state']);
            $this->assertNotSame($inspection['expected_sha256'], $inspection['actual_sha256']);
            $this->assertSame('pending', DB::table('governance_election_dispute_evidence')
                ->where('id', $evidenceId)
                ->value('verification_status'));

            Storage::disk('local')->delete($evidence->storage_key);
            $inspection = $integrityService->inspect((int) $tenant->id, $evidenceId);
            $this->assertSame('missing', $inspection['integrity_state']);

            $ledger = app(GovernanceElectionDisputeEventLedger::class);
            $this->assertTrue($ledger->verify((int) $tenant->id, (int) $electionId, 'objection', (int) $objectionId));

            $event = DB::table('governance_election_dispute_events')
                ->where('tenant_id', $tenant->id)
                ->where('election_id', $electionId)
                ->where('subject_type', 'objection')
                ->where('subject_id', $objectionId)
                ->first(['id', 'event_sha256']);
            $this->assertNotNull($event);

            // Query-builder writes bypass Eloquent hooks, so these assertions
            // prove the database triggers enforce immutability at the storage boundary.
            try {
                DB::table('governance_election_dispute_events')->where('id', $event->id)->update([
                    'event_payload' => json_encode(['tampered' => true], JSON_THROW_ON_ERROR),
                ]);
                $this->fail('Direct updates to the dispute event ledger must be rejected by the database.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            try {
                DB::table('governance_election_dispute_events')->where('id', $event->id)->delete();
                $this->fail('Direct deletes from the dispute event ledger must be rejected by the database.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            DB::table('governance_election_dispute_events')->insert([
                'tenant_id' => $tenant->id,
                'election_id' => $electionId,
                'subject_type' => 'objection',
                'subject_id' => $objectionId,
                'event_type' => 'test.corrupt',
                'actor_user_id' => $user->id,
                'event_payload' => json_encode(['tampered' => true], JSON_THROW_ON_ERROR),
                'previous_event_sha256' => $event->event_sha256,
                'event_sha256' => str_repeat('c', 64),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->assertFalse($ledger->verify((int) $tenant->id, (int) $electionId, 'objection', (int) $objectionId));
        } finally {
            $this->withoutDisputeEventAppendOnlyTriggers(function () use ($tenant, $electionId, $objectionId, $evidenceId, $personId, $user): void {
                if ($evidenceId !== null) {
                    $evidenceRow = DB::table('governance_election_dispute_evidence')->where('id', $evidenceId)->first(['storage_key']);
                    if ($evidenceRow !== null) {
                        Storage::disk('local')->delete($evidenceRow->storage_key);
                    }
                    DB::table('governance_election_dispute_evidence')->where('id', $evidenceId)->delete();
                }
                DB::table('governance_election_dispute_events')
                    ->where('tenant_id', $tenant->id)
                    ->where('election_id', $electionId)
                    ->where('subject_type', 'objection')
                    ->where('subject_id', $objectionId)
                    ->delete();
                DB::table('governance_election_objections')->where('id', $objectionId)->delete();
                DB::table('governance_elections')->where('id', $electionId)->delete();
                DB::table('person_tenant_accesses')->where('person_id', $personId)->delete();
                DB::table('people')->where('id', $personId)->delete();
                $user->delete();
                $tenant->delete();
            });
        }
    }

    private function withoutDisputeEventAppendOnlyTriggers(callable $callback): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS governance_election_dispute_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS governance_election_dispute_events_no_delete');
        } elseif ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS governance_election_dispute_events_no_mutation ON governance_election_dispute_events');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS governance_election_dispute_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS governance_election_dispute_events_no_delete');
        }

        try {
            $callback();
        } finally {
            if ($driver === 'mysql') {
                DB::unprepared("CREATE TRIGGER governance_election_dispute_events_no_update BEFORE UPDATE ON governance_election_dispute_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Election dispute events are append-only'");
                DB::unprepared("CREATE TRIGGER governance_election_dispute_events_no_delete BEFORE DELETE ON governance_election_dispute_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Election dispute events are append-only'");
            } elseif ($driver === 'pgsql') {
                DB::unprepared('CREATE TRIGGER governance_election_dispute_events_no_mutation BEFORE UPDATE OR DELETE ON governance_election_dispute_events FOR EACH ROW EXECUTE FUNCTION arna_reject_election_dispute_event_mutation()');
            } elseif ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER governance_election_dispute_events_no_update BEFORE UPDATE ON governance_election_dispute_events BEGIN SELECT RAISE(ABORT, 'Election dispute events are append-only'); END");
                DB::unprepared("CREATE TRIGGER governance_election_dispute_events_no_delete BEFORE DELETE ON governance_election_dispute_events BEGIN SELECT RAISE(ABORT, 'Election dispute events are append-only'); END");
            }
        }
    }

}
