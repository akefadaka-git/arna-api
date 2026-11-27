<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Replace ordinal-based future-occurrence keys with immutable source
     * occurrence identities. The unique index on idempotency_key remains the
     * concurrency guard; row IDs and notification dedupe keys are unchanged.
     */
    public function up(): void
    {
        DB::table('calendar_reminder_outbox')
            ->where('occurrence_index', '>', 0)
            ->whereNotNull('occurrence_key')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $key = (string) $row->event_id.':'.(int) $row->recipient_user_id.':'
                        .(string) $row->reminder_type.':occurrence:'.(string) $row->occurrence_key;

                    DB::table('calendar_reminder_outbox')
                        ->where('id', $row->id)
                        ->update(['idempotency_key' => $key]);
                }
            });
    }

    /**
     * Restore the previous ordinal identity format on rollback.
     */
    public function down(): void
    {
        DB::table('calendar_reminder_outbox')
            ->where('occurrence_index', '>', 0)
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $key = (string) $row->event_id.':'.(int) $row->recipient_user_id.':'
                        .(string) $row->reminder_type.':'.(int) $row->occurrence_index;

                    DB::table('calendar_reminder_outbox')
                        ->where('id', $row->id)
                        ->update(['idempotency_key' => $key]);
                }
            });
    }
};
