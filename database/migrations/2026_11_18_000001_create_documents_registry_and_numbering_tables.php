<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical first-generation Documents registry schema.
 *
 * This intentionally replaces the conflicting PR #53 old/v2 migration pair
 * before either is merged. There is one counter table and one uniqueness
 * contract matching the visible number components.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_type_catalogue', function (Blueprint $table): void {
            $table->char('numeric_code', 2)->primary();
            $table->string('legacy_code', 8)->unique();
            $table->string('category', 32);
            $table->string('name_am', 160);
            $table->string('name_en', 160);
            $table->string('direction', 16);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('catalogue_version')->default(1);
            $table->timestamps();
        });

        // Stable identifiers are seeded with the schema so clean installs
        // never depend on an optional seeder or mutable production setup.
        DB::table('document_type_catalogue')->insert([
            ['numeric_code' => '01', 'legacy_code' => 'LET', 'category' => 'correspondence', 'name_am' => 'ደብዳቤ', 'name_en' => 'Letter', 'direction' => 'outgoing', 'is_active' => true, 'catalogue_version' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['numeric_code' => '02', 'legacy_code' => 'MEM', 'category' => 'correspondence', 'name_am' => 'ማስታወሻ', 'name_en' => 'Memorandum', 'direction' => 'internal', 'is_active' => true, 'catalogue_version' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['numeric_code' => '03', 'legacy_code' => 'LEG', 'category' => 'legal', 'name_am' => 'ሕጋዊ ሰነድ', 'name_en' => 'Legal instrument', 'direction' => 'other', 'is_active' => true, 'catalogue_version' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['numeric_code' => '04', 'legacy_code' => 'INV', 'category' => 'finance', 'name_am' => 'የክፍያ መጠየቂያ', 'name_en' => 'Invoice', 'direction' => 'financial', 'is_active' => true, 'catalogue_version' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['numeric_code' => '05', 'legacy_code' => 'CER', 'category' => 'certificate', 'name_am' => 'ማረጋገጫ ወረቀት', 'name_en' => 'Certificate', 'direction' => 'outgoing', 'is_active' => true, 'catalogue_version' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['numeric_code' => '06', 'legacy_code' => 'RES', 'category' => 'governance', 'name_am' => 'ውሳኔ', 'name_en' => 'Resolution', 'direction' => 'governance', 'is_active' => true, 'catalogue_version' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['numeric_code' => '07', 'legacy_code' => 'CIR', 'category' => 'correspondence', 'name_am' => 'ሰርኩላር', 'name_en' => 'Circular', 'direction' => 'outgoing', 'is_active' => true, 'catalogue_version' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['numeric_code' => '08', 'legacy_code' => 'RPT', 'category' => 'reporting', 'name_am' => 'ሪፖርት', 'name_en' => 'Report', 'direction' => 'internal', 'is_active' => true, 'catalogue_version' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['numeric_code' => '09', 'legacy_code' => 'CON', 'category' => 'legal', 'name_am' => 'ውል', 'name_en' => 'Contract', 'direction' => 'legal', 'is_active' => true, 'catalogue_version' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['numeric_code' => '10', 'legacy_code' => 'NOT', 'category' => 'correspondence', 'name_am' => 'ማስታወቂያ', 'name_en' => 'Notice', 'direction' => 'outgoing', 'is_active' => true, 'catalogue_version' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['numeric_code' => '11', 'legacy_code' => 'POL', 'category' => 'governance', 'name_am' => 'ፖሊሲ', 'name_en' => 'Policy', 'direction' => 'governance', 'is_active' => true, 'catalogue_version' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['numeric_code' => '12', 'legacy_code' => 'PUR', 'category' => 'finance', 'name_am' => 'የግዢ ትዕዛዝ', 'name_en' => 'Purchase order', 'direction' => 'financial', 'is_active' => true, 'catalogue_version' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['numeric_code' => '13', 'legacy_code' => 'EXP', 'category' => 'finance', 'name_am' => 'የወጪ ትዕዛዝ', 'name_en' => 'Expense order', 'direction' => 'financial', 'is_active' => true, 'catalogue_version' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['numeric_code' => '14', 'legacy_code' => 'INC', 'category' => 'finance', 'name_am' => 'የገቢ ትዕዛዝ', 'name_en' => 'Income order', 'direction' => 'financial', 'is_active' => true, 'catalogue_version' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['numeric_code' => '15', 'legacy_code' => 'RCT', 'category' => 'finance', 'name_am' => 'ደረሰኝ', 'name_en' => 'Receipt', 'direction' => 'financial', 'is_active' => true, 'catalogue_version' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('document_numbering_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('organization_unit_id');
            $table->char('bb_code', 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // A printed BB code identifies exactly one issuer scope per tenant.
            $table->unique(['tenant_id', 'bb_code'], 'doc_number_assignment_bb_unique');
            $table->unique(
                ['tenant_id', 'legal_entity_id', 'organization_unit_id'],
                'doc_number_assignment_unit_unique'
            );
            $table->unique(
                ['tenant_id', 'legal_entity_id', 'organization_unit_id', 'bb_code'],
                'doc_number_assignment_identity_unique'
            );
            $table->foreign(['tenant_id', 'legal_entity_id'], 'doc_number_assignment_entity_fk')
                ->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_unit_id'], 'doc_number_assignment_unit_fk')
                ->references(['tenant_id', 'id'])->on('organization_units')->restrictOnDelete();
            $table->index(['tenant_id', 'is_active'], 'doc_number_assignment_active_idx');
        });

        Schema::create('document_number_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->char('bb_code', 2);
            $table->char('document_type_number', 2);
            $table->unsignedSmallInteger('ec_year');
            $table->unsignedInteger('next_sequence')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Exactly the components that determine SSSS in the printed number.
            $table->unique(
                ['tenant_id', 'bb_code', 'document_type_number', 'ec_year'],
                'doc_counter_canonical_scope_unique'
            );
            $table->foreign(['document_type_number'], 'doc_counter_type_fk')
                ->references(['numeric_code'])->on('document_type_catalogue')->restrictOnDelete();
            $table->foreign(['tenant_id', 'bb_code'], 'doc_counter_assignment_fk')
                ->references(['tenant_id', 'bb_code'])->on('document_numbering_assignments')->restrictOnDelete();
            $table->index(['tenant_id', 'is_active'], 'doc_counter_tenant_active_idx');
        });

        // Required parent key for a database-enforced same-tenant file reference.
        Schema::table('files', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'files_tenant_id_unique');
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('organization_unit_id');
            $table->unsignedBigInteger('governance_body_id')->nullable();
            $table->unsignedBigInteger('governance_position_id')->nullable();
            $table->string('organization_code', 64);
            $table->char('bb_code', 2);
            $table->string('document_type_code', 8);
            $table->char('document_type_number', 2);
            $table->string('department_code', 64)->nullable();
            $table->string('issuing_role_code', 32)->nullable();
            $table->unsignedSmallInteger('ec_year');
            $table->unsignedInteger('sequence_number');
            $table->string('canonical_number', 200);
            $table->unsignedBigInteger('replaces_document_id')->nullable();
            $table->string('title', 255);
            $table->unsignedBigInteger('file_id');
            $table->unsignedInteger('file_version');
            $table->char('file_sha256', 64)->nullable();
            $table->string('status', 16)->default('issued');
            $table->char('request_hash', 64);
            $table->string('idempotency_key', 128);
            $table->json('metadata')->nullable();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 1000)->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 1000)->nullable();
            $table->timestamps();

            // Parent key for tenant-bound lifecycle and replacement references.
            $table->unique(['tenant_id', 'id'], 'documents_tenant_id_unique');
            $table->unique(['tenant_id', 'canonical_number'], 'documents_tenant_canonical_unique');
            $table->unique(
                ['tenant_id', 'bb_code', 'document_type_number', 'ec_year', 'sequence_number'],
                'documents_canonical_scope_sequence_unique'
            );
            $table->unique(['tenant_id', 'idempotency_key'], 'documents_idempotency_unique');
            $table->unique(['tenant_id', 'replaces_document_id'], 'documents_single_replacement_unique');

            $table->foreign(['tenant_id', 'legal_entity_id'], 'documents_entity_tenant_fk')
                ->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_unit_id'], 'documents_unit_tenant_fk')
                ->references(['tenant_id', 'id'])->on('organization_units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'bb_code'], 'documents_numbering_assignment_fk')
                ->references(['tenant_id', 'bb_code'])->on('document_numbering_assignments')->restrictOnDelete();
            $table->foreign('document_type_number', 'documents_type_fk')
                ->references('numeric_code')->on('document_type_catalogue')->restrictOnDelete();
            $table->foreign(['tenant_id', 'file_id'], 'documents_file_tenant_fk')
                ->references(['tenant_id', 'id'])->on('files')->restrictOnDelete();
            $table->foreign(['tenant_id', 'replaces_document_id'], 'documents_replaces_same_tenant_fk')
                ->references(['tenant_id', 'id'])->on('documents')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'issued_at'], 'documents_tenant_status_issued_idx');
            $table->index(['tenant_id', 'legal_entity_id', 'issued_at'], 'documents_entity_issued_idx');
        });

        Schema::create('document_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('event_type', 24);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 1000)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign(['tenant_id', 'document_id'], 'document_lifecycle_document_tenant_fk')
                ->references(['tenant_id', 'id'])->on('documents')->restrictOnDelete();
            $table->index(['tenant_id', 'document_id', 'occurred_at'], 'doc_lifecycle_tenant_doc_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_lifecycle_events');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_number_counters');
        Schema::dropIfExists('document_numbering_assignments');
        Schema::dropIfExists('document_type_catalogue');
        Schema::table('files', function (Blueprint $table): void {
            $table->dropUnique('files_tenant_id_unique');
        });
    }
};
