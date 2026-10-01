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
     * Creates the `role_has_permissions` pivot table – the many-to-many
     * relationship between roles and permissions.
     *
     * Each row represents a single permission assigned to a role.
     * The combination of (role_id, permission_id) is unique.
     *
     * ይህ ማይግሬሽን በሚናዎች እና በፈቃዶች መካከል ያለውን የብዙ‑ወደ‑ብዙ ግንኙነት
     * የሚያስተዳድረውን `role_has_permissions` ሠንጠረዥ ይፈጥራል።
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            // Foreign key to roles – cascade on delete.
            $table->foreignId('role_id')
                  ->constrained('roles')
                  ->cascadeOnUpdate()
                  ->cascadeOnDelete()
                  ->comment('Role ID');

            // Foreign key to permissions – cascade on delete.
            $table->foreignId('permission_id')
                  ->constrained('permissions')
                  ->cascadeOnUpdate()
                  ->cascadeOnDelete()
                  ->comment('Permission ID');

            // Timestamp when the permission was assigned to the role.
            $table->timestamp('assigned_at')
                  ->useCurrent()
                  ->comment('When the permission was assigned');

            // Standard Eloquent pivot timestamps (created_at, updated_at)
            // These are required by ->withTimestamps() in relationships.
            $table->timestamps();

            // Composite primary key ensures uniqueness.
            $table->primary(['role_id', 'permission_id']);

            // Index for reverse lookups: find all roles that have a given permission.
            // (role_id is already covered by the composite primary key.)
            $table->index('permission_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
    }
};