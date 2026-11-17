<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::unprepared("CREATE TRIGGER governance_election_dispute_events_no_update BEFORE UPDATE ON governance_election_dispute_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Election dispute events are append-only'");
            DB::unprepared("CREATE TRIGGER governance_election_dispute_events_no_delete BEFORE DELETE ON governance_election_dispute_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Election dispute events are append-only'");

            return;
        }

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION arna_reject_election_dispute_event_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'Election dispute events are append-only';
    RETURN OLD;
END;
$$
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER governance_election_dispute_events_no_mutation
BEFORE UPDATE OR DELETE ON governance_election_dispute_events
FOR EACH ROW EXECUTE FUNCTION arna_reject_election_dispute_event_mutation()
SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER governance_election_dispute_events_no_update BEFORE UPDATE ON governance_election_dispute_events BEGIN SELECT RAISE(ABORT, 'Election dispute events are append-only'); END");
            DB::unprepared("CREATE TRIGGER governance_election_dispute_events_no_delete BEFORE DELETE ON governance_election_dispute_events BEGIN SELECT RAISE(ABORT, 'Election dispute events are append-only'); END");

            return;
        }

        throw new RuntimeException("Election dispute event immutability is not implemented for database driver [{$driver}].");
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS governance_election_dispute_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS governance_election_dispute_events_no_delete');

            return;
        }

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS governance_election_dispute_events_no_mutation ON governance_election_dispute_events');
            DB::unprepared('DROP FUNCTION IF EXISTS arna_reject_election_dispute_event_mutation()');

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS governance_election_dispute_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS governance_election_dispute_events_no_delete');
        }
    }
};
