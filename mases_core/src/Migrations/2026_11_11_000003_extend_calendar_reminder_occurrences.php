<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_reminder_outbox', function (Blueprint $table): void {
            $table->unsignedInteger('occurrence_index')->nullable()->after('reminder_type');
            $table->dateTime('occurrence_starts_at')->nullable()->after('occurrence_index');
            $table->dateTime('occurrence_key')->nullable()->after('occurrence_starts_at');
            $table->index(['tenant_id', 'event_id', 'occurrence_index'], 'calendar_reminder_occurrence_idx');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_reminder_outbox', function (Blueprint $table): void {
            $table->dropIndex('calendar_reminder_occurrence_idx');
            $table->dropColumn(['occurrence_index', 'occurrence_starts_at', 'occurrence_key']);
        });
    }
};
