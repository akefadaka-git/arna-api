<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The ledger foundation owns this column on fresh installs. Older
        // schemas may reach this compatibility migration without it.
        if (!Schema::hasColumn('journal_entries', 'journal_type')) {
            Schema::table('journal_entries', function (Blueprint $table): void {
                $table->string('journal_type', 24)->default('standard')->after('journal_number');
            });
        }
    }

    public function down(): void
    {
        // The canonical ledger-foundation migration owns this column.
        // This compatibility migration must never remove it on rollback.
    }
};
