<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_inbox_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('source_type', 64);
            $table->string('source_event_id', 128);
            $table->string('source_version', 64);
            $table->string('event_type', 120);
            $table->json('payload');
            $table->char('payload_sha256', 64);
            $table->string('evidence_ref', 255)->nullable();
            $table->string('status', 16)->default('quarantined');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['tenant_id', 'source_type', 'source_event_id', 'source_version'],
                'finance_inbox_event_identity_unique'
            );
            $table->index(['tenant_id', 'status', 'received_at'], 'finance_inbox_tenant_status_index');
            $table->index(['tenant_id', 'source_type', 'event_type'], 'finance_inbox_source_event_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_inbox_events');
    }
};
