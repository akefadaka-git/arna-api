<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Calendar\Services\CalendarService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\RequestTenantResolver;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Tests\TestCase;

final class CalendarHttpAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('mases.tenant.isolation_enabled', true);
        Config::set('mases.tenant.context_resolver', RequestTenantResolver::class);
    }

    private function tenant(): Tenant
    {
        return Tenant::factory()->create(['is_active' => true]);
    }

    private function member(Tenant $tenant): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->tenants()->attach($tenant->id, ['status' => 'active']);
        return $user;
    }

    private function grant(User $user, Tenant $tenant, string ...$slugs): void
    {
        foreach ($slugs as $slug) {
            $permission = Permission::query()->create([
                'name_en' => $slug,
                'slug' => $slug,
                'category' => 'calendar',
                'scope' => 'tenant',
                'tenant_id' => $tenant->id,
                'is_active' => true,
                'is_system' => false,
            ]);
            $user->permissions()->attach($permission->id, [
                'assigned_at' => now(),
                'assigned_by' => $user->id,
            ]);
        }
    }

    private function service(Tenant $tenant): CalendarService
    {
        app(TenantContextService::class)->setTenantById($tenant->id);
        return app(CalendarService::class);
    }

    public function test_calendar_http_routes_deny_member_without_calendar_permission(): void
    {
        $tenant = $this->tenant();
        $actor = $this->member($tenant);
        Sanctum::actingAs($actor);

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/calendar/calendars')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'AUTH_PERMISSION_DENIED');
    }

    public function test_calendar_http_route_allows_active_member_with_tenant_scoped_view_permission(): void
    {
        $tenant = $this->tenant();
        $actor = $this->member($tenant);
        $this->grant($actor, $tenant, 'calendar.view');
        $calendar = $this->service($tenant)->createCalendar($actor, ['name' => 'Visible calendar', 'visibility' => 'tenant']);
        Sanctum::actingAs($actor);

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/calendar/calendars')
            ->assertOk()
            ->assertSee($calendar->id);
    }

    public function test_all_day_event_accepts_ethiopian_civil_dates_through_canonical_calendar_conversion(): void
    {
        $tenant = $this->tenant();
        $actor = $this->member($tenant);
        $this->grant($actor, $tenant, 'calendar.create');
        $calendar = $this->service($tenant)->createCalendar($actor, ['name' => 'Ethiopian dates']);
        Sanctum::actingAs($actor);

        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/events', [
                'calendar_id' => $calendar->id,
                'title' => 'Meskerem New Year',
                'all_day' => true,
                'starts_on_ec' => ['year' => 2016, 'month' => 1, 'day' => 1],
                'ends_on_ec' => ['year' => 2016, 'month' => 1, 'day' => 1],
            ])
            ->assertCreated()
            ->assertJsonPath('data.all_day', true)
            ->assertJsonPath('data.starts_on_ec.iso', '2016-01-01')
            ->assertJsonPath('data.ends_on_ec.iso', '2016-01-01');

        $eventId = $response->json('data.id');
        $this->assertSame('2023-09-12', DB::table('calendar_events')->where('id', $eventId)->value('starts_on'));
        $this->assertSame('2023-09-12', DB::table('calendar_events')->where('id', $eventId)->value('ends_on'));
        $this->assertNull(DB::table('calendar_events')->where('id', $eventId)->value('starts_at'));
    }

    public function test_ethiopian_event_filters_and_recurrence_ranges_are_supported_over_http(): void
    {
        $tenant = $this->tenant();
        $actor = $this->member($tenant);
        $this->grant($actor, $tenant, 'calendar.create', 'calendar.view', 'calendar.update');
        $calendar = $this->service($tenant)->createCalendar($actor, ['name' => 'E.C. API calendar']);
        Sanctum::actingAs($actor);

        $created = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/events', [
                'calendar_id' => $calendar->id,
                'title' => 'Pagume monthly observance',
                'all_day' => true,
                'starts_on_ec' => ['year' => 2015, 'month' => 13, 'day' => 6],
                'ends_on_ec' => ['year' => 2015, 'month' => 13, 'day' => 6],
                'recurrence_rule' => [
                    'frequency' => 'monthly',
                    'calendar_system' => 'ethiopian',
                    'count' => 3,
                    'until_ec' => ['year' => 2016, 'month' => 2, 'day' => 6],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.starts_on_ec.iso', '2015-13-06');

        $eventId = $created->json('data.id');

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/calendar/events?from_ec[year]=2015&from_ec[month]=13&from_ec[day]=1&to_ec[year]=2015&to_ec[month]=13&to_ec[day]=6')
            ->assertOk()
            ->assertJsonPath('data.data.0.starts_on_ec.iso', '2015-13-06');

        $occurrences = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/calendar/events/'.$eventId.'/occurrences?from_ec[year]=2015&from_ec[month]=13&from_ec[day]=1&to_ec[year]=2016&to_ec[month]=2&to_ec[day]=10')
            ->assertOk();

        $this->assertSame(
            ['2015-13-06', '2016-01-06', '2016-02-06'],
            array_map(fn (array $item): string => $item['starts_on_ec']['iso'], $occurrences->json('data')),
        );

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/events/'.$eventId.'/occurrences/cancel', [
                'original_starts_on_ec' => ['year' => 2016, 'month' => 1, 'day' => 6],
            ])
            ->assertOk()
            ->assertJsonPath('data.cancelled', true);

        $afterCancellation = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/calendar/events/'.$eventId.'/occurrences?from_ec[year]=2015&from_ec[month]=13&from_ec[day]=1&to_ec[year]=2016&to_ec[month]=2&to_ec[day]=10')
            ->assertOk();
        $this->assertSame(
            ['2015-13-06', '2016-02-06'],
            array_map(fn (array $item): string => $item['starts_on_ec']['iso'], $afterCancellation->json('data')),
        );

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/events/'.$eventId.'/occurrences/move', [
                'original_starts_on_ec' => ['year' => 2015, 'month' => 13, 'day' => 6],
                'override_starts_on_ec' => ['year' => 2016, 'month' => 1, 'day' => 8],
                'override_ends_on_ec' => ['year' => 2016, 'month' => 1, 'day' => 9],
            ])
            ->assertOk()
            ->assertJsonPath('data.overridden', true);

        $afterMove = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/calendar/events/'.$eventId.'/occurrences?from_ec[year]=2015&from_ec[month]=13&from_ec[day]=1&to_ec[year]=2016&to_ec[month]=2&to_ec[day]=10')
            ->assertOk();
        $this->assertSame(
            ['2016-01-08', '2016-02-06'],
            array_map(fn (array $item): string => $item['starts_on_ec']['iso'], $afterMove->json('data')),
        );
    }

    public function test_ethiopian_event_filters_include_the_entire_addis_civil_day_for_timed_events(): void
    {
        $tenant = $this->tenant();
        $actor = $this->member($tenant);
        $this->grant($actor, $tenant, 'calendar.create', 'calendar.view');
        $calendar = $this->service($tenant)->createCalendar($actor, ['name' => 'E.C. timed filter']);
        Sanctum::actingAs($actor);

        $created = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/events', [
                'calendar_id' => $calendar->id,
                'title' => 'Late Addis meeting',
                'starts_at' => '2023-09-12T23:30:00+03:00',
                'ends_at' => '2023-09-13T00:30:00+03:00',
            ])
            ->assertCreated();

        $eventId = $created->json('data.id');

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/calendar/events?from_ec[year]=2016&from_ec[month]=1&from_ec[day]=1&to_ec[year]=2016&to_ec[month]=1&to_ec[day]=1')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $eventId);
    }

    public function test_private_calendar_is_not_visible_to_another_member_over_http(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $actor = $this->member($tenant);
        $this->grant($actor, $tenant, 'calendar.view');
        $privateCalendar = $this->service($tenant)->createCalendar($owner, [
            'name' => 'Owner private',
            'visibility' => 'private',
        ]);
        Sanctum::actingAs($actor);

        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/calendar/calendars')
            ->assertOk();
        $this->assertStringNotContainsString($privateCalendar->id, $response->getContent());
    }

    public function test_invited_attendee_can_view_private_event_without_browsing_private_calendar(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $attendee = $this->member($tenant);
        $this->grant($attendee, $tenant, 'calendar.view');
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($owner, ['name' => 'Private board calendar', 'visibility' => 'private']);
        $event = $service->createEvent($owner, [
            'calendar_id' => $calendar->id,
            'title' => 'Invited private session',
            'starts_at' => '2026-11-20T10:00:00+03:00',
            'ends_at' => '2026-11-20T11:00:00+03:00',
            'attendee_user_ids' => [$attendee->id],
        ]);
        Sanctum::actingAs($attendee);

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/calendar/calendars')
            ->assertOk()
            ->assertJsonMissing(['id' => $calendar->id]);

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/calendar/events')
            ->assertOk()
            ->assertSee($event->id)
            ->assertSee('Invited private session');
    }

    public function test_cross_tenant_event_update_is_hidden_as_not_found_over_http(): void
    {
        $tenantA = $this->tenant();
        $tenantB = $this->tenant();
        $actor = $this->member($tenantA);
        $owner = $this->member($tenantB);
        $this->grant($actor, $tenantA, 'calendar.update');
        $serviceB = $this->service($tenantB);
        $calendar = $serviceB->createCalendar($owner, ['name' => 'Foreign tenant']);
        $event = $serviceB->createEvent($owner, [
            'calendar_id' => $calendar->id,
            'title' => 'Foreign event',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T10:30:00+03:00',
        ]);
        Sanctum::actingAs($actor);

        $this->withHeader('X-Tenant-ID', (string) $tenantA->id)
            ->patchJson('/api/calendar/events/'.$event->id, [
                'version' => 1,
                'title' => 'Unauthorized update',
            ])
            ->assertNotFound();
    }

    public function test_private_calendar_event_update_is_hidden_from_another_member_over_http(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $actor = $this->member($tenant);
        $this->grant($actor, $tenant, 'calendar.update');
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($owner, ['name' => 'Private events', 'visibility' => 'private']);
        $event = $service->createEvent($owner, [
            'calendar_id' => $calendar->id,
            'title' => 'Owner-only event',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T10:30:00+03:00',
        ]);
        Sanctum::actingAs($actor);

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->patchJson('/api/calendar/events/'.$event->id, ['version' => 1, 'title' => 'Private overwrite'])
            ->assertNotFound();
    }

    public function test_private_calendar_reservation_is_hidden_from_another_member_over_http(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $actor = $this->member($tenant);
        $this->grant($actor, $tenant, 'calendar.reserve');
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($owner, ['name' => 'Private room schedule', 'visibility' => 'private']);
        $event = $service->createEvent($owner, [
            'calendar_id' => $calendar->id,
            'title' => 'Private room meeting',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T10:30:00+03:00',
        ]);
        $resourceId = (string) Str::uuid();
        DB::table('calendar_resources')->insert([
            'id' => $resourceId,
            'tenant_id' => $tenant->id,
            'name' => 'Private room',
            'resource_type' => 'room',
            'capacity' => 8,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($actor);

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/reservations', [
                'resource_id' => $resourceId,
                'event_id' => $event->id,
                'starts_at' => '2026-11-02T10:00:00+03:00',
                'ends_at' => '2026-11-02T10:30:00+03:00',
            ])
            ->assertNotFound();
    }

    public function test_private_calendar_recurrence_expansion_is_hidden_from_another_member_over_http(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $actor = $this->member($tenant);
        $this->grant($actor, $tenant, 'calendar.view');
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($owner, ['name' => 'Private recurrence', 'visibility' => 'private']);
        $event = $service->createEvent($owner, [
            'calendar_id' => $calendar->id,
            'title' => 'Private recurring event',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T10:30:00+03:00',
            'recurrence_rule' => ['frequency' => 'daily', 'interval' => 1, 'count' => 3],
        ]);
        Sanctum::actingAs($actor);

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/calendar/events/'.$event->id.'/occurrences?from=2026-11-02T00:00:00%2B03:00&to=2026-11-04T00:00:00%2B03:00')
            ->assertNotFound();
    }

    public function test_attendee_response_cannot_be_submitted_for_another_users_invitation(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $actor = $this->member($tenant);
        $this->grant($actor, $tenant, 'calendar.view');
        $service = $this->service($tenant);
        $calendar = $service->createCalendar($owner, ['name' => 'Team calendar', 'visibility' => 'tenant']);
        $event = $service->createEvent($owner, [
            'calendar_id' => $calendar->id,
            'title' => 'Invitation ownership',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T10:30:00+03:00',
            'attendee_user_ids' => [$owner->id],
        ]);
        Sanctum::actingAs($actor);

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/events/'.$event->id.'/response', ['response' => 'accepted'])
            ->assertNotFound();
    }
}
