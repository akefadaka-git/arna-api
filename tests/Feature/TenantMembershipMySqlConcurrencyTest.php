<?php

declare(strict_types=1);

namespace Tests\Feature;

use DomainException;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Security\Models\SecurityEvent;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Models\TenantMembership;
use MaaleArna\MASES\Core\Tenancy\Services\TenantMembershipService;
use RuntimeException;
use Throwable;
use Tests\TestCase;

/**
 * Exercises simultaneous lifecycle transitions against real independent
 * MySQL connections and InnoDB row locks. The fixture is committed before
 * workers are forked; no shared PDO handle is used by either worker.
 */
final class TenantMembershipMySqlConcurrencyTest extends TestCase
{
    public function test_simultaneous_suspend_and_revoke_serialize_with_audited_state_chain(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql'
            || ! function_exists('pcntl_fork')
            || getenv('ARNA_RUN_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Requires the dedicated MySQL membership concurrency workflow and pcntl.');
        }

        $suffix = bin2hex(random_bytes(5));
        $tenant = Tenant::factory()->create([
            'slug' => 'membership-concurrency-'.$suffix,
            'name_en' => 'Membership Concurrency '.$suffix,
            'domain' => $suffix.'.membership-concurrency.test',
            'is_active' => true,
        ]);
        $member = User::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);

        $member->tenants()->attach($tenant->getKey(), [
            'joined_at' => now(),
            'status' => TenantMembership::STATUS_ACTIVE,
        ]);

        $releasePath = tempnam(sys_get_temp_dir(), 'arna-membership-release-');
        $readyPaths = [
            tempnam(sys_get_temp_dir(), 'arna-membership-ready-'),
            tempnam(sys_get_temp_dir(), 'arna-membership-ready-'),
        ];
        $resultPaths = [
            tempnam(sys_get_temp_dir(), 'arna-membership-result-'),
            tempnam(sys_get_temp_dir(), 'arna-membership-result-'),
        ];
        @unlink($releasePath);
        foreach (array_merge($readyPaths, $resultPaths) as $path) {
            @unlink($path);
        }

        $children = [];

        try {
            // Prevent child processes from inheriting a live parent PDO.
            DB::disconnect();

            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork a membership lifecycle worker.');
                }

                if ($pid === 0) {
                    try {
                        DB::purge();
                        $connectionId = (int) DB::selectOne(
                            'SELECT CONNECTION_ID() AS connection_id'
                        )->connection_id;
                        file_put_contents($readyPaths[$index], (string) $connectionId);

                        $deadline = microtime(true) + 25;
                        while (! is_file($releasePath) && microtime(true) < $deadline) {
                            usleep(10000);
                        }
                        if (! is_file($releasePath)) {
                            throw new RuntimeException('Timed out waiting for concurrent worker release.');
                        }

                        // Worker 0 suspends; worker 1 revokes. If suspension
                        // wins, revocation follows it. If revocation wins,
                        // suspension must be rejected because revoked is terminal.
                        if ($index === 0) {
                            app(TenantMembershipService::class)->suspend(
                                (int) $tenant->getKey(),
                                (int) $member->getKey(),
                                (int) $actor->getKey(),
                                'Concurrent suspension regression',
                            );
                        } else {
                            app(TenantMembershipService::class)->revoke(
                                (int) $tenant->getKey(),
                                (int) $member->getKey(),
                                (int) $actor->getKey(),
                                'Concurrent revocation regression',
                            );
                        }

                        file_put_contents($resultPaths[$index], 'success');
                        exit(0);
                    } catch (DomainException $exception) {
                        file_put_contents(
                            $resultPaths[$index],
                            'rejected:'.get_class($exception),
                        );
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents(
                            $resultPaths[$index],
                            'error:'.get_class($exception).':'.$exception->getMessage(),
                        );
                        exit(2);
                    }
                }

                $children[] = $pid;
            }

            // Both workers must hold distinct live MySQL sessions before they
            // are released together; this is not a sequential unit-test proxy.
            $deadline = microtime(true) + 25;
            while (microtime(true) < $deadline
                && (! is_file($readyPaths[0]) || ! is_file($readyPaths[1]))) {
                usleep(10000);
            }

            $this->assertFileExists($readyPaths[0], 'First worker did not establish its MySQL connection.');
            $this->assertFileExists($readyPaths[1], 'Second worker did not establish its MySQL connection.');
            $connectionIds = [
                trim((string) file_get_contents($readyPaths[0])),
                trim((string) file_get_contents($readyPaths[1])),
            ];
            $this->assertNotSame($connectionIds[0], $connectionIds[1], 'Workers must use independent MySQL connections.');

            file_put_contents($releasePath, 'go');

            $exitCodes = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $exitCodes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 99;
            }

            $this->assertSame([0, 0], $exitCodes, 'A worker encountered an unexpected infrastructure or database error.');
            $outcomes = [
                trim((string) file_get_contents($resultPaths[0])),
                trim((string) file_get_contents($resultPaths[1])),
            ];

            $this->assertSame('success', $outcomes[1], 'Revocation must succeed whether it runs before or after suspension.');
            $this->assertTrue(
                $outcomes[0] === 'success' || str_starts_with($outcomes[0], 'rejected:'.DomainException::class),
                'Suspension may succeed before revocation or be rejected after terminal revocation: '.json_encode($outcomes),
            );

            DB::purge();

            $this->assertDatabaseHas('tenant_memberships', [
                'tenant_id' => $tenant->getKey(),
                'user_id' => $member->getKey(),
                'status' => TenantMembership::STATUS_REVOKED,
            ]);

            $events = SecurityEvent::query()
                ->where('event_type', 'tenant.membership.status_changed')
                ->where('tenant_id', $tenant->getKey())
                ->where('user_id', $actor->getKey())
                ->orderBy('id')
                ->get();

            $this->assertContains($events->count(), [1, 2], 'Only committed lifecycle transitions may have durable events.');

            $expectedTransitions = $events->count() === 1
                ? [[TenantMembership::STATUS_ACTIVE, TenantMembership::STATUS_REVOKED]]
                : [
                    [TenantMembership::STATUS_ACTIVE, TenantMembership::STATUS_SUSPENDED],
                    [TenantMembership::STATUS_SUSPENDED, TenantMembership::STATUS_REVOKED],
                ];

            foreach ($events as $index => $event) {
                $data = $event->event_data;
                $this->assertSame($expectedTransitions[$index][0], $data['previous_status']);
                $this->assertSame($expectedTransitions[$index][1], $data['new_status']);
                $this->assertSame((int) $actor->getKey(), (int) $data['actor_user_id']);
                $this->assertSame((int) $member->getKey(), (int) $data['member_user_id']);
            }
        } finally {
            // Always release and reap workers, including when a parent-side
            // assertion fails before the normal barrier release.
            if (! is_file($releasePath)) {
                file_put_contents($releasePath, 'abort');
            }
            foreach ($children as $pid) {
                $waited = pcntl_waitpid($pid, $status, WNOHANG);
                if ($waited === 0) {
                    pcntl_waitpid($pid, $status);
                }
            }
            DB::purge();
            @unlink($releasePath);
            foreach (array_merge($readyPaths, $resultPaths) as $path) {
                @unlink($path);
            }
        }
    }
}
