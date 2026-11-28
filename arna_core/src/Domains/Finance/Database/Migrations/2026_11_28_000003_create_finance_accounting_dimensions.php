<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 01 accounting dimensions and journal-line allocation foundation.
 * No dimension values are seeded; each entity configures its own dimensions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_dimensions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->string('code', 48);
            $table->string('dimension_type', 32);
            $table->string('name_am', 160)->nullable();
            $table->string('name_en', 160);
            $table->boolean('is_required')->default(false);
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'legal_entity_id', 'code'], 'finance_dimensions_entity_code_unique');
            $table->unique(['tenant_id', 'legal_entity_id', 'id'], 'finance_dimensions_entity_id_unique');
            $table->foreign(['tenant_id', 'legal_entity_id'], 'finance_dimensions_entity_fk')
                ->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
        });

        Schema::create('finance_dimension_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('dimension_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 64);
            $table->string('name_am', 180)->nullable();
            $table->string('name_en', 180);
            $table->string('status', 16)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'legal_entity_id', 'dimension_id', 'code'], 'finance_dimension_values_code_unique');
            $table->unique(['tenant_id', 'legal_entity_id', 'dimension_id', 'id'], 'finance_dimension_values_identity_unique');
            $table->foreign(['tenant_id', 'legal_entity_id', 'dimension_id'], 'finance_dimension_values_dimension_fk')
                ->references(['tenant_id', 'legal_entity_id', 'id'])->on('finance_dimensions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'legal_entity_id', 'dimension_id', 'parent_id'], 'finance_dimension_values_parent_fk')
                ->references(['tenant_id', 'legal_entity_id', 'dimension_id', 'id'])->on('finance_dimension_values')->restrictOnDelete();
            $table->index(['tenant_id', 'legal_entity_id', 'dimension_id', 'status'], 'finance_dimension_values_status_idx');
        });

        Schema::create('journal_line_dimensions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedSmallInteger('line_number');
            $table->unsignedBigInteger('dimension_id');
            $table->unsignedBigInteger('dimension_value_id');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'legal_entity_id', 'journal_entry_id', 'line_number', 'dimension_id'],
                'journal_line_dimensions_line_dimension_unique'
            );
            $table->foreign(['tenant_id', 'legal_entity_id', 'journal_entry_id', 'line_number'], 'journal_line_dimensions_line_fk')
                ->references(['tenant_id', 'legal_entity_id', 'journal_entry_id', 'line_number'])->on('journal_lines')->restrictOnDelete();
            $table->foreign(['tenant_id', 'legal_entity_id', 'dimension_id'], 'journal_line_dimensions_dimension_fk')
                ->references(['tenant_id', 'legal_entity_id', 'id'])->on('finance_dimensions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'legal_entity_id', 'dimension_id', 'dimension_value_id'], 'journal_line_dimensions_value_fk')
                ->references(['tenant_id', 'legal_entity_id', 'dimension_id', 'id'])->on('finance_dimension_values')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE finance_dimensions ADD CONSTRAINT finance_dimensions_type_chk CHECK (dimension_type IN ('department', 'branch', 'project', 'cost_center', 'contract', 'fund', 'region'))");
            DB::statement("ALTER TABLE finance_dimension_values ADD CONSTRAINT finance_dimension_values_effective_dates_chk CHECK (effective_from IS NULL OR effective_to IS NULL OR effective_to >= effective_from)");

            DB::unprepared("CREATE TRIGGER journal_line_dimensions_insert_draft_only BEFORE INSERT ON journal_line_dimensions FOR EACH ROW BEGIN IF NOT EXISTS (SELECT 1 FROM journal_entries WHERE id = NEW.journal_entry_id AND tenant_id = NEW.tenant_id AND legal_entity_id = NEW.legal_entity_id AND status = 'draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dimensions may only be assigned to draft journal lines'; END IF; END");
            DB::unprepared("CREATE TRIGGER journal_line_dimensions_update_draft_only BEFORE UPDATE ON journal_line_dimensions FOR EACH ROW BEGIN IF NOT EXISTS (SELECT 1 FROM journal_entries WHERE id = OLD.journal_entry_id AND tenant_id = OLD.tenant_id AND legal_entity_id = OLD.legal_entity_id AND status = 'draft') OR NOT EXISTS (SELECT 1 FROM journal_entries WHERE id = NEW.journal_entry_id AND tenant_id = NEW.tenant_id AND legal_entity_id = NEW.legal_entity_id AND status = 'draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal line dimensions are immutable'; END IF; END");
            DB::unprepared("CREATE TRIGGER journal_line_dimensions_delete_draft_only BEFORE DELETE ON journal_line_dimensions FOR EACH ROW BEGIN IF NOT EXISTS (SELECT 1 FROM journal_entries WHERE id = OLD.journal_entry_id AND tenant_id = OLD.tenant_id AND legal_entity_id = OLD.legal_entity_id AND status = 'draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal line dimensions cannot be deleted'; END IF; END");
            DB::unprepared("CREATE TRIGGER finance_dimensions_used_immutable_update BEFORE UPDATE ON finance_dimensions FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM journal_line_dimensions d INNER JOIN journal_entries j ON j.id = d.journal_entry_id AND j.tenant_id = d.tenant_id AND j.legal_entity_id = d.legal_entity_id WHERE d.dimension_id = OLD.id AND d.tenant_id = OLD.tenant_id AND d.legal_entity_id = OLD.legal_entity_id AND j.status <> 'draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dimensions referenced by posted journals are immutable'; END IF; END");
            DB::unprepared("CREATE TRIGGER finance_dimensions_used_immutable_delete BEFORE DELETE ON finance_dimensions FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM journal_line_dimensions d INNER JOIN journal_entries j ON j.id = d.journal_entry_id AND j.tenant_id = d.tenant_id AND j.legal_entity_id = d.legal_entity_id WHERE d.dimension_id = OLD.id AND d.tenant_id = OLD.tenant_id AND d.legal_entity_id = OLD.legal_entity_id AND j.status <> 'draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dimensions referenced by posted journals cannot be deleted'; END IF; END");
            DB::unprepared("CREATE TRIGGER finance_dimension_values_used_immutable_update BEFORE UPDATE ON finance_dimension_values FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM journal_line_dimensions d INNER JOIN journal_entries j ON j.id = d.journal_entry_id AND j.tenant_id = d.tenant_id AND j.legal_entity_id = d.legal_entity_id WHERE d.dimension_value_id = OLD.id AND d.dimension_id = OLD.dimension_id AND d.tenant_id = OLD.tenant_id AND d.legal_entity_id = OLD.legal_entity_id AND j.status <> 'draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dimension values referenced by posted journals are immutable'; END IF; END");
            DB::unprepared("CREATE TRIGGER finance_dimension_values_used_immutable_delete BEFORE DELETE ON finance_dimension_values FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM journal_line_dimensions d INNER JOIN journal_entries j ON j.id = d.journal_entry_id AND j.tenant_id = d.tenant_id AND j.legal_entity_id = d.legal_entity_id WHERE d.dimension_value_id = OLD.id AND d.dimension_id = OLD.dimension_id AND d.tenant_id = OLD.tenant_id AND d.legal_entity_id = OLD.legal_entity_id AND j.status <> 'draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dimension values referenced by posted journals cannot be deleted'; END IF; END");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS journal_line_dimensions_delete_draft_only');
            DB::unprepared('DROP TRIGGER IF EXISTS journal_line_dimensions_update_draft_only');
            DB::unprepared('DROP TRIGGER IF EXISTS finance_dimension_values_used_immutable_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS finance_dimension_values_used_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS finance_dimensions_used_immutable_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS finance_dimensions_used_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS journal_line_dimensions_insert_draft_only');
        }

        Schema::dropIfExists('journal_line_dimensions');
        Schema::dropIfExists('finance_dimension_values');
        Schema::dropIfExists('finance_dimensions');
    }
};
