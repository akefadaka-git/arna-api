<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('setting_key', 160)->unique();
            $table->string('category', 24);
            $table->longText('value_ciphertext');
            $table->boolean('is_secret')->default(false);
            $table->string('description', 500)->nullable();
            $table->string('environment', 24)->default('production');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['category', 'environment']);
        });

        Schema::create('platform_setting_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('setting_key', 160);
            $table->string('category', 24);
            $table->longText('proposed_value_ciphertext');
            $table->boolean('is_secret')->default(false);
            $table->string('description', 500)->nullable();
            $table->string('environment', 24)->default('production');
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('reason', 500);
            $table->string('approval_note', 500)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'category', 'created_at']);
            $table->index(['setting_key', 'environment']);
        });

        Schema::create('platform_setting_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('change_request_id')->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('setting_key', 160);
            $table->string('action', 64);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['setting_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_setting_audit_events');
        Schema::dropIfExists('platform_setting_change_requests');
        Schema::dropIfExists('platform_settings');
    }
};
