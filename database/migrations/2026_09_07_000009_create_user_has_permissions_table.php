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
     * Creates the `user_has_permissions` pivot table, which assigns permissions
     * directly to users (bypassing roles). This allows granular, per-user
     * permission overrides or exceptions.
     *
     * Each row represents a single permission granted directly to a user.
     * The combination of (user_id, permission_id) is unique.
     *
     * Direct permissions are evaluated alongside role-based permissions.
     *
     * ይህ ማይግሬሽን በተጠቃሚዎች እና በፈቃዶች መካከል ቀጥተኛ ግንኙነት የሚያስተዳድረውን
     * `user_has_permissions` ሠንጠረዥ ይፈጥራል። ይህም ከሚና ውጭ ለተጠቃሚዎች በቀጥታ ፈቃድ ለመስጠት ያስችላል።
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('user_has_permissions', function (Blueprint $table): void {
            // Foreign key to users – cascade on delete.
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->cascadeOnDelete()
                  ->comment('User ID');

            // Foreign key to permissions – cascade on delete.
            $table->foreignId('permission_id')
                  ->constrained('permissions')
                  ->cascadeOnUpdate()
                  ->cascadeOnDelete()
                  ->comment('Permission ID');

            // Timestamp when the permission was assigned.
            $table->timestamp('assigned_at')
                  ->useCurrent()
                  ->comment('When the permission was assigned');

            // Optional tracking of who assigned the permission.
            $table->foreignId('assigned_by')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User ID of the assigner (nullable)');

            // Standard Eloquent pivot timestamps (created_at, updated_at)
            // These are required by ->withTimestamps() in relationships.
            $table->timestamps();

            // Composite primary key ensures uniqueness.
            $table->primary(['user_id', 'permission_id']);

            // Index for reverse lookups: find all users with a given permission.
            // (user_id is already indexed by the composite primary key.)
            $table->index('permission_id');

            // Index on assigned_by for auditing queries.
            $table->index('assigned_by');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('user_has_permissions');
    }
};