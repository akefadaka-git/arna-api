<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Composite key supports tenant-safe nomination references.
        Schema::table('shareholders', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'shareholders_tenant_id_uq');
        });

        Schema::create('governance_election_nomination_windows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->string('status', 16)->default('draft');
            $table->dateTime('opens_at');
            $table->dateTime('closes_at');
            $table->string('rule_set_version', 80);
            $table->string('rule_set_digest', 64);
            $table->string('authority_reference', 200);
            $table->unsignedBigInteger('authority_document_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('opened_by')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->string('closure_reason', 2000)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id'], 'gov_nom_window_election_uq');
            $table->foreign(['tenant_id', 'election_id'], 'gov_nom_window_election_fk')
                ->references(['tenant_id', 'id'])->on('governance_elections')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'opens_at', 'closes_at'], 'gov_nom_window_status_idx');
        });

        Schema::create('governance_election_candidates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('person_id');
            $table->string('status', 32)->default('intake_pending');
            $table->string('consent_status', 16)->default('pending');
            $table->string('consent_method', 16)->nullable();
            $table->dateTime('consented_at')->nullable();
            $table->unsignedBigInteger('consented_by')->nullable();
            $table->json('declarations')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'person_id'], 'gov_elec_candidate_person_uq');
            $table->unique(['tenant_id', 'election_id', 'id'], 'gov_elec_candidate_tenant_id_uq');
            $table->foreign(['tenant_id', 'election_id'], 'gov_elec_candidate_election_fk')
                ->references(['tenant_id', 'id'])->on('governance_elections')->restrictOnDelete();
            $table->foreign('person_id', 'gov_elec_candidate_person_fk')
                ->references('id')->on('people')->restrictOnDelete();
            $table->index(['tenant_id', 'election_id', 'status'], 'gov_elec_candidate_status_idx');
        });

        Schema::create('governance_election_nomination_submissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('nominated_by_shareholder_id');
            $table->unsignedBigInteger('submitted_by_user_id');
            $table->boolean('self_nomination')->default(false);
            $table->string('status', 16)->default('submitted');
            $table->string('idempotency_key', 64);
            $table->string('payload_digest', 64);
            $table->string('rule_set_version', 80);
            $table->string('rule_set_digest', 64);
            $table->text('statement')->nullable();
            $table->dateTime('submitted_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'idempotency_key'], 'gov_nom_idempotency_uq');
            $table->unique(['tenant_id', 'election_id', 'id'], 'gov_nom_tenant_id_uq');
            $table->unique(['tenant_id', 'election_id', 'candidate_id', 'nominated_by_shareholder_id'], 'gov_nom_holder_candidate_uq');
            $table->foreign(['tenant_id', 'election_id', 'candidate_id'], 'gov_nom_candidate_fk')
                ->references(['tenant_id', 'election_id', 'id'])->on('governance_election_candidates')->restrictOnDelete();
            $table->foreign(['tenant_id', 'nominated_by_shareholder_id'], 'gov_nom_shareholder_fk')
                ->references(['tenant_id', 'id'])->on('shareholders')->restrictOnDelete();
            $table->foreign('submitted_by_user_id', 'gov_nom_submitter_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'election_id', 'status'], 'gov_nom_status_idx');
        });

        Schema::create('governance_election_candidate_evidence', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('file_id');
            $table->string('evidence_type', 32);
            $table->string('metadata_digest', 64);
            $table->unsignedBigInteger('attached_by');
            $table->dateTime('attached_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'candidate_id', 'file_id', 'evidence_type'], 'gov_candidate_evidence_uq');
            $table->foreign(['tenant_id', 'election_id', 'candidate_id'], 'gov_candidate_evidence_candidate_fk')
                ->references(['tenant_id', 'election_id', 'id'])->on('governance_election_candidates')->restrictOnDelete();
            $table->foreign('attached_by', 'gov_candidate_evidence_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'election_id', 'candidate_id'], 'gov_candidate_evidence_idx');
        });

        Schema::create('governance_election_candidate_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('candidate_id');
            $table->string('outcome', 32);
            $table->string('rule_set_version', 80);
            $table->string('rule_set_digest', 64);
            $table->string('rationale', 2000);
            $table->json('evidence_references');
            $table->unsignedBigInteger('decided_by');
            $table->unsignedInteger('aggregate_version');
            $table->dateTime('decided_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'candidate_id', 'aggregate_version'], 'gov_candidate_decision_version_uq');
            $table->foreign(['tenant_id', 'election_id', 'candidate_id'], 'gov_candidate_decision_candidate_fk')
                ->references(['tenant_id', 'election_id', 'id'])->on('governance_election_candidates')->restrictOnDelete();
            $table->foreign('decided_by', 'gov_candidate_decision_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'election_id', 'outcome'], 'gov_candidate_decision_outcome_idx');
        });

        Schema::create('governance_election_candidate_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedInteger('sequence');
            $table->string('event_type', 48);
            $table->unsignedBigInteger('actor_user_id');
            $table->json('payload')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'candidate_id', 'sequence'], 'gov_candidate_event_seq_uq');
            $table->foreign(['tenant_id', 'election_id', 'candidate_id'], 'gov_candidate_event_candidate_fk')
                ->references(['tenant_id', 'election_id', 'id'])->on('governance_election_candidates')->restrictOnDelete();
            $table->foreign('actor_user_id', 'gov_candidate_event_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'election_id', 'candidate_id', 'occurred_at'], 'gov_candidate_event_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_election_candidate_events');
        Schema::dropIfExists('governance_election_candidate_decisions');
        Schema::dropIfExists('governance_election_candidate_evidence');
        Schema::dropIfExists('governance_election_nomination_submissions');
        Schema::dropIfExists('governance_election_candidates');
        Schema::dropIfExists('governance_election_nomination_windows');
        Schema::table('shareholders', function (Blueprint $table): void {
            $table->dropUnique('shareholders_tenant_id_uq');
        });
    }
};
