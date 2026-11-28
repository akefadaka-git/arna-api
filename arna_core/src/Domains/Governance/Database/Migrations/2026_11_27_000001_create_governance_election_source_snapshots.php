<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_election_source_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('election_id');
            $table->unsignedInteger('version');
            $table->string('source_schema_version', 16);
            $table->dateTime('record_date_time_utc', 6);
            $table->string('record_date_timezone', 8);
            $table->string('database_timezone', 64);
            $table->dateTime('captured_at_utc', 6);
            $table->char('source_digest', 64);
            $table->char('manifest_digest', 64);
            $table->unsignedBigInteger('shareholder_count');
            $table->unsignedBigInteger('holding_count');
            $table->unsignedBigInteger('ledger_entry_count');
            $table->string('snapshot_state', 24)->default('captured');
            $table->string('electorate_state', 32)->default('blocked_unverified');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('prepared_at_utc', 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'ge_src_snap_tenant_id_uq');
            $table->unique(['tenant_id', 'election_id', 'version'], 'ge_src_snap_version_uq');
            $table->foreign(['tenant_id', 'election_id'], 'ge_src_snap_election_fk')
                ->references(['tenant_id', 'id'])->on('governance_elections')->restrictOnDelete();
            $table->index(['tenant_id', 'election_id', 'created_at'], 'ge_src_snap_list_idx');
        });

        Schema::create('governance_election_source_snapshot_holders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('snapshot_id');
            $table->unsignedBigInteger('shareholder_id');
            $table->string('shareholder_number', 64)->nullable();
            $table->string('shareholder_type', 32);
            $table->unsignedBigInteger('person_id')->nullable();
            $table->unsignedBigInteger('direct_user_id')->nullable();
            $table->unsignedBigInteger('representative_user_id')->nullable();
            $table->string('current_status', 32);
            $table->string('identity_temporal_state', 48);
            $table->char('holder_digest', 64);
            $table->unsignedInteger('holding_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'snapshot_id', 'shareholder_id'], 'ge_src_holders_uq');
            $table->foreign(['tenant_id', 'election_id'], 'ge_src_holders_election_fk')
                ->references(['tenant_id', 'id'])->on('governance_elections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'snapshot_id'], 'ge_src_holders_snapshot_fk')
                ->references(['tenant_id', 'id'])->on('governance_election_source_snapshots')->restrictOnDelete();
            $table->foreign('shareholder_id', 'ge_src_holders_shareholder_fk')
                ->references('id')->on('shareholders')->restrictOnDelete();
            $table->foreign('person_id', 'ge_src_holders_person_fk')
                ->references('id')->on('people')->restrictOnDelete();
            $table->foreign('direct_user_id', 'ge_src_holders_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('representative_user_id', 'ge_src_holders_rep_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'snapshot_id'], 'ge_src_holders_snapshot_idx');
        });

        Schema::create('governance_election_source_snapshot_holdings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('snapshot_id');
            $table->unsignedBigInteger('shareholder_id');
            $table->unsignedBigInteger('source_holding_id');
            $table->unsignedBigInteger('share_class_id');
            $table->string('share_class_code', 64);
            $table->decimal('quantity_at_cutoff', 20, 4);
            $table->decimal('current_projection', 20, 4);
            $table->unsignedBigInteger('ledger_entry_count');
            $table->char('ledger_digest', 64);
            $table->string('source_state', 32);
            $table->timestamps();

            $table->unique(['tenant_id', 'snapshot_id', 'source_holding_id'], 'ge_src_holdings_uq');
            $table->foreign(['tenant_id', 'election_id'], 'ge_src_holdings_election_fk')
                ->references(['tenant_id', 'id'])->on('governance_elections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'snapshot_id'], 'ge_src_holdings_snapshot_fk')
                ->references(['tenant_id', 'id'])->on('governance_election_source_snapshots')->restrictOnDelete();
            $table->foreign('shareholder_id', 'ge_src_holdings_shareholder_fk')
                ->references('id')->on('shareholders')->restrictOnDelete();
            $table->foreign('source_holding_id', 'ge_src_holdings_source_fk')
                ->references('id')->on('share_holdings')->restrictOnDelete();
            $table->foreign('share_class_id', 'ge_src_holdings_class_fk')
                ->references('id')->on('share_classes')->restrictOnDelete();
            $table->index(['tenant_id', 'snapshot_id', 'shareholder_id'], 'ge_src_holdings_holder_idx');
        });

        Schema::create('governance_election_source_snapshot_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('snapshot_id');
            $table->unsignedBigInteger('source_holding_id');
            $table->unsignedBigInteger('source_entry_id');
            $table->char('entry_digest', 64);
            $table->boolean('included_by_business_time');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'snapshot_id', 'source_entry_id'], 'ge_src_entries_uq');
            $table->foreign(['tenant_id', 'election_id'], 'ge_src_entries_election_fk')
                ->references(['tenant_id', 'id'])->on('governance_elections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'snapshot_id'], 'ge_src_entries_snapshot_fk')
                ->references(['tenant_id', 'id'])->on('governance_election_source_snapshots')->restrictOnDelete();
            $table->foreign('source_holding_id', 'ge_src_entries_holding_fk')
                ->references('id')->on('share_holdings')->restrictOnDelete();
            $table->foreign('source_entry_id', 'ge_src_entries_source_fk')
                ->references('id')->on('share_holding_entries')->restrictOnDelete();
            $table->index(['tenant_id', 'snapshot_id', 'source_holding_id'], 'ge_src_entries_holding_idx');
        });

        Schema::create('governance_election_source_snapshot_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('snapshot_id');
            $table->char('observed_source_digest', 64);
            $table->string('result', 16);
            $table->string('reason', 2000);
            $table->unsignedBigInteger('reconciled_by');
            $table->dateTime('reconciled_at_utc', 6);

            $table->foreign('reconciled_by', 'ge_src_recon_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'election_id'], 'ge_src_recon_election_fk')
                ->references(['tenant_id', 'id'])->on('governance_elections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'snapshot_id'], 'ge_src_recon_snapshot_fk')
                ->references(['tenant_id', 'id'])->on('governance_election_source_snapshots')->restrictOnDelete();
            $table->index(['tenant_id', 'snapshot_id', 'created_at'], 'ge_src_recon_list_idx');
        });

        $this->installImmutabilityTriggers();
    }


    private function installImmutabilityTriggers(): void
    {
        $tables = [
            'governance_election_source_snapshots' => 'ge_src_snap',
            'governance_election_source_snapshot_holders' => 'ge_src_hold',
            'governance_election_source_snapshot_holdings' => 'ge_src_holding',
            'governance_election_source_snapshot_entries' => 'ge_src_entry',
            'governance_election_source_snapshot_reconciliations' => 'ge_src_recon',
        ];

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            foreach ($tables as $table => $prefix) {
                DB::unprepared("CREATE TRIGGER {$prefix}_bu BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Election source snapshot records are append-only'");
                DB::unprepared("CREATE TRIGGER {$prefix}_bd BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Election source snapshot records are append-only'");
            }
            return;
        }

        if ($driver === 'sqlite') {
            foreach ($tables as $table => $prefix) {
                DB::unprepared("CREATE TRIGGER {$prefix}_bu BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, 'Election source snapshot records are append-only'); END");
                DB::unprepared("CREATE TRIGGER {$prefix}_bd BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Election source snapshot records are append-only'); END");
            }
            return;
        }

        if ($driver === 'pgsql') {
            DB::unprepared('CREATE OR REPLACE FUNCTION ge_reject_source_snapshot_mutation() RETURNS trigger AS $function$ BEGIN RAISE EXCEPTION \'Election source snapshot records are append-only\'; END; $function$ LANGUAGE plpgsql');
            foreach ($tables as $table => $prefix) {
                DB::unprepared("CREATE TRIGGER {$prefix}_mutate_guard BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ge_reject_source_snapshot_mutation()");
            }
            return;
        }

        throw new RuntimeException("Unsupported database driver for election source snapshot immutability: {$driver}");
    }

    private function dropImmutabilityTriggers(): void
    {
        $tables = [
            'governance_election_source_snapshots' => 'ge_src_snap',
            'governance_election_source_snapshot_holders' => 'ge_src_hold',
            'governance_election_source_snapshot_holdings' => 'ge_src_holding',
            'governance_election_source_snapshot_entries' => 'ge_src_entry',
            'governance_election_source_snapshot_reconciliations' => 'ge_src_recon',
        ];

        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'sqlite'], true)) {
            foreach ($tables as $prefix) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$prefix}_bu");
                DB::unprepared("DROP TRIGGER IF EXISTS {$prefix}_bd");
                DB::unprepared("DROP TRIGGER IF EXISTS {$prefix}_mutate_guard");
            }
            return;
        }

        if ($driver === 'pgsql') {
            foreach ($tables as $table => $prefix) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$prefix}_mutate_guard ON {$table}");
            }
            DB::unprepared('DROP FUNCTION IF EXISTS ge_reject_source_snapshot_mutation()');
        }
    }

    public function down(): void
    {
        $this->dropImmutabilityTriggers();
        Schema::dropIfExists('governance_election_source_snapshot_reconciliations');
        Schema::dropIfExists('governance_election_source_snapshot_entries');
        Schema::dropIfExists('governance_election_source_snapshot_holdings');
        Schema::dropIfExists('governance_election_source_snapshot_holders');
        Schema::dropIfExists('governance_election_source_snapshots');
    }
};
