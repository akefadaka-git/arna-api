<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('governance_meetings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('meeting_round')->nullable()->after('meeting_category');
            $table->index(['tenant_id', 'meeting_kind', 'meeting_category', 'meeting_round'], 'gm_tenant_ga_round_ix');
        });
    }

    public function down(): void
    {
        Schema::table('governance_meetings', function (Blueprint $table): void {
            $table->dropIndex('gm_tenant_ga_round_ix');
            $table->dropColumn('meeting_round');
        });
    }
};
