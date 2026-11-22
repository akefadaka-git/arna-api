<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_election_manual_count_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->string('batch_reference', 100);
            $table->string('polling_station', 120)->nullable();
            $table->unsignedInteger('ballots_received');
            $table->unsignedInteger('ballots_valid');
            $table->unsignedInteger('ballots_rejected');
            $table->string('sealed_box_reference', 160);
            $table->string('count_sheet_reference', 160);
            $table->unsignedBigInteger('entered_by');
            $table->unsignedBigInteger('witnessed_by')->nullable();
            $table->dateTime('witnessed_at')->nullable();
            $table->string('status', 24)->default('pending_witness');
            $table->dateTime('counted_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'election_id', 'batch_reference'], 'gov_elec_manual_batch_uq');
            $table->unique(['tenant_id', 'election_id', 'id'], 'gov_elec_manual_batch_tenant_id_uq');
            $table->index(['tenant_id', 'election_id', 'status', 'counted_at'], 'gov_elec_manual_batch_status_idx');
            $table->foreign(['tenant_id', 'election_id'], 'gov_elec_manual_batch_election_fk')
                ->references(['tenant_id', 'id'])->on('governance_elections')->restrictOnDelete();
            $table->foreign('entered_by', 'gov_elec_manual_batch_recorder_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('witnessed_by', 'gov_elec_manual_batch_witness_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('governance_election_manual_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedInteger('ballot_count');
            $table->timestamps();

            $table->unique(['tenant_id', 'election_id', 'batch_id', 'candidate_id'], 'gov_elec_manual_line_once');
            $table->index(['tenant_id', 'election_id', 'candidate_id'], 'gov_elec_manual_line_candidate_idx');
            $table->foreign(['tenant_id', 'election_id', 'batch_id'], 'gov_elec_manual_line_batch_fk')
                ->references(['tenant_id', 'election_id', 'id'])->on('governance_election_manual_count_batches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'election_id', 'candidate_id'], 'gov_elec_manual_line_candidate_fk')
                ->references(['tenant_id', 'election_id', 'id'])->on('governance_election_candidates')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('governance_election_manual_count_batches')
            && DB::table('governance_election_manual_count_batches')->exists()) {
            throw new RuntimeException('Refusing to remove witnessed or pending manual-count evidence.');
        }
        if (Schema::hasTable('governance_election_manual_count_lines')
            && DB::table('governance_election_manual_count_lines')->exists()) {
            throw new RuntimeException('Refusing to remove manual-count evidence lines.');
        }

        Schema::dropIfExists('governance_election_manual_count_lines');
        Schema::dropIfExists('governance_election_manual_count_batches');
    }
};
