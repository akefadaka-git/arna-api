<?php

declare(strict_types=1);

namespace Tests\Feature;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Services\PersonIdentityService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PersonIdentityMySqlConcurrencyTest extends TestCase
{
    #[Test]
    public function concurrent_independent_mysql_connections_cannot_link_two_active_employee_records_to_one_person(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql'
            || ! function_exists('pcntl_fork')
            || getenv('ARNA_RUN_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Requires the dedicated MySQL concurrency workflow and pcntl.');
        }

        $suffix = bin2hex(random_bytes(5));
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true, 'default_tenant_id' => $tenant->getKey()]);
        $actor->tenants()->attach($tenant->getKey(), ['joined_at' => now(), 'status' => 'active']);

        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'people.manage'],
            [
                'name_en' => 'Manage People Profiles',
                'category' => 'user',
                'scope' => 'tenant',
                'tenant_id' => null,
                'is_active' => true,
                'is_system' => true,
            ],
        );
        $createdPermission = $permission->wasRecentlyCreated;
        $role = Role::query()->create([
            'name_en' => 'Concurrency People Manager '.$suffix,
            'slug' => 'people-concurrency-'.$suffix,
            'scope' => 'tenant',
            'tenant_id' => $tenant->getKey(),
            'is_active' => true,
        ]);
        $role->permissions()->attach($permission->getKey(), ['assigned_at' => now()]);
        $actor->roles()->attach($role->getKey(), ['assigned_at' => now()]);

        $person = Person::query()->create(['first_name_en' => 'Employee Link Concurrency']);
        DB::table('person_tenant_accesses')->insert([
            'person_id' => $person->getKey(),
            'tenant_id' => $tenant->getKey(),
            'created_by' => $actor->getKey(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $employeeIds = [];
        foreach (['EMP-CONC-A-'.$suffix, 'EMP-CONC-B-'.$suffix] as $number) {
            $employeeIds[] = (int) DB::table('employees')->insertGetId([
                'tenant_id' => $tenant->getKey(),
                'employee_number' => $number,
                'user_id' => null,
                'person_id' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $release = tempnam(sys_get_temp_dir(), 'arna-employee-release-');
        $results = [
            tempnam(sys_get_temp_dir(), 'arna-employee-result-'),
            tempnam(sys_get_temp_dir(), 'arna-employee-result-'),
        ];
        @unlink($release);
        foreach ($results as $path) {
            @unlink($path);
        }
        DB::disconnect();
        $children = [];

        try {
            foreach ($employeeIds as $index => $employeeId) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork employee-link concurrency worker.');
                }
                if ($pid === 0) {
                    DB::purge();
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
                        app(PersonIdentityService::class)->linkEmployee(
                            (int) $tenant->getKey(),
                            $employeeId,
                            (int) $person->getKey(),
                            $workerActor,
                            'Concurrency test employee identity link',
                        );
                        file_put_contents($results[$index], 'success');
                        exit(0);
                    } catch (DomainException $exception) {
                        file_put_contents($results[$index], 'conflict');
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents($results[$index], 'error:'.get_class($exception));
                        exit(3);
                    }
                }
                $children[] = $pid;
            }

            file_put_contents($release, 'go');
            $exitCodes = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $exitCodes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 99;
            }
            $outcomes = array_map(
                static fn (string $path): string => is_file($path) ? trim((string) file_get_contents($path)) : 'missing-result',
                $results,
            );
            $this->assertSame([0, 0], $exitCodes, 'Both employee-link workers must finish without infrastructure errors.');
            $this->assertCount(1, array_filter($outcomes, static fn (string $value): bool => $value === 'success'));
            $this->assertCount(1, array_filter($outcomes, static fn (string $value): bool => $value === 'conflict'));
        } finally {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }
            DB::purge();
            DB::table('employees')->whereIn('id', $employeeIds)->delete();
            DB::table('person_tenant_accesses')->where('person_id', $person->getKey())->delete();
            Person::withTrashed()->whereKey($person->getKey())->forceDelete();
            DB::table('user_has_roles')->where('user_id', $actor->getKey())->delete();
            DB::table('role_has_permissions')->where('role_id', $role->getKey())->delete();
            DB::table('tenant_memberships')->where('tenant_id', $tenant->getKey())->where('user_id', $actor->getKey())->delete();
            DB::table('roles')->where('id', $role->getKey())->delete();
            if ($createdPermission) {
                DB::table('permissions')->where('id', $permission->getKey())->delete();
            }
            DB::table('users')->where('id', $actor->getKey())->delete();
            DB::table('tenants')->where('id', $tenant->getKey())->delete();
            @unlink($release);
            foreach ($results as $path) {
                @unlink($path);
            }
        }
    }

    #[Test]
    public function concurrent_independent_mysql_connections_cannot_link_one_account_to_two_people(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl; run in the dedicated MySQL concurrency workflow step.');
        }

        $user = User::factory()->create(['is_active' => true]);
        $first = Person::query()->create(['first_name_en' => 'Concurrency A']);
        $second = Person::query()->create(['first_name_en' => 'Concurrency B']);

        $release = tempnam(sys_get_temp_dir(), 'arna-people-release-');
        $results = [
            tempnam(sys_get_temp_dir(), 'arna-people-result-'),
            tempnam(sys_get_temp_dir(), 'arna-people-result-'),
        ];
        @unlink($release);
        foreach ($results as $path) {
            @unlink($path);
        }

        // Do not let forked workers inherit a live PDO socket. Each worker
        // must open its own independent MySQL connection after the fork.
        DB::disconnect();
        $children = [];

        try {
            foreach ([$first->getKey(), $second->getKey()] as $index => $personId) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork MySQL concurrency worker.');
                }

                if ($pid === 0) {
                    DB::purge();
                    $deadline = microtime(true) + 10;
                    while (! file_exists($release) && microtime(true) < $deadline) {
                        usleep(10000);
                    }

                    if (! file_exists($release)) {
                        file_put_contents($results[$index], 'error:release-timeout');
                        exit(2);
                    }

                    try {
                        DB::connection()->table('people')
                            ->where('id', $personId)
                            ->update(['user_id' => $user->getKey()]);
                        file_put_contents($results[$index], 'success');
                        exit(0);
                    } catch (QueryException $exception) {
                        file_put_contents($results[$index], 'constraint');
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents($results[$index], 'error:'.get_class($exception));
                        exit(3);
                    }
                }

                $children[] = $pid;
            }

            // Release both workers together so the writes race across
            // separate connections rather than running serially in PHP.
            file_put_contents($release, 'go');
            $exitCodes = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $exitCodes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 99;
            }

            $outcomes = array_map(
                static fn (string $path): string => is_file($path) ? trim((string) file_get_contents($path)) : 'missing-result',
                $results,
            );

            $this->assertSame([0, 0], $exitCodes, 'Both workers must finish without infrastructure errors.');
            $this->assertCount(1, array_filter($outcomes, static fn (string $value): bool => $value === 'success'));
            $this->assertCount(1, array_filter($outcomes, static fn (string $value): bool => $value === 'constraint'));
        } finally {
            foreach ($children as $pid) {
                // A worker that already exited is harmless; reap any remaining child.
                pcntl_waitpid($pid, $status, WNOHANG);
            }
            DB::purge();
            Person::withTrashed()->whereIn('id', [$first->getKey(), $second->getKey()])->forceDelete();
            $user->delete();
            @unlink($release);
            foreach ($results as $path) {
                @unlink($path);
            }
        }
    }
}
