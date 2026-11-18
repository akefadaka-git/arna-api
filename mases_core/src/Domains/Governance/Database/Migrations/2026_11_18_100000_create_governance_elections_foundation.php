<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_elections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('governance_body_id')->nullable();
            $table->string('code', 64);
            $table->string('title_am', 200)->nullable();
            $table->string('title_en', 200)->nullable();
            $table->string('election_scope', 64);
            $table->string('election_type', 64);
            $table->string('status', 24)->default('draft');
            $table->string('authority_reference', 200)->nullable();
            $table->unsignedBigInteger('authority_document_id')->nullable();
            $table->string('authority_evidence_digest', 64)->nullable();
            $table->string('authority_version', 80)->nullable();
            $table->json('authority_snapshot')->nullable();
            $table->string('rule_set_version', 80)->nullable();
            $table->string('rule_set_digest', 64)->nullable();
            $table->string('timezone', 64)->default('Africa/Addis_Ababa');
            $table->dateTime('scheduled_start_at')->nullable();
            $table->dateTime('scheduled_end_at')->nullable();
            $table->dateTime('authorized_at')->nullable();
            $table->unsignedBigInteger('authorized_by')->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->unsignedBigInteger('suspended_by')->nullable();
            $table->string('suspension_reason', 2000)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancellation_reason', 2000)->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'gov_elections_tenant_code_uq');
            $table->unique(['tenant_id', 'id'], 'gov_elections_tenant_id_uq');
            $table->index(['tenant_id', 'status'], 'gov_elections_tenant_status_idx');
            $table->index(['tenant_id', 'governance_body_id'], 'gov_elections_tenant_body_idx');
        });

        Schema::create('governance_election_lifecycle_transitions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedInteger('sequence');
            $table->string('action', 32);
            $table->string('from_status', 24);
            $table->string('to_status', 24);
            $table->unsignedBigInteger('actor_user_id');
            $table->string('reason', 2000)->nullable();
            $table->json('evidence_references')->nullable();
            $table->unsignedInteger('aggregate_version');
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'sequence'], 'gov_elec_lifecycle_seq_uq');
            $table->index(['tenant_id', 'election_id', 'occurred_at'], 'gov_elec_lifecycle_time_idx');\n            $table->foreign(['tenant_id', 'election_id'], 'gov_elec_lifecycle_election_fk')\n                ->references(['tenant_id', 'id'])->on('governance_elections')->restrictOnDelete();
        });

        // The committee-security migration predates this parent table.
        // Attach its tenant/election references now that the canonical
        // election key exists; this prevents cross-tenant key pairing.
        foreach ([
            'governance_election_committee_admins' => 'gov_elec_admin_election_fk',
            'governance_election_committee_conflicts' => 'gov_elec_conflict_election_fk',
            'governance_election_committee_security_events' => 'gov_elec_security_election_fk',
        ] as $tableName => $constraint) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($constraint): void {
                    $table->foreign(['tenant_id', 'election_id'], $constraint)
                        ->references(['tenant_id', 'id'])->on('governance_elections')->restrictOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'governance_election_committee_admins' => 'gov_elec_admin_election_fk',
            'governance_election_committee_conflicts' => 'gov_elec_conflict_election_fk',
            'governance_election_committee_security_events' => 'gov_elec_security_election_fk',
        ] as $tableName => $constraint) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($constraint): void {
                    $table->dropForeign($constraint);
                });
            }
        }
        Schema::dropIfExists('governance_election_lifecycle_transitions');
        Schema::dropIfExists('governance_elections');
    }
};
