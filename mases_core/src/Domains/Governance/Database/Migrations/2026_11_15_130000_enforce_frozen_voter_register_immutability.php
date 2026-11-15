<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\DB;

/**
 * Enforce frozen voter-register immutability at the database boundary.
 *
 * Service checks and row locks protect cooperating application writers.
 * These triggers also reject direct INSERT/UPDATE/DELETE statements against
 * a frozen snapshot, regardless of whether the caller uses Eloquent or the
 * query builder.
 */
return new class extends Migration
{
    private const TABLE = 'governance_election_voters';

    private const TRIGGERS = [
        'age_voter_register_frozen_insert',
        'age_voter_register_frozen_update',
        'age_voter_register_frozen_delete',
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

        foreach (self::TRIGGERS as $trigger) {
            if ($driver === 'mysql') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            } elseif ($driver === 'sqlite') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            } else {
                throw new RuntimeException(
                    "Cannot safely remove frozen voter-register triggers for database driver [{$driver}]."
                );
            }
        }
    }

    private function createMySqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_voter_register_frozen_insert
BEFORE INSERT ON governance_election_voters
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM governance_election_register_snapshots
        WHERE id = NEW.snapshot_id
          AND (register_status = 'frozen' OR frozen_at IS NOT NULL)
    ) THEN
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
    IF EXISTS (
        SELECT 1
        FROM governance_election_register_snapshots
        WHERE id IN (OLD.snapshot_id, NEW.snapshot_id)
          AND (register_status = 'frozen' OR frozen_at IS NOT NULL)
    ) THEN
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
    IF EXISTS (
        SELECT 1
        FROM governance_election_register_snapshots
        WHERE id = OLD.snapshot_id
          AND (register_status = 'frozen' OR frozen_at IS NOT NULL)
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Frozen voter registers are immutable.';
    END IF;
END
SQL);
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_voter_register_frozen_insert
BEFORE INSERT ON governance_election_voters
WHEN EXISTS (
    SELECT 1
    FROM governance_election_register_snapshots
    WHERE id = NEW.snapshot_id
      AND (register_status = 'frozen' OR frozen_at IS NOT NULL)
)
BEGIN
    SELECT RAISE(ABORT, 'Frozen voter registers are immutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_voter_register_frozen_update
BEFORE UPDATE ON governance_election_voters
WHEN EXISTS (
    SELECT 1
    FROM governance_election_register_snapshots
    WHERE id IN (OLD.snapshot_id, NEW.snapshot_id)
      AND (register_status = 'frozen' OR frozen_at IS NOT NULL)
)
BEGIN
    SELECT RAISE(ABORT, 'Frozen voter registers are immutable.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_voter_register_frozen_delete
BEFORE DELETE ON governance_election_voters
WHEN EXISTS (
    SELECT 1
    FROM governance_election_register_snapshots
    WHERE id = OLD.snapshot_id
      AND (register_status = 'frozen' OR frozen_at IS NOT NULL)
)
BEGIN
    SELECT RAISE(ABORT, 'Frozen voter registers are immutable.');
END
SQL);
    }
};
