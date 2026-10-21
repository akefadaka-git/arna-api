<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add explicit lifecycle state to tenant memberships.
     *
     * Existing rows default to active for additive compatibility. This does
     * not certify historical membership assignments; review and status changes
     * remain an Owner-governed operation.
     */
    public function up(): void
    {
        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->string('status', 16)
                ->default('active')
                ->index()
                ->after('user_id')
                ->comment('Membership lifecycle: active, suspended, or revoked');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
