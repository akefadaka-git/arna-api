<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Committee security foundation.
 *
 * These records are intentionally separate from ballot and tally data.
 * The event ledger is append-only at the application boundary and uses
 * a per-tenant/election hash chain to make alteration detectable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_election_committee_admins', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('person_id');
            $table->unsignedBigInteger('user_id');
            $table->string('appointment_reference', 160);
            $table->unsignedBigInteger('granted_by');
            $table->dateTime('granted_at');
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'user_id'], 'gov_elec_committee_admin_uq');
            $table->index(['tenant_id', 'election_id', 'status'], 'gov_elec_committee_admin_status_idx');
        });

        Schema::create('governance_election_committee_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('committee_user_id');
            $table->unsignedBigInteger('committee_person_id');
            $table->unsignedBigInteger('subject_person_id')->nullable();
            $table->string('conflict_type', 32);
            $table->string('scope', 24)->default('candidate');
            $table->text('disclosure');
            $table->string('evidence_reference', 160)->nullable();
            $table->dateTime('declared_at');
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('withdrawn_by')->nullable();
            $table->dateTime('withdrawn_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'election_id', 'committee_user_id', 'status'], 'gov_elec_conflict_actor_idx');
            $table->index(['tenant_id', 'election_id', 'subject_person_id'], 'gov_elec_conflict_subject_idx');
        });

        Schema::create('governance_election_committee_security_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('sequence');
            $table->uuid('event_uuid')->unique();
            $table->string('event_type', 64);
            $table->unsignedBigInteger('actor_user_id');
            $table->unsignedBigInteger('subject_user_id')->nullable();
            $table->unsignedBigInteger('subject_person_id')->nullable();
            $table->string('reference', 160)->nullable();
            $table->json('payload');
            $table->char('previous_hash', 64)->nullable();
            $table->char('event_hash', 64);
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->unique(['tenant_id', 'election_id', 'sequence'], 'gov_elec_security_event_seq_uq');
            $table->index(['tenant_id', 'election_id', 'event_type'], 'gov_elec_security_event_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_election_committee_security_events');
        Schema::dropIfExists('governance_election_committee_conflicts');
        Schema::dropIfExists('governance_election_committee_admins');
    }
};
