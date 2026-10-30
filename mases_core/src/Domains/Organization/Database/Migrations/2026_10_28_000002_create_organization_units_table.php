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
        Schema::create('organization_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable();
            $table->foreignId('legal_entity_id')->nullable();
            $table->string('code', 64);
            $table->string('unit_type', 32);
            $table->string('name_am', 180)->nullable();
            $table->string('name_en', 180)->nullable();
            $table->string('status', 24)->default('active');
            $table->text('lifecycle_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code'], 'organization_units_tenant_code_unique');
            $table->unique(['tenant_id', 'id'], 'organization_units_tenant_id_id_unique');
            $table->index(['tenant_id', 'parent_id', 'status'], 'organization_units_tree_index');
            $table->foreign(['tenant_id', 'parent_id'], 'organization_units_parent_same_tenant_fk')
                ->references(['tenant_id', 'id'])->on('organization_units')->restrictOnDelete();
        });

        Schema::create('organization_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('organization_unit_id');
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->string('assignment_type', 48)->default('member');
            $table->string('title_am', 160)->nullable();
            $table->string('title_en', 160)->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 24)->default('active');
            $table->text('lifecycle_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign(['tenant_id', 'organization_unit_id'], 'organization_assignments_unit_tenant_fk')
                ->references(['tenant_id', 'id'])->on('organization_units')->restrictOnDelete();
            // A composite FK guarantees the person has a tenant-access record for this same tenant.
            // Access revocation changes lifecycle state; it does not erase historical assignments.
            $table->foreign(['person_id', 'tenant_id'], 'organization_assignments_person_tenant_access_fk')
                ->references(['person_id', 'tenant_id'])->on('person_tenant_accesses')->restrictOnDelete();
            $table->index(['tenant_id', 'person_id', 'status'], 'organization_assignments_person_status_index');
            $table->index(['organization_unit_id', 'starts_on', 'ends_on'], 'organization_assignments_dates_index');
        });

        Schema::create('organization_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            // Preserve actor attribution; referenced users cannot be deleted. Null is reserved for system events.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('subject_type', 48);
            $table->unsignedBigInteger('subject_id');
            $table->string('action', 48);
            $table->text('reason');
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'subject_type', 'subject_id', 'created_at'], 'organization_audit_subject_index');
        });

        // MySQL/MariaDB enforcement also blocks bulk query-builder writes that bypass Eloquent events.
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER organization_audit_events_no_update BEFORE UPDATE ON organization_audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Organization audit events are append-only'");
            DB::unprepared("CREATE TRIGGER organization_audit_events_no_delete BEFORE DELETE ON organization_audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Organization audit events are append-only'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS organization_audit_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS organization_audit_events_no_delete');
        }
        Schema::dropIfExists('organization_audit_events');
        Schema::dropIfExists('organization_assignments');
        Schema::dropIfExists('organization_units');
    }
};