<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->createMySqlGuards();
            return;
        }
        if ($driver === 'sqlite') {
            $this->createSqliteGuards();
            return;
        }

        throw new RuntimeException('Manual-count evidence immutability requires MySQL or SQLite trigger support.');
    }

    public function down(): void
    {
        if (DB::table('governance_election_manual_count_batches')->exists()
            || DB::table('governance_election_manual_count_lines')->exists()) {
            throw new RuntimeException('Refusing to remove immutability guards while manual-count evidence exists.');
        }

        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            foreach ([
                'gov_elec_manual_batch_insert_guard',
                'gov_elec_manual_batch_update_guard',
                'gov_elec_manual_batch_delete_guard',
                'gov_elec_manual_line_insert_guard',
                'gov_elec_manual_line_update_guard',
                'gov_elec_manual_line_delete_guard',
            ] as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
            return;
        }

        if ($driver === 'sqlite') {
            foreach ([
                'gov_elec_manual_batch_insert_guard',
                'gov_elec_manual_batch_update_guard',
                'gov_elec_manual_batch_delete_guard',
                'gov_elec_manual_line_insert_guard',
                'gov_elec_manual_line_update_guard',
                'gov_elec_manual_line_delete_guard',
            ] as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }
    }

    private function createMySqlGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER gov_elec_manual_batch_insert_guard
BEFORE INSERT ON governance_election_manual_count_batches
FOR EACH ROW
BEGIN
    IF NEW.status <> 'pending_witness'
       OR NEW.witnessed_by IS NOT NULL
       OR NEW.witnessed_at IS NOT NULL
       OR NEW.ballots_received <> (NEW.ballots_valid + NEW.ballots_rejected)
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Manual count batches must begin as balanced pending-witness evidence.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gov_elec_manual_batch_update_guard
BEFORE UPDATE ON governance_election_manual_count_batches
FOR EACH ROW
BEGIN
    IF OLD.status <> 'pending_witness'
       OR NEW.status <> 'witnessed'
       OR NEW.witnessed_by IS NULL
       OR NEW.witnessed_at IS NULL
       OR NEW.witnessed_by = OLD.entered_by
       OR NOT (NEW.tenant_id <=> OLD.tenant_id)
       OR NOT (NEW.election_id <=> OLD.election_id)
       OR NOT (NEW.batch_reference <=> OLD.batch_reference)
       OR NOT (NEW.polling_station <=> OLD.polling_station)
       OR NOT (NEW.ballots_received <=> OLD.ballots_received)
       OR NOT (NEW.ballots_valid <=> OLD.ballots_valid)
       OR NOT (NEW.ballots_rejected <=> OLD.ballots_rejected)
       OR NOT (NEW.sealed_box_reference <=> OLD.sealed_box_reference)
       OR NOT (NEW.count_sheet_reference <=> OLD.count_sheet_reference)
       OR NOT (NEW.entered_by <=> OLD.entered_by)
       OR NOT (NEW.counted_at <=> OLD.counted_at)
       OR NOT (NEW.created_at <=> OLD.created_at)
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Manual count evidence permits only one independent witness transition.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gov_elec_manual_batch_delete_guard
BEFORE DELETE ON governance_election_manual_count_batches
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Manual count evidence cannot be deleted.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gov_elec_manual_line_insert_guard
BEFORE INSERT ON governance_election_manual_count_lines
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM governance_election_manual_count_batches
        WHERE tenant_id = NEW.tenant_id
          AND election_id = NEW.election_id
          AND id = NEW.batch_id
          AND status = 'pending_witness'
    ) OR NOT EXISTS (
        SELECT 1 FROM governance_election_candidates
        WHERE tenant_id = NEW.tenant_id
          AND election_id = NEW.election_id
          AND id = NEW.candidate_id
          AND status = 'eligible'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Count lines require a pending batch and an eligible candidate in the same tenant election.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gov_elec_manual_line_update_guard
BEFORE UPDATE ON governance_election_manual_count_lines
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Manual count lines are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gov_elec_manual_line_delete_guard
BEFORE DELETE ON governance_election_manual_count_lines
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Manual count lines cannot be deleted.';
END
SQL);
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER gov_elec_manual_batch_insert_guard
BEFORE INSERT ON governance_election_manual_count_batches
WHEN NEW.status <> 'pending_witness'
  OR NEW.witnessed_by IS NOT NULL
  OR NEW.witnessed_at IS NOT NULL
  OR NEW.ballots_received <> (NEW.ballots_valid + NEW.ballots_rejected)
BEGIN
    SELECT RAISE(ABORT, 'Manual count batches must begin as balanced pending-witness evidence.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gov_elec_manual_batch_update_guard
BEFORE UPDATE ON governance_election_manual_count_batches
WHEN OLD.status <> 'pending_witness'
  OR NEW.status <> 'witnessed'
  OR NEW.witnessed_by IS NULL
  OR NEW.witnessed_at IS NULL
  OR NEW.witnessed_by = OLD.entered_by
  OR NEW.tenant_id IS NOT OLD.tenant_id
  OR NEW.election_id IS NOT OLD.election_id
  OR NEW.batch_reference IS NOT OLD.batch_reference
  OR NEW.polling_station IS NOT OLD.polling_station
  OR NEW.ballots_received IS NOT OLD.ballots_received
  OR NEW.ballots_valid IS NOT OLD.ballots_valid
  OR NEW.ballots_rejected IS NOT OLD.ballots_rejected
  OR NEW.sealed_box_reference IS NOT OLD.sealed_box_reference
  OR NEW.count_sheet_reference IS NOT OLD.count_sheet_reference
  OR NEW.entered_by IS NOT OLD.entered_by
  OR NEW.counted_at IS NOT OLD.counted_at
  OR NEW.created_at IS NOT OLD.created_at
BEGIN
    SELECT RAISE(ABORT, 'Manual count evidence permits only one independent witness transition.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gov_elec_manual_batch_delete_guard
BEFORE DELETE ON governance_election_manual_count_batches
BEGIN
    SELECT RAISE(ABORT, 'Manual count evidence cannot be deleted.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gov_elec_manual_line_insert_guard
BEFORE INSERT ON governance_election_manual_count_lines
WHEN NOT EXISTS (
    SELECT 1 FROM governance_election_manual_count_batches
    WHERE tenant_id = NEW.tenant_id
      AND election_id = NEW.election_id
      AND id = NEW.batch_id
      AND status = 'pending_witness'
) OR NOT EXISTS (
    SELECT 1 FROM governance_election_candidates
    WHERE tenant_id = NEW.tenant_id
      AND election_id = NEW.election_id
      AND id = NEW.candidate_id
      AND status = 'eligible'
)
BEGIN
    SELECT RAISE(ABORT, 'Count lines require a pending batch and an eligible candidate in the same tenant election.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gov_elec_manual_line_update_guard
BEFORE UPDATE ON governance_election_manual_count_lines
BEGIN
    SELECT RAISE(ABORT, 'Manual count lines are immutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gov_elec_manual_line_delete_guard
BEFORE DELETE ON governance_election_manual_count_lines
BEGIN
    SELECT RAISE(ABORT, 'Manual count lines cannot be deleted.');
END
SQL);
    }
};
