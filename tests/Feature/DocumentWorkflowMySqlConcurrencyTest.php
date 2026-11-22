<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Documents\Contracts\DocumentWorkflowAuthorizer;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentWorkflowAggregateService;
use MaaleArna\MASES\Core\Domains\Documents\Services\SodiumDocumentSignatureEvidenceVerifier;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

final class DocumentWorkflowMySqlConcurrencyTest extends TestCase
{
    public function test_two_independent_mysql_sessions_can_issue_one_workflow_only_once(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql'
            || ! function_exists('pcntl_fork')
            || getenv('ARNA_RUN_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Requires the dedicated MySQL concurrency workflow and pcntl.');
        }

        $suffix = bin2hex(random_bytes(5));
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true, 'default_tenant_id' => $tenant->getKey()]);
        $entityId = (int) DB::table('legal_entities')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'code' => 'DOC-CONC-'.$suffix,
            'name_en' => 'Document concurrency issuer', 'base_currency' => 'ETB',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'legal_entity_id' => $entityId,
            'code' => 'DOC-CONC-UNIT-'.$suffix, 'unit_type' => 'department',
            'name_en' => 'Document concurrency office', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('document_entity_code_assignments')->insert([
            'tenant_id' => $tenant->getKey(), 'legal_entity_id' => $entityId,
            'entity_code' => '01', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('document_department_code_assignments')->insert([
            'tenant_id' => $tenant->getKey(), 'legal_entity_id' => $entityId,
            'organization_unit_id' => $unitId, 'entity_code' => '01', 'department_code' => '01',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Storage::fake('local');
        Storage::fake('document-concurrency-evidence');
        config(['mases.documents.evidence_disk' => 'document-concurrency-evidence']);
        Storage::disk('local')->put('document-concurrency/'.$suffix.'.pdf', 'concurrent immutable bytes');
        $fileId = (int) DB::table('files')->insertGetId([
            'tenant_id' => $tenant->getKey(), 'user_id' => $actor->getKey(),
            'original_name' => 'concurrency.pdf', 'name' => 'concurrency.pdf',
            'size' => strlen('concurrent immutable bytes'), 'path' => 'document-concurrency/'.$suffix.'.pdf',
            'disk' => 'local', 'version' => 1, 'status' => 'published', 'is_public' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $allow = new class implements DocumentWorkflowAuthorizer {
            public function hasPermission(User $actor, int $tenantId, string $permission): bool { return true; }
            public function allowsGovernedAction(User $actor, int $tenantId, int $governanceBodyId, int $governancePositionId, string $action, ?int $documentAuthorUserId = null): bool { return true; }
            public function requirePermission(User $actor, int $tenantId, string $permission): void {}
            public function requireGovernedAction(User $actor, int $tenantId, int $governanceBodyId, int $governancePositionId, string $action, ?int $documentAuthorUserId = null): void {}
        };
        $this->app->instance(DocumentWorkflowAuthorizer::class, $allow);

        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $service->createDraft($actor, (int) $tenant->getKey(), $entityId, $unitId, '01', 'Concurrent issuance', 9, 4);
        $workflow = $service->transition($actor, (int) $tenant->getKey(), (int) $workflow->id, 'submit', 0, null, [
            'submitted_file_id' => $fileId, 'submitted_file_version' => 1,
        ]);
        $workflow = $service->transition($actor, (int) $tenant->getKey(), (int) $workflow->id, 'start_review', 1);
        $workflow = $service->transition($actor, (int) $tenant->getKey(), (int) $workflow->id, 'approve', 2);
        $workflow = $service->transition($actor, (int) $tenant->getKey(), (int) $workflow->id, 'begin_signing', 3);

        $pair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($pair);
        $secretKey = sodium_crypto_sign_secretkey($pair);
        $keyId = 'mysql-concurrency-'.$suffix;
        $signedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        DB::table('document_signing_keys')->insert([
            'tenant_id' => $tenant->getKey(), 'key_id' => $keyId, 'signer_user_id' => $actor->getKey(),
            'algorithm' => 'Ed25519', 'public_key_base64' => base64_encode($publicKey),
            'trusted_by_user_id' => $actor->getKey(), 'trust_reference' => 'Disposable MySQL concurrency test',
            'trusted_at' => now()->subMinute(), 'valid_until' => null,
        ]);
        $payload = SodiumDocumentSignatureEvidenceVerifier::canonicalPayload(
            (int) $tenant->getKey(), (string) $workflow->uuid, (string) $workflow->submitted_file_sha256,
            (int) $actor->getKey(), $signedAt,
        );
        $signatureEvidence = [
            'key_id' => $keyId,
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, $secretKey)),
            'signed_at' => $signedAt->format('Y-m-d\TH:i:s\Z'),
        ];

        $release = tempnam(sys_get_temp_dir(), 'arna-doc-issue-release-');
        $ready = [
            tempnam(sys_get_temp_dir(), 'arna-doc-issue-ready-'),
            tempnam(sys_get_temp_dir(), 'arna-doc-issue-ready-'),
        ];
        $results = [
            tempnam(sys_get_temp_dir(), 'arna-doc-issue-result-'),
            tempnam(sys_get_temp_dir(), 'arna-doc-issue-result-'),
        ];
        @unlink($release);
        foreach (array_merge($ready, $results) as $path) {
            @unlink($path);
        }

        // Do not fork while a PDO socket is open: inherited MySQL file descriptors
        // are shared across processes and a child disconnect can invalidate the
        // coordinator's session. Workers and coordinator each establish a fresh,
        // independent MySQL connection after the fork.
        DB::disconnect();
        $children = [];

        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Unable to fork document issuance concurrency worker.');
                }
                if ($pid === 0) {
                    DB::purge();
                    // Establish a real, independent MySQL session before telling
                    // the coordinator this worker is ready for the barrier.
                    DB::connection()->selectOne('SELECT CONNECTION_ID() AS connection_id');
                    file_put_contents($ready[$index], 'ready');
                    $deadline = microtime(true) + 10;
                    while (! file_exists($release) && microtime(true) < $deadline) {
                        usleep(10000);
                    }
                    if (! file_exists($release)) {
                        file_put_contents($results[$index], 'error:release-timeout');
                        exit(2);
                    }
                    try {
                        $workerActor = User::query()->findOrFail($actor->getKey());
                        app(DocumentWorkflowAggregateService::class)->transition(
                            $workerActor,
                            (int) $tenant->getKey(),
                            (int) $workflow->id,
                            'issue',
                            4,
                            null,
                            ['signature_evidence' => $signatureEvidence],
                        );
                        file_put_contents($results[$index], 'success');
                        exit(0);
                    } catch (ConflictHttpException) {
                        file_put_contents($results[$index], 'conflict');
                        exit(0);
                    } catch (\Throwable $exception) {
                        file_put_contents($results[$index], 'error:'.get_class($exception).':'.$exception->getMessage());
                        exit(3);
                    }
                }
                $children[] = $pid;
            }

            $readyDeadline = microtime(true) + 10;
            while ((!is_file($ready[0]) || !is_file($ready[1])) && microtime(true) < $readyDeadline) {
                usleep(10000);
            }
            $this->assertFileExists($ready[0], 'Worker 1 must establish its independent MySQL session.');
            $this->assertFileExists($ready[1], 'Worker 2 must establish its independent MySQL session.');

            // The coordinator opens its own connection only after both children
            // have forked and established independent sessions.
            DB::connection()->selectOne('SELECT CONNECTION_ID() AS coordinator_connection_id');
            DB::beginTransaction();
            DB::table('document_workflow_aggregates')->where('id', $workflow->id)
                ->lockForUpdate()->first();
            file_put_contents($release, 'go');

            $waitDeadline = microtime(true) + 15;
            $waitingSessions = 0;
            do {
                $row = DB::selectOne(
                    'SELECT COUNT(DISTINCT requested.THREAD_ID) AS waiting_count
                     FROM performance_schema.data_lock_waits AS waits
                     INNER JOIN performance_schema.data_locks AS requested
                       ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID
                     WHERE requested.OBJECT_SCHEMA = DATABASE()
                       AND requested.OBJECT_NAME = ?
                       AND requested.LOCK_STATUS = ?',
                    ['document_workflow_aggregates', 'WAITING'],
                );
                $waitingSessions = (int) ($row->waiting_count ?? 0);
                if ($waitingSessions >= 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $waitDeadline);

            $this->assertSame(
                2,
                $waitingSessions,
                'Both independent workers must be observed by MySQL waiting on the same aggregate-row lock before the coordinator releases it.',
            );
            DB::commit();

            $exitCodes = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $exitCodes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 99;
            }
            DB::purge();
            $outcomes = array_map(
                static fn (string $path): string => is_file($path) ? trim((string) file_get_contents($path)) : 'missing-result',
                $results,
            );

            $this->assertSame([0, 0], $exitCodes, 'Both independent MySQL workers must complete without infrastructure errors.');
            $this->assertCount(1, array_filter($outcomes, static fn (string $value): bool => $value === 'success'));
            $this->assertCount(1, array_filter($outcomes, static fn (string $value): bool => $value === 'conflict'));
            $this->assertSame(1, DB::table('documents')->where('tenant_id', $tenant->getKey())
                ->where('idempotency_key', 'document-workflow:'.$workflow->uuid)->count());
            $this->assertSame(1, DB::table('document_signature_evidence')->where('tenant_id', $tenant->getKey())
                ->where('workflow_id', $workflow->id)->count());
            $this->assertSame(2, (int) DB::table('document_number_counters')->where('tenant_id', $tenant->getKey())->value('next_sequence'));
            $this->assertSame('issued', DB::table('document_workflow_aggregates')->where('id', $workflow->id)->value('status'));
        } finally {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge();
            @unlink($release);
            foreach (array_merge($ready, $results) as $path) {
                @unlink($path);
            }
        }
    }

    public function test_capture_intent_is_durable_and_row_lock_serializes_cleanup_claim_against_attachment(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires independent MySQL sessions.');
        }

        $suffix = bin2hex(random_bytes(5));
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true, 'default_tenant_id' => $tenant->getKey()]);
        Storage::fake('local');
        Storage::fake('capture-intent-evidence');
        config(['mases.documents.evidence_disk' => 'capture-intent-evidence']);
        Storage::disk('local')->put('capture-race/'.$suffix.'.pdf', 'durable capture race bytes');
        $fileId = (int) DB::table('files')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $actor->getKey(),
            'original_name' => 'capture-race.pdf',
            'name' => 'capture-race.pdf',
            'size' => strlen('durable capture race bytes'),
            'path' => 'capture-race/'.$suffix.'.pdf',
            'disk' => 'local',
            'version' => 1,
            'status' => 'published',
            'is_public' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lifecycle = app(\MaaleArna\MASES\Core\Domains\Documents\Services\DocumentEvidenceCaptureLifecycle::class);
        $prepared = $lifecycle->prepare((int) $tenant->getKey(), $fileId, 1, (int) $actor->getKey());
        $retried = $lifecycle->prepare((int) $tenant->getKey(), $fileId, 1, (int) $actor->getKey());
        $this->assertSame($prepared['intent_id'], $retried['intent_id']);
        $this->assertSame($prepared['snapshot']['path'], $retried['snapshot']['path']);
        $this->assertSame(2, DB::table('document_evidence_capture_events')
            ->where('capture_intent_id', $prepared['intent_id'])->count());

        // A separately opened session must observe CAPTURED immediately after
        // prepare() returns, before any workflow submission transaction links it.
        config(['database.connections.capture_observer' => config('database.connections.mysql')]);
        DB::purge('capture_observer');
        $observer = DB::connection('capture_observer');
        $observed = $observer->table('document_evidence_capture_intents')
            ->where('id', $prepared['intent_id'])->value('status');
        $this->assertSame('captured', $observed);

        $writer = DB::connection();
        $writer->beginTransaction();
        try {
            $writer->table('document_evidence_capture_intents')
                ->where('id', $prepared['intent_id'])->lockForUpdate()->first();

            $observer->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $observer->beginTransaction();
            try {
                $observer->table('document_evidence_capture_intents')
                    ->where('id', $prepared['intent_id'])->lockForUpdate()->first();
                $this->fail('A second MySQL session must wait while the capture intent row is locked.');
            } catch (\Illuminate\Database\QueryException) {
                $this->assertTrue(true, 'The competing session was blocked by the intent row lock.');
            } finally {
                if ($observer->transactionLevel() > 0) {
                    $observer->rollBack();
                }
            }

            $writer->table('document_evidence_capture_intents')->where('id', $prepared['intent_id'])->update([
                'status' => 'cleanup_claimed',
                'cleanup_claim_token' => (string) \Illuminate\Support\Str::uuid(),
                'cleanup_claimed_at' => now(),
                'cleanup_lease_until' => now()->addMinutes(10),
            ]);
            $writer->commit();
        } catch (\Throwable $exception) {
            if ($writer->transactionLevel() > 0) {
                $writer->rollBack();
            }
            throw $exception;
        }

        $this->expectException(ConflictHttpException::class);
        DB::transaction(fn () => $lifecycle->markLinked(
            $prepared['intent_id'],
            (int) $tenant->getKey(),
            1,
            (int) $actor->getKey(),
        ));
    }

}
