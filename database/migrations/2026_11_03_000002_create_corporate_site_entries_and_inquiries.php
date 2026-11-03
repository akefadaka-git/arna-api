<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_site_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('entry_type', 32)->index();
            $table->string('slug_am', 180)->nullable()->unique();
            $table->string('slug_en', 180)->nullable()->unique();
            $table->json('translations')->nullable();
            $table->json('payload')->nullable();
            $table->json('seo')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('moderation_note', 1000)->nullable();
            $table->string('asset_path', 500)->nullable();
            $table->string('asset_mime', 120)->nullable();
            $table->unsignedBigInteger('asset_size')->nullable();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['entry_type', 'status', 'published_at']);
        });

        Schema::create('corporate_site_inquiries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('inquiry_type', 32)->default('general')->index();
            $table->string('name', 160);
            $table->string('email', 190)->index();
            $table->string('phone', 50)->nullable();
            $table->string('subject', 200);
            $table->text('message');
            $table->string('status', 24)->default('new')->index();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('internal_note')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_site_inquiries');
        Schema::dropIfExists('corporate_site_entries');
    }
};
