<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

/**
 * Evidence-neutral A3 foundation for election objection and appeal intake.
 *
 * Existing objection/appeal rows remain valid: receipt and digest columns are
 * nullable for legacy records. This migration does not establish standing,
 * deadlines, jurisdiction, decision authority, quorum, appeal rights, or
 * certification effects. Evidence rows contain storage metadata only; the
 * actual file bytes remain in private object storage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('governance_election_objections', function (Blueprint $table): void {
            $table->string('receipt_reference', 64)->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->char('submission_sha256', 64)->nullable();
            $table->unique(['tenant_id', 'receipt_reference'], 'gov_elec_obj_receipt_uq');
        });

        Schema::table('governance_election_appeals', function (Blueprint $table): void {
            $table->string('receipt_reference', 64)->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->char('submission_sha256', 64)->nullable();
            $table->unique(['tenant_id', 'receipt_reference'], 'gov_elec_app_receipt_uq');
        });

        Schema::create('governance_election_dispute_evidence', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->string('subject_type', 16);
            $table->unsignedBigInteger('subject_id');
            $table->string('storage_disk', 80);
            $table->string('storage_key', 512);
            $table->string('original_filename', 255);
            $table->string('detected_mime_type', 160)->nullable();
            $table->unsignedBigInteger('byte_size');
            $table->char('content_sha256', 64);
            $table->string('verification_status', 20)->default('pending');
            $table->unsignedBigInteger('uploaded_by');
            $table->dateTime('uploaded_at');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'election_id', 'subject_type', 'subject_id'], 'gov_elec_evidence_subject_idx');
            $table->unique(['tenant_id', 'storage_disk', 'storage_key'], 'gov_elec_evidence_storage_uq');
        });

        Schema::create('governance_election_dispute_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->string('subject_type', 16);
            $table->unsignedBigInteger('subject_id');
            $table->string('event_type', 48);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->json('event_payload')->nullable();
            $table->char('previous_event_sha256', 64)->nullable();
            $table->char('event_sha256', 64);
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'election_id', 'subject_type', 'subject_id', 'id'], 'gov_elec_dispute_event_subject_idx');
            $table->unique(['tenant_id', 'event_sha256'], 'gov_elec_dispute_event_hash_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_election_dispute_events');
        Schema::dropIfExists('governance_election_dispute_evidence');

        Schema::table('governance_election_appeals', function (Blueprint $table): void {
            $table->dropUnique('gov_elec_app_receipt_uq');
            $table->dropColumn(['receipt_reference', 'submitted_at', 'submission_sha256']);
        });

        Schema::table('governance_election_objections', function (Blueprint $table): void {
            $table->dropUnique('gov_elec_obj_receipt_uq');
            $table->dropColumn(['receipt_reference', 'submitted_at', 'submission_sha256']);
        });
    }
};
