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
            $table->string('label', 80)->nullable();
            // Laravel encrypted cast stores ciphertext; contact values are not indexed or searchable.
            $table->text('contact_value');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('deactivation_reason', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'person_id', 'status'], 'person_contact_points_scope_index');
            $table->index(['tenant_id', 'person_id', 'contact_type'], 'person_contact_points_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_contact_points');
    }
};
