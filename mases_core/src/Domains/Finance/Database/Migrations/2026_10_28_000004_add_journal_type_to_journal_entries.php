<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The ledger-foundation migration already defines this column on fresh installs.
        // Guard this additive migration so fresh and historically-created schemas converge.
        if (!Schema::hasColumn('journal_entries', 'journal_type')) {
            Schema::table('journal_entries', function (Blueprint $table): void {
                $table->string('journal_type', 24)->default('standard')->after('journal_number');
            });
        }
    }

    public function down(): void
    {
        // journal_type is part of the ledger-foundation schema and must not be dropped
        // when rolling back this compatibility migration.
    }
};
