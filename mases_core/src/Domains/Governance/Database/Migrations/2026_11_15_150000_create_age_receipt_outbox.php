<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('age_receipt_outbox')) {
            throw new RuntimeException('AGE receipt outbox table already exists; reconcile schema ownership before migrating.');
        }

        Schema::create('age_receipt_outbox', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('event_uuid', 36)->unique();
            $table->char('stream_uuid', 36);
            $table->char('snapshot_uuid', 36);
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('snapshot_id');
            $table->unsignedBigInteger('sequence');
            $table->string('event_type', 64);
            $table->longText('canonical_event');
            $table->char('event_sha256', 64);
            $table->char('idempotency_key', 36)->unique();
            $table->unsignedBigInteger('expected_previous_sequence')->default(0);
            $table->char('expected_previous_receipt_sha256', 64)->nullable();
            $table->string('delivery_state', 32)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->longText('receipt_payload')->nullable();
            $table->char('receipt_sha256', 64)->nullable();
            $table->longText('witness_payload')->nullable();
            $table->timestamp('anchored_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_detail', 512)->nullable();
            $table->timestamp('quarantined_at')->nullable();
            $table->timestamps();

            $table->unique(['stream_uuid', 'sequence'], 'age_receipt_stream_sequence_unique');
            $table->index(['delivery_state', 'next_attempt_at'], 'age_receipt_delivery_scan_idx');
            $table->index(['tenant_id', 'election_id', 'snapshot_id'], 'age_receipt_scope_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('age_receipt_outbox')) {
            return;
        }

        if (DB::table('age_receipt_outbox')->where('delivery_state', 'anchored')->exists()) {
            throw new RuntimeException('Refusing to drop AGE outbox with externally anchored evidence; export and retention approval are required.');
        }

        if (DB::table('age_receipt_outbox')->exists()) {
            throw new RuntimeException('Refusing to drop non-empty AGE outbox; preserve pending and quarantined evidence before rollback.');
        }

        Schema::drop('age_receipt_outbox');
    }
};
