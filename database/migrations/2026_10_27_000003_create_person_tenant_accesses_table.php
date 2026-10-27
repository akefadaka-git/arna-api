<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_tenant_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['person_id', 'tenant_id'], 'person_tenant_access_unique');
            $table->index(['tenant_id', 'status'], 'person_tenant_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_tenant_accesses');
    }
};
