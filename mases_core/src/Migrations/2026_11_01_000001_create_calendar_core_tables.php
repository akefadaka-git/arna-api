<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_calendars', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->string('visibility', 20)->default('tenant');
            $table->string('timezone', 64)->default('Africa/Addis_Ababa');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('calendar_resources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('resource_type', 64);
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('calendar_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('calendar_id')->constrained('calendar_calendars')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 240);
            $table->text('description')->nullable();
            $table->string('event_type', 64)->default('event');
            $table->boolean('all_day')->default(false);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone', 64)->default('Africa/Addis_Ababa');
            $table->string('status', 24)->default('scheduled');
            $table->json('recurrence_rule')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['tenant_id', 'starts_at', 'ends_at']);
            $table->index(['tenant_id', 'starts_on', 'ends_on']);
        });

        Schema::create('calendar_attendees', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('response', 20)->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('calendar_event_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('domain', 64);
            $table->string('resource_type', 100);
            $table->string('resource_id', 100);
            $table->string('relationship', 40)->default('related');
            $table->timestamps();
            $table->unique(['event_id', 'domain', 'resource_type', 'resource_id'], 'calendar_event_link_unique');
            $table->index(['tenant_id', 'domain', 'resource_type', 'resource_id'], 'calendar_event_link_lookup');
        });

        Schema::create('calendar_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('resource_id')->constrained('calendar_resources')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 20)->default('confirmed');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['tenant_id', 'resource_id', 'starts_at', 'ends_at'], 'calendar_reservation_overlap_idx');
        });

        Schema::create('calendar_audit_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('aggregate_type', 64);
            $table->string('aggregate_id', 100);
            $table->string('action', 64);
            $table->json('changes')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'aggregate_type', 'aggregate_id'], 'cal_audit_tenant_agg_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_audit_entries');
        Schema::dropIfExists('calendar_reservations');
        Schema::dropIfExists('calendar_event_links');
        Schema::dropIfExists('calendar_attendees');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('calendar_resources');
        Schema::dropIfExists('calendar_calendars');
    }
};
