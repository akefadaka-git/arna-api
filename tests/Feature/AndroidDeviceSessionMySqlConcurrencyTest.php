<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Illuminate\\Support\\Facades\\DB;
use Laravel\\Sanctum\\PersonalAccessToken;
use MaaleArna\\MASES\\Core\\Auth\\Models\\DeviceSession;
use MaaleArna\\MASES\\Core\\Auth\\Models\\User;
use MaaleArna\\MASES\\Core\\Auth\\Services\\DeviceSessionService;
use MaaleArna\\MASES\\Core\\Tenancy\\Models\\Tenant;
use RuntimeException;
use Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException;
use Tests\\TestCase;
use Throwable;

/**
 * Real InnoDB race test: two independent processes refresh the same bearer token.
 * Exactly one refresh may rotate the session; the stale concurrent request must fail closed.
 */
final class AndroidDeviceSessionMySqlConcurrencyTest extends TestCase
{
    public function test_parallel_refresh_requests_have_exactly_one_winner(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql'
            || ! function_exists('pcntl_fork')
            || getenv('ARNA_RUN_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Requires the dedicated MySQL concurrency workflow and pcntl.');
        }

        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $user->tenants()->attach($tenant->getKey(), ['joined_at' => now(), 'status' => 'active']);

        $expiresAt = now()->addMinutes(30);
        $issued = $user->createToken(
            DeviceSessionService::MOBILE_TOKEN_NAME,
            [DeviceSessionService::MOBILE_ABILITY],
            $expiresAt,
        );
        $oldTokenId = (int) $issued->accessToken->getKey();

        $session = new DeviceSession();
        $session->public_id = (string) \\Illuminate\\Support\\Str::uuid();
        $session->tenant_id = $tenant->getKey();
        $session->installation_hash = app(DeviceSessionService::class)->installationHash('parallel-refresh-installation');
        $session->platform = 'android';
        $session->last_seen_at = now();
        $session->expires_at = $expiresAt;
        $session->user()->associate($user);
        $session->personal_access_token_id = $oldTokenId;
        $session->save();

        $releasePath = tempnam(sys_get_temp_dir(), 'arna-device-refresh-release-');
        $readyPaths = [
            tempnam(sys_get_temp_dir(), 'arna-device-refresh-ready-'),
            tempnam(sys_get_temp_dir(), 'arna-device-refresh-ready-'),
        ];
        $resultPaths = [
            tempnam(sys_get_temp_dir(), 'arna-device-refresh-result-'),
            tempnam(sys_get_temp_dir(), 'arna-device-refresh-result-'),
        ];
        @unlink($releasePath);
        foreach (array_merge($readyPaths, $resultPaths) as $path) {
            @unlink($path);
        }

        $children = [];
        DB::disconnect();

        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork Android refresh concurrency worker.');
                }

                if ($pid === 0) {
                    try {
                        // Each process opens a fresh PDO connection after fork.
                        DB::purge();
                        $workerUser = User::query()->findOrFail((int) $user->getKey());
                        $workerToken = PersonalAccessToken::query()->findOrFail($oldTokenId);
                        $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
                        file_put_contents($readyPaths[$index], (string) $connectionId);

                        $deadline = microtime(true) + 20;
                        while (! is_file($releasePath) && microtime(true) < $deadline) {
                            usleep(10000);
                        }
                        if (! is_file($releasePath)) {
                            throw new RuntimeException('Timed out waiting for concurrent refresh release.');
                        }

                        app(DeviceSessionService::class)->refresh($workerUser, $workerToken);
                        file_put_contents($resultPaths[$index], 'success');
                        exit(0);
                    } catch (AccessDeniedHttpException) {
                        file_put_contents($resultPaths[$index], 'rejected');
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents(
                            $resultPaths[$index],
                            'error:'.$exception::class.':'.$exception->getMessage(),
                        );
                        exit(1);
                    }
                }

                $children[] = $pid;
            }

            $readyDeadline = microtime(true) + 20;
            while ((! is_file($readyPaths[0]) || ! is_file($readyPaths[1]))
                && microtime(true) < $readyDeadline) {
                usleep(10000);
            }
            $this->assertFileExists($readyPaths[0], 'First refresh worker did not become ready.');
            $this->assertFileExists($readyPaths[1], 'Second refresh worker did not become ready.');

            $connectionIds = [
                trim((string) file_get_contents($readyPaths[0])),
                trim((string) file_get_contents($readyPaths[1])),
            ];
            $this->assertNotSame(
                $connectionIds[0],
                $connectionIds[1],
                'Refresh workers must use distinct MySQL server connections.',
            );

            file_put_contents($releasePath, 'go');
            $exitCodes = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $exitCodes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 99;
            }
            $outcomes = array_map(
                static fn (string $path): string => is_file($path)
                    ? trim((string) file_get_contents($path))
                    : 'missing-result',
                $resultPaths,
            );

            $this->assertSame([0, 0], $exitCodes, 'Both workers must finish without infrastructure errors: '.json_encode($outcomes));
            $this->assertCount(1, array_filter($outcomes, static fn (string $value): bool => $value === 'success'));
            $this->assertCount(1, array_filter($outcomes, static fn (string $value): bool => $value === 'rejected'));

            DB::purge();
            $freshSession = DeviceSession::query()->whereKey($session->getKey())->firstOrFail();
            $this->assertNotNull($freshSession->personal_access_token_id);
            $this->assertNotSame($oldTokenId, (int) $freshSession->personal_access_token_id);
            $this->assertNull($freshSession->revoked_at);
            $this->assertSame(
                1,
                PersonalAccessToken::query()
                    ->where('tokenable_type', $user->getMorphClass())
                    ->where('tokenable_id', $user->getKey())
                    ->where('name', DeviceSessionService::MOBILE_TOKEN_NAME)
                    ->count(),
                'Exactly one current Android bearer token must remain for this session.',
            );
            $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldTokenId]);
        } finally {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }
            DB::purge();
            DB::table('auth_device_sessions')->where('id', $session->getKey())->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->getKey())->delete();
            DB::table('tenant_memberships')->where('tenant_id', $tenant->getKey())
                ->where('user_id', $user->getKey())->delete();
            DB::table('users')->where('id', $user->getKey())->delete();
            DB::table('tenants')->where('id', $tenant->getKey())->delete();

            @unlink($releasePath);
            foreach (array_merge($readyPaths, $resultPaths) as $path) {
                @unlink($path);
            }
        }
    }
}
