<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Documents\Services\IncomingDocumentRegistrationService;
use MaaleArna\MASES\Core\Domains\Organization\Services\OrganizationNumberingRegistryService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class IncomingDocumentRegistrationMySqlConcurrencyTest extends TestCase
{
    #[Test]
    public function concurrent_same_idempotency_key_returns_one_persisted_registration(): void
    {
        $this->requireMySql('ARNA_RUN_MYSQL_CONCURRENCY');
        [$tenant, $actor, $entityId, $fileId] = $this->fixture();
        $token = $actor->createToken('incoming-registration-concurrency')->plainTextToken;
        $key = 'incoming-concurrency-'.bin2hex(random_bytes(12));
        $payload = $this->payload($entityId, $fileId);

        $port = random_int(19000, 24000);
        $logPath = tempnam(sys_get_temp_dir(), 'arna-doc-server-');
        $server = proc_open(
            [PHP_BINARY, 'artisan', 'serve', '--host=127.0.0.1', '--port='.$port],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']],
            $pipes,
            base_path(),
            array_merge((array) getenv(), ['PHP_CLI_SERVER_WORKERS' => '4']),
        );
        if (!is_resource($server)) {
            @unlink($logPath);
            throw new RuntimeException('Could not start the HTTP server for incoming-registration concurrency.');
        }

        $multi = curl_multi_init();
        $handles = [];
        try {
            $this->waitForServer($port, $logPath);
            DB::disconnect();
            for ($i = 0; $i < 2; $i++) {
                $handle = curl_init('http://127.0.0.1:'.$port.'/api/documents/incoming');
                curl_setopt_array($handle, [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Accept: application/json',
                        'Content-Type: application/json',
                        'Authorization: Bearer '.$token,
                        'X-Tenant-ID: '.$tenant->getKey(),
                        'Idempotency-Key: '.$key,
                    ],
                    CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 30,
                ]);
                curl_multi_add_handle($multi, $handle);
                $handles[] = $handle;
            }

            do {
                $status = curl_multi_exec($multi, $active);
                if ($active) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($active && $status === CURLM_OK);

            $responses = [];
            foreach ($handles as $handle) {
                $responses[] = [
                    'status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
                    'body' => json_decode((string) curl_multi_getcontent($handle), true),
                    'error' => curl_error($handle),
                ];
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }

            DB::reconnect();
            $rows = DB::table('incoming_document_registrations')
                ->where('tenant_id', $tenant->getKey())
                ->where('idempotency_key', $key)
                ->get();
            $this->assertCount(1, $rows, 'Concurrent retries must persist exactly one registration.');
            $this->assertSame([201, 201], array_column($responses, 'status'), json_encode($responses));
            $ids = array_map(fn (array $response): mixed => $this->nestedValue($response['body'] ?? [], 'incoming_registration_id'), $responses);
            $this->assertSame(
                [$rows[0]->registration_uuid, $rows[0]->registration_uuid],
                $ids,
                'Both successful responses must expose the same persisted registration UUID. Responses: '.json_encode($responses, JSON_UNESCAPED_UNICODE),
            );
            $this->assertSame(1, DB::table('incoming_document_events')
                ->where('tenant_id', $tenant->getKey())
                ->where('incoming_document_registration_id', $rows[0]->id)
                ->count());
        } finally {
            foreach ($handles as $handle) {
                if (is_resource($handle)) {
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                }
            }
            curl_multi_close($multi);
            if (is_resource($server)) {
                proc_terminate($server);
                proc_close($server);
            }
            DB::purge();
            @unlink($logPath);
        }
    }

    #[Test]
    public function event_insert_failure_rolls_back_registration_and_event(): void
    {
        $this->requireMySql('ARNA_RUN_MYSQL_FAILURE_ATOMICITY');
        [$tenant, $actor, $entityId, $fileId] = $this->fixture();
        app(TenantContextService::class)->setTenantById($tenant->getKey());

        $key = 'incoming-atomicity-'.bin2hex(random_bytes(12));
        $trigger = 'fail_incoming_doc_event_'.substr(bin2hex(random_bytes(8)), 0, 12);
        DB::unprepared("CREATE TRIGGER {$trigger} BEFORE INSERT ON incoming_document_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced incoming event failure'");

        try {
            try {
                app(IncomingDocumentRegistrationService::class)->register(
                    $this->payload($entityId, $fileId),
                    $actor,
                    $key,
                );
                $this->fail('The forced lifecycle-event failure should abort registration.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('forced incoming event failure', $exception->getMessage());
            }

            $this->assertSame(0, DB::table('incoming_document_registrations')
                ->where('tenant_id', $tenant->getKey())
                ->where('idempotency_key', $key)
                ->count());
            $this->assertSame(0, DB::table('incoming_document_events')
                ->where('tenant_id', $tenant->getKey())
                ->count());
        } finally {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function requireMySql(string $flag): void
    {
        if (DB::connection()->getDriverName() !== 'mysql'
            || !function_exists('proc_open')
            || !function_exists('curl_init')
            || getenv($flag) !== '1') {
            $this->markTestSkipped('Requires the dedicated MySQL incoming-registration gate.');
        }
    }

    /** @return array{Tenant,User,int,int} */
    private function fixture(): array
    {
        $suffix = bin2hex(random_bytes(5));
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create([
            'is_active' => true,
            'default_tenant_id' => $tenant->getKey(),
        ]);
        DB::table('tenant_memberships')->insert([
            'user_id' => $actor->getKey(),
            'tenant_id' => $tenant->getKey(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['documents.receive', 'file.view'] as $slug) {
            $permission = Permission::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name_en' => $slug,
                    'category' => 'document',
                    'scope' => 'tenant',
                    'tenant_id' => null,
                    'is_active' => true,
                    'is_system' => true,
                ],
            );
            $actor->permissions()->attach($permission->getKey());
        }

        $companyId = (int) DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'parent_id' => null,
            'code' => 'ROOT-'.$suffix,
            'unit_type' => 'company',
            'name_en' => 'Incoming registration root',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'parent_id' => $companyId,
            'code' => 'BR-'.$suffix,
            'unit_type' => 'branch',
            'name_en' => 'Incoming registration branch',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $entityId = (int) DB::table('legal_entities')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'code' => 'ENTITY-'.$suffix,
            'name_en' => 'Incoming registration entity',
            'base_currency' => 'ETB',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $departmentId = (int) DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'parent_id' => $branchId,
            'legal_entity_id' => $entityId,
            'code' => 'DEPT-'.$suffix,
            'unit_type' => 'department',
            'name_en' => 'Incoming registration department',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $numbering = app(OrganizationNumberingRegistryService::class);
        $numbering->registerBranch((int) $tenant->getKey(), $branchId, '1', 'Register incoming branch', (int) $actor->getKey());
        $numbering->registerDepartment((int) $tenant->getKey(), $departmentId, $entityId, '1', '1', 'Register incoming department', (int) $actor->getKey());

        $fileId = (int) DB::table('files')->insertGetId([
            'user_id' => $actor->getKey(),
            'tenant_id' => $tenant->getKey(),
            'original_name' => 'incoming-'.$suffix.'.pdf',
            'display_name_am' => null,
            'display_name_en' => 'Incoming file',
            'name' => 'incoming-'.$suffix.'.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 128,
            'path' => 'incoming/'.$suffix.'.pdf',
            'disk' => 'local',
            'version' => 1,
            'parent_file_id' => null,
            'status' => 'published',
            'is_public' => false,
            'metadata' => null,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenant, $actor, $entityId, $fileId];
    }

    private function payload(int $entityId, int $fileId): array
    {
        return [
            'legal_entity_id' => $entityId,
            'bb_code' => '11',
            'file_id' => $fileId,
            'subject' => 'Incoming registration concurrency test',
            'sender_name' => 'External sender',
            'sender_reference' => '  EXT/ማጣቀሻ-0007  ',
            'notes' => 'Automated MySQL integration test',
            'metadata' => ['source' => 'ci', 'kind' => 'incoming'],
        ];
    }

    private function nestedValue(mixed $value, string $key): mixed
    {
        if (!is_array($value)) {
            return null;
        }
        if (array_key_exists($key, $value)) {
            return $value[$key];
        }
        foreach ($value as $child) {
            $found = $this->nestedValue($child, $key);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    private function waitForServer(int $port, string $logPath): void
    {
        $deadline = microtime(true) + 20;
        do {
            $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.2);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Incoming-registration HTTP server did not start: '.(string) file_get_contents($logPath));
    }
}
