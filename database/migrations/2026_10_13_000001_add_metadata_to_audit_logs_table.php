<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('audit_logs', 'metadata')) {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->json('metadata')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('audit_logs', 'metadata')) {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->dropColumn('metadata');
            });
        }
    }
};