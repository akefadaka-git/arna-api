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
            $table->string('meeting_category', 24)->nullable()->after('meeting_kind');
            $table->index(['tenant_id', 'meeting_kind', 'meeting_category'], 'gm_tenant_kind_category_ix');
        });
    }

    public function down(): void
    {
        Schema::table('governance_meetings', function (Blueprint $table): void {
            $table->dropIndex('gm_tenant_kind_category_ix');
            $table->dropColumn('meeting_category');
        });
    }
};
