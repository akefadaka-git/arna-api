<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('governance_memberships', function (Blueprint $table): void {
            // Explicit opt-in: active membership alone does not imply voting rights.
            $table->boolean('is_voting_member')->default(false)->after('is_ex_officio');
        });

        Schema::table('governance_meetings', function (Blueprint $table): void {
            $table->json('quorum_rule_snapshot')->nullable()->after('quorum_snapshot_json');
            $table->boolean('quorum_met')->nullable()->after('quorum_rule_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('governance_meetings', function (Blueprint $table): void {
            $table->dropColumn(['quorum_met', 'quorum_rule_snapshot']);
        });
        Schema::table('governance_memberships', function (Blueprint $table): void {
            $table->dropColumn('is_voting_member');
        });
    }
};
