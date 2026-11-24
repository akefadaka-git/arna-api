<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('type', 20); // direct, group, department
            $table->string('title', 180)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'updated_at'], 'comm_conversation_tenant_status_updated_idx');
        });

        Schema::create('communication_conversation_participants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('conversation_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status', 16)->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('communication_conversations')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['conversation_id', 'user_id'], 'comm_conversation_participant_unique');
            $table->index(['tenant_id', 'user_id', 'status'], 'comm_conversation_participant_inbox_idx');
        });

        Schema::create('communication_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('sender_id');
            $table->string('idempotency_key', 100);
            $table->longText('body');
            $table->string('status', 16)->default('sent');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('communication_conversations')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('sender_id')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'sender_id', 'idempotency_key'], 'comm_message_idempotency_unique');
            $table->index(['tenant_id', 'conversation_id', 'created_at'], 'comm_message_tenant_conversation_created_idx');
        });

        Schema::create('communication_message_reads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('message_id');
            $table->uuid('conversation_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('read_at');
            $table->timestamps();

            $table->foreign('message_id')->references('id')->on('communication_messages')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('communication_conversations')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['message_id', 'user_id'], 'comm_message_read_unique');
            $table->index(['tenant_id', 'conversation_id', 'user_id'], 'comm_message_read_inbox_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_message_reads');
        Schema::dropIfExists('communication_messages');
        Schema::dropIfExists('communication_conversation_participants');
        Schema::dropIfExists('communication_conversations');
    }
};
