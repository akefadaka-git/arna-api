<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Test helpers and recovery tooling may replay this migration against a
        // schema where the column already exists. Keep the operation idempotent.
        if (Schema::hasColumn('journal_entries', 'journal_type')) {
            return;
        }

        Schema::table('journal_entries', function (Blueprint $table): void {
            // Existing rows remain valid and are classified as standard journals.
            $table->string('journal_type', 24)->default('standard')->after('journal_number');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('journal_entries', 'journal_type')) {
            return;
        }

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropColumn('journal_type');
        });
    }
};
