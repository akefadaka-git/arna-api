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
        Schema::create('document_file_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('file_id');
            $table->unsignedInteger('file_version');
            $table->string('storage_disk', 64);
            $table->string('storage_path', 512);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size');
            $table->foreignId('captured_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('captured_at');
            $table->unique(['tenant_id', 'id'], 'doc_file_version_tenant_id_unique');
            $table->unique(['tenant_id', 'id', 'file_id', 'file_version'], 'doc_file_version_identity_unique');
            $table->unique(['tenant_id', 'file_id', 'file_version'], 'doc_file_version_source_unique');
            $table->unique(['storage_disk', 'storage_path'], 'doc_file_version_path_unique');
            $table->foreign(['tenant_id', 'file_id'], 'doc_file_version_file_tenant_fk')
                ->references(['tenant_id', 'id'])->on('files')->restrictOnDelete();
        });

        Schema::create('document_signing_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('key_id', 100);
            $table->foreignId('signer_user_id')->constrained('users')->restrictOnDelete();
            $table->string('algorithm', 16)->default('Ed25519');
            $table->string('public_key_base64', 128);
            $table->foreignId('trusted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('trust_reference', 255);
            $table->timestamp('trusted_at');
            $table->timestamp('valid_until')->nullable();
            $table->unique(['tenant_id', 'id'], 'doc_signing_key_tenant_id_unique');
            $table->unique(['tenant_id', 'key_id'], 'doc_signing_key_tenant_key_unique');
            $table->index(['tenant_id', 'signer_user_id', 'algorithm'], 'doc_signing_key_signer_idx');
        });

        Schema::create('document_signing_key_revocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('signing_key_id');
            $table->foreignId('revoked_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestamp('revoked_at');
            $table->unique(['tenant_id', 'signing_key_id'], 'doc_signing_key_revocation_unique');
            $table->foreign(['tenant_id', 'signing_key_id'], 'doc_signing_key_revocation_fk')
                ->references(['tenant_id', 'id'])->on('document_signing_keys')->restrictOnDelete();
        });

        Schema::create('document_signature_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('workflow_id');
            $table->unsignedBigInteger('signing_key_id');
            $table->foreignId('signer_user_id')->constrained('users')->restrictOnDelete();
            $table->string('algorithm', 16)->default('Ed25519');
            $table->text('signature_base64');
            $table->char('payload_sha256', 64);
            $table->timestamp('signed_at');
            $table->timestamp('verified_at');
            $table->unique(['tenant_id', 'id'], 'doc_signature_tenant_id_unique');
            $table->unique(['tenant_id', 'workflow_id'], 'doc_signature_workflow_unique');
            $table->foreign(['tenant_id', 'workflow_id'], 'doc_signature_workflow_fk')
                ->references(['tenant_id', 'id'])->on('document_workflow_aggregates')->restrictOnDelete();
            $table->foreign(['tenant_id', 'signing_key_id'], 'doc_signature_key_fk')
                ->references(['tenant_id', 'id'])->on('document_signing_keys')->restrictOnDelete();
        });

        Schema::table('document_workflow_aggregates', function (Blueprint $table): void {
            $table->unsignedBigInteger('submitted_file_evidence_id')->nullable();
            $table->foreign(['tenant_id', 'submitted_file_evidence_id', 'submitted_file_id', 'submitted_file_version'], 'doc_workflow_file_evidence_fk')
                ->references(['tenant_id', 'id', 'file_id', 'file_version'])->on('document_file_versions')->restrictOnDelete();
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('immutable_file_version_id')->nullable();
            $table->unsignedBigInteger('signature_evidence_id')->nullable();
            $table->foreign(['tenant_id', 'immutable_file_version_id', 'file_id', 'file_version'], 'documents_immutable_file_version_fk')
                ->references(['tenant_id', 'id', 'file_id', 'file_version'])->on('document_file_versions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'signature_evidence_id'], 'documents_signature_evidence_fk')
                ->references(['tenant_id', 'id'])->on('document_signature_evidence')->restrictOnDelete();
        });

        foreach ([
            'document_file_versions',
            'document_signing_keys',
            'document_signing_key_revocations',
            'document_signature_evidence',
        ] as $table) {
            $update = $table . '_no_update';
            $delete = $table . '_no_delete';
            if (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$update} BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Document evidence records are append-only'");
                DB::unprepared("CREATE TRIGGER {$delete} BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Document evidence records are append-only'");
            } elseif (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$update} BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, 'Document evidence records are append-only'); END");
                DB::unprepared("CREATE TRIGGER {$delete} BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Document evidence records are append-only'); END");
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'document_file_versions',
            'document_signing_keys',
            'document_signing_key_revocations',
            'document_signature_evidence',
        ] as $table) {
            if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'sqlite') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
            }
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign('documents_signature_evidence_fk');
            $table->dropForeign('documents_immutable_file_version_fk');
            $table->dropColumn(['signature_evidence_id', 'immutable_file_version_id']);
        });
        Schema::table('document_workflow_aggregates', function (Blueprint $table): void {
            $table->dropForeign('doc_workflow_file_evidence_fk');
            $table->dropColumn('submitted_file_evidence_id');
        });

        Schema::dropIfExists('document_signature_evidence');
        Schema::dropIfExists('document_signing_key_revocations');
        Schema::dropIfExists('document_signing_keys');
        Schema::dropIfExists('document_file_versions');
    }
};
