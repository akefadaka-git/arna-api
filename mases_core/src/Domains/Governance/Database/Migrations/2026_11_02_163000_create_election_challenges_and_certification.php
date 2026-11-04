<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Election challenge and independent certification evidence.
 * No status transition in this migration automatically certifies or ratifies
 * an election; those decisions require recorded human authority and evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_election_objections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('election_id')->index();
            $table->unsignedBigInteger('nomination_id')->nullable()->index();
            $table->unsignedBigInteger('submitted_by_shareholder_id')->index();
            $table->string('category', 40);
            $table->text('grounds');
            $table->string('evidence_reference', 160)->nullable();
            $table->string('status', 24)->default('submitted')->index();
            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('governance_election_appeals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('election_id')->index();
            $table->unsignedBigInteger('objection_id')->index();
            $table->unsignedBigInteger('appellant_shareholder_id')->index();
            $table->text('grounds');
            $table->string('evidence_reference', 160)->nullable();
            $table->string('status', 24)->default('submitted')->index();
            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'objection_id', 'appellant_shareholder_id'], 'gov_elec_appeal_once');
        });

        Schema::create('governance_election_certifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('election_id')->index();
            $table->unsignedBigInteger('tally_id')->index();
            $table->string('certifying_authority', 160);
            $table->string('certification_reference', 160);
            $table->char('evidence_sha256', 64);
            $table->unsignedBigInteger('certified_by');
            $table->dateTime('certified_at');
            $table->unsignedBigInteger('ratified_by')->nullable();
            $table->dateTime('ratified_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id'], 'gov_elec_certification_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_election_certifications');
        Schema::dropIfExists('governance_election_appeals');
        Schema::dropIfExists('governance_election_objections');
    }
};
