<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_contents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('content_type', 32);
            $table->string('title', 200);
            $table->longText('body');
            $table->string('author_name', 160);
            $table->string('author_email', 254)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('pending_review');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['content_type', 'status']);
            $table->index(['status', 'scheduled_at']);
            $table->index(['submitted_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_contents');
    }
};
