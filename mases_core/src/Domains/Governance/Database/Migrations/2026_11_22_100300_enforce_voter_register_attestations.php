<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TRIGGERS = [
        'age_register_attestation_insert_guard',
        'age_register_attestation_update_guard',
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException("AGE register attestations are not implemented for database driver [{$driver}].");
        }

        // Attestation columns are part of the current-main AGE register foundation.
        // This migration installs database guards only; it does not alter data or schema.
        if ($driver === 'mysql') {
            $this->createMySqlTriggers();
            return;
        }
        if ($driver === 'sqlite') {
            $this->createSqliteTriggers();
            return;
        }

    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException("Cannot safely remove AGE register attestation guards for database driver [{$driver}].");
        }

        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }

    }

    private function createMySqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_register_attestation_insert_guard
BEFORE INSERT ON governance_election_register_snapshots
FOR EACH ROW
BEGIN
    IF NEW.snapshot_uuid IS NULL OR NEW.snapshot_uuid = ''
       OR NEW.prepared_attestation_sha256 IS NULL OR CHAR_LENGTH(NEW.prepared_attestation_sha256) <> 64
       OR NEW.prepared_attestation_key_id IS NULL OR NEW.prepared_attestation_key_id = ''
       OR NEW.reconciliation_attestation_sha256 IS NOT NULL
       OR NEW.reconciliation_attestation_key_id IS NOT NULL
       OR NEW.approval_attestation_sha256 IS NOT NULL
       OR NEW.approval_attestation_key_id IS NOT NULL
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'New voter-register snapshots require a prepared application attestation only.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_register_attestation_update_guard
BEFORE UPDATE ON governance_election_register_snapshots
FOR EACH ROW
BEGIN
    IF NOT (NEW.snapshot_uuid <=> OLD.snapshot_uuid)
       OR NOT (NEW.prepared_attestation_sha256 <=> OLD.prepared_attestation_sha256)
       OR NOT (NEW.prepared_attestation_key_id <=> OLD.prepared_attestation_key_id)
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Prepared voter-register attestation is immutable.';
    END IF;

    IF OLD.register_status = 'draft' AND NEW.register_status = 'reconciled' THEN
        IF OLD.reconciliation_attestation_sha256 IS NOT NULL
           OR OLD.reconciliation_attestation_key_id IS NOT NULL
           OR NEW.reconciliation_attestation_sha256 IS NULL
           OR CHAR_LENGTH(NEW.reconciliation_attestation_sha256) <> 64
           OR NEW.reconciliation_attestation_key_id IS NULL
           OR NEW.reconciliation_attestation_key_id = ''
           OR NEW.approval_attestation_sha256 IS NOT NULL
           OR NEW.approval_attestation_key_id IS NOT NULL
        THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Draft-to-reconciled transition requires one reconciliation attestation.';
        END IF;
    ELSEIF OLD.register_status = 'reconciled' AND NEW.register_status = 'frozen' THEN
        IF OLD.reconciliation_attestation_sha256 IS NULL
           OR OLD.reconciliation_attestation_key_id IS NULL
           OR NOT (NEW.reconciliation_attestation_sha256 <=> OLD.reconciliation_attestation_sha256)
           OR NOT (NEW.reconciliation_attestation_key_id <=> OLD.reconciliation_attestation_key_id)
           OR OLD.approval_attestation_sha256 IS NOT NULL
           OR OLD.approval_attestation_key_id IS NOT NULL
           OR NEW.approval_attestation_sha256 IS NULL
           OR CHAR_LENGTH(NEW.approval_attestation_sha256) <> 64
           OR NEW.approval_attestation_key_id IS NULL
           OR NEW.approval_attestation_key_id = ''
        THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Reconciled-to-frozen transition requires one approval attestation.';
        END IF;
    ELSEIF NOT (NEW.reconciliation_attestation_sha256 <=> OLD.reconciliation_attestation_sha256)
       OR NOT (NEW.reconciliation_attestation_key_id <=> OLD.reconciliation_attestation_key_id)
       OR NOT (NEW.approval_attestation_sha256 <=> OLD.approval_attestation_sha256)
       OR NOT (NEW.approval_attestation_key_id <=> OLD.approval_attestation_key_id)
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Lifecycle attestations may be added only during their matching forward transition.';
    END IF;
END
SQL);
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_register_attestation_insert_guard
BEFORE INSERT ON governance_election_register_snapshots
WHEN NEW.snapshot_uuid IS NULL OR NEW.snapshot_uuid = ''
 OR NEW.prepared_attestation_sha256 IS NULL OR length(NEW.prepared_attestation_sha256) <> 64
 OR NEW.prepared_attestation_key_id IS NULL OR NEW.prepared_attestation_key_id = ''
 OR NEW.reconciliation_attestation_sha256 IS NOT NULL
 OR NEW.reconciliation_attestation_key_id IS NOT NULL
 OR NEW.approval_attestation_sha256 IS NOT NULL
 OR NEW.approval_attestation_key_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'New voter-register snapshots require a prepared application attestation only.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_register_attestation_update_guard
BEFORE UPDATE ON governance_election_register_snapshots
WHEN NEW.snapshot_uuid IS NOT OLD.snapshot_uuid
 OR NEW.prepared_attestation_sha256 IS NOT OLD.prepared_attestation_sha256
 OR NEW.prepared_attestation_key_id IS NOT OLD.prepared_attestation_key_id
 OR (
    OLD.register_status = 'draft' AND NEW.register_status = 'reconciled'
    AND (
       OLD.reconciliation_attestation_sha256 IS NOT NULL
       OR OLD.reconciliation_attestation_key_id IS NOT NULL
       OR NEW.reconciliation_attestation_sha256 IS NULL
       OR length(NEW.reconciliation_attestation_sha256) <> 64
       OR NEW.reconciliation_attestation_key_id IS NULL
       OR NEW.reconciliation_attestation_key_id = ''
       OR NEW.approval_attestation_sha256 IS NOT NULL
       OR NEW.approval_attestation_key_id IS NOT NULL
    )
 )
 OR (
    OLD.register_status = 'reconciled' AND NEW.register_status = 'frozen'
    AND (
       OLD.reconciliation_attestation_sha256 IS NULL
       OR OLD.reconciliation_attestation_key_id IS NULL
       OR NEW.reconciliation_attestation_sha256 IS NOT OLD.reconciliation_attestation_sha256
       OR NEW.reconciliation_attestation_key_id IS NOT OLD.reconciliation_attestation_key_id
       OR OLD.approval_attestation_sha256 IS NOT NULL
       OR OLD.approval_attestation_key_id IS NOT NULL
       OR NEW.approval_attestation_sha256 IS NULL
       OR length(NEW.approval_attestation_sha256) <> 64
       OR NEW.approval_attestation_key_id IS NULL
       OR NEW.approval_attestation_key_id = ''
    )
 )
 OR (
    NOT (OLD.register_status = 'draft' AND NEW.register_status = 'reconciled')
    AND NOT (OLD.register_status = 'reconciled' AND NEW.register_status = 'frozen')
    AND (
       NEW.reconciliation_attestation_sha256 IS NOT OLD.reconciliation_attestation_sha256
       OR NEW.reconciliation_attestation_key_id IS NOT OLD.reconciliation_attestation_key_id
       OR NEW.approval_attestation_sha256 IS NOT OLD.approval_attestation_sha256
       OR NEW.approval_attestation_key_id IS NOT OLD.approval_attestation_key_id
    )
 )
BEGIN
    SELECT RAISE(ABORT, 'AGE register attestation fields are immutable outside their matching forward transition.');
END
SQL);
    }
};
