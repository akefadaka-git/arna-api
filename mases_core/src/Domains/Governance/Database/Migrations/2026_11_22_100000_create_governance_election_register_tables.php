<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

/**
 * AGE A3 — tenant-scoped, immutable-by-lifecycle voter-register foundation.
 *
 * This migration extends the current-main election aggregate. It deliberately
 * does not recreate governance_elections or import the obsolete election
 * foundation from the historical AGE branches.
 *
 * A register is prepared from the canonical shareholding ledger, independently
 * reconciled, independently approved, then frozen. No voting entitlement,
 * share-class rate, proxy authority, or candidate qualification is inferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_election_register_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->char('snapshot_uuid', 36);
            $table->date('record_date');
            $table->string('source_reference', 160);
            $table->string('rule_version', 80);
            $table->char('content_sha256', 64);
            $table->unsignedInteger('voter_count');
            $table->string('register_status', 24)->default('draft');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('prepared_by')->nullable();
            $table->unsignedBigInteger('reconciled_by')->nullable();
            $table->dateTime('reconciled_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('integrity_verified_at')->nullable();
            $table->dateTime('frozen_at')->nullable();

            // Application-held HMAC attestations. Secrets never enter the DB.
            $table->string('prepared_attestation_key_id', 64)->nullable();
            $table->char('prepared_attestation_sha256', 64)->nullable();
            $table->string('reconciled_attestation_key_id', 64)->nullable();
            $table->char('reconciled_attestation_sha256', 64)->nullable();
            $table->string('approved_attestation_key_id', 64)->nullable();
            $table->char('approved_attestation_sha256', 64)->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'election_id'], 'gov_elec_register_tenant_election_uq');
            $table->unique(['tenant_id', 'id'], 'gov_elec_register_tenant_id_uq');
            $table->unique('snapshot_uuid', 'gov_elec_register_uuid_uq');
            $table->index(['tenant_id', 'register_status'], 'gov_elec_register_status_idx');
            $table->foreign(['tenant_id', 'election_id'], 'gov_elec_register_election_fk')
                ->references(['tenant_id', 'id'])->on('governance_elections')->restrictOnDelete();
            $table->foreign('created_by', 'gov_elec_register_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('prepared_by', 'gov_elec_register_preparer_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reconciled_by', 'gov_elec_register_reconciler_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by', 'gov_elec_register_approver_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('governance_election_voters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('snapshot_id');
            $table->unsignedBigInteger('shareholder_id');
            $table->string('share_class_code', 40);
            $table->decimal('share_quantity', 20, 4);
            $table->decimal('voting_weight', 20, 4);
            $table->string('entitlement_reference', 160);
            $table->boolean('proxy_authorized')->default(false);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'election_id', 'shareholder_id', 'share_class_code'],
                'gov_elec_voter_class_uq'
            );
            $table->index(['tenant_id', 'snapshot_id'], 'gov_elec_voter_snapshot_idx');
            $table->foreign(['tenant_id', 'election_id'], 'gov_elec_voter_election_fk')
                ->references(['tenant_id', 'id'])->on('governance_elections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'snapshot_id'], 'gov_elec_voter_snapshot_fk')
                ->references(['tenant_id', 'id'])->on('governance_election_register_snapshots')->restrictOnDelete();
            $table->foreign('shareholder_id', 'gov_elec_voter_shareholder_fk')
                ->references('id')->on('shareholders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('governance_election_register_snapshots')
            && Schema::hasTable('governance_election_voters')
            && (
                DB::table('governance_election_register_snapshots')->where('register_status', 'frozen')->exists()
                || DB::table('governance_election_voters')->exists()
            )) {
            throw new RuntimeException(
                'Refusing to remove voter-register evidence. Restore from a verified backup under an approved recovery procedure.'
            );
        }

        Schema::dropIfExists('governance_election_voters');
        Schema::dropIfExists('governance_election_register_snapshots');
    }
};
