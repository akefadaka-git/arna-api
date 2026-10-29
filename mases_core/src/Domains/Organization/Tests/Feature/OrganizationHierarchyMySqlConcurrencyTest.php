<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationUnit;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class OrganizationHierarchyMySqlConcurrencyTest extends TestCase
{
    #[Test]
    public function concurrent_hierarchy_mutations_preserve_parent_type_compatibility(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql'
            || ! function_exists('pcntl_fork')
            || ! function_exists('proc_open')
            || ! function_exists('curl_init')
            || getenv('ARNA_RUN_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Requires the dedicated MySQL concurrency workflow, curl, and pcntl.');
        }

        $suffix = bin2hex(random_bytes(5));
        $tenant = Tenant::query()->create([
            'name_am' => 'የድርጅት ተያያዥነት ሙከራ',
            'name_en' => 'Organization Hierarchy Concurrency',
            'slug' => 'org-hierarchy-concurrency-'.$suffix,
            'domain' => $suffix.'.org-concurrency.test',
            'is_active' => true,
        ]);
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

        $role = Role::query()->create([
            'name_en' => 'Organization concurrency manager '.$suffix,
            'slug' => 'org-concurrency-manager-'.$suffix,
            'scope' => 'tenant',
            'tenant_id' => $tenant->getKey(),
            'is_active' => true,
        ]);
        foreach (['organization.unit.view', 'organization.unit.manage'] as $slug) {
            $permission = Permission::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name_en' => $slug,
                    'category' => 'organization',
                    'scope' => 'tenant',
                    'tenant_id' => null,
                    'is_active' => true,
                    'is_system' => true,
                ],
            );
            DB::table('role_has_permissions')->updateOrInsert([
                'role_id' => $role->getKey(),
                'permission_id' => $permission->getKey(),
            ], ['assigned_at' => now()]);
        }
        DB::table('user_has_roles')->insert([
            'user_id' => $actor->getKey(),
            'role_id' => $role->getKey(),
            'assigned_at' => now(),
            'assigned_by' => $actor->getKey(),
        ]);
        $token = $actor->createToken('organization-hierarchy-concurrency')->plainTextToken;

        // A is the company root. B is a division and C is a branch under A.
        // Worker one changes B from division to branch. Worker two moves C
        // under B. Either change is valid alone, but both cannot commit:
        // a branch cannot parent another branch. The tenant-row lock in the
        // controller must serialize the two requests and force one to 409.
        $companyId = $this->insertUnit((int) $tenant->getKey(), null, 'ROOT-'.$suffix, 'company', (int) $actor->getKey());
        $divisionId = $this->insertUnit((int) $tenant->getKey(), $companyId, 'DIV-'.$suffix, 'division', (int) $actor->getKey());
        $branchId = $this->insertUnit((int) $tenant->getKey(), $companyId, 'BR-'.$suffix, 'branch', (int) $actor->getKey());

        $port = random_int(19000, 24000);
        $logPath = tempnam(sys_get_temp_dir(), 'arna-org-server-');
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logPath, 'a'],
            2 => ['file', $logPath, 'a'],
        ];
        $environment = array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '4']);
        $server = proc_open(
            [PHP_BINARY, 'artisan', 'serve', '--host=127.0.0.1', '--port='.$port],
            $descriptors,
            $pipes,
            base_path(),
            $environment,
        );
        if (! is_resource($server)) {
            @unlink($logPath);
            throw new RuntimeException('Could not start the local HTTP server for Organization concurrency requests.');
        }

        $release = tempnam(sys_get_temp_dir(), 'arna-org-release-');
        $resultPaths = [
            tempnam(sys_get_temp_dir(), 'arna-org-result-'),
            tempnam(sys_get_temp_dir(), 'arna-org-result-'),
        ];
        @unlink($release);
        foreach ($resultPaths as $resultPath) {
            @unlink($resultPath);
        }

        try {
            $this->waitForHttpServer($port, $logPath);
            DB::disconnect();
            $children = [];

            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork Organization hierarchy concurrency worker.');
                }

                if ($pid === 0) {
                    DB::purge();
                    $deadline = microtime(true) + 10;
                    while (! file_exists($release) && microtime(true) < $deadline) {
                        usleep(10000);
                    }
                    if (! file_exists($release)) {
                        file_put_contents($resultPaths[$index], 'error:release-timeout');
                        exit(2);
                    }

                    $targetId = $index === 0 ? $divisionId : $branchId;
                    $payload = $index === 0
                        ? ['unit_type' => 'branch', 'reason' => 'Concurrent type change regression']
                        : ['parent_id' => $divisionId, 'reason' => 'Concurrent reparent regression'];
                    try {
                        $status = $this->sendUpdate($port, $token, (int) $tenant->getKey(), $targetId, $payload);
                        file_put_contents($resultPaths[$index], (string) $status);
                        exit(0);
                    } catch (\Throwable $exception) {
                        file_put_contents($resultPaths[$index], 'error:'.$exception::class);
                        exit(3);
                    }
                }

                $children[] = $pid;
            }

            // Both independent OS processes are released together. Each HTTP
            // request reaches a separate PHP built-in-server worker and uses a
            // fresh database connection, so this exercises the real controller.
            file_put_contents($release, 'go');
            $exitCodes = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $exitCodes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 99;
            }
            $outcomes = array_map(
                static fn (string $resultPath): string => is_file($resultPath)
                    ? trim((string) file_get_contents($resultPath))
                    : 'missing-result',
                $resultPaths,
            );

            $this->assertSame([0, 0], $exitCodes, 'Both HTTP workers must finish without infrastructure errors: '.json_encode($outcomes));
            $this->assertCount(1, array_filter($outcomes, static fn (string $value): bool => $value === '200'));
            $this->assertCount(1, array_filter($outcomes, static fn (string $value): bool => $value === '409'), 'Exactly one incompatible concurrent mutation must be rejected.');

            $division = OrganizationUnit::query()->findOrFail($divisionId);
            $branch = OrganizationUnit::query()->findOrFail($branchId);
            if ((int) $branch->parent_id === (int) $division->getKey()) {
                $this->assertSame('division', $division->unit_type, 'A branch cannot remain beneath a parent changed to branch.');
            } else {
                $this->assertSame('branch', $division->unit_type);
                $this->assertSame($companyId, (int) $branch->parent_id, 'Rejected reparenting must leave the branch under the company.');
            }

            $this->assertSame(0, DB::table('organization_units as child')
                ->join('organization_units as parent', 'parent.id', '=', 'child.parent_id')
                ->where('child.tenant_id', $tenant->getKey())
                ->where('child.id', $branchId)
                ->where('parent.id', $divisionId)
                ->where('parent.unit_type', 'branch')
                ->count(), 'A branch must never remain beneath a parent concurrently changed to branch.');
        } finally {
            if (is_resource($server)) {
                proc_terminate($server);
                proc_close($server);
            }
            DB::purge();
            // Audit events are intentionally append-only and reference the
            // tenant with RESTRICT; this disposable CI test leaves its uniquely
            // named fixture intact rather than bypassing audit immutability.
            @unlink($release);
            foreach ($resultPaths as $resultPath) {
                @unlink($resultPath);
            }
            @unlink($logPath);
        }
    }

    private function insertUnit(int $tenantId, ?int $parentId, string $code, string $type, int $actorId): int
    {
        return (int) DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenantId,
            'parent_id' => $parentId,
            'code' => $code,
            'unit_type' => $type,
            'name_en' => 'Concurrency '.$type,
            'status' => 'active',
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, int|string> $payload */
    private function sendUpdate(int $port, string $token, int $tenantId, int $unitId, array $payload): int
    {
        $handle = curl_init('http://127.0.0.1:'.$port.'/api/organization/units/'.$unitId);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer '.$token,
                'X-Tenant-ID: '.$tenantId,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
        ]);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($status === 0) {
            throw new RuntimeException('Organization update HTTP request failed: '.$error);
        }

        return $status;
    }

    private function waitForHttpServer(int $port, string $logPath): void
    {
        $deadline = microtime(true) + 15;
        do {
            $handle = curl_init('http://127.0.0.1:'.$port.'/api/tenant-context');
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_TIMEOUT => 1,
            ]);
            curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);
            if ($status !== 0) {
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Organization HTTP test server did not become ready: '.(string) file_get_contents($logPath));
    }
}
