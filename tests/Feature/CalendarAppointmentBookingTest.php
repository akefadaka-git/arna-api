<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Calendar\Services\CalendarReminderDispatcher;
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
    public function all_day_calendar_holds_block_overlapping_appointment_requests(): void
    {
        $tenant = $this->tenant();
        $requester = $this->member($tenant);
        $host = $this->member($tenant);
        $calendarId = $this->calendar($tenant, $requester);

        Sanctum::actingAs($requester);
        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/events', [
                'calendar_id' => $calendarId,
                'title' => 'Host all-day commitment',
                'all_day' => true,
                'starts_on' => '2026-11-26',
                'ends_on' => '2026-11-26',
                'attendee_user_ids' => [$host->id],
            ])
            ->assertCreated();

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/availability', $this->bookingPayload($calendarId, $host))
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.participant_conflict_count', 1);
    }

    #[Test]
    public function resource_overlap_is_checked_across_personal_and_corporate_bookings(): void
    {
        $tenant = $this->tenant();
        $requesterA = $this->member($tenant);
        $hostA = $this->member($tenant);
        $requesterB = $this->member($tenant);
        $hostB = $this->member($tenant);
        $calendarId = $this->calendar($tenant, $requesterA);
        $this->grant($requesterB, $tenant, 'calendar.view', 'calendar.create', 'calendar.update');

        Sanctum::actingAs($requesterA);
        $resourceId = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/resources', [
                'name' => 'Consultation room',
                'resource_type' => 'room',
                'capacity' => 1,
            ])
            ->assertCreated()
            ->json('data.id');

        $firstPayload = $this->bookingPayload($calendarId, $hostA, [
            'resource_ids' => [$resourceId],
        ]);
        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments', $firstPayload)
            ->assertCreated();

        Sanctum::actingAs($requesterB);
        $secondPayload = $this->bookingPayload($calendarId, $hostB, [
            'resource_ids' => [$resourceId],
            'appointment_scope' => 'corporate',
            'starts_at' => '2026-11-26T10:15:00+03:00',
            'ends_at' => '2026-11-26T11:15:00+03:00',
        ]);
        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/availability', $secondPayload)
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.participant_conflict_count', 0)
            ->assertJsonPath('data.resource_conflict_count', 1);

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments', $secondPayload)
            ->assertStatus(409);
        $this->assertSame(1, DB::table('calendar_appointments')->where('tenant_id', $tenant->id)->count());
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

    #[Test]
    public function confirmed_appointment_can_be_rescheduled_by_proposal_and_counterparty_acceptance(): void
    {
        $tenant = $this->tenant();
        $requester = $this->member($tenant);
        $host = $this->member($tenant);
        $attendee = $this->member($tenant);
        $calendarId = $this->calendar($tenant, $requester);
        $this->grant($host, $tenant, 'calendar.view', 'calendar.update');

        Sanctum::actingAs($requester);
        $created = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments', $this->bookingPayload($calendarId, $host, [
                'attendee_user_ids' => [$attendee->id],
                'reminders' => [15, 60],
            ]))->assertCreated();
        $appointmentId = $created->json('data.id');
        $eventId = $created->json('data.event_id');

        Sanctum::actingAs($host);
        $confirmed = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/'.$appointmentId.'/confirm')
            ->assertOk()->assertJsonPath('data.status', 'confirmed');
        $appointmentVersion = $confirmed->json('data.version');
        $eventVersion = $confirmed->json('data.event.version');
        $originalStart = $confirmed->json('data.event.starts_at');

        Sanctum::actingAs($requester);
        $proposal = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/'.$appointmentId.'/reschedule-proposals', [
                'starts_at' => '2026-11-28T10:00:00+03:00',
                'ends_at' => '2026-11-28T11:00:00+03:00',
                'expected_appointment_version' => $appointmentVersion,
                'expected_event_version' => $eventVersion,
                'reason' => 'Schedule adjustment',
            ])->assertCreated()->assertJsonPath('data.status', 'pending');
        $proposalId = $proposal->json('data.id');

        $this->assertSame(3, DB::table('calendar_appointment_notification_outbox')
            ->where('proposal_id', $proposalId)->where('action', 'proposed')->where('status', 'pending')->count());
        $this->assertDatabaseHas('calendar_events', ['id' => $eventId, 'starts_at' => $originalStart]);
        $this->assertDatabaseHas('calendar_appointment_reschedule_proposals', [
            'id' => $proposalId, 'appointment_id' => $appointmentId, 'status' => 'pending',
        ]);

        Sanctum::actingAs($host);
        $accepted = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/'.$appointmentId.'/reschedule-proposals/'.$proposalId.'/accept')
            ->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertSame($eventId, $accepted->json('data.event_id'));
        $this->assertSame(3, (int) $accepted->json('data.version'));
        $this->assertSame(2, (int) $accepted->json('data.event.version'));
        $this->assertSame('2026-11-28T07:00:00.000000Z', $accepted->json('data.event.starts_at'));

        $this->assertDatabaseHas('calendar_appointment_reschedule_proposals', [
            'id' => $proposalId, 'status' => 'accepted', 'responded_by_user_id' => $host->id,
        ]);
        $this->assertSame(3, DB::table('calendar_appointment_notification_outbox')
            ->where('proposal_id', $proposalId)->where('action', 'accepted')->where('status', 'pending')->count());
        $dispatch = app(CalendarReminderDispatcher::class)->dispatch(100);
        $this->assertSame(6, $dispatch['delivered']);
        $this->assertSame(6, DB::table('calendar_appointment_notification_outbox')
            ->where('proposal_id', $proposalId)->where('status', 'delivered')->count());
        $this->assertSame(0, DB::table('calendar_reminder_outbox')->where('event_id', $eventId)
            ->where('status', 'pending')->where('idempotency_key', $eventId.':'.$host->id.':event_start_15m')->count());
        $this->assertGreaterThan(0, DB::table('calendar_reminder_outbox')->where('event_id', $eventId)
            ->where('status', 'pending')->where('idempotency_key', 'like', '%:reschedule:v2')->count());
    }

    #[Test]
    public function only_one_pending_proposal_is_allowed_and_declined_or_withdrawn_proposals_preserve_the_booking(): void
    {
        $tenant = $this->tenant();
        $requester = $this->member($tenant);
        $host = $this->member($tenant);
        $calendarId = $this->calendar($tenant, $requester);
        $this->grant($host, $tenant, 'calendar.view', 'calendar.update');

        Sanctum::actingAs($requester);
        $created = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments', $this->bookingPayload($calendarId, $host))
            ->assertCreated();
        $appointmentId = $created->json('data.id');
        $eventId = $created->json('data.event_id');

        Sanctum::actingAs($host);
        $confirmed = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/'.$appointmentId.'/confirm')->assertOk();
        $appointmentVersion = $confirmed->json('data.version');
        $eventVersion = $confirmed->json('data.event.version');

        Sanctum::actingAs($requester);
        $payload = [
            'starts_at' => '2026-11-29T10:00:00+03:00',
            'ends_at' => '2026-11-29T11:00:00+03:00',
            'expected_appointment_version' => $appointmentVersion,
            'expected_event_version' => $eventVersion,
        ];
        $proposal = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/'.$appointmentId.'/reschedule-proposals', $payload)
            ->assertCreated();
        $proposalId = $proposal->json('data.id');

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/'.$appointmentId.'/reschedule-proposals', $payload)
            ->assertStatus(409);

        Sanctum::actingAs($host);
        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/'.$appointmentId.'/reschedule-proposals/'.$proposalId.'/decline')
            ->assertOk()->assertJsonPath('data.status', 'declined');

        Sanctum::actingAs($requester);
        $second = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/'.$appointmentId.'/reschedule-proposals', $payload)
            ->assertCreated();
        $secondId = $second->json('data.id');
        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/'.$appointmentId.'/reschedule-proposals/'.$secondId.'/withdraw')
            ->assertOk()->assertJsonPath('data.status', 'withdrawn');

        $this->assertDatabaseHas('calendar_appointments', ['id' => $appointmentId, 'status' => 'confirmed']);
        $this->assertDatabaseHas('calendar_events', ['id' => $eventId, 'starts_at' => '2026-11-26 07:00:00']);
    }

    #[Test]
    public function stale_proposal_cannot_overwrite_a_changed_calendar_event(): void
    {
        $tenant = $this->tenant();
        $requester = $this->member($tenant);
        $host = $this->member($tenant);
        $calendarId = $this->calendar($tenant, $requester);
        $this->grant($host, $tenant, 'calendar.view', 'calendar.update');

        Sanctum::actingAs($requester);
        $created = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments', $this->bookingPayload($calendarId, $host))
            ->assertCreated();
        $appointmentId = $created->json('data.id');
        $eventId = $created->json('data.event_id');

        Sanctum::actingAs($host);
        $confirmed = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/'.$appointmentId.'/confirm')->assertOk();
        $appointmentVersion = $confirmed->json('data.version');
        $eventVersion = $confirmed->json('data.event.version');

        Sanctum::actingAs($requester);
        $proposal = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/'.$appointmentId.'/reschedule-proposals', [
                'starts_at' => '2026-11-30T10:00:00+03:00',
                'ends_at' => '2026-11-30T11:00:00+03:00',
                'expected_appointment_version' => $appointmentVersion,
                'expected_event_version' => $eventVersion,
            ])->assertCreated();
        $proposalId = $proposal->json('data.id');
        $oldStart = $confirmed->json('data.event.starts_at');

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->patchJson('/api/calendar/events/'.$eventId, [
                'version' => $eventVersion,
                'title' => 'Updated appointment title',
            ])->assertOk();

        Sanctum::actingAs($host);
        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/calendar/appointments/'.$appointmentId.'/reschedule-proposals/'.$proposalId.'/accept')
            ->assertStatus(409);

        $this->assertDatabaseHas('calendar_events', ['id' => $eventId, 'starts_at' => $oldStart]);
        $this->assertDatabaseHas('calendar_appointment_reschedule_proposals', ['id' => $proposalId, 'status' => 'pending']);
    }

}
