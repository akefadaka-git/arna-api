<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Calendar\Services\CalendarService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class CalendarLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function service(Tenant $tenant): CalendarService
    {
        $context = app(TenantContextService::class);
        $context->setTenantById($tenant->id);
        return app(CalendarService::class);
    }

    private function tenant(): Tenant
    {
        return Tenant::factory()->create(['is_active' => true]);
    }

    #[Test]
    public function private_calendars_are_visible_only_to_their_owner(): void
    {
        $tenant = $this->tenant();
        $owner = User::factory()->create(['is_active' => true]);
        $other = User::factory()->create(['is_active' => true]);
        $service = $this->service($tenant);
        $private = $service->createCalendar($owner, ['name' => 'Private', 'visibility' => 'private']);
        $shared = $service->createCalendar($owner, ['name' => 'Shared', 'visibility' => 'tenant']);

        $ownerIds = collect($service->calendars($owner)->items())->pluck('id')->all();
        $otherIds = collect($service->calendars($other)->items())->pluck('id')->all();

        $this->assertContains($private->id, $ownerIds);
        $this->assertNotContains($private->id, $otherIds);
        $this->assertContains($shared->id, $otherIds);
    }

    #[Test]
    public function calendar_owner_can_update_metadata_with_optimistic_versioning(): void
    {
        $tenant = $this->tenant();
        $owner = User::factory()->create(['is_active' => true]);
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($owner, ['name' => 'Initial name']);

        $updated = $service->updateCalendar($owner, $calendar->id, [
            'version' => 1,
            'name' => 'Board calendar',
            'visibility' => 'private',
            'timezone' => 'Africa/Addis_Ababa',
        ]);

        $this->assertSame('Board calendar', $updated->name);
        $this->assertSame('private', $updated->visibility);
        $this->assertSame(2, $updated->version);
        $this->assertDatabaseHas('calendar_calendars', [
            'id' => $calendar->id,
            'name' => 'Board calendar',
            'version' => 2,
        ]);
    }

    #[Test]
    public function calendar_update_rejects_non_owner_and_stale_versions(): void
    {
        $tenant = $this->tenant();
        $owner = User::factory()->create(['is_active' => true]);
        $other = User::factory()->create(['is_active' => true]);
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($owner, ['name' => 'Owner calendar']);

        try {
            $service->updateCalendar($other, $calendar->id, ['version' => 1, 'name' => 'Intrusion']);
            $this->fail('A non-owner must not update a calendar.');
        } catch (NotFoundHttpException) {
            $this->assertSame('Owner calendar', $calendar->fresh()->name);
        }

        $service->updateCalendar($owner, $calendar->id, ['version' => 1, 'name' => 'Updated']);
        $this->expectException(\Symfony\\Component\\HttpKernel\\Exception\\ConflictHttpException::class);
        $service->updateCalendar($owner, $calendar->id, ['version' => 1, 'name' => 'Stale update']);
    }

    #[Test]
    public function another_user_cannot_create_an_event_in_a_private_calendar(): void
    {
        $tenant = $this->tenant();
        $owner = User::factory()->create(['is_active' => true]);
        $other = User::factory()->create(['is_active' => true]);
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($owner, ['name' => 'Private', 'visibility' => 'private']);

        $this->expectException(NotFoundHttpException::class);
        $service->createEvent($other, [
            'calendar_id' => $calendar->id,
            'title' => 'Unauthorized',
            'starts_at' => '2026-11-01T10:00:00+03:00',
            'ends_at' => '2026-11-01T11:00:00+03:00',
        ]);
    }

    #[Test]
    public function resource_administration_is_tenant_scoped(): void
    {
        $tenantA = $this->tenant();
        $tenantB = $this->tenant();
        $actor = User::factory()->create(['is_active' => true]);
        $serviceA = $this->service($tenantA);
        $resource = $serviceA->createResource($actor, ['name' => 'Board Room', 'resource_type' => 'room', 'capacity' => 12]);

        $serviceB = $this->service($tenantB);
        $this->expectException(NotFoundHttpException::class);
        $serviceB->updateResource($actor, $resource->id, ['is_active' => false]);
    }

    #[Test]
    public function domain_links_are_persisted_as_opaque_tenant_scoped_references_only(): void
    {
        $tenant = $this->tenant();
        $actor = User::factory()->create(['is_active' => true]);
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($actor, ['name' => 'Linked agenda']);

        $event = $service->createEvent($actor, [
            'calendar_id' => $calendar->id,
            'title' => 'Governance meeting agenda',
            'starts_at' => '2026-11-01T10:00:00+03:00',
            'ends_at' => '2026-11-01T11:00:00+03:00',
            'links' => [[
                'domain' => 'governance',
                'resource_type' => 'meeting',
                'resource_id' => 'opaque-meeting-reference',
                'relationship' => 'agenda',
            ]],
        ]);

        $this->assertCount(1, $event->links);
        $this->assertSame('governance', $event->links[0]->domain);
        $this->assertSame('opaque-meeting-reference', $event->links[0]->resource_id);
        $this->assertSame('agenda', $event->links[0]->relationship);
        $this->assertDatabaseHas('calendar_event_links', [
            'tenant_id' => $tenant->id,
            'event_id' => $event->id,
            'resource_id' => 'opaque-meeting-reference',
        ]);
    }

    #[Test]
    public function recurrence_expansion_is_bounded_and_occurrence_cancellation_is_applied(): void
    {
        $tenant = $this->tenant();
        $actor = User::factory()->create(['is_active' => true]);
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($actor, ['name' => 'Operations']);
        $event = $service->createEvent($actor, [
            'calendar_id' => $calendar->id,
            'title' => 'Daily briefing',
            'starts_at' => '2026-11-01T10:00:00+03:00',
            'ends_at' => '2026-11-01T10:30:00+03:00',
            'timezone' => 'Africa/Addis_Ababa',
            'recurrence_rule' => ['frequency' => 'daily', 'interval' => 1, 'count' => 3],
        ]);

        $before = $service->recurringOccurrences($actor, $event->id, '2026-11-01T00:00:00+03:00', '2026-11-04T00:00:00+03:00');
        $this->assertCount(3, $before);

        $service->cancelOccurrence($actor, $event->id, '2026-11-01T10:00:00+03:00');
        $after = $service->recurringOccurrences($actor, $event->id, '2026-11-01T00:00:00+03:00', '2026-11-04T00:00:00+03:00');
        $this->assertCount(2, $after);
    }

    #[Test]
    public function recurrence_occurrence_can_be_moved_without_changing_the_series(): void
    {
        $tenant = $this->tenant();
        $actor = User::factory()->create(['is_active' => true]);
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($actor, ['name' => 'Operations']);
        $event = $service->createEvent($actor, [
            'calendar_id' => $calendar->id,
            'title' => 'Daily briefing',
            'starts_at' => '2026-11-01T10:00:00+03:00',
            'ends_at' => '2026-11-01T10:30:00+03:00',
            'timezone' => 'Africa/Addis_Ababa',
            'recurrence_rule' => ['frequency' => 'daily', 'interval' => 1, 'count' => 3],
        ]);

        $service->moveOccurrence(
            $actor,
            $event->id,
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T12:00:00+03:00',
            '2026-11-02T12:30:00+03:00',
        );

        $occurrences = $service->recurringOccurrences(
            $actor,
            $event->id,
            '2026-11-01T00:00:00+03:00',
            '2026-11-04T00:00:00+03:00',
        );

        $this->assertCount(3, $occurrences);
        $this->assertSame('2026-11-02T12:00:00+03:00', $occurrences[1]['starts_at']);
        $this->assertSame('2026-11-02T12:30:00+03:00', $occurrences[1]['ends_at']);
        $this->assertDatabaseHas('calendar_recurrence_exceptions', [
            'event_id' => $event->id,
            'status' => 'overridden',
        ]);
    }

    #[Test]
    public function all_day_recurrence_expands_and_supports_date_only_cancellation_and_overrides(): void
    {
        $tenant = $this->tenant();
        $actor = User::factory()->create(['is_active' => true]);
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($actor, ['name' => 'All-day recurrence']);
        $event = $service->createEvent($actor, [
            'calendar_id' => $calendar->id,
            'title' => 'Multi-day observance',
            'all_day' => true,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-02',
            'timezone' => 'Africa/Addis_Ababa',
            'recurrence_rule' => ['frequency' => 'daily', 'interval' => 1, 'count' => 3],
        ]);

        $before = $service->recurringOccurrences($actor, $event->id, '2026-11-01', '2026-11-10');
        $this->assertCount(3, $before);
        $this->assertSame(['2026-11-01', '2026-11-02', '2026-11-03'], array_column($before, 'starts_on'));
        $this->assertSame(['2026-11-02', '2026-11-03', '2026-11-04'], array_column($before, 'ends_on'));

        $service->cancelOccurrence($actor, $event->id, '2026-11-02');
        $service->moveOccurrence($actor, $event->id, '2026-11-03', '2026-11-05', '2026-11-06');

        $after = $service->recurringOccurrences($actor, $event->id, '2026-11-01', '2026-11-10');
        $this->assertCount(2, $after);
        $this->assertSame(['2026-11-01', '2026-11-05'], array_column($after, 'starts_on'));
        $this->assertSame(['2026-11-02', '2026-11-06'], array_column($after, 'ends_on'));
        $this->assertTrue(collect($after)->every(fn (array $item): bool => $item['all_day'] === true));
        $this->assertDatabaseHas('calendar_recurrence_exceptions', [
            'event_id' => $event->id,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('calendar_recurrence_exceptions', [
            'event_id' => $event->id,
            'status' => 'overridden',
        ]);
    }

    #[Test]
    public function monthly_and_yearly_recurrence_clamp_short_month_and_leap_day_without_drift(): void
    {
        $tenant = $this->tenant();
        $actor = User::factory()->create(['is_active' => true]);
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($actor, ['name' => 'Temporal edge cases']);

        $monthly = $service->createEvent($actor, [
            'calendar_id' => $calendar->id,
            'title' => 'Month end',
            'starts_at' => '2027-01-31T10:00:00+03:00',
            'ends_at' => '2027-01-31T10:30:00+03:00',
            'timezone' => 'Africa/Addis_Ababa',
            'recurrence_rule' => ['frequency' => 'monthly', 'interval' => 1, 'count' => 4],
        ]);
        $monthlyItems = $service->recurringOccurrences(
            $actor, $monthly->id, '2027-01-31T00:00:00+03:00', '2027-04-30T23:59:59+03:00',
        );
        $this->assertSame([
            '2027-01-31T10:00:00+03:00',
            '2027-02-28T10:00:00+03:00',
            '2027-03-31T10:00:00+03:00',
            '2027-04-30T10:00:00+03:00',
        ], array_column($monthlyItems, 'starts_at'));

        $yearly = $service->createEvent($actor, [
            'calendar_id' => $calendar->id,
            'title' => 'Leap day',
            'starts_at' => '2024-02-29T10:00:00+03:00',
            'ends_at' => '2024-02-29T10:30:00+03:00',
            'timezone' => 'Africa/Addis_Ababa',
            'recurrence_rule' => ['frequency' => 'yearly', 'interval' => 1, 'count' => 3],
        ]);
        $yearlyItems = [];
        foreach ([2024, 2025, 2026] as $year) {
            $yearlyItems = array_merge($yearlyItems, $service->recurringOccurrences(
                $actor,
                $yearly->id,
                sprintf('%04d-02-01T00:00:00+03:00', $year),
                sprintf('%04d-03-31T23:59:59+03:00', $year),
            ));
        }
        $this->assertSame([
            '2024-02-29T10:00:00+03:00',
            '2025-02-28T10:00:00+03:00',
            '2026-02-28T10:00:00+03:00',
        ], array_column($yearlyItems, 'starts_at'));
    }

    #[Test]
    public function recurrence_expansion_honors_inclusive_until_date(): void
    {
        $tenant = $this->tenant();
        $actor = User::factory()->create(['is_active' => true]);
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($actor, ['name' => 'Bounded series']);
        $event = $service->createEvent($actor, [
            'calendar_id' => $calendar->id,
            'title' => 'Bounded daily briefing',
            'starts_at' => '2026-11-01T10:00:00+03:00',
            'ends_at' => '2026-11-01T10:30:00+03:00',
            'timezone' => 'Africa/Addis_Ababa',
            'recurrence_rule' => ['frequency' => 'daily', 'interval' => 1, 'count' => 10, 'until' => '2026-11-03'],
        ]);

        $items = $service->recurringOccurrences(
            $actor, $event->id, '2026-11-01T00:00:00+03:00', '2026-11-10T23:59:59+03:00',
        );

        $this->assertSame([
            '2026-11-01T10:00:00+03:00',
            '2026-11-02T10:00:00+03:00',
            '2026-11-03T10:00:00+03:00',
        ], array_column($items, 'starts_at'));
    }

    #[Test]
    public function all_day_recurrence_honors_inclusive_until_date(): void
    {
        $tenant = $this->tenant();
        $actor = User::factory()->create(['is_active' => true]);
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($actor, ['name' => 'Bounded observances']);
        $event = $service->createEvent($actor, [
            'calendar_id' => $calendar->id,
            'title' => 'Bounded observance',
            'all_day' => true,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-01',
            'timezone' => 'Africa/Addis_Ababa',
            'recurrence_rule' => ['frequency' => 'daily', 'interval' => 1, 'count' => 10, 'until' => '2026-11-03'],
        ]);

        $items = $service->recurringOccurrences($actor, $event->id, '2026-11-01', '2026-11-10');

        $this->assertSame(['2026-11-01', '2026-11-02', '2026-11-03'], array_column($items, 'starts_on'));
    }

    #[Test]
    public function recurrence_expansion_rejects_windows_longer_than_ninety_days(): void
    {
        $tenant = $this->tenant();
        $actor = User::factory()->create(['is_active' => true]);
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($actor, ['name' => 'Operations']);
        $event = $service->createEvent($actor, [
            'calendar_id' => $calendar->id,
            'title' => 'Weekly review',
            'starts_at' => '2026-11-01T10:00:00+03:00',
            'ends_at' => '2026-11-01T10:30:00+03:00',
            'recurrence_rule' => ['frequency' => 'weekly', 'count' => 10],
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $service->recurringOccurrences($actor, $event->id, '2026-11-01T00:00:00+03:00', '2027-03-01T00:00:00+03:00');
    }
    #[Test]
    public function timed_events_support_multiple_configurable_reminder_offsets(): void
    {
        $tenant = $this->tenant();
        $actor = User::factory()->create(['is_active' => true]);
        $attendee = User::factory()->create(['is_active' => true]);
        $attendee->tenants()->attach($tenant->id, ['status' => 'active']);
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($actor, ['name' => 'Reminder offsets']);

        $event = $service->createEvent($actor, [
            'calendar_id' => $calendar->id,
            'title' => 'Multi-reminder meeting',
            'starts_at' => '2026-11-20T10:00:00+03:00',
            'ends_at' => '2026-11-20T11:00:00+03:00',
            'attendee_user_ids' => [$attendee->id],
            'reminders' => [60, 15],
        ]);

        $rows = DB::table('calendar_reminder_outbox')
            ->where('event_id', $event->id)
            ->orderBy('deliver_at')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(['event_start_60m', 'event_start_15m'], $rows->pluck('reminder_type')->all());
        $this->assertSame(['2026-11-20 06:00:00', '2026-11-20 06:45:00'], $rows->pluck('deliver_at')->all());
        $this->assertCount(2, $rows->pluck('idempotency_key')->unique());
    }

    #[Test]
    public function cancelling_an_event_retires_its_pending_reminders_atomically(): void
    {
        $tenant = $this->tenant();
        $actor = User::factory()->create(['is_active' => true]);
        $attendee = User::factory()->create(['is_active' => true]);
        $attendee->tenants()->attach($tenant->id, ['status' => 'active']);
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($actor, ['name' => 'Reminder lifecycle']);
        $event = $service->createEvent($actor, [
            'calendar_id' => $calendar->id,
            'title' => 'Future meeting',
            'starts_at' => '2026-11-20T10:00:00+03:00',
            'ends_at' => '2026-11-20T11:00:00+03:00',
            'attendee_user_ids' => [$attendee->id],
        ]);

        $outbox = DB::table('calendar_reminder_outbox')->where('event_id', $event->id)->first();
        $this->assertNotNull($outbox);
        $this->assertSame('pending', $outbox->status);

        $service->updateEvent($actor, $event->id, ['version' => 1, 'status' => 'cancelled']);

        $this->assertSame('skipped', DB::table('calendar_reminder_outbox')->where('id', $outbox->id)->value('status'));
        $this->assertSame('cancelled', DB::table('calendar_events')->where('id', $event->id)->value('status'));
    }

    #[Test]
    public function event_and_reminder_outbox_roll_back_when_audit_persistence_fails(): void
    {
        $tenant = $this->tenant();
        $actor = User::factory()->create(['is_active' => true]);
        $actor->tenants()->attach($tenant->id, ['status' => 'active']);
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($actor, ['name' => 'Atomicity']);

        DB::listen(static function (QueryExecuted $query): void {
            $sql = strtolower(ltrim($query->sql));
            if (str_starts_with($sql, 'insert') && str_contains($sql, 'calendar_audit_entries')) {
                throw new RuntimeException('simulated calendar audit persistence failure');
            }
        });

        try {
            $service->createEvent($actor, [
                'calendar_id' => $calendar->id,
                'title' => 'Must roll back',
                'starts_at' => '2026-11-01T10:00:00+03:00',
                'ends_at' => '2026-11-01T10:30:00+03:00',
                'attendee_user_ids' => [$actor->id],
            ]);
            $this->fail('Expected audit persistence failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated calendar audit persistence failure', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('calendar_events')->where('calendar_id', $calendar->id)->count());
        $this->assertSame(0, DB::table('calendar_reminder_outbox')->where('tenant_id', $tenant->id)->count());
    }

    #[Test]
    public function event_creation_rolls_back_when_reminder_outbox_insert_fails(): void
    {
        $tenant = $this->tenant();
        $actor = User::factory()->create(['is_active' => true]);
        $actor->tenants()->attach($tenant->id, ['status' => 'active']);
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($actor, ['name' => 'Outbox atomicity']);

        DB::listen(static function (QueryExecuted $query): void {
            $sql = strtolower(ltrim($query->sql));
            if (str_starts_with($sql, 'insert') && str_contains($sql, 'calendar_reminder_outbox')) {
                throw new RuntimeException('simulated reminder outbox persistence failure');
            }
        });

        try {
            $service->createEvent($actor, [
                'calendar_id' => $calendar->id,
                'title' => 'Must roll back',
                'starts_at' => '2026-11-01T10:00:00+03:00',
                'ends_at' => '2026-11-01T10:30:00+03:00',
                'attendee_user_ids' => [$actor->id],
            ]);
            $this->fail('Expected reminder outbox persistence failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated reminder outbox persistence failure', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('calendar_events')->where('calendar_id', $calendar->id)->count());
        $this->assertSame(0, DB::table('calendar_reminder_outbox')->where('tenant_id', $tenant->id)->count());
    }

}
