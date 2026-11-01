<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('communication_conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('type', 16)->index(); // direct, group, department
            $table->string('title', 180)->nullable();
            $table->unsignedBigInteger('created_by')->index();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'updated_at'], 'comm_conv_tenant_updated_idx');
        });

        Schema::create('communication_conversation_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id')->index();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('role', 16)->default('member');
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id'], 'comm_conv_participant_unique');
            $table->index(['tenant_id', 'user_id', 'left_at'], 'comm_conv_participant_user_idx');
        });

        Schema::create('communication_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id')->index();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('sender_id')->index();
            $table->text('body');
            $table->string('body_locale', 5)->default('am');
            $table->string('idempotency_key', 100);
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'sender_id', 'idempotency_key'], 'comm_message_idempotency_unique');
            $table->index(['tenant_id', 'conversation_id', 'created_at'], 'comm_message_timeline_idx');
        });

        Schema::create('communication_message_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('message_id')->index();
            $table->uuid('conversation_id')->index();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['message_id', 'user_id'], 'comm_message_receipt_unique');
            $table->index(['tenant_id', 'user_id', 'read_at'], 'comm_message_receipt_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_message_receipts');
        Schema::dropIfExists('communication_messages');
        Schema::dropIfExists('communication_conversation_participants');
        Schema::dropIfExists('communication_conversations');
    }
};
