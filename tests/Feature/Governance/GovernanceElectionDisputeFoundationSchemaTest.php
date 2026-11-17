<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

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
}
