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
     * Creates the `tenant_memberships` pivot table that establishes the
     * many‑to‑many relationship between users and tenants.
     *
     * This table is critical for tenant isolation and authorization.
     * A user must have at least one membership to access any tenant‑scoped
     * resource.
     *
     * ይህ ማይግሬሽን በተጠቃሚዎች እና በተከራዮች መካከል ያለውን የብዙ‑ወደ‑ብዙ ግንኙነት
     * የሚያስተዳድረውን `tenant_memberships` ሠንጠረዥ ይፈጥራል።
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('tenant_memberships', function (Blueprint $table): void {
            // Tenant foreign key – cascades on delete to remove membership when tenant is deleted.
            $table->foreignId('tenant_id')
                  ->constrained('tenants')
                  ->cascadeOnUpdate()
                  ->cascadeOnDelete()
                  ->comment('Tenant ID');

            // User foreign key – cascades on delete to remove membership when user is deleted.
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->cascadeOnDelete()
                  ->comment('User ID');

            // When the user joined the tenant.
            $table->timestamp('joined_at')
                  ->useCurrent()
                  ->comment('Timestamp when user joined the tenant');

            // Optional JSON for membership‑specific configuration.
            $table->json('settings')
                  ->nullable()
                  ->comment('Membership‑specific settings (JSON)');

            // Standard record tracking timestamps.
            $table->timestamps();

            // Composite primary key ensures a user cannot be a member of the same tenant twice.
            $table->primary(['tenant_id', 'user_id']);

            // Index for reverse lookups (find all tenants a user belongs to).
            $table->index('user_id');
            // Index on joined_at if filtering by join date is common.
            $table->index('joined_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_memberships');
    }
};