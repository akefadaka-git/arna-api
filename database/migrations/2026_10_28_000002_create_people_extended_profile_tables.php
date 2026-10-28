<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_contact_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->string('contact_type', 16);
            $table->string('label', 120)->nullable();
            // Application-layer encrypted ciphertext; never return this column.
            $table->text('contact_value');
            $table->char('normalized_hash', 64);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->string('source', 32)->default('manual');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deactivation_reason', 1000)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'person_id', 'status'], 'person_contact_profile_status_idx');
            $table->index(['tenant_id', 'contact_type', 'normalized_hash'], 'person_contact_lookup_idx');
        });

        Schema::create('person_relationships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('related_person_id')->constrained('people')->restrictOnDelete();
            $table->string('relationship_type', 32);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('reason', 1000);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deactivation_reason', 1000)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'person_id', 'status'], 'person_relationship_profile_status_idx');
            $table->index(['tenant_id', 'related_person_id', 'status'], 'person_relationship_related_status_idx');
        });

        Schema::create('person_participations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->string('participation_type', 32);
            $table->string('title', 180);
            $table->string('reference_code', 100)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('reason', 1000);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deactivation_reason', 1000)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'person_id', 'status'], 'person_participation_profile_status_idx');
            $table->index(['tenant_id', 'participation_type', 'status'], 'person_participation_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_participations');
        Schema::dropIfExists('person_relationships');
        Schema::dropIfExists('person_contact_points');
    }
};
