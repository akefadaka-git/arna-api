<?php
declare(strict_types=1);
use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('calendar_recurrence_exceptions', function (Blueprint $table): void {
   $table->id(); $table->foreignUuid('event_id')->constrained('calendar_events')->cascadeOnDelete();
   $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
   $table->timestamp('original_starts_at'); $table->string('status',20)->default('cancelled');
   $table->timestamp('override_starts_at')->nullable(); $table->timestamp('override_ends_at')->nullable();
   $table->foreignId('created_by')->constrained('users')->restrictOnDelete(); $table->timestamps();
   $table->unique(['event_id','original_starts_at']); $table->index(['tenant_id','event_id']);
  });
  Schema::create('calendar_reminder_outbox', function (Blueprint $table): void {
   $table->uuid('id')->primary(); $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
   $table->foreignUuid('event_id')->constrained('calendar_events')->cascadeOnDelete();
   $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
   $table->string('reminder_type',40); $table->timestamp('deliver_at'); $table->string('status',20)->default('pending');
   $table->unsignedInteger('attempts')->default(0); $table->string('idempotency_key',160)->unique();
   $table->timestamp('delivered_at')->nullable(); $table->text('last_error')->nullable(); $table->timestamps();
   $table->index(['status','deliver_at']); $table->index(['tenant_id','event_id']);
  });
 }
 public function down(): void { Schema::dropIfExists('calendar_reminder_outbox'); Schema::dropIfExists('calendar_recurrence_exceptions'); }
};