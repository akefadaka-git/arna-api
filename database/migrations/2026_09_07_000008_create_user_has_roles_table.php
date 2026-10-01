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
     * Creates the `user_has_roles` pivot table – the many-to-many
     * relationship between users and roles.
     *
     * Each row represents a single role assigned to a user.
     * The combination of (user_id, role_id) is unique.
     *
     * ይህ ማይግሬሽን በተጠቃሚዎች እና በሚናዎች መካከል ያለውን የብዙ‑ወደ‑ብዙ ግንኙነት
     * የሚያስተዳድረውን `user_has_roles` ሠንጠረዥ ይፈጥራል።
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('user_has_roles', function (Blueprint $table): void {
            // Foreign key to users – cascade on delete.
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->cascadeOnDelete()
                  ->comment('User ID');

            // Foreign key to roles – cascade on delete.
            $table->foreignId('role_id')
                  ->constrained('roles')
                  ->cascadeOnUpdate()
                  ->cascadeOnDelete()
                  ->comment('Role ID');

            // Timestamp when the role was assigned to the user.
            $table->timestamp('assigned_at')
                  ->useCurrent()
                  ->comment('When the role was assigned');

            // Optional tracking of who assigned the role.
            $table->foreignId('assigned_by')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User ID of the assigner (nullable)');

            // Composite primary key ensures uniqueness.
            $table->primary(['user_id', 'role_id']);

            // Index for reverse lookups: find all users with a given role.
            // (user_id is already indexed by the composite primary key.)
            $table->index('role_id');

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
        Schema::dropIfExists('user_has_roles');
    }
};