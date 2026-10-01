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
     * Creates the `security_events` table – stores security‑related events
     * such as failed logins, password resets, MFA events, suspicious activity,
     * and other security‑critical occurrences. This table is separate from
     * `audit_logs` to provide focused security monitoring and alerting.
     *
     * ይህ ማይግሬሽን እንደ ያልተሳኩ መግቢያዎች፣ የይለፍ ቃል ዳግም ማስጀመር፣ የMFA ክስተቶች፣
     * አጠራጣሪ እንቅስቃሴዎች ያሉ የደህንነት ክስተቶችን የሚያከማች `security_events` ሠንጠረዥ ይፈጥራል።
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table): void {
            // Primary identifier.
            $table->id();

            // User who triggered the event (nullable for unauthenticated events).
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User who triggered the event (nullable)');

            // Tenant context (nullable for platform‑level events).
            $table->foreignId('tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('Tenant context (nullable)');

            // Event type (e.g., 'login.failed', 'password.reset.requested').
            $table->string('event_type', 100)
                  ->comment('Security event type slug');

            // Severity level.
            $table->enum('severity', ['info', 'warning', 'critical'])
                  ->default('info')
                  ->comment('Severity level');

            // Human-readable description.
            $table->text('description')
                  ->nullable()
                  ->comment('Event description');

            // JSON field for additional event data.
            $table->json('event_data')
                  ->nullable()
                  ->comment('Additional event data (JSON)');

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

            // Resolution tracking.
            $table->timestamp('resolved_at')
                  ->nullable()
                  ->comment('When the event was resolved');
            $table->foreignId('resolved_by')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User who resolved the event (nullable)');
            $table->text('resolution_notes')
                  ->nullable()
                  ->comment('Resolution notes');

            // Event timestamp (append‑only, no updated_at).
            $table->timestamp('created_at')
                  ->useCurrent()
                  ->comment('Event timestamp');

            // Indexes for common query patterns.
            $table->index('user_id');
            $table->index('tenant_id');
            $table->index('event_type');
            $table->index('severity');
            $table->index('ip_address');
            $table->index('created_at');
            $table->index('resolved_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};