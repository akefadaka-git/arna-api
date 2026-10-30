<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_branch_number_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('organization_unit_id');
            $table->char('branch_code', 1);
            $table->string('status', 16)->default('active');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->text('lifecycle_reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_code'], 'org_branch_number_tenant_code_unique');
            $table->unique(['tenant_id', 'organization_unit_id'], 'org_branch_number_unit_unique');
            $table->foreign(['tenant_id', 'organization_unit_id'], 'org_branch_number_unit_tenant_fk')
                ->references(['tenant_id', 'id'])->on('organization_units')->restrictOnDelete();
            $table->index(['tenant_id', 'status'], 'org_branch_number_status_idx');
        });

        Schema::create('organization_department_number_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->char('branch_code', 1);
            $table->char('department_code', 1);
            $table->char('bb_code', 2);
            $table->foreignId('organization_unit_id');
            $table->unsignedBigInteger('legal_entity_id');
            $table->string('status', 16)->default('active');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->text('lifecycle_reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'bb_code'], 'org_department_bb_unique');
            $table->unique(['tenant_id', 'branch_code', 'department_code'], 'org_department_branch_code_unique');
            $table->unique(['tenant_id', 'organization_unit_id'], 'org_department_unit_unique');
            $table->foreign(['tenant_id', 'branch_code'], 'org_department_branch_tenant_fk')
                ->references(['tenant_id', 'branch_code'])->on('organization_branch_number_codes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_unit_id'], 'org_department_unit_tenant_fk')
                ->references(['tenant_id', 'id'])->on('organization_units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'legal_entity_id'], 'org_department_entity_tenant_fk')
                ->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->index(['tenant_id', 'legal_entity_id', 'status'], 'org_department_entity_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_department_number_codes');
        Schema::dropIfExists('organization_branch_number_codes');
    }
};
