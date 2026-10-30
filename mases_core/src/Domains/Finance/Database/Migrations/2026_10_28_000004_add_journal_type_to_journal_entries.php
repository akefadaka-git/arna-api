<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * The journal_type column is part of the canonical journal_entries definition
 * in 2026_10_28_000003_create_financial_ledger_foundation.php.
 *
 * This historical follow-up is intentionally retained as a no-op so existing
 * migration ledgers remain compatible without attempting to add the column a
 * second time on fresh installations.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Owned by the financial ledger foundation migration.
    }

    public function down(): void
    {
        // Do not drop a column owned by the financial ledger foundation migration.
    }
};
