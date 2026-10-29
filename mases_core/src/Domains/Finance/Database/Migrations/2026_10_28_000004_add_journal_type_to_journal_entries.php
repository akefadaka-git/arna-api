<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The ledger foundation currently declares this column directly.
        // Older installations may still reach this migration without it, so
        // preserve compatibility while avoiding duplicate-column failures on
        // a clean install.
        if (!Schema::hasColumn('journal_entries', 'journal_type')) {
            Schema::table('journal_entries', function (Blueprint $table): void {
                $table->string('journal_type', 24)->default('standard')->after('journal_number');
            });
        }
    }

    public function down(): void
    {
        // journal_type is part of the ledger foundation's canonical schema.
        // Do not drop a column that may have been created by that foundation;
        // the foundation migration owns its removal when the ledger is rolled
        // back as a whole. This compatibility migration is intentionally
        // non-destructive on rollback.
    }
};
