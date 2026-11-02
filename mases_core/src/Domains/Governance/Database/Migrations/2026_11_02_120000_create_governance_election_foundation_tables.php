<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3 foundation only. Legal rules are references, never inferred defaults.
 * Digital voting is intentionally not represented by an enabled workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_elections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('governance_body_id')->nullable()->index();
            $table->string('code', 80);
            $table->string('title');
            $table->string('status', 24)->default('planned')->index();
            $table->date('record_date')->nullable();
            $table->dateTime('nominations_open_at')->nullable();
            $table->dateTime('nominations_close_at')->nullable();
            $table->dateTime('poll_open_at')->nullable();
            $table->dateTime('poll_close_at')->nullable();
            $table->string('authority_reference', 160)->nullable();
            $table->dateTime('authority_verified_at')->nullable();
            $table->unsignedBigInteger('authority_verified_by')->nullable();
            $table->string('voting_rule_version', 80)->nullable();
            $table->string('voting_method', 32)->default('physical');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code'], 'gov_elections_tenant_code_uq');
        });

        Schema::create('governance_election_register_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('election_id')->index();
            $table->date('record_date');
            $table->string('source_reference', 160);
            $table->string('rule_version', 80);
            $table->char('content_sha256', 64);
            $table->unsignedInteger('voter_count');
            $table->unsignedBigInteger('created_by');
            $table->dateTime('frozen_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id'], 'gov_elec_snap_tenant_elec_uq');
        });

        Schema::create('governance_election_voters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('election_id')->index();
            $table->unsignedBigInteger('snapshot_id')->index();
            $table->unsignedBigInteger('shareholder_id')->index();
            $table->string('share_class_code', 40);
            $table->decimal('share_quantity', 20, 4);
            $table->decimal('voting_weight', 20, 4);
            $table->string('entitlement_reference', 160);
            $table->boolean('proxy_authorized')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'shareholder_id'], 'gov_election_voter_once');
        });

        Schema::create('governance_election_nominations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('election_id')->index();
            $table->unsignedBigInteger('shareholder_id')->index();
            $table->string('status', 24)->default('submitted')->index();
            $table->string('evidence_reference', 160)->nullable();
            $table->text('eligibility_reason')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'shareholder_id'], 'gov_election_nominee_once');
        });

        Schema::create('governance_election_ballots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('election_id')->index();
            $table->unsignedBigInteger('voter_id')->index();
            $table->string('ballot_reference', 100);
            $table->string('channel', 24)->default('physical');
            $table->string('status', 24)->default('issued');
            $table->string('custody_reference', 160)->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'voter_id'], 'gov_election_ballot_once');
            $table->unique(['tenant_id', 'election_id', 'ballot_reference'], 'gov_election_ballot_ref_once');
        });

        Schema::create('governance_election_tallies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('election_id')->index();
            $table->string('version', 40);
            $table->string('method', 32)->default('manual_reconciled');
            $table->json('result_payload');
            $table->char('evidence_sha256', 64);
            $table->unsignedBigInteger('prepared_by');
            $table->dateTime('prepared_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_election_tallies');
        Schema::dropIfExists('governance_election_ballots');
        Schema::dropIfExists('governance_election_nominations');
        Schema::dropIfExists('governance_election_voters');
        Schema::dropIfExists('governance_election_register_snapshots');
        Schema::dropIfExists('governance_elections');
    }
};
