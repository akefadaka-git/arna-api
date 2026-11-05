<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('governance_meeting_attendance', function (Blueprint $table): void {
            $table->foreignId('proxy_id')->nullable()->after('user_id')
                ->constrained('governance_meeting_proxies')->restrictOnDelete();
            $table->foreignId('represented_by_user_id')->nullable()->after('proxy_id')
                ->constrained('users')->restrictOnDelete();
            $table->string('represented_by_name', 150)->nullable()->after('represented_by_user_id');
            $table->index(['tenant_id', 'meeting_id', 'proxy_id'], 'gma_proxy_attendance_ix');
        });
    }

    public function down(): void
    {
        Schema::table('governance_meeting_attendance', function (Blueprint $table): void {
            $table->dropIndex('gma_proxy_attendance_ix');
            $table->dropConstrainedForeignId('represented_by_user_id');
            $table->dropConstrainedForeignId('proxy_id');
            $table->dropColumn('represented_by_name');
        });
    }
};
