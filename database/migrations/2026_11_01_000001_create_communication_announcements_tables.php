<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('communication_announcements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('created_by')->index();
            $table->string('reference', 64)->nullable();
            $table->json('title');
            $table->longText('content');
            $table->string('content_locale', 5)->default('am');
            $table->string('priority', 16)->default('normal');
            $table->string('status', 24)->default('draft')->index();
            $table->boolean('requires_acknowledgement')->default(false);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->unsignedBigInteger('withdrawn_by')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'published_at'], 'comm_announce_tenant_status_pub_idx');
        });

        Schema::create('communication_announcement_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('announcement_id')->index();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->unique(['announcement_id', 'user_id'], 'comm_announce_recipient_unique');
            $table->index(['tenant_id', 'user_id', 'read_at'], 'comm_announce_recipient_inbox_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_announcement_recipients');
        Schema::dropIfExists('communication_announcements');
    }
};
