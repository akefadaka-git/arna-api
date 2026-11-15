<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TRIGGERS = ['age_receipt_outbox_immutable_update', 'age_receipt_outbox_no_delete'];
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException("AGE receipt outbox guards require MySQL or SQLite; driver [{$driver}] is unsupported.");
        }
        if (Schema::hasTable('age_receipt_outbox')) {
            throw new RuntimeException('AGE receipt outbox table already exists; reconcile schema ownership before migrating.');
        }

        Schema::create('age_receipt_outbox', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('event_uuid', 36)->unique();
            $table->char('stream_uuid', 36);
            $table->char('snapshot_uuid', 36);
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('snapshot_id');
            $table->unsignedBigInteger('sequence');
            $table->string('event_type', 64);
            $table->longText('canonical_event');
            $table->char('event_sha256', 64);
            $table->char('idempotency_key', 36)->unique();
            $table->unsignedBigInteger('expected_previous_sequence')->default(0);
            $table->char('expected_previous_receipt_sha256', 64)->nullable();
            $table->string('delivery_state', 32)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->longText('receipt_payload')->nullable();
            $table->char('receipt_sha256', 64)->nullable();
            $table->longText('witness_payload')->nullable();
            $table->timestamp('anchored_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_detail', 512)->nullable();
            $table->timestamp('quarantined_at')->nullable();
            $table->timestamps();

            $table->unique(['stream_uuid', 'sequence'], 'age_receipt_stream_sequence_unique');
            $table->index(['delivery_state', 'next_attempt_at'], 'age_receipt_delivery_scan_idx');
            $table->index(['tenant_id', 'election_id', 'snapshot_id'], 'age_receipt_scope_idx');
        });

        if ($driver === 'mysql') {
            $this->createMySqlGuards();
        } elseif ($driver === 'sqlite') {
            $this->createSqliteGuards();
        } else {
            throw new RuntimeException('AGE receipt outbox guards require MySQL or SQLite.');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('age_receipt_outbox')) {
            return;
        }

        if (DB::table('age_receipt_outbox')->where('delivery_state', 'anchored')->exists()) {
            throw new RuntimeException('Refusing to drop AGE outbox with externally anchored evidence; export and retention approval are required.');
        }

        if (DB::table('age_receipt_outbox')->exists()) {
            throw new RuntimeException('Refusing to drop non-empty AGE outbox; preserve pending and quarantined evidence before rollback.');
        }

        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::drop('age_receipt_outbox');
    }

    private function createMySqlGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_receipt_outbox_immutable_update
BEFORE UPDATE ON age_receipt_outbox
FOR EACH ROW
BEGIN
    IF NOT (NEW.event_uuid <=> OLD.event_uuid)
       OR NOT (NEW.stream_uuid <=> OLD.stream_uuid)
       OR NOT (NEW.snapshot_uuid <=> OLD.snapshot_uuid)
       OR NOT (NEW.tenant_id <=> OLD.tenant_id)
       OR NOT (NEW.election_id <=> OLD.election_id)
       OR NOT (NEW.snapshot_id <=> OLD.snapshot_id)
       OR NOT (NEW.sequence <=> OLD.sequence)
       OR NOT (NEW.event_type <=> OLD.event_type)
       OR NOT (NEW.canonical_event <=> OLD.canonical_event)
       OR NOT (NEW.event_sha256 <=> OLD.event_sha256)
       OR NOT (NEW.idempotency_key <=> OLD.idempotency_key)
       OR NOT (NEW.expected_previous_sequence <=> OLD.expected_previous_sequence)
       OR NOT (NEW.expected_previous_receipt_sha256 <=> OLD.expected_previous_receipt_sha256)
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'AGE receipt outbox event payload is immutable.';
    END IF;

    IF OLD.delivery_state = 'anchored' AND NEW.delivery_state <> 'anchored' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'AGE anchored receipt state is monotonic.';
    END IF;
    IF OLD.delivery_state = 'quarantined' AND NEW.delivery_state <> 'quarantined' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'AGE quarantined receipt state requires a separate recovery procedure.';
    END IF;
    IF NEW.delivery_state = 'anchored'
       AND (NEW.receipt_payload IS NULL OR NEW.witness_payload IS NULL
            OR NEW.receipt_sha256 IS NULL OR NEW.anchored_at IS NULL)
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'AGE receipt cannot be anchored without receipt and witness evidence.';
    END IF;
    IF NEW.delivery_state = 'quarantined' AND NEW.quarantined_at IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'AGE quarantined receipt requires a quarantine timestamp.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_receipt_outbox_no_delete
BEFORE DELETE ON age_receipt_outbox
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'AGE receipt outbox evidence is append-only.';
END
SQL);
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_receipt_outbox_immutable_update
BEFORE UPDATE ON age_receipt_outbox
WHEN NEW.event_uuid IS NOT OLD.event_uuid
  OR NEW.stream_uuid IS NOT OLD.stream_uuid
  OR NEW.snapshot_uuid IS NOT OLD.snapshot_uuid
  OR NEW.tenant_id IS NOT OLD.tenant_id
  OR NEW.election_id IS NOT OLD.election_id
  OR NEW.snapshot_id IS NOT OLD.snapshot_id
  OR NEW.sequence IS NOT OLD.sequence
  OR NEW.event_type IS NOT OLD.event_type
  OR NEW.canonical_event IS NOT OLD.canonical_event
  OR NEW.event_sha256 IS NOT OLD.event_sha256
  OR NEW.idempotency_key IS NOT OLD.idempotency_key
  OR NEW.expected_previous_sequence IS NOT OLD.expected_previous_sequence
  OR NEW.expected_previous_receipt_sha256 IS NOT OLD.expected_previous_receipt_sha256
  OR (OLD.delivery_state = 'anchored' AND NEW.delivery_state <> 'anchored')
  OR (OLD.delivery_state = 'quarantined' AND NEW.delivery_state <> 'quarantined')
  OR (NEW.delivery_state = 'anchored' AND (
      NEW.receipt_payload IS NULL OR NEW.witness_payload IS NULL
      OR NEW.receipt_sha256 IS NULL OR NEW.anchored_at IS NULL
  ))
  OR (NEW.delivery_state = 'quarantined' AND NEW.quarantined_at IS NULL)
BEGIN
    SELECT RAISE(ABORT, 'AGE receipt outbox immutable or monotonic-state guard rejected the update.');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER age_receipt_outbox_no_delete
BEFORE DELETE ON age_receipt_outbox
BEGIN
    SELECT RAISE(ABORT, 'AGE receipt outbox evidence is append-only.');
END
SQL);
    }
};
