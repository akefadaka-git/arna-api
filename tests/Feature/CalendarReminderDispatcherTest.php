<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Calendar\Services\CalendarReminderDispatcher;
use MaaleArna\MASES\Core\Calendar\Services\CalendarService;
use MaaleArna\MASES\Core\Notifications\Services\NotificationService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Models\TenantMembership;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class CalendarReminderDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createDueReminder(): array
    {
        Carbon::setTestNow('2026-11-01 06:00:00');
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $recipient = User::factory()->create(['is_active' => true]);
        $recipient->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);

        $context = app(TenantContextService::class);
        $context->setTenantById($tenant->id);
        $calendar = app(CalendarService::class)->createCalendar($recipient, ['name' => 'Operations']);
        $event = app(CalendarService::class)->createEvent($recipient, [
            'calendar_id' => $calendar->id,
            'title' => 'Board preparation',
            'starts_at' => '2026-11-01T06:20:00+00:00',
            'ends_at' => '2026-11-01T06:50:00+00:00',
            'timezone' => 'Africa/Addis_Ababa',
            'attendee_user_ids' => [$recipient->id],
        ]);

        Carbon::setTestNow('2026-11-01 06:10:00');

        return [$tenant, $recipient, $event];
    }

    public function test_due_reminder_is_delivered_once_and_outbox_is_acknowledged_atomically(): void
    {
        [, , $event] = $this->createDueReminder();

        $result = app(CalendarReminderDispatcher::class)->dispatch();

        $this->assertSame(['delivered' => 1, 'skipped' => 0, 'retried' => 0, 'failed' => 0], $result);
        $this->assertDatabaseHas('calendar_reminder_outbox', [
            'event_id' => $event->id,
            'status' => 'delivered',
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('platform_notifications', [
            'event_key' => 'calendar.event.reminder',
            'category' => 'calendar',
            'dedupe_key' => DB::table('calendar_reminder_outbox')->where('event_id', $event->id)->value('id')
                ? 'calendar-reminder:'.DB::table('calendar_reminder_outbox')->where('event_id', $event->id)->value('id')
                : null,
        ]);

        $again = app(CalendarReminderDispatcher::class)->dispatch();
        $this->assertSame(0, $again['delivered']);
        $this->assertSame(1, DB::table('platform_notifications')->where('event_key', 'calendar.event.reminder')->count());
    }

    public function test_failed_delivery_is_retried_with_backoff_and_then_delivered_idempotently(): void
    {
        [, , $event] = $this->createDueReminder();
        $failNotificationInsert = true;
        DB::listen(static function (QueryExecuted $query) use (&$failNotificationInsert): void {
            $sql = strtolower(ltrim($query->sql));
            if ($failNotificationInsert && str_starts_with($sql, 'insert') && str_contains($sql, 'platform_notifications')) {
                throw new RuntimeException('temporary notification store failure');
            }
        });

        $first = app(CalendarReminderDispatcher::class)->dispatch();
        $failNotificationInsert = false;
        $this->assertSame(1, $first['retried']);
        $this->assertDatabaseHas('calendar_reminder_outbox', ['event_id' => $event->id, 'status' => 'pending', 'attempts' => 1]);

        Carbon::setTestNow('2026-11-01 06:12:00');
        $notifications = new NotificationService(app(TenantContextService::class));
        $dispatcher = new CalendarReminderDispatcher($notifications);
        $second = $dispatcher->dispatch();

        $this->assertSame(1, $second['delivered']);
        $this->assertDatabaseHas('calendar_reminder_outbox', ['event_id' => $event->id, 'status' => 'delivered', 'attempts' => 2]);
        $this->assertSame(1, DB::table('platform_notifications')->where('event_key', 'calendar.event.reminder')->count());
    }
}
