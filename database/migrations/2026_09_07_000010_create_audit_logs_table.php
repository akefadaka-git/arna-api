<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `audit_logs` table – centralized audit infrastructure.
     * Every significant action that modifies data or accesses sensitive
     * information is recorded here. The system is append‑only; no updates
     * are expected after insertion.
     *
     * ይህ ማይግሬሽን የተማከለ ኦዲት መዝገብ `audit_logs` ሠንጠረዥ ይፈጥራል።
     * መረጃን የሚቀይሩ ወይም ሚስጥራዊ መረጃን የሚደርሱ እያንዳንዱ ጉልህ ድርጊቶች እዚህ ይመዘገባሉ።
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            // Primary identifier.
            $table->id();

            // Who performed the action (nullable for system actions).
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User who performed the action (nullable)');

            // Which tenant context (nullable for platform‑level actions).
            $table->foreignId('tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('Tenant context (nullable)');

            // Action identifier – machine‑readable string (e.g., 'user.login').
            $table->string('action', 100)
                  ->comment('Action slug');

            // Resource type – e.g., 'user', 'tenant', 'transaction'.
            $table->string('resource_type', 100)
                  ->nullable()
                  ->comment('Resource type');

            // Resource identifier – the ID of the affected resource.
            $table->string('resource_id', 100)
                  ->nullable()
                  ->comment('Resource ID');

            // JSON column for old values (before the change).
            $table->json('old_values')
                  ->nullable()
                  ->comment('Old values (JSON)');

            // JSON column for new values (after the change).
            $table->json('new_values')
                  ->nullable()
                  ->comment('New values (JSON)');

            // Request metadata.
            $table->string('ip_address', 45)
                  ->nullable()
                  ->comment('IP address of the request');
            $table->text('user_agent')
                  ->nullable()
                  ->comment('User agent string');
            $table->string('request_id', 64)
                  ->nullable()
                  ->comment('Request ID for correlation');

            // Timestamp of the audit event (append‑only, no updated_at).
            $table->timestamp('created_at')
                  ->useCurrent()
                  ->comment('Event timestamp');

            // Indexes for common query patterns.
            $table->index('user_id');
            $table->index('tenant_id');
            $table->index('action');
            $table->index('resource_type');
            $table->index('resource_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};