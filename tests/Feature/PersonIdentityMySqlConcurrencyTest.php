<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PersonIdentityMySqlConcurrencyTest extends TestCase
{
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
