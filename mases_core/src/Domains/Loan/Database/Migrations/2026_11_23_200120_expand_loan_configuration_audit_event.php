<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit event identifiers are append-only history. Expand capacity
        // without rewriting or truncating any existing audit records.
        Schema::table('loan_configuration_audit_logs', function (Blueprint $table): void {
            $table->string('event', 64)->change();
        });
    }

    public function down(): void
    {
        // Refuse a lossy rollback if any recorded event exceeds the legacy
        // 32-character limit. Existing audit history must never be truncated.
        $hasLongEvents = DB::table('loan_configuration_audit_logs')
            ->whereRaw('CHAR_LENGTH(event) > 32')
            ->exists();

        if ($hasLongEvents) {
            throw new RuntimeException(
                'Cannot shrink loan audit event capacity: audit history contains event identifiers longer than 32 characters.'
            );
        }

        Schema::table('loan_configuration_audit_logs', function (Blueprint $table): void {
            $table->string('event', 32)->change();
        });
    }
};
