<?php

declare(strict_types=1);

/**
 * Bounded, multi-process MySQL concurrency verification for Finance Phase 1.
 *
 * Run only against a disposable, freshly migrated MySQL 8 database:
 *   php mases_core/src/Domains/Finance/Tests/Support/mysql-concurrency-harness.php
 *
 * Each worker has its own Laravel process and PDO connection. The coordinator
 * holds the contested InnoDB row, starts workers in a controlled order, waits
 * until MySQL reports each worker blocked on that row, then releases the lock.
 * This deliberately avoids PHPUnit's outer test transaction and arbitrary
 * timing sleeps.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Services\AccountingPeriodService;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalEntryService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

$basePath = dirname(__DIR__, 6);
require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (($argv[1] ?? '') === '--worker') {
    financeWorker($argv[2] ?? '');
    exit(0);
}

if (DB::connection()->getDriverName() !== 'mysql') {
    fwrite(STDERR, "FAIL: the concurrency harness requires MySQL.\n");
    exit(2);
}

$mysql = DB::selectOne('SELECT VERSION() AS version, @@transaction_isolation AS isolation_level');
if (!str_starts_with((string) $mysql->version, '8.')) {
    fwrite(STDERR, "FAIL: expected MySQL 8.x; got {$mysql->version}.\n");
    exit(2);
}

$schema = DB::selectOne("SELECT COUNT(*) AS available FROM information_schema.tables WHERE table_schema = 'performance_schema' AND table_name = 'data_lock_waits'");
if ((int) $schema->available !== 1) {
    fwrite(STDERR, "FAIL: performance_schema.data_lock_waits is unavailable; lock-wait evidence is required.\n");
    exit(2);
}

$repeatCount = 10;
$permutations = [
    ['first' => 'left', 'second' => 'right'],
    ['first' => 'right', 'second' => 'left'],
];
$scenarios = ['duplicate_idempotency', 'period_close_vs_post', 'direct_line_insert_vs_post'];
$completed = 0;

printf(
    "Finance MySQL concurrency verification\nMySQL: %s\nIsolation: %s\nProtocol: %d repeats x %d arrival orders x %d races = %d controlled races\n",
    $mysql->version,
    $mysql->isolation_level,
    $repeatCount,
    count($permutations),
    count($scenarios),
    $repeatCount * count($permutations) * count($scenarios),
);

for ($repeat = 1; $repeat <= $repeatCount; $repeat++) {
    foreach ($permutations as $permutationIndex => $order) {
        foreach ($scenarios as $scenario) {
            runRace($scenario, $repeat, $permutationIndex + 1, $order);
            $completed++;
            printf("PASS %02d/60 repeat=%02d order=%d race=%s\n", $completed, $repeat, $permutationIndex + 1, $scenario);
        }
    }
}

printf("PASS: %d/%d controlled MySQL races; no unexpected state, SQL error, timeout, or deadlock.\n", $completed, 60);

function financeWorker(string $encodedPayload): void
{
    $raw = base64_decode($encodedPayload, true);
    $payload = $raw === false ? null : json_decode($raw, true);

    if (!is_array($payload)) {
        fwrite(STDERR, "Invalid worker payload.\n");
        exit(2);
    }

    $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    echo "READY:{$connectionId}\n";
    fflush(STDOUT);

    $signal = fgets(STDIN);
    if (trim((string) $signal) !== 'GO') {
        fwrite(STDERR, "Worker did not receive GO barrier signal.\n");
        exit(2);
    }

    try {
        $result = match ($payload['operation'] ?? '') {
            'create_draft' => workerCreateDraft($payload),
            'post' => workerPost($payload),
            'close_period' => workerClosePeriod($payload),
            'insert_balanced_lines' => workerInsertBalancedLines($payload),
            default => throw new RuntimeException('Unsupported concurrency worker operation.'),
        };
        echo 'RESULT:'.json_encode(['ok' => true, 'operation' => $payload['operation'], ...$result], JSON_THROW_ON_ERROR)."\n";
        fflush(STDOUT);
    } catch (Throwable $exception) {
        $sqlState = $exception instanceof QueryException
            ? (string) ($exception->errorInfo[0] ?? '')
            : '';
        $errors = $exception instanceof ValidationException ? $exception->errors() : [];
        echo 'RESULT:'.json_encode([
            'ok' => false,
            'operation' => $payload['operation'],
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'sql_state' => $sqlState,
            'errors' => $errors,
        ], JSON_THROW_ON_ERROR)."\n";
        fflush(STDOUT);
    }
}

function workerCreateDraft(array $payload): array
{
    $journal = app(JournalEntryService::class)->createDraft(
        (int) $payload['tenant_id'],
        (int) $payload['actor_id'],
        $payload['header'],
        $payload['lines'],
    );

    return ['journal_id' => (int) $journal->id];
}

function workerPost(array $payload): array
{
    $journal = app(JournalEntryService::class)->post(
        (int) $payload['tenant_id'],
        (int) $payload['actor_id'],
        (int) $payload['journal_id'],
    );

    return ['journal_id' => (int) $journal->id, 'status' => $journal->status];
}

function workerClosePeriod(array $payload): array
{
    $period = app(AccountingPeriodService::class)->close(
        (int) $payload['tenant_id'],
        (int) $payload['actor_id'],
        (int) $payload['period_id'],
        'Controlled FIN-1.03 concurrency verification',
    );

    return ['period_id' => (int) $period->id, 'status' => $period->status];
}

function workerInsertBalancedLines(array $payload): array
{
    DB::transaction(function () use ($payload): void {
        $now = now()->toDateTimeString();
        DB::table('journal_lines')->insert([
            [
                'tenant_id' => $payload['tenant_id'],
                'legal_entity_id' => $payload['legal_entity_id'],
                'journal_entry_id' => $payload['journal_id'],
                'account_id' => $payload['debit_account_id'],
                'line_number' => 3,
                'description' => 'Concurrent direct-write debit probe',
                'debit_amount' => '5.0000',
                'credit_amount' => '0.0000',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'tenant_id' => $payload['tenant_id'],
                'legal_entity_id' => $payload['legal_entity_id'],
                'journal_entry_id' => $payload['journal_id'],
                'account_id' => $payload['credit_account_id'],
                'line_number' => 4,
                'description' => 'Concurrent direct-write credit probe',
                'debit_amount' => '0.0000',
                'credit_amount' => '5.0000',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    });

    return ['journal_id' => (int) $payload['journal_id'], 'inserted_lines' => 2];
}

/** @param array{first:string,second:string} $order */
function runRace(string $scenario, int $repeat, int $permutation, array $order): void
{
    [$tenant, $user, $entity, $period, $debit, $credit] = concurrencyFixture($repeat, $permutation, $scenario);
    $journal = null;

    if ($scenario !== 'duplicate_idempotency') {
        $journal = createConcurrencyDraft($tenant, $user, $entity, $debit, $credit, $repeat, $permutation, $scenario);
    }

    $lockTable = match ($scenario) {
        'duplicate_idempotency' => 'legal_entities',
        'period_close_vs_post' => 'accounting_periods',
        'direct_line_insert_vs_post' => 'journal_entries',
    };
    $lockId = match ($scenario) {
        'duplicate_idempotency' => (int) $entity->id,
        'period_close_vs_post' => (int) $period->id,
        'direct_line_insert_vs_post' => (int) $journal->id,
    };

    $leftPayload = workerPayload('left', $scenario, $tenant, $user, $entity, $period, $debit, $credit, $journal, $repeat, $permutation);
    $rightPayload = workerPayload('right', $scenario, $tenant, $user, $entity, $period, $debit, $credit, $journal, $repeat, $permutation);
    $payloads = ['left' => $leftPayload, 'right' => $rightPayload];
    $workers = [];
    $connectionIds = [];

    DB::beginTransaction();
    try {
        DB::table($lockTable)->where('id', $lockId)->lockForUpdate()->first();

        foreach ([$order['first'], $order['second']] as $side) {
            $workers[$side] = startWorker($payloads[$side]);
            $connectionIds[$side] = $workers[$side]['connection_id'];
            if ($connectionIds[$side] === (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id) {
                throw new RuntimeException('Concurrency worker reused the coordinator DB connection.');
            }
            signalWorker($workers[$side]);
            waitForLockWait($connectionIds[$side], $scenario, $repeat, $permutation);
        }

        DB::commit();

        $results = [
            'left' => finishWorker($workers['left']),
            'right' => finishWorker($workers['right']),
        ];
        assertRaceOutcome($scenario, $results, $tenant, $user, $entity, $period, $debit, $credit, $journal);
    } catch (Throwable $exception) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($workers as $worker) {
            stopWorker($worker);
        }
        throw $exception;
    }
}

function concurrencyFixture(int $repeat, int $permutation, string $scenario): array
{
    $tenant = Tenant::factory()->create(['is_active' => true]);
    $user = User::factory()->create();
    $suffix = strtoupper(substr(hash('sha256', "{$repeat}:{$permutation}:{$scenario}"), 0, 10));

    $entity = LegalEntity::query()->create([
        'tenant_id' => $tenant->id,
        'code' => 'RACE-'.$suffix,
        'name_en' => 'Finance concurrency verification',
        'base_currency' => 'ETB',
        'status' => 'active',
    ]);
    $period = AccountingPeriod::query()->create([
        'tenant_id' => $tenant->id,
        'legal_entity_id' => $entity->id,
        'code' => 'RACE-'.$suffix,
        'starts_on' => '2026-10-01',
        'ends_on' => '2026-10-31',
        'status' => AccountingPeriod::OPEN,
    ]);
    $debit = LedgerAccount::query()->create([
        'tenant_id' => $tenant->id,
        'legal_entity_id' => $entity->id,
        'code' => 'RACE-D-'.$suffix,
        'name_en' => 'Concurrency debit account',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'is_posting_allowed' => true,
        'status' => 'active',
    ]);
    $credit = LedgerAccount::query()->create([
        'tenant_id' => $tenant->id,
        'legal_entity_id' => $entity->id,
        'code' => 'RACE-C-'.$suffix,
        'name_en' => 'Concurrency credit account',
        'account_type' => 'equity',
        'normal_balance' => 'credit',
        'is_posting_allowed' => true,
        'status' => 'active',
    ]);

    return [$tenant, $user, $entity, $period, $debit, $credit];
}

function createConcurrencyDraft($tenant, $user, $entity, $debit, $credit, int $repeat, int $permutation, string $scenario): JournalEntry
{
    $suffix = strtoupper(substr(hash('sha256', "{$repeat}:{$permutation}:{$scenario}"), 0, 10));

    return app(JournalEntryService::class)->createDraft(
        (int) $tenant->id,
        (int) $user->id,
        [
            'legal_entity_id' => $entity->id,
            'journal_number' => 'RACE-JV-'.$suffix,
            'effective_date' => '2026-10-10',
            'description' => 'FIN-1.03 controlled concurrency fixture',
            'currency' => 'ETB',
            'source_type' => JournalSourceType::MANUAL->value,
            'idempotency_key' => 'race-key-'.$suffix,
        ],
        [
            ['account_id' => $debit->id, 'debit_amount' => '25.0000', 'credit_amount' => '0'],
            ['account_id' => $credit->id, 'debit_amount' => '0', 'credit_amount' => '25.0000'],
        ],
    );
}

function workerPayload(string $side, string $scenario, $tenant, $user, $entity, $period, $debit, $credit, ?JournalEntry $journal, int $repeat, int $permutation): array
{
    $base = [
        'tenant_id' => (int) $tenant->id,
        'actor_id' => (int) $user->id,
        'legal_entity_id' => (int) $entity->id,
        'period_id' => (int) $period->id,
        'journal_id' => $journal ? (int) $journal->id : null,
    ];

    if ($scenario === 'duplicate_idempotency') {
        $suffix = strtoupper(substr(hash('sha256', "{$repeat}:{$permutation}:{$scenario}"), 0, 10));
        return [
            ...$base,
            'operation' => 'create_draft',
            'header' => [
                'legal_entity_id' => (int) $entity->id,
                'journal_number' => 'RACE-JV-'.$suffix,
                'effective_date' => '2026-10-10',
                'description' => 'FIN-1.03 duplicate idempotency race',
                'currency' => 'ETB',
                'source_type' => JournalSourceType::MANUAL->value,
                'idempotency_key' => 'race-key-'.$suffix,
            ],
            'lines' => [
                ['account_id' => (int) $debit->id, 'debit_amount' => '25.0000', 'credit_amount' => '0'],
                ['account_id' => (int) $credit->id, 'debit_amount' => '0', 'credit_amount' => '25.0000'],
            ],
        ];
    }

    if ($scenario === 'period_close_vs_post') {
        return [
            ...$base,
            'operation' => $side === 'left' ? 'post' : 'close_period',
        ];
    }

    return [
        ...$base,
        'operation' => $side === 'left' ? 'insert_balanced_lines' : 'post',
        'debit_account_id' => (int) $debit->id,
        'credit_account_id' => (int) $credit->id,
    ];
}

function startWorker(array $payload): array
{
    $command = [PHP_BINARY, __FILE__, '--worker', base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))];
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, base_path());

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start an independent MySQL concurrency worker.');
    }

    $ready = fgets($pipes[1]);
    if (!is_string($ready) || !preg_match('/^READY:(\d+)\s*$/', $ready, $matches)) {
        $stderr = stream_get_contents($pipes[2]);
        stopWorker(['process' => $process, 'pipes' => $pipes]);
        throw new RuntimeException('Concurrency worker did not become ready: '.trim((string) $stderr));
    }

    return ['process' => $process, 'pipes' => $pipes, 'connection_id' => (int) $matches[1]];
}

function signalWorker(array $worker): void
{
    fwrite($worker['pipes'][0], "GO\n");
    fclose($worker['pipes'][0]);
}

function waitForLockWait(int $connectionId, string $scenario, int $repeat, int $permutation): void
{
    $deadline = microtime(true) + 15.0;

    do {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS waiting
             FROM performance_schema.data_lock_waits w
             INNER JOIN performance_schema.threads t
                ON t.THREAD_ID = w.REQUESTING_THREAD_ID
             WHERE t.PROCESSLIST_ID = ?',
            [$connectionId],
        );

        if ((int) $row->waiting > 0) {
            return;
        }

        usleep(20000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("Worker {$connectionId} did not reach the expected InnoDB lock wait for {$scenario}, repeat {$repeat}, order {$permutation}.");
}

function finishWorker(array $worker): array
{
    $line = fgets($worker['pipes'][1]);
    $stderr = stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    $exitCode = proc_close($worker['process']);

    if (!is_string($line) || !str_starts_with($line, 'RESULT:') || $exitCode !== 0) {
        throw new RuntimeException('Concurrency worker failed: '.trim((string) $stderr).' output='.trim((string) $line));
    }

    $result = json_decode(substr(trim($line), 7), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($result)) {
        throw new RuntimeException('Concurrency worker returned invalid JSON.');
    }

    return $result;
}

function stopWorker(array $worker): void
{
    foreach (($worker['pipes'] ?? []) as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    if (isset($worker['process']) && is_resource($worker['process'])) {
        proc_terminate($worker['process']);
        proc_close($worker['process']);
    }
}

function assertRaceOutcome(string $scenario, array $results, $tenant, $user, $entity, $period, $debit, $credit, ?JournalEntry $journal): void
{
    if ($scenario === 'duplicate_idempotency') {
        foreach ($results as $result) {
            if (($result['ok'] ?? false) !== true) {
                throw new RuntimeException('Duplicate idempotency worker failed: '.json_encode($result));
            }
        }

        $ids = array_unique(array_column($results, 'journal_id'));
        if (count($ids) !== 1) {
            throw new RuntimeException('Duplicate idempotency submissions did not converge on one persisted journal.');
        }

        if (JournalEntry::query()->where('tenant_id', $tenant->id)->where('legal_entity_id', $entity->id)->count() !== 1) {
            throw new RuntimeException('Duplicate idempotency race persisted more than one journal.');
        }

        return;
    }

    if ($scenario === 'period_close_vs_post') {
        $postResults = array_values(array_filter(
            $results,
            static fn (array $result): bool => ($result['operation'] ?? null) === 'post',
        ));
        $postResult = $postResults[0] ?? null;
        if (!is_array($postResult)
            || ($postResult['ok'] ?? false) !== true
            || ($postResult['status'] ?? null) !== JournalEntry::POSTED) {
            throw new RuntimeException('Posting did not complete after period-close contention: '.json_encode($results));
        }

        $closeResults = array_values(array_filter($results, static fn (array $result): bool => ($result['operation'] ?? null) === 'close_period'));
        if (count($closeResults) !== 1) {
            throw new RuntimeException('Period-close worker result is missing or ambiguous: '.json_encode($results));
        }
        $closeResult = $closeResults[0];

        if (($closeResult['ok'] ?? false) !== true) {
            $periodError = $closeResult['errors']['period'][0] ?? null;
            if (!is_string($periodError) || !str_contains($periodError, 'draft journals')) {
                throw new RuntimeException('Period close failed for an unexpected reason: '.json_encode($closeResult));
            }

            app(AccountingPeriodService::class)->close(
                (int) $tenant->id,
                (int) $user->id,
                (int) $period->id,
                'Close after the concurrent journal posting completed',
            );
        }

        if ($journal->fresh()->status !== JournalEntry::POSTED || $period->fresh()->status !== AccountingPeriod::CLOSED) {
            throw new RuntimeException('Period close/post race left an invalid final journal or period state.');
        }

        return;
    }

    $postResult = null;
    $insertResult = null;
    foreach ($results as $result) {
        if (($result['operation'] ?? null) === 'post') {
            if (($result['journal_id'] ?? null) === (int) $journal->id && ($result['ok'] ?? false) === true) {
                $postResult = $result;
            }
        }
        if (($result['operation'] ?? null) === 'insert_balanced_lines') {
            $insertResult = $result;
        }
    }

    if ($postResult === null) {
        $postResult = array_values(array_filter($results, static fn (array $r): bool => ($r['journal_id'] ?? null) === (int) $journal->id && ($r['status'] ?? null) === JournalEntry::POSTED))[0] ?? null;
    }
    if (!is_array($postResult) || ($postResult['ok'] ?? false) !== true) {
        throw new RuntimeException('Posting did not complete in the direct-line-write race: '.json_encode($results));
    }

    $finalJournal = $journal->fresh();
    $lineCount = DB::table('journal_lines')->where('journal_entry_id', $journal->id)->count();
    if ($finalJournal->status !== JournalEntry::POSTED || !in_array($lineCount, [2, 4], true)) {
        throw new RuntimeException('Direct line insertion race left an invalid journal state or line count.');
    }

    $totals = DB::table('journal_lines')->where('journal_entry_id', $journal->id)
        ->selectRaw('COUNT(*) AS line_count, SUM(debit_amount) AS debits, SUM(credit_amount) AS credits')->first();
    if ((string) $totals->debits !== (string) $totals->credits) {
        throw new RuntimeException('Direct line insertion race produced an unbalanced posted journal.');
    }

    if ($lineCount === 4) {
        if (($insertResult['ok'] ?? false) !== true || (int) ($insertResult['inserted_lines'] ?? 0) !== 2) {
            throw new RuntimeException('The direct line writer did not report its committed balanced pair.');
        }
    } elseif (($insertResult['ok'] ?? true) !== false || ($insertResult['sql_state'] ?? '') !== '45000') {
        throw new RuntimeException('Direct line insertion was rejected for an unexpected reason: '.json_encode($insertResult));
    }
}
