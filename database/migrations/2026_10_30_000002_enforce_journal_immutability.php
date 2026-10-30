<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MYSQL_TRIGGERS = [
        'journal_lines_immutable_insert',
        'journal_lines_immutable_update',
        'journal_lines_immutable_delete',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER journal_lines_immutable_insert BEFORE INSERT ON journal_lines FOR EACH ROW BEGIN DECLARE parent_status VARCHAR(32); SELECT status INTO parent_status FROM journal_entries WHERE id = NEW.journal_entry_id FOR UPDATE; IF parent_status IS NULL OR parent_status <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal lines may only be inserted while the journal is a draft'; END IF; END");
            DB::unprepared("CREATE TRIGGER journal_lines_immutable_update BEFORE UPDATE ON journal_lines FOR EACH ROW BEGIN DECLARE parent_status VARCHAR(32); IF OLD.journal_entry_id <> NEW.journal_entry_id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal lines cannot be moved between journal entries'; END IF; SELECT status INTO parent_status FROM journal_entries WHERE id = OLD.journal_entry_id FOR UPDATE; IF parent_status IS NULL OR parent_status <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal lines are immutable'; END IF; END");
            DB::unprepared("CREATE TRIGGER journal_lines_immutable_delete BEFORE DELETE ON journal_lines FOR EACH ROW BEGIN DECLARE parent_status VARCHAR(32); SELECT status INTO parent_status FROM journal_entries WHERE id = OLD.journal_entry_id FOR UPDATE; IF parent_status IS NULL OR parent_status <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal lines cannot be deleted'; END IF; END");

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER journal_entries_immutable_header_update BEFORE UPDATE OF id, tenant_id, legal_entity_id, accounting_period_id, journal_number, effective_date, currency, description, journal_type, cancelled_by, cancelled_at, cancellation_reason, source_type, source_id, source_event_id, source_event_version, source_event_hash, idempotency_key, request_hash, created_by, posted_by, posted_at, reversal_of_id, created_at, updated_at ON journal_entries WHEN OLD.status IN ('posted', 'reversed', 'cancelled') BEGIN SELECT RAISE(ABORT, 'Posted journal headers are immutable'); END");
            DB::unprepared("CREATE TRIGGER journal_entries_immutable_status_update BEFORE UPDATE OF status ON journal_entries WHEN OLD.status IN ('posted', 'reversed', 'cancelled') AND NOT (OLD.status = 'posted' AND NEW.status = 'reversed' AND NEW.reversed_at IS NOT NULL) BEGIN SELECT RAISE(ABORT, 'Posted journal status transition is not permitted'); END");
            DB::unprepared("CREATE TRIGGER journal_entries_immutable_reversed_at_update BEFORE UPDATE OF reversed_at ON journal_entries WHEN OLD.status IN ('posted', 'reversed', 'cancelled') AND NOT (OLD.status = 'posted' AND NEW.status = 'reversed' AND NEW.reversed_at IS NOT NULL) BEGIN SELECT RAISE(ABORT, 'Journal reversal timestamp is immutable'); END");
            DB::unprepared("CREATE TRIGGER journal_lines_immutable_insert BEFORE INSERT ON journal_lines WHEN (SELECT status FROM journal_entries WHERE id = NEW.journal_entry_id) <> 'draft' BEGIN SELECT RAISE(ABORT, 'Journal lines may only be inserted while the journal is a draft'); END");
            DB::unprepared("CREATE TRIGGER journal_lines_immutable_update BEFORE UPDATE ON journal_lines WHEN (SELECT status FROM journal_entries WHERE id = OLD.journal_entry_id) <> 'draft' OR (SELECT status FROM journal_entries WHERE id = NEW.journal_entry_id) <> 'draft' BEGIN SELECT RAISE(ABORT, 'Posted journal lines are immutable'); END");
            DB::unprepared("CREATE TRIGGER journal_lines_immutable_delete BEFORE DELETE ON journal_lines WHEN (SELECT status FROM journal_entries WHERE id = OLD.journal_entry_id) <> 'draft' BEGIN SELECT RAISE(ABORT, 'Posted journal lines cannot be deleted'); END");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            foreach (self::MYSQL_TRIGGERS as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }

        if ($driver === 'sqlite') {
            foreach ([
                'journal_entries_immutable_header_update',
                'journal_entries_immutable_status_update',
                'journal_entries_immutable_reversed_at_update',
                'journal_lines_immutable_insert',
                'journal_lines_immutable_update',
                'journal_lines_immutable_delete',
            ] as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }
    }
};
