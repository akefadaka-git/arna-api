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
            // The application stores Laravel-encrypted ciphertext here.
            $table->text('contact_value');
            // HMAC fingerprint only; the raw contact value is never indexed.
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
    }

    public function down(): void
    {
        Schema::dropIfExists('person_contact_points');
    }
};
