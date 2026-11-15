<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Opt-in MySQL integration coverage for AGE voter-register database guards.
 *
 * Run only against a disposable database whose name contains "age_test",
 * with AGE_MYSQL_INTEGRATION=1 and the normal Laravel DB_* variables set.
 * This suite creates and drops the three AGE fixture tables.
 */
final class AgeVoterRegisterMySqlIntegrationTest extends TestCase
{
    private string $migrationPath = '';
    private bool $fixturesCreated = false;
    private bool $migrationApplied = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('AGE MySQL integration tests require DB_CONNECTION=mysql.');
        }

        if (env('AGE_MYSQL_INTEGRATION') !== '1') {
            self::markTestSkipped('Set AGE_MYSQL_INTEGRATION=1 to enable destructive disposable-database tests.');
        }

        $database = (string) DB::connection()->getDatabaseName();
        if (!preg_match('/age[_-]?test/i', $database)) {
            throw new RuntimeException('Refusing AGE MySQL integration tests: database name must contain age_test.');
        }

        $this->migrationPath = base_path(
            'mases_core/src/Domains/Governance/Database/Migrations/2026_11_15_130000_enforce_frozen_voter_register_immutability.php'
        );

        $this->dropFixtureTables();
        $this->createFixtureTables();
        $this->fixturesCreated = true;

        $migration = require $this->migrationPath;
        $migration->up();
        $this->migrationApplied = true;
    }

    public function test_mysql_triggers_reject_invalid_freeze_transition(): void
    {
        $snapshotId = $this->insertSnapshot();

        try {
            DB::table('governance_election_register_snapshots')
                ->where('id', $snapshotId)
                ->update([
                    'register_status' => 'frozen',
                    'frozen_at' => now(),
                ]);
            self::fail('MySQL must reject a draft-to-frozen transition without reconciliation and independent approval.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('cannot freeze', strtolower($exception->getMessage()));
        }

        self::assertSame(
            'draft',
            DB::table('governance_election_register_snapshots')->where('id', $snapshotId)->value('register_status')
        );
    }

    public function test_mysql_rejects_freeze_timestamp_without_frozen_status(): void
    {
        $snapshotId = $this->insertSnapshot();

        try {
            DB::table('governance_election_register_snapshots')
                ->where('id', $snapshotId)
                ->update(['frozen_at' => now()]);
            self::fail('MySQL must reject a freeze timestamp unless the register enters the validated frozen state.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('cannot freeze or set frozen_at', strtolower($exception->getMessage()));
        }

        $snapshot = DB::table('governance_election_register_snapshots')->where('id', $snapshotId)->first();
        self::assertSame('draft', $snapshot->register_status);
        self::assertNull($snapshot->frozen_at);
    }

    public function test_mysql_rejects_snapshot_inserts_that_bypass_initial_draft_state(): void
    {
        $base = [
            'register_status' => 'frozen',
            'frozen_at' => now(),
            'reconciled_by' => 101,
            'reconciled_at' => now(),
            'approved_by' => 102,
            'approved_at' => now(),
            'integrity_verified_at' => now(),
        ];

        try {
            $this->insertSnapshot($base);
            self::fail('MySQL must reject a snapshot inserted directly in the frozen state.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('initial prepared draft state', strtolower($exception->getMessage()));
        }

        try {
            $this->insertSnapshot([
                'register_status' => 'draft',
                'frozen_at' => now(),
            ]);
            self::fail('MySQL must reject a draft snapshot inserted with a freeze timestamp.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('initial prepared draft state', strtolower($exception->getMessage()));
        }

        self::assertSame(0, DB::table('governance_election_register_snapshots')->count());
    }

    public function test_mysql_triggers_reject_missing_cross_tenant_and_frozen_register_writes(): void
    {
        $snapshotId = $this->insertSnapshot();

        $this->assertWriteRejected(function () use ($snapshotId): void {
            $this->insertVoter($snapshotId, 8, 10, 'CROSS-TENANT');
        });

        $this->assertWriteRejected(function () use ($snapshotId): void {
            $this->insertVoter(999999, 7, 10, 'MISSING-SNAPSHOT');
        });

        $this->assertWriteRejected(function () use ($snapshotId): void {
            DB::table('governance_election_voters')->insert([
                'tenant_id' => 7,
                'election_id' => 11,
                'snapshot_id' => $snapshotId,
                'shareholder_id' => 21,
                'share_class_code' => 'ORDINARY',
                'share_quantity' => '1.0000',
                'voting_weight' => '1.0000',
                'entitlement_reference' => 'WRONG-ELECTION',
                'proxy_authorized' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $voterId = $this->insertVoter($snapshotId, 7, 10, 'VALID-ROW');

        // Updating scope columns while retaining snapshot_id must still validate the NEW scope.
        $this->assertWriteRejected(function () use ($voterId): void {
            DB::table('governance_election_voters')->where('id', $voterId)->update(['tenant_id' => 8]);
        });
        $this->assertWriteRejected(function () use ($voterId): void {
            DB::table('governance_election_voters')->where('id', $voterId)->update(['election_id' => 11]);
        });
        self::assertSame(7, (int) DB::table('governance_election_voters')->where('id', $voterId)->value('tenant_id'));
        self::assertSame(10, (int) DB::table('governance_election_voters')->where('id', $voterId)->value('election_id'));

        DB::table('governance_election_register_snapshots')->where('id', $snapshotId)->update([
            'register_status' => 'reconciled',
            'prepared_by' => 100,
            'reconciled_by' => 101,
            'reconciled_at' => now(),
            'approved_by' => 102,
            'approved_at' => now(),
            'integrity_verified_at' => now(),
        ]);

        // Freeze timestamp and status change together; a non-null frozen_at is itself immutable.
        DB::table('governance_election_register_snapshots')->where('id', $snapshotId)
            ->update(['register_status' => 'frozen', 'frozen_at' => now()]);

        $this->assertWriteRejected(function () use ($snapshotId): void {
            $this->insertVoter($snapshotId, 7, 10, 'AFTER-FREEZE');
        });

        $this->assertWriteRejected(function () use ($voterId): void {
            DB::table('governance_election_voters')->where('id', $voterId)
                ->update(['share_quantity' => '99.0000']);
        });

        $this->assertWriteRejected(function () use ($voterId): void {
            DB::table('governance_election_voters')->where('id', $voterId)->delete();
        });

        $this->assertWriteRejected(function () use ($snapshotId): void {
            DB::table('governance_election_register_snapshots')->where('id', $snapshotId)
                ->update(['source_reference' => 'ALTERED']);
        });

        self::assertSame(1, DB::table('governance_election_voters')->where('snapshot_id', $snapshotId)->count());
    }

    public function test_mysql_concurrent_voter_insert_waits_for_freeze_and_is_rejected(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid') || !function_exists('pcntl_exec')) {
            self::markTestSkipped('The MySQL race test requires pcntl fork, exec, and waitpid support.');
        }

        $snapshotId = $this->insertSnapshot();
        DB::beginTransaction();
        DB::table('governance_election_register_snapshots')
            ->where('id', $snapshotId)
            ->lockForUpdate()
            ->first();

        $startedFile = tempnam(sys_get_temp_dir(), 'age-mysql-start-');
        $resultFile = tempnam(sys_get_temp_dir(), 'age-mysql-result-');
        $workerFile = tempnam(sys_get_temp_dir(), 'age-mysql-worker-');
        if ($startedFile === false || $resultFile === false || $workerFile === false) {
            DB::rollBack();
            self::fail('Unable to create synchronization files for the MySQL race test.');
        }

        $worker = <<<'PHP'
<?php
declare(strict_types=1);
[$script, $snapshotId, $startedFile, $resultFile] = $argv;
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_PORT') ?: '3306',
        getenv('DB_DATABASE') ?: ''
    );
    $pdo = new PDO($dsn, getenv('DB_USERNAME') ?: '', getenv('DB_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    file_put_contents($startedFile, 'attempting');
    $statement = $pdo->prepare(
        'INSERT INTO governance_election_voters
        (tenant_id, election_id, snapshot_id, shareholder_id, share_class_code,
         share_quantity, voting_weight, entitlement_reference, proxy_authorized, created_at, updated_at)
        VALUES (7, 10, ?, 21, "ORDINARY", 1.0000, 1.0000, "CONCURRENT-INSERT", 0, NOW(), NOW())'
    );
    $statement->execute([(int) $snapshotId]);
    file_put_contents($resultFile, 'inserted');
} catch (Throwable $exception) {
    file_put_contents($resultFile, 'rejected:' . $exception->getMessage());
}
PHP;
        file_put_contents($workerFile, $worker);

        $pid = pcntl_fork();
        if ($pid === -1) {
            DB::rollBack();
            @unlink($startedFile);
            @unlink($resultFile);
            @unlink($workerFile);
            self::fail('Unable to fork the MySQL race-test worker.');
        }

        if ($pid === 0) {
            // Replace the forked process image so it cannot run PHP/PDO destructors
            // against the parent's inherited Laravel connection.
            pcntl_exec(PHP_BINARY, [$workerFile, (string) $snapshotId, $startedFile, $resultFile]);
            file_put_contents($resultFile, 'rejected: unable to exec isolated MySQL worker');
            exit(127);
        }

        $childReaped = false;
        try {
            $deadline = microtime(true) + 10;
            while (!file_exists($startedFile) && microtime(true) < $deadline) {
                usleep(50000);
            }
            self::assertFileExists($startedFile, 'The isolated child process did not reach its database write.');

            // The child has its own PDO connection and must block on the parent's row lock.
            usleep(300000);
            $childStatus = 0;
            $childState = pcntl_waitpid($pid, $childStatus, WNOHANG);
            self::assertSame(0, $childState, 'The voter insert did not wait for the parent snapshot lock.');

            DB::table('governance_election_register_snapshots')->where('id', $snapshotId)->update([
                'register_status' => 'reconciled',
                'prepared_by' => 100,
                'reconciled_by' => 101,
                'reconciled_at' => now(),
                'approved_by' => 102,
                'approved_at' => now(),
                'integrity_verified_at' => now(),
            ]);
            DB::table('governance_election_register_snapshots')->where('id', $snapshotId)->update([
                'register_status' => 'frozen',
                'frozen_at' => now(),
            ]);
            DB::commit();

            pcntl_waitpid($pid, $childStatus);
            $childReaped = true;
            self::assertFileExists($resultFile);
            $result = (string) file_get_contents($resultFile);
            self::assertStringStartsWith('rejected:', $result, 'A concurrent voter insert must observe the committed frozen state and be rejected.');
            self::assertSame(0, DB::table('governance_election_voters')->where('snapshot_id', $snapshotId)->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if (!$childReaped) {
                $childStatus = 0;
                pcntl_waitpid($pid, $childStatus);
            }
            @unlink($startedFile);
            @unlink($resultFile);
            @unlink($workerFile);
        }
    }

    public function test_mysql_trigger_migration_can_be_rolled_back_and_reapplied(): void
    {
        $migration = require $this->migrationPath;
        $migration->down();

        $remaining = DB::table('information_schema.triggers')
            ->where('trigger_schema', DB::connection()->getDatabaseName())
            ->whereIn('trigger_name', [
                'age_register_snapshot_initial_state_guard',
                'age_voter_register_frozen_insert',
                'age_voter_register_frozen_update',
                'age_voter_register_frozen_delete',
                'age_register_snapshot_frozen_update',
                'age_register_snapshot_freeze_transition_guard',
                'age_register_snapshot_frozen_delete',
            ])
            ->count();

        self::assertSame(0, $remaining);

        $migration->up();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql'
            && env('AGE_MYSQL_INTEGRATION') === '1'
            && preg_match('/age[_-]?test/i', (string) DB::connection()->getDatabaseName())) {
            try {
                if ($this->migrationApplied && $this->migrationPath !== '') {
                    $migration = require $this->migrationPath;
                    $migration->down();
                }
            } finally {
                if ($this->fixturesCreated) {
                    $this->dropFixtureTables();
                }
            }
        }

        parent::tearDown();
    }

    private function createFixtureTables(): void
    {
        Schema::create('governance_election_register_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->date('record_date');
            $table->string('source_reference');
            $table->string('rule_version');
            $table->string('content_sha256', 64);
            $table->unsignedInteger('voter_count');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('prepared_by')->nullable();
            $table->unsignedBigInteger('reconciled_by')->nullable();
            $table->dateTime('reconciled_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('integrity_verified_at')->nullable();
            $table->string('register_status', 24)->default('draft');
            $table->dateTime('frozen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('governance_election_voters', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('snapshot_id');
            $table->unsignedBigInteger('shareholder_id');
            $table->string('share_class_code');
            $table->decimal('share_quantity', 20, 4);
            $table->decimal('voting_weight', 20, 4);
            $table->text('entitlement_reference');
            $table->boolean('proxy_authorized')->default(false);
            $table->timestamps();
            $table->index(['snapshot_id', 'tenant_id', 'election_id'], 'age_voters_snapshot_scope_idx');
        });
    }

    private function dropFixtureTables(): void
    {
        Schema::dropIfExists('governance_election_voters');
        Schema::dropIfExists('governance_election_register_snapshots');
    }

    private function insertSnapshot(array $overrides = []): int
    {
        return (int) DB::table('governance_election_register_snapshots')->insertGetId(array_replace([
            'tenant_id' => 7,
            'election_id' => 10,
            'record_date' => '2026-01-15',
            'source_reference' => 'MYSQL-AGE-TEST',
            'rule_version' => 'AGE-TEST-1',
            'content_sha256' => str_repeat('a', 64),
            'voter_count' => 1,
            'created_by' => 100,
            'prepared_by' => 100,
            'register_status' => 'draft',
            'frozen_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertVoter(int $snapshotId, int $tenantId, int $electionId, string $reference): int
    {
        return (int) DB::table('governance_election_voters')->insertGetId([
            'tenant_id' => $tenantId,
            'election_id' => $electionId,
            'snapshot_id' => $snapshotId,
            'shareholder_id' => 21,
            'share_class_code' => 'ORDINARY',
            'share_quantity' => '1.0000',
            'voting_weight' => '1.0000',
            'entitlement_reference' => $reference,
            'proxy_authorized' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertWriteRejected(callable $write): void
    {
        try {
            $write();
            self::fail('MySQL AGE trigger should have rejected this write.');
        } catch (QueryException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }
}
