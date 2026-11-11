<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Calendar\Services\CalendarService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CalendarReservationConcurrencyTest extends TestCase
{
    #[Test]
    public function independent_connections_cannot_confirm_overlapping_reservations(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql'
            || ! function_exists('pcntl_fork')
            || getenv('ARNA_RUN_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Requires the dedicated MySQL multi-process concurrency workflow.');
        }

        $suffix = bin2hex(random_bytes(6));
        $tenant = Tenant::factory()->create([
            'name_en' => 'Calendar Reservation Race '.$suffix,
            'slug' => 'calendar-reservation-race-'.$suffix,
            'domain' => $suffix.'.calendar-race.test',
            'is_active' => true,
        ]);
        $actor = User::factory()->create([
            'is_active' => true,
            'default_tenant_id' => $tenant->getKey(),
        ]);
        $context = app(TenantContextService::class);
        $context->setTenantById($tenant->getKey());
        $service = app(CalendarService::class);
        $calendar = $service->createCalendar($actor, ['name' => 'Race test']);
        $eventA = $service->createEvent($actor, [
            'calendar_id' => $calendar->id, 'title' => 'Reservation A',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ]);
        $eventB = $service->createEvent($actor, [
            'calendar_id' => $calendar->id, 'title' => 'Reservation B',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ]);
        $resourceId = (string) Str::uuid();
        DB::table('calendar_resources')->insert([
            'id' => $resourceId, 'tenant_id' => $tenant->getKey(), 'name' => 'Race Room '.$suffix,
            'resource_type' => 'room', 'capacity' => 8, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $release = tempnam(sys_get_temp_dir(), 'arna-calendar-release-');
        $resultPaths = [
            tempnam(sys_get_temp_dir(), 'arna-calendar-result-'),
            tempnam(sys_get_temp_dir(), 'arna-calendar-result-'),
        ];
        @unlink($release);
        foreach ($resultPaths as $path) @unlink($path);
        DB::disconnect();
        $children = [];

        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Unable to fork Calendar reservation worker.');
                }
                if ($pid === 0) {
                    DB::purge();
                    $deadline = microtime(true) + 10;
                    while (!file_exists($release) && microtime(true) < $deadline) usleep(10000);
                    if (!file_exists($release)) {
                        file_put_contents($resultPaths[$index], 'error:release-timeout');
                        exit(2);
                    }
                    try {
                        $workerContext = app(TenantContextService::class);
                        $workerContext->setTenantById($tenant->getKey());
                        $worker = app(CalendarService::class);
                        $worker->reserveResource($actor, [
                            'resource_id' => $resourceId,
                            'event_id' => $index === 0 ? $eventA->id : $eventB->id,
                            'starts_at' => '2026-11-02T10:00:00+03:00',
                            'ends_at' => '2026-11-02T11:00:00+03:00',
                        ]);
                        file_put_contents($resultPaths[$index], 'confirmed');
                        exit(0);
                    } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
                        file_put_contents($resultPaths[$index], 'conflict');
                        exit(0);
                    } catch (\Throwable $exception) {
                        file_put_contents($resultPaths[$index], 'error:'.$exception::class.':'.$exception->getMessage());
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
                $resultPaths,
            );
            $this->assertSame([0, 0], $exitCodes, 'Workers must complete without infrastructure errors: '.json_encode($outcomes));
            $this->assertCount(1, array_filter($outcomes, static fn (string $v): bool => $v === 'confirmed'));
            $this->assertCount(1, array_filter($outcomes, static fn (string $v): bool => $v === 'conflict'));
            $this->assertSame(1, DB::table('calendar_reservations')
                ->where('tenant_id', $tenant->getKey())->where('resource_id', $resourceId)
                ->where('status', 'confirmed')->count());
        } finally {
            @unlink($release);
            foreach ($resultPaths as $path) @unlink($path);
            DB::purge();
        }
    }
}
