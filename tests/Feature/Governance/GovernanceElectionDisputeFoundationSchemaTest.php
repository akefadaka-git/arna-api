<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            DB::table('governance_election_dispute_events')->whereIn('id', array_filter([$firstId, $otherTenantId]))->delete();
        }
    }
}
