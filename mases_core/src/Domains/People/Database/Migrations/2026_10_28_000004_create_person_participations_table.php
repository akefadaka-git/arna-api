<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }
};
