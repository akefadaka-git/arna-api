<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('governance_elections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('governance_body_id')->constrained('governance_bodies')->restrictOnDelete();
            $table->string('title_am', 200)->nullable();
            $table->string('title_en', 200)->nullable();
            $table->string('mandate', 255);
            $table->string('status', 32)->default('draft');
            $table->dateTime('nominations_open_at')->nullable();
            $table->dateTime('nominations_close_at')->nullable();
            $table->dateTime('voting_open_at')->nullable();
            $table->dateTime('voting_close_at')->nullable();
            $table->json('rules_snapshot')->nullable();
            $table->foreignId('certified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('certified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'governance_body_id', 'status'], 'gov_election_tenant_body_status_idx');
        });
        Schema::create('governance_election_nominations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('election_id')->constrained('governance_elections')->restrictOnDelete();
            $table->foreignId('governance_membership_id')->constrained('governance_memberships')->restrictOnDelete();
            $table->text('statement')->nullable();
            $table->string('status', 24)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['election_id', 'governance_membership_id'], 'gov_election_nominee_uq');
            $table->index(['tenant_id', 'election_id', 'status'], 'gov_election_nom_status_idx');
        });
        Schema::create('governance_election_eligible_voters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('election_id')->constrained('governance_elections')->restrictOnDelete();
            $table->foreignId('governance_membership_id')->constrained('governance_memberships')->restrictOnDelete();
            $table->timestamp('cast_at')->nullable();
            $table->timestamps();
            $table->unique(['election_id', 'governance_membership_id'], 'gov_election_voter_uq');
            $table->index(['tenant_id', 'election_id', 'cast_at'], 'gov_election_voter_cast_idx');
        });
        Schema::create('governance_election_ballots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('election_id')->constrained('governance_elections')->restrictOnDelete();
            $table->foreignId('nomination_id')->constrained('governance_election_nominations')->restrictOnDelete();
            $table->string('ballot_receipt_hash', 128)->unique();
            $table->timestamp('cast_at');
            $table->index(['tenant_id', 'election_id', 'nomination_id'], 'gov_election_ballot_count_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_election_ballots');
        Schema::dropIfExists('governance_election_eligible_voters');
        Schema::dropIfExists('governance_election_nominations');
        Schema::dropIfExists('governance_elections');
    }
};
