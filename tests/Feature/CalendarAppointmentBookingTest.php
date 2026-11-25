<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\RequestTenantResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CalendarAppointmentBookingTest extends TestCase
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
            $permission = Permission::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name_en' => $slug,
                    'category' => 'calendar',
                    'scope' => 'tenant',
                    'tenant_id' => $tenant->id,
                    'is_active' => true,
                    'is_system' => false,
                ],
            );
            $user->permissions()->attach($permission->id, [
                'assigned_at' => now(),
                'assigned_by' => $user->id,
            ]);
        }
    }

    private function calendar(Tenant $tenant, User $requester): string
    {
        $this->grant($requester, $tenant, 'calendar.view', 'calendar.create', 'calendar.update');
        Sanctum::actingAs($requester);

        return (string) $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/calendars', [
                'name' => 'Shared appointment calendar',
                'visibility' => 'tenant',
                'timezone' => 'Africa/Addis_Ababa',
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function bookingPayload(string $calendarId, User $host, array $extra = []): array
    {
        return array_merge([
            'calendar_id' => $calendarId,
            'appointment_scope' => 'personal',
            'host_user_id' => $host->id,
            'title' => 'Consultation appointment',
            'description' => 'Shared appointment booking test',
            'starts_at' => '2026-11-26T10:00:00+03:00',
            'ends_at' => '2026-11-26T11:00:00+03:00',
            'timezone' => 'Africa/Addis_Ababa',
        ], $extra);
    }

    #[Test]
    public function shared_personal_appointment_can_be_requested_confirmed_and_cancelled(): void
    {
        $tenant = $this->tenant();
        $requester = $this->member($tenant);
        $host = $this->member($tenant);
        $attendee = $this->member($tenant);
        $calendarId = $this->calendar($tenant, $requester);
        $this->grant($host, $tenant, 'calendar.view', 'calendar.update');

        Sanctum::actingAs($requester);
        $payload = $this->bookingPayload($calendarId, $host, [
            'attendee_user_ids' => [$attendee->id],
        ]);

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/availability', $payload)
            ->assertOk()
            ->assertJsonPath('data.available', true);

        $created = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.appointment_scope', 'personal')
            ->assertJsonPath('data.event.event_type', 'appointment');

        $appointmentId = $created->json('data.id');
        $eventId = $created->json('data.event_id');

        $this->assertDatabaseHas('calendar_appointments', [
            'id' => $appointmentId,
            'tenant_id' => $tenant->id,
            'event_id' => $eventId,
            'requested_by_user_id' => $requester->id,
            'host_user_id' => $host->id,
            'status' => 'requested',
        ]);
        $this->assertDatabaseHas('calendar_attendees', [
            'event_id' => $eventId,
            'user_id' => $host->id,
            'response' => 'pending',
        ]);

        Sanctum::actingAs($host);
        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/'.$appointmentId.'/confirm')
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        Sanctum::actingAs($requester);
        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/calendar/appointments')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $appointmentId);

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/'.$appointmentId.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('calendar_events', ['id' => $eventId, 'status' => 'cancelled']);
        $this->assertSame(0, DB::table('calendar_reminder_outbox')
            ->where('event_id', $eventId)->where('status', 'pending')->count());
    }

    #[Test]
    public function appointment_availability_detects_participant_conflicts_and_booking_fails_closed(): void
    {
        $tenant = $this->tenant();
        $requester = $this->member($tenant);
        $host = $this->member($tenant);
        $calendarId = $this->calendar($tenant, $requester);

        Sanctum::actingAs($requester);
        $payload = $this->bookingPayload($calendarId, $host);
        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments', $payload)
            ->assertCreated();

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/availability', $payload)
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.participant_conflict_count', 1);

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments', $payload)
            ->assertStatus(409);

        $this->assertSame(1, DB::table('calendar_appointments')->where('tenant_id', $tenant->id)->count());
    }

    #[Test]
    public function host_can_decline_and_foreign_tenant_members_cannot_be_invited(): void
    {
        $tenant = $this->tenant();
        $foreignTenant = $this->tenant();
        $requester = $this->member($tenant);
        $host = $this->member($tenant);
        $foreign = $this->member($foreignTenant);
        $calendarId = $this->calendar($tenant, $requester);
        $this->grant($host, $tenant, 'calendar.update');

        Sanctum::actingAs($requester);
        $payload = $this->bookingPayload($calendarId, $host);
        $created = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments', $payload)
            ->assertCreated();
        $appointmentId = $created->json('data.id');

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments', $this->bookingPayload($calendarId, $host, [
                'attendee_user_ids' => [$foreign->id],
                'starts_at' => '2026-11-27T10:00:00+03:00',
                'ends_at' => '2026-11-27T11:00:00+03:00',
            ]))
            ->assertStatus(400);

        Sanctum::actingAs($host);
        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/'.$appointmentId.'/decline')
            ->assertOk()
            ->assertJsonPath('data.status', 'declined');
    }

    #[Test]
    public function corporate_appointments_require_a_tenant_visible_calendar(): void
    {
        $tenant = $this->tenant();
        $requester = $this->member($tenant);
        $host = $this->member($tenant);
        $this->grant($requester, $tenant, 'calendar.view', 'calendar.create', 'calendar.update');
        Sanctum::actingAs($requester);

        $privateCalendar = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/calendars', [
                'name' => 'Private appointment calendar',
                'visibility' => 'private',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments', $this->bookingPayload($privateCalendar, $host, [
                'appointment_scope' => 'corporate',
            ]))
            ->assertStatus(400);

        $this->assertSame(0, DB::table('calendar_appointments')->where('tenant_id', $tenant->id)->count());
    }
}
