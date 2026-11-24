<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people_contact_points', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('person_id');
            $table->string('type', 32);
            $table->string('label', 80)->nullable();
            // Application-encrypted value only; never return this column from API resources.
            $table->longText('value_encrypted');
            $table->string('value_masked', 160);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('person_id')->references('id')->on('people')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'person_id', 'status'], 'people_contact_tenant_person_status_idx');
            $table->index(['tenant_id', 'type', 'status'], 'people_contact_tenant_type_status_idx');
        });

        Schema::create('people_relationships', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('person_id');
            $table->unsignedBigInteger('related_person_id');
            $table->string('relationship_type', 48);
            $table->string('description', 500)->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('person_id')->references('id')->on('people')->restrictOnDelete();
            $table->foreign('related_person_id')->references('id')->on('people')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'person_id', 'related_person_id', 'relationship_type'], 'people_relationship_unique');
            $table->index(['tenant_id', 'person_id', 'status'], 'people_relationship_tenant_person_idx');
        });

        Schema::create('people_participations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('person_id');
            $table->string('participation_type', 48);
            $table->string('title', 160)->nullable();
            $table->string('organization_label', 160)->nullable();
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->string('description', 1000)->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('person_id')->references('id')->on('people')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'person_id', 'status'], 'people_participation_tenant_person_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people_participations');
        Schema::dropIfExists('people_relationships');
        Schema::dropIfExists('people_contact_points');
    }
};
