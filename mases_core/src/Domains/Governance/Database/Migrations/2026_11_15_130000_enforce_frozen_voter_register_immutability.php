<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TRIGGERS = [
        'age_register_snapshot_initial_state_guard',
        'age_register_snapshot_lifecycle_transition_guard',
        'age_voter_register_frozen_insert',
        'age_voter_register_frozen_update',
        'age_voter_register_frozen_delete',
        'age_register_snapshot_frozen_update',
        'age_register_snapshot_freeze_transition_guard',
        'age_register_snapshot_frozen_delete',
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $this->createMySqlTriggers();
            return;
        }

        if ($driver === 'sqlite') {
            $this->createSqliteTriggers();
            return;
        }

        throw new RuntimeException(
            "Frozen voter-register immutability triggers are not implemented for database driver [{$driver}]."
        );
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException(
                "Cannot safely remove frozen voter-register triggers for database driver [{$driver}]."
            );
        }

        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function createMySqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_register_snapshot_initial_state_guard
BEFORE INSERT ON governance_election_register_snapshots
FOR EACH ROW
BEGIN
    IF NEW.register_status <> 'draft'
       OR NEW.frozen_at IS NOT NULL
       OR NEW.prepared_by IS NULL
       OR NEW.prepared_by <> NEW.created_by
       OR NEW.reconciled_by IS NOT NULL
       OR NEW.reconciled_at IS NOT NULL
       OR NEW.approved_by IS NOT NULL
       OR NEW.approved_at IS NOT NULL
       OR NEW.integrity_verified_at IS NOT NULL
       OR NEW.content_sha256 IS NULL
       OR NEW.content_sha256 = ''
       OR NEW.voter_count < 1
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Voter register snapshots must be inserted in the initial prepared draft state.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_register_snapshot_lifecycle_transition_guard
BEFORE UPDATE ON governance_election_register_snapshots
FOR EACH ROW
BEGIN
    IF NOT (NEW.tenant_id <=> OLD.tenant_id)
       OR NOT (NEW.election_id <=> OLD.election_id)
       OR NOT (NEW.record_date <=> OLD.record_date)
       OR NOT (NEW.source_reference <=> OLD.source_reference)
       OR NOT (NEW.rule_version <=> OLD.rule_version)
       OR NOT (NEW.content_sha256 <=> OLD.content_sha256)
       OR NOT (NEW.voter_count <=> OLD.voter_count)
       OR NOT (NEW.created_by <=> OLD.created_by)
       OR NOT (NEW.prepared_by <=> OLD.prepared_by)
       OR NOT (NEW.created_at <=> OLD.created_at)
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Voter register governing metadata is immutable after preparation.';
    END IF;

    IF NEW.register_status <> OLD.register_status THEN
        IF OLD.register_status = 'draft' AND NEW.register_status = 'reconciled' THEN
            IF NEW.reconciled_by IS NULL
               OR NEW.reconciled_by = OLD.prepared_by
               OR NEW.reconciled_at IS NULL
               OR NEW.integrity_verified_at IS NULL
               OR NEW.approved_by IS NOT NULL
               OR NEW.approved_at IS NOT NULL
               OR NEW.frozen_at IS NOT NULL
            THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Draft registers may transition only to independently reconciled state.';
            END IF;
        ELSEIF OLD.register_status = 'reconciled' AND NEW.register_status = 'frozen' THEN
            IF OLD.reconciled_by IS NULL
               OR OLD.reconciled_by = OLD.prepared_by
               OR OLD.reconciled_at IS NULL
               OR OLD.integrity_verified_at IS NULL
               OR NOT (NEW.reconciled_by <=> OLD.reconciled_by)
               OR NOT (NEW.reconciled_at <=> OLD.reconciled_at)
               OR NOT (NEW.integrity_verified_at <=> OLD.integrity_verified_at)
               OR NEW.approved_by IS NULL
               OR NEW.approved_by = OLD.prepared_by
               OR NEW.approved_by = OLD.reconciled_by
               OR NEW.approved_at IS NULL
               OR NEW.frozen_at IS NULL
            THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Reconciled registers may freeze only with preserved reconciliation and an independent approver.';
            END IF;
        ELSE
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Voter register lifecycle transitions must be draft to reconciled to frozen.';
        END IF;
    ELSEIF NOT (NEW.reconciled_by <=> OLD.reconciled_by)
       OR NOT (NEW.reconciled_at <=> OLD.reconciled_at)
       OR NOT (NEW.approved_by <=> OLD.approved_by)
       OR NOT (NEW.approved_at <=> OLD.approved_at)
       OR NOT (NEW.integrity_verified_at <=> OLD.integrity_verified_at)
       OR NOT (NEW.frozen_at <=> OLD.frozen_at)
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Voter register lifecycle metadata may change only during a valid forward transition.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_register_snapshot_lifecycle_transition_guard
BEFORE UPDATE ON governance_election_register_snapshots
WHEN NEW.tenant_id IS NOT OLD.tenant_id
 OR NEW.election_id IS NOT OLD.election_id
 OR NEW.record_date IS NOT OLD.record_date
 OR NEW.source_reference IS NOT OLD.source_reference
 OR NEW.rule_version IS NOT OLD.rule_version
 OR NEW.content_sha256 IS NOT OLD.content_sha256
 OR NEW.voter_count IS NOT OLD.voter_count
 OR NEW.created_by IS NOT OLD.created_by
 OR NEW.prepared_by IS NOT OLD.prepared_by
 OR NEW.created_at IS NOT OLD.created_at
 OR (
    NEW.register_status <> OLD.register_status
    AND NOT (
        (
            OLD.register_status = 'draft'
            AND NEW.register_status = 'reconciled'
            AND NEW.reconciled_by IS NOT NULL
            AND NEW.reconciled_by <> OLD.prepared_by
            AND NEW.reconciled_at IS NOT NULL
            AND NEW.integrity_verified_at IS NOT NULL
            AND NEW.approved_by IS NULL
            AND NEW.approved_at IS NULL
            AND NEW.frozen_at IS NULL
        )
        OR (
            OLD.register_status = 'reconciled'
            AND NEW.register_status = 'frozen'
            AND OLD.reconciled_by IS NOT NULL
            AND OLD.reconciled_by <> OLD.prepared_by
            AND OLD.reconciled_at IS NOT NULL
            AND OLD.integrity_verified_at IS NOT NULL
            AND NEW.reconciled_by IS OLD.reconciled_by
            AND NEW.reconciled_at IS OLD.reconciled_at
            AND NEW.integrity_verified_at IS OLD.integrity_verified_at
            AND NEW.approved_by IS NOT NULL
            AND NEW.approved_by <> OLD.prepared_by
            AND NEW.approved_by <> OLD.reconciled_by
            AND NEW.approved_at IS NOT NULL
            AND NEW.frozen_at IS NOT NULL
        )
    )
 )
 OR (
    NEW.register_status = OLD.register_status
    AND (
        NEW.reconciled_by IS NOT OLD.reconciled_by
        OR NEW.reconciled_at IS NOT OLD.reconciled_at
        OR NEW.approved_by IS NOT OLD.approved_by
        OR NEW.approved_at IS NOT OLD.approved_at
        OR NEW.integrity_verified_at IS NOT OLD.integrity_verified_at
        OR NEW.frozen_at IS NOT OLD.frozen_at
    )
 )
BEGIN
    SELECT RAISE(ABORT, 'Voter register lifecycle or governing metadata mutation is invalid.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_register_snapshot_freeze_transition_guard
BEFORE UPDATE ON governance_election_register_snapshots
FOR EACH ROW
BEGIN
    IF (
       (NEW.frozen_at IS NOT NULL AND OLD.frozen_at IS NULL AND NEW.register_status <> 'frozen')
       OR (
       NEW.register_status = 'frozen'
       AND OLD.register_status <> 'frozen'
       AND (
           OLD.register_status <> 'reconciled'
           OR NEW.prepared_by IS NULL
           OR NEW.reconciled_by IS NULL
           OR NEW.approved_by IS NULL
           OR NEW.prepared_by = NEW.reconciled_by
           OR NEW.prepared_by = NEW.approved_by
           OR NEW.reconciled_by = NEW.approved_by
           OR NEW.reconciled_at IS NULL
           OR NEW.approved_at IS NULL
           OR NEW.integrity_verified_at IS NULL
           OR NEW.frozen_at IS NULL
           OR NEW.content_sha256 IS NULL
           OR NEW.content_sha256 = ''
           OR NEW.voter_count < 1
       )))
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Voter register cannot freeze or set frozen_at without independent approval and verified integrity.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_register_snapshot_frozen_update
BEFORE UPDATE ON governance_election_register_snapshots
FOR EACH ROW
BEGIN
    IF OLD.register_status = 'frozen' OR OLD.frozen_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Frozen voter-register snapshots are immutable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_register_snapshot_frozen_delete
BEFORE DELETE ON governance_election_register_snapshots
FOR EACH ROW
BEGIN
    IF OLD.register_status = 'frozen' OR OLD.frozen_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Frozen voter-register snapshots are immutable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_voter_register_frozen_insert
BEFORE INSERT ON governance_election_voters
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(24);
    DECLARE v_frozen_at DATETIME;
    SELECT register_status, frozen_at INTO v_status, v_frozen_at
    FROM governance_election_register_snapshots
    WHERE id = NEW.snapshot_id AND tenant_id = NEW.tenant_id AND election_id = NEW.election_id
    FOR UPDATE;
    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Voter row must reference an existing snapshot in the same tenant and election.';
    END IF;
    IF v_status = 'frozen' OR v_frozen_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Frozen voter registers are immutable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_voter_register_frozen_update
BEFORE UPDATE ON governance_election_voters
FOR EACH ROW
BEGIN
    DECLARE v_old_status VARCHAR(24);
    DECLARE v_new_status VARCHAR(24);
    DECLARE v_old_frozen_at DATETIME;
    DECLARE v_new_frozen_at DATETIME;
    IF OLD.snapshot_id = NEW.snapshot_id THEN
        SELECT register_status, frozen_at INTO v_old_status, v_old_frozen_at
        FROM governance_election_register_snapshots
        WHERE id = OLD.snapshot_id AND tenant_id = OLD.tenant_id AND election_id = OLD.election_id
        FOR UPDATE;
        IF v_old_status IS NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Voter row must reference an existing snapshot in the same tenant and election.';
        END IF;
        SELECT register_status, frozen_at INTO v_new_status, v_new_frozen_at
        FROM governance_election_register_snapshots
        WHERE id = NEW.snapshot_id AND tenant_id = NEW.tenant_id AND election_id = NEW.election_id
        FOR UPDATE;
    ELSEIF OLD.snapshot_id < NEW.snapshot_id THEN
        SELECT register_status, frozen_at INTO v_old_status, v_old_frozen_at
        FROM governance_election_register_snapshots
        WHERE id = OLD.snapshot_id AND tenant_id = OLD.tenant_id AND election_id = OLD.election_id
        FOR UPDATE;
        SELECT register_status, frozen_at INTO v_new_status, v_new_frozen_at
        FROM governance_election_register_snapshots
        WHERE id = NEW.snapshot_id AND tenant_id = NEW.tenant_id AND election_id = NEW.election_id
        FOR UPDATE;
    ELSE
        SELECT register_status, frozen_at INTO v_new_status, v_new_frozen_at
        FROM governance_election_register_snapshots
        WHERE id = NEW.snapshot_id AND tenant_id = NEW.tenant_id AND election_id = NEW.election_id
        FOR UPDATE;
        SELECT register_status, frozen_at INTO v_old_status, v_old_frozen_at
        FROM governance_election_register_snapshots
        WHERE id = OLD.snapshot_id AND tenant_id = OLD.tenant_id AND election_id = OLD.election_id
        FOR UPDATE;
    END IF;
    IF v_old_status IS NULL OR v_new_status IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Voter row must reference an existing snapshot in the same tenant and election.';
    END IF;
    IF v_old_status = 'frozen' OR v_old_frozen_at IS NOT NULL
       OR v_new_status = 'frozen' OR v_new_frozen_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Frozen voter registers are immutable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_voter_register_frozen_delete
BEFORE DELETE ON governance_election_voters
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(24);
    DECLARE v_frozen_at DATETIME;
    SELECT register_status, frozen_at INTO v_status, v_frozen_at
    FROM governance_election_register_snapshots
    WHERE id = OLD.snapshot_id AND tenant_id = OLD.tenant_id AND election_id = OLD.election_id
    FOR UPDATE;
    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Voter row must reference an existing snapshot in the same tenant and election.';
    END IF;
    IF v_status = 'frozen' OR v_frozen_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Frozen voter registers are immutable.';
    END IF;
END
SQL);
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_register_snapshot_initial_state_guard
BEFORE INSERT ON governance_election_register_snapshots
WHEN NEW.register_status <> 'draft'
 OR NEW.frozen_at IS NOT NULL
 OR NEW.prepared_by IS NULL
 OR NEW.prepared_by <> NEW.created_by
 OR NEW.reconciled_by IS NOT NULL
 OR NEW.reconciled_at IS NOT NULL
 OR NEW.approved_by IS NOT NULL
 OR NEW.approved_at IS NOT NULL
 OR NEW.integrity_verified_at IS NOT NULL
 OR NEW.content_sha256 IS NULL
 OR NEW.content_sha256 = ''
 OR NEW.voter_count < 1
BEGIN
    SELECT RAISE(ABORT, 'Voter register snapshots must be inserted in the initial prepared draft state.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_register_snapshot_freeze_transition_guard
BEFORE UPDATE ON governance_election_register_snapshots
WHEN (
 (NEW.frozen_at IS NOT NULL AND OLD.frozen_at IS NULL AND NEW.register_status <> 'frozen')
 OR (
 NEW.register_status = 'frozen'
 AND OLD.register_status <> 'frozen'
 AND (
    OLD.register_status <> 'reconciled'
    OR NEW.prepared_by IS NULL
    OR NEW.reconciled_by IS NULL
    OR NEW.approved_by IS NULL
    OR NEW.prepared_by = NEW.reconciled_by
    OR NEW.prepared_by = NEW.approved_by
    OR NEW.reconciled_by = NEW.approved_by
    OR NEW.reconciled_at IS NULL
    OR NEW.approved_at IS NULL
    OR NEW.integrity_verified_at IS NULL
    OR NEW.frozen_at IS NULL
    OR NEW.content_sha256 IS NULL
    OR NEW.content_sha256 = ''
    OR NEW.voter_count < 1
 )))
BEGIN
    SELECT RAISE(ABORT, 'Voter register cannot freeze or set frozen_at without independent approval and verified integrity.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_register_snapshot_frozen_update
BEFORE UPDATE ON governance_election_register_snapshots
WHEN OLD.register_status = 'frozen' OR OLD.frozen_at IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'Frozen voter-register snapshots are immutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_register_snapshot_frozen_delete
BEFORE DELETE ON governance_election_register_snapshots
WHEN OLD.register_status = 'frozen' OR OLD.frozen_at IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'Frozen voter-register snapshots are immutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_voter_register_frozen_insert
BEFORE INSERT ON governance_election_voters
WHEN NOT EXISTS (
    SELECT 1 FROM governance_election_register_snapshots
    WHERE id = NEW.snapshot_id AND tenant_id = NEW.tenant_id AND election_id = NEW.election_id
) OR EXISTS (
    SELECT 1 FROM governance_election_register_snapshots
    WHERE id = NEW.snapshot_id AND tenant_id = NEW.tenant_id AND election_id = NEW.election_id
      AND (register_status = 'frozen' OR frozen_at IS NOT NULL)
)
BEGIN
    SELECT RAISE(ABORT, 'Frozen voter registers are immutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_voter_register_frozen_update
BEFORE UPDATE ON governance_election_voters
WHEN NOT EXISTS (
    SELECT 1 FROM governance_election_register_snapshots
    WHERE id = OLD.snapshot_id AND tenant_id = OLD.tenant_id AND election_id = OLD.election_id
) OR NOT EXISTS (
    SELECT 1 FROM governance_election_register_snapshots
    WHERE id = NEW.snapshot_id AND tenant_id = NEW.tenant_id AND election_id = NEW.election_id
) OR EXISTS (
    SELECT 1 FROM governance_election_register_snapshots
    WHERE (id = OLD.snapshot_id AND tenant_id = OLD.tenant_id AND election_id = OLD.election_id
           OR id = NEW.snapshot_id AND tenant_id = NEW.tenant_id AND election_id = NEW.election_id)
      AND (register_status = 'frozen' OR frozen_at IS NOT NULL)
)
BEGIN
    SELECT RAISE(ABORT, 'Frozen voter registers are immutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_voter_register_frozen_delete
BEFORE DELETE ON governance_election_voters
WHEN NOT EXISTS (
    SELECT 1 FROM governance_election_register_snapshots
    WHERE id = OLD.snapshot_id AND tenant_id = OLD.tenant_id AND election_id = OLD.election_id
) OR EXISTS (
    SELECT 1 FROM governance_election_register_snapshots
    WHERE id = OLD.snapshot_id AND tenant_id = OLD.tenant_id AND election_id = OLD.election_id
      AND (register_status = 'frozen' OR frozen_at IS NOT NULL)
)
BEGIN
    SELECT RAISE(ABORT, 'Frozen voter registers are immutable.');
END
SQL);
    }
};
