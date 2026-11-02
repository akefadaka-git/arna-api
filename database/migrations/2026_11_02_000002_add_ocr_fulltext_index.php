<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL uses its native FULLTEXT index. Other test/development
        // drivers retain the tenant-scoped LIKE fallback.
        if (DB::getDriverName() === 'mysql') {
            Schema::table('ocr_results', function (Blueprint $table): void {
                $table->fullText('full_text', 'ocr_results_full_text_idx');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('ocr_results', function (Blueprint $table): void {
                $table->dropFullText('ocr_results_full_text_idx');
            });
        }
    }
};
