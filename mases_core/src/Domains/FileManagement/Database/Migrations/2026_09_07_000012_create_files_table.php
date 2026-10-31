<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `files` table for the centralized file service.
     * Stores metadata for all uploaded files across the platform.
     * The original uploaded filename is preserved, and localized display
     * names (`display_name_am`, `display_name_en`) are provided for
     * user‑facing presentation.
     *
     * ይህ ማይግሬሽን የተማከለ ፋይል አገልግሎት `files` ሠንጠረዥ ይፈጥራል።
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table): void {
            // Primary identifier.
            $table->id();

            // Owner of the file (nullable for system files).
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('Owner user ID (nullable)');

            // Tenant context (nullable for platform‑level files).
            $table->foreignId('tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('Tenant ID (nullable)');

            // Original file name as uploaded by the user.
            $table->string('original_name', 255)
                  ->comment('Original file name');

            // Localized display names for user‑facing presentation.
            $table->string('display_name_am', 255)
                  ->nullable()
                  ->comment('Display name in Amharic');
            $table->string('display_name_en', 255)
                  ->nullable()
                  ->comment('Display name in English');

            // Stored file name on disk (may be hashed or UUID).
            $table->string('name', 255)
                  ->comment('Stored file name');

            // MIME type (e.g., 'application/pdf').
            $table->string('mime_type', 100)
                  ->nullable()
                  ->comment('MIME type');

            // File extension (e.g., 'pdf').
            $table->string('extension', 20)
                  ->nullable()
                  ->comment('File extension');

            // File size in bytes.
            $table->unsignedBigInteger('size')
                  ->comment('File size in bytes');

            // Storage path relative to disk root.
            $table->string('path', 1000)
                  ->comment('Storage path');

            // Storage disk name (e.g., 'local', 's3').
            $table->string('disk', 50)
                  ->default('local')
                  ->comment('Storage disk');

            // File version number (starts at 1).
            $table->unsignedInteger('version')
                  ->default(1)
                  ->comment('Version number');

            // Self‑referential foreign key for versioning.
            $table->foreignId('parent_file_id')
                  ->nullable()
                  ->constrained('files')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('Parent file ID (for versioning)');

            // File status.
            $table->enum('status', ['draft', 'published', 'archived'])
                  ->default('published')
                  ->comment('File status');

            // Whether the file is publicly accessible.
            $table->boolean('is_public')
                  ->default(false)
                  ->comment('Is file public?');

            // Additional metadata (JSON).
            $table->json('metadata')
                  ->nullable()
                  ->comment('Additional metadata (JSON)');

            // Soft delete.
            $table->softDeletes()
                  ->comment('Soft delete timestamp');

            // Standard timestamps.
            $table->timestamps();

            // Indexes for performance.
            $table->index('user_id');
            $table->index('tenant_id');
            $table->index('mime_type');
            $table->index('status');
            $table->index('is_public');
            $table->index('version');
            $table->index('parent_file_id');
            $table->index('deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};