<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->nullable();
            $table->string('event_key', 191)->unique('finance_outbox_event_key_unique');
            $table->uuid('event_id')->unique('finance_outbox_event_id_unique');
            $table->string('event_type', 120);
            $table->string('subject_type', 80);
            $table->unsignedBigInteger('subject_id');
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('claimed_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at', 'id'], 'finance_outbox_pending_index');
            $table->index(['tenant_id', 'event_type', 'created_at'], 'finance_outbox_tenant_event_index');
            $table->index(['status', 'claimed_at'], 'finance_outbox_claim_lease_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_outbox_messages');
    }
};
