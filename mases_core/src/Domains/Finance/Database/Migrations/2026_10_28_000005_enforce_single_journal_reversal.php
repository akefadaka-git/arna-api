<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    private const INDEX_NAME = 'journal_entries_single_reversal_unique';

    public function up(): void
    {
        // Fail explicitly rather than silently selecting a winner if legacy data
        // already contains multiple reversals for the same original journal.
        $duplicateExists = DB::table('journal_entries')
            ->select('tenant_id', 'legal_entity_id', 'reversal_of_id')
            ->whereNotNull('reversal_of_id')
            ->groupBy('tenant_id', 'legal_entity_id', 'reversal_of_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateExists) {
            throw new RuntimeException(
                'Cannot enforce single journal reversal: duplicate reversal_of_id references already exist. '
                . 'Reconcile these journals before retrying this migration.'
            );
        }

        Schema::table('journal_entries', function (Blueprint $table): void {
            // NULL references on ordinary journals remain unrestricted. A
            // non-NULL original journal can be reversed at most once per entity.
            $table->unique(
                ['tenant_id', 'legal_entity_id', 'reversal_of_id'],
                self::INDEX_NAME
            );
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX_NAME);
        });
    }
};
