<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SOURCE_EVENT_UNIQUE = 'journal_entries_source_event_version_unique';

    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            // Existing/manual journals remain NULL. Every new external-source
            // journal is required by the service contract to supply a version.
            $table->string('source_event_version', 64)->nullable()->after('source_id');
            $table->char('source_event_hash', 64)->nullable()->after('source_event_version');
            $table->unique(
                ['tenant_id', 'legal_entity_id', 'source_type', 'source_id', 'source_event_version'],
                self::SOURCE_EVENT_UNIQUE
            );
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_immutable_update');
            DB::unprepared("CREATE TRIGGER journal_entries_immutable_update BEFORE UPDATE ON journal_entries FOR EACH ROW BEGIN IF OLD.status IN ('posted', 'reversed', 'cancelled') THEN IF NOT (OLD.status = 'posted' AND NEW.status = 'reversed' AND NEW.reversed_at IS NOT NULL AND OLD.id <=> NEW.id AND OLD.tenant_id <=> NEW.tenant_id AND OLD.legal_entity_id <=> NEW.legal_entity_id AND OLD.accounting_period_id <=> NEW.accounting_period_id AND OLD.journal_number <=> NEW.journal_number AND OLD.effective_date <=> NEW.effective_date AND OLD.currency <=> NEW.currency AND OLD.description <=> NEW.description AND OLD.journal_type <=> NEW.journal_type AND OLD.cancelled_by <=> NEW.cancelled_by AND OLD.cancelled_at <=> NEW.cancelled_at AND OLD.cancellation_reason <=> NEW.cancellation_reason AND OLD.source_type <=> NEW.source_type AND OLD.source_id <=> NEW.source_id AND OLD.source_event_version <=> NEW.source_event_version AND OLD.source_event_hash <=> NEW.source_event_hash AND OLD.idempotency_key <=> NEW.idempotency_key AND OLD.request_hash <=> NEW.request_hash AND OLD.created_by <=> NEW.created_by AND OLD.posted_by <=> NEW.posted_by AND OLD.posted_at <=> NEW.posted_at AND OLD.reversal_of_id <=> NEW.reversal_of_id AND OLD.created_at <=> NEW.created_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal entries are immutable'; END IF; END IF; END");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_immutable_update');
            DB::unprepared("CREATE TRIGGER journal_entries_immutable_update BEFORE UPDATE ON journal_entries FOR EACH ROW BEGIN IF OLD.status IN ('posted', 'reversed', 'cancelled') THEN IF NOT (OLD.status = 'posted' AND NEW.status = 'reversed' AND NEW.reversed_at IS NOT NULL AND OLD.id <=> NEW.id AND OLD.tenant_id <=> NEW.tenant_id AND OLD.legal_entity_id <=> NEW.legal_entity_id AND OLD.accounting_period_id <=> NEW.accounting_period_id AND OLD.journal_number <=> NEW.journal_number AND OLD.effective_date <=> NEW.effective_date AND OLD.currency <=> NEW.currency AND OLD.description <=> NEW.description AND OLD.journal_type <=> NEW.journal_type AND OLD.cancelled_by <=> NEW.cancelled_by AND OLD.cancelled_at <=> NEW.cancelled_at AND OLD.cancellation_reason <=> NEW.cancellation_reason AND OLD.source_type <=> NEW.source_type AND OLD.source_id <=> NEW.source_id AND OLD.idempotency_key <=> NEW.idempotency_key AND OLD.request_hash <=> NEW.request_hash AND OLD.created_by <=> NEW.created_by AND OLD.posted_by <=> NEW.posted_by AND OLD.posted_at <=> NEW.posted_at AND OLD.reversal_of_id <=> NEW.reversal_of_id AND OLD.created_at <=> NEW.created_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal entries are immutable'; END IF; END IF; END");
        }

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropUnique(self::SOURCE_EVENT_UNIQUE);
            $table->dropColumn(['source_event_version', 'source_event_hash']);
        });
    }
};
