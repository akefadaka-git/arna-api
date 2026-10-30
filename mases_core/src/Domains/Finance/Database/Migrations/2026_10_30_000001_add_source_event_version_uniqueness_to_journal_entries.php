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
            // Existing/manual journals remain NULL. New external-source journals
            // must supply an aggregate reference, immutable event ID/version,
            // and canonical event payload hash.
            $table->string('source_event_id', 128)->nullable()->after('source_id');
            $table->string('source_event_version', 64)->nullable()->after('source_event_id');
            $table->char('source_event_hash', 64)->nullable()->after('source_event_version');
            $table->unique(
                ['tenant_id', 'legal_entity_id', 'source_type', 'source_event_id', 'source_event_version'],
                self::SOURCE_EVENT_UNIQUE
            );
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_validate_source_identity_insert');
            DB::unprepared("CREATE TRIGGER journal_entries_validate_source_identity_insert BEFORE INSERT ON journal_entries FOR EACH ROW BEGIN IF NEW.source_type IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal source type is required'; ELSEIF NEW.source_type NOT IN ('manual', 'system') THEN IF NEW.source_id IS NULL OR TRIM(NEW.source_id) = '' OR NEW.source_event_id IS NULL OR TRIM(NEW.source_event_id) = '' OR NEW.source_event_version IS NULL OR TRIM(NEW.source_event_version) = '' OR NEW.source_event_hash IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'External source journals require a source record ID, immutable event ID, event version, and payload hash'; END IF; ELSEIF NEW.source_event_id IS NOT NULL OR NEW.source_event_version IS NOT NULL OR NEW.source_event_hash IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Manual and system journals cannot carry external source identity'; END IF; END");
            DB::unprepared("CREATE TRIGGER journal_entries_immutable_update BEFORE UPDATE ON journal_entries FOR EACH ROW BEGIN IF NOT (OLD.source_type <=> NEW.source_type AND OLD.source_id <=> NEW.source_id AND OLD.source_event_id <=> NEW.source_event_id AND OLD.source_event_version <=> NEW.source_event_version AND OLD.source_event_hash <=> NEW.source_event_hash) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal source event identity is immutable'; END IF; IF OLD.status IN ('posted', 'reversed', 'cancelled') THEN IF NOT (OLD.status = 'posted' AND NEW.status = 'reversed' AND NEW.reversed_at IS NOT NULL AND OLD.id <=> NEW.id AND OLD.tenant_id <=> NEW.tenant_id AND OLD.legal_entity_id <=> NEW.legal_entity_id AND OLD.accounting_period_id <=> NEW.accounting_period_id AND OLD.journal_number <=> NEW.journal_number AND OLD.effective_date <=> NEW.effective_date AND OLD.currency <=> NEW.currency AND OLD.description <=> NEW.description AND OLD.journal_type <=> NEW.journal_type AND OLD.cancelled_by <=> NEW.cancelled_by AND OLD.cancelled_at <=> NEW.cancelled_at AND OLD.cancellation_reason <=> NEW.cancellation_reason AND OLD.source_type <=> NEW.source_type AND OLD.source_id <=> NEW.source_id AND OLD.source_event_id <=> NEW.source_event_id AND OLD.source_event_version <=> NEW.source_event_version AND OLD.source_event_hash <=> NEW.source_event_hash AND OLD.idempotency_key <=> NEW.idempotency_key AND OLD.request_hash <=> NEW.request_hash AND OLD.created_by <=> NEW.created_by AND OLD.posted_by <=> NEW.posted_by AND OLD.posted_at <=> NEW.posted_at AND OLD.reversal_of_id <=> NEW.reversal_of_id AND OLD.created_at <=> NEW.created_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal entries are immutable'; END IF; END IF; END");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_validate_source_identity_insert');
            DB::unprepared("CREATE TRIGGER journal_entries_immutable_update BEFORE UPDATE ON journal_entries FOR EACH ROW BEGIN IF OLD.status IN ('posted', 'reversed', 'cancelled') THEN IF NOT (OLD.status = 'posted' AND NEW.status = 'reversed' AND NEW.reversed_at IS NOT NULL AND OLD.id <=> NEW.id AND OLD.tenant_id <=> NEW.tenant_id AND OLD.legal_entity_id <=> NEW.legal_entity_id AND OLD.accounting_period_id <=> NEW.accounting_period_id AND OLD.journal_number <=> NEW.journal_number AND OLD.effective_date <=> NEW.effective_date AND OLD.currency <=> NEW.currency AND OLD.description <=> NEW.description AND OLD.journal_type <=> NEW.journal_type AND OLD.cancelled_by <=> NEW.cancelled_by AND OLD.cancelled_at <=> NEW.cancelled_at AND OLD.cancellation_reason <=> NEW.cancellation_reason AND OLD.source_type <=> NEW.source_type AND OLD.source_id <=> NEW.source_id AND OLD.idempotency_key <=> NEW.idempotency_key AND OLD.request_hash <=> NEW.request_hash AND OLD.created_by <=> NEW.created_by AND OLD.posted_by <=> NEW.posted_by AND OLD.posted_at <=> NEW.posted_at AND OLD.reversal_of_id <=> NEW.reversal_of_id AND OLD.created_at <=> NEW.created_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal entries are immutable'; END IF; END IF; END");
        }

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropUnique(self::SOURCE_EVENT_UNIQUE);
            $table->dropColumn(['source_event_id', 'source_event_version', 'source_event_hash']);
        });
    }
};
