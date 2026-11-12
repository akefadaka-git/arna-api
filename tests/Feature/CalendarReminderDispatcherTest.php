<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Database\Events\QueryExecuted;
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

    public function test_due_reminder_is_skipped_when_tenant_is_inactive(): void
    {
        [$tenant, , $event] = $this->createDueReminder();
        $tenant->forceFill(['is_active' => false])->save();

        $result = app(CalendarReminderDispatcher::class)->dispatch();

        $this->assertSame(['delivered' => 0, 'skipped' => 1, 'retried' => 0, 'failed' => 0], $result);
        $this->assertDatabaseHas('calendar_reminder_outbox', [
            'event_id' => $event->id,
            'status' => 'skipped',
            'last_error' => 'Tenant is inactive; reminder delivery is prohibited.',
        ]);
        $this->assertSame(0, DB::table('platform_notifications')->where('event_key', 'calendar.event.reminder')->count());
    }

    public function test_due_reminder_is_skipped_when_recipient_account_is_inactive(): void
    {
        [, $recipient, $event] = $this->createDueReminder();
        $recipient->forceFill(['is_active' => false])->save();

        $result = app(CalendarReminderDispatcher::class)->dispatch();

        $this->assertSame(['delivered' => 0, 'skipped' => 1, 'retried' => 0, 'failed' => 0], $result);
        $this->assertDatabaseHas('calendar_reminder_outbox', [
            'event_id' => $event->id,
            'status' => 'skipped',
            'last_error' => 'Recipient account is inactive; reminder delivery is prohibited.',
        ]);
        $this->assertSame(0, DB::table('platform_notifications')->where('event_key', 'calendar.event.reminder')->count());
    }

    public function test_due_all_day_reminder_uses_event_timezone_midnight(): void
    {
        Carbon::setTestNow('2026-10-31 20:50:00');
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $recipient = User::factory()->create(['is_active' => true]);
        $recipient->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);

        app(TenantContextService::class)->setTenantById($tenant->id);
        $calendar = app(CalendarService::class)->createCalendar($recipient, ['name' => 'All-day observances']);
        $event = app(CalendarService::class)->createEvent($recipient, [
            'calendar_id' => $calendar->id,
            'title' => 'New year',
            'all_day' => true,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-01',
            'timezone' => 'Africa/Addis_Ababa',
            'attendee_user_ids' => [$recipient->id],
            'reminders' => [15],
        ]);

        $this->assertSame('2026-10-31 20:45:00', DB::table('calendar_reminder_outbox')
            ->where('event_id', $event->id)->value('deliver_at'));

        $result = app(CalendarReminderDispatcher::class)->dispatch();

        $this->assertSame(['delivered' => 1, 'skipped' => 0, 'retried' => 0, 'failed' => 0], $result);
        $this->assertDatabaseHas('calendar_reminder_outbox', ['event_id' => $event->id, 'status' => 'delivered']);
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
        $dispatcher = new CalendarReminderDispatcher($notifications, app(CalendarService::class), app(TenantContextService::class));
        $second = $dispatcher->dispatch();

        $this->assertSame(1, $second['delivered']);
        $this->assertDatabaseHas('calendar_reminder_outbox', ['event_id' => $event->id, 'status' => 'delivered', 'attempts' => 2]);
        $this->assertSame(1, DB::table('platform_notifications')->where('event_key', 'calendar.event.reminder')->count());
    }
    public function test_recurring_series_materializes_idempotent_reminders_for_future_instances(): void
    {
        Carbon::setTestNow('2026-11-01 06:00:00');
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $recipient = User::factory()->create(['is_active' => true]);
        $recipient->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);
        app(TenantContextService::class)->setTenantById($tenant->id);
        $calendar = app(CalendarService::class)->createCalendar($recipient, ['name' => 'Recurring operations']);
        $event = app(CalendarService::class)->createEvent($recipient, [
            'calendar_id' => $calendar->id,
            'title' => 'Daily stand-up',
            'starts_at' => '2026-11-01T07:00:00+00:00',
            'ends_at' => '2026-11-01T07:30:00+00:00',
            'timezone' => 'Africa/Addis_Ababa',
            'recurrence_rule' => ['frequency' => 'daily', 'interval' => 1, 'count' => 3],
            'attendee_user_ids' => [$recipient->id],
            'reminders' => [15],
        ]);

        $first = app(CalendarReminderDispatcher::class)->dispatch();
        $this->assertSame(0, $first['delivered']);
        $rows = DB::table('calendar_reminder_outbox')->where('event_id', $event->id)->orderBy('occurrence_index')->get();
        $this->assertCount(3, $rows);
        $this->assertSame([0, 1, 2], $rows->pluck('occurrence_index')->map(fn ($value) => (int) $value)->all());
        $this->assertSame('2026-11-02 06:45:00', $rows[1]->deliver_at);
        $this->assertSame('2026-11-03 06:45:00', $rows[2]->deliver_at);
        $this->assertSame(
            $event->id.':'.$recipient->id.':event_start_15m:occurrence:2026-11-02 07:00:00',
            $rows[1]->idempotency_key,
        );
        $this->assertNotSame($rows[1]->idempotency_key, $rows[2]->idempotency_key);

        app(CalendarReminderDispatcher::class)->dispatch();
        $this->assertSame(3, DB::table('calendar_reminder_outbox')->where('event_id', $event->id)->count());
    }

    public function test_recurring_reminder_tracks_moved_occurrence_and_cancellation(): void
    {
        Carbon::setTestNow('2026-11-01 06:00:00');
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $recipient = User::factory()->create(['is_active' => true]);
        $recipient->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);
        app(TenantContextService::class)->setTenantById($tenant->id);
        $service = app(CalendarService::class);
        $calendar = $service->createCalendar($recipient, ['name' => 'Recurring changes']);
        $event = $service->createEvent($recipient, [
            'calendar_id' => $calendar->id,
            'title' => 'Recurring review',
            'starts_at' => '2026-11-01T07:00:00+00:00',
            'ends_at' => '2026-11-01T07:30:00+00:00',
            'timezone' => 'Africa/Addis_Ababa',
            'recurrence_rule' => ['frequency' => 'daily', 'interval' => 1, 'count' => 3],
            'attendee_user_ids' => [$recipient->id],
            'reminders' => [15],
        ]);

        $service->moveOccurrence(
            $recipient,
            $event->id,
            '2026-11-01T07:00:00+00:00',
            '2026-11-01T07:30:00+00:00',
            '2026-11-01T08:00:00+00:00',
        );
        $service->moveOccurrence(
            $recipient,
            $event->id,
            '2026-11-02T07:00:00+00:00',
            '2026-11-02T08:00:00+00:00',
            '2026-11-02T08:30:00+00:00',
        );
        $service->cancelOccurrence($recipient, $event->id, '2026-11-03T07:00:00+00:00');

        app(CalendarReminderDispatcher::class)->dispatch();
        $firstOccurrence = DB::table('calendar_reminder_outbox')
            ->where('event_id', $event->id)->where('occurrence_index', 0)->first();
        $this->assertSame('2026-11-01 07:30:00', $firstOccurrence->occurrence_starts_at);
        $this->assertSame('2026-11-01 07:15:00', $firstOccurrence->deliver_at);

        $moved = DB::table('calendar_reminder_outbox')
            ->where('event_id', $event->id)->where('occurrence_index', 1)->first();
        $this->assertNotNull($moved);
        $this->assertSame('2026-11-02 08:00:00', $moved->occurrence_starts_at);
        $this->assertSame('2026-11-02 07:45:00', $moved->deliver_at);
        $this->assertDatabaseMissing('calendar_reminder_outbox', [
            'event_id' => $event->id,
            'occurrence_index' => 2,
        ]);
    }

    public function test_old_timed_recurring_series_materializes_only_the_current_rolling_horizon_idempotently(): void
    {
        Carbon::setTestNow('2026-11-01 06:00:00');
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $recipient = User::factory()->create(['is_active' => true]);
        $recipient->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);
        app(TenantContextService::class)->setTenantById($tenant->id);
        $calendar = app(CalendarService::class)->createCalendar($recipient, ['name' => 'Older timed series']);
        $event = app(CalendarService::class)->createEvent($recipient, [
            'calendar_id' => $calendar->id,
            'title' => 'Long-running timed series',
            'starts_at' => '2026-07-01T07:00:00+00:00',
            'ends_at' => '2026-07-01T07:30:00+00:00',
            'timezone' => 'Africa/Addis_Ababa',
            'recurrence_rule' => ['frequency' => 'daily', 'interval' => 1, 'count' => 200],
            'attendee_user_ids' => [$recipient->id],
            'reminders' => [15],
        ]);

        $first = app(CalendarReminderDispatcher::class)->dispatch();
        $this->assertSame(0, $first['failed']);
        $this->assertDatabaseHas('calendar_reminder_outbox', [
            'event_id' => $event->id,
            'occurrence_index' => 123,
            'occurrence_key' => '2026-11-01 07:00:00',
            'status' => 'pending',
        ]);
        $before = DB::table('calendar_reminder_outbox')->where('event_id', $event->id)->count();

        app(CalendarReminderDispatcher::class)->dispatch();

        $this->assertSame($before, DB::table('calendar_reminder_outbox')->where('event_id', $event->id)->count());
    }

    public function test_old_all_day_recurring_series_materializes_only_the_current_rolling_horizon_idempotently(): void
    {
        Carbon::setTestNow('2026-11-01 06:00:00');
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $recipient = User::factory()->create(['is_active' => true]);
        $recipient->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);
        app(TenantContextService::class)->setTenantById($tenant->id);
        $calendar = app(CalendarService::class)->createCalendar($recipient, ['name' => 'Older all-day series']);
        $event = app(CalendarService::class)->createEvent($recipient, [
            'calendar_id' => $calendar->id,
            'title' => 'Long-running all-day series',
            'all_day' => true,
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-01',
            'timezone' => 'Africa/Addis_Ababa',
            'recurrence_rule' => ['frequency' => 'daily', 'interval' => 1, 'count' => 200],
            'attendee_user_ids' => [$recipient->id],
            'reminders' => [15],
        ]);

        $first = app(CalendarReminderDispatcher::class)->dispatch();
        $this->assertSame(0, $first['failed']);
        $this->assertDatabaseHas('calendar_reminder_outbox', [
            'event_id' => $event->id,
            'occurrence_index' => 124,
            'occurrence_key' => '2026-11-02 00:00:00',
            'status' => 'pending',
        ]);
        $before = DB::table('calendar_reminder_outbox')->where('event_id', $event->id)->count();

        app(CalendarReminderDispatcher::class)->dispatch();

        $this->assertSame($before, DB::table('calendar_reminder_outbox')->where('event_id', $event->id)->count());
    }

    public function test_recurring_materialization_failure_is_counted_in_dispatch_result(): void
    {
        Carbon::setTestNow('2026-11-01 06:00:00');
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $recipient = User::factory()->create(['is_active' => true]);
        $recipient->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);
        app(TenantContextService::class)->setTenantById($tenant->id);
        $calendar = app(CalendarService::class)->createCalendar($recipient, ['name' => 'Failure reporting']);
        app(CalendarService::class)->createEvent($recipient, [
            'calendar_id' => $calendar->id,
            'title' => 'Recurring failure reporting',
            'starts_at' => '2026-07-01T07:00:00+00:00',
            'ends_at' => '2026-07-01T07:30:00+00:00',
            'timezone' => 'Africa/Addis_Ababa',
            'recurrence_rule' => ['frequency' => 'daily', 'interval' => 1, 'count' => 200],
            'attendee_user_ids' => [$recipient->id],
            'reminders' => [15],
        ]);

        $calendarService = Mockery::mock(CalendarService::class);
        $calendarService->shouldReceive('recurringOccurrences')
            ->once()
            ->andThrow(new RuntimeException('simulated recurrence expansion failure'));
        $context = app(TenantContextService::class);
        $dispatcher = new CalendarReminderDispatcher(
            new NotificationService($context),
            $calendarService,
            $context,
        );

        $result = $dispatcher->dispatch();

        $this->assertSame(1, $result['failed']);
    }

}
