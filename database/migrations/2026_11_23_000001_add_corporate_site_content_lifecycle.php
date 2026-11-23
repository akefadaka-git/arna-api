<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['corporate_site_pages', 'corporate_site_entries'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->boolean('is_enabled')->default(true)->index();
                $table->timestamp('archived_at')->nullable()->index();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (['corporate_site_pages', 'corporate_site_entries'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropSoftDeletes();
                $table->dropColumn(['archived_at', 'is_enabled']);
            });
        }
    }
};
