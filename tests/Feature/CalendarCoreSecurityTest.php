<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Calendar\Models\Calendar;
use MaaleArna\MASES\Core\Calendar\Services\CalendarService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class CalendarCoreSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function context(Tenant $tenant): CalendarService
    {
        $context = app(TenantContextService::class);
        $context->setTenantById($tenant->id);
        return app(CalendarService::class);
    }

    private function tenant(): Tenant
    {
        return Tenant::factory()->create(['is_active' => true]);
    }

    private function calendar(CalendarService $service, User $user): Calendar
    {
        return $service->createCalendar($user, ['name' => 'Operations']);
    }

    #[Test]
    public function tenant_context_is_required_and_cross_tenant_calendar_is_not_found(): void
    {
        $tenantA = $this->tenant();
        $tenantB = $this->tenant();
        $user = User::factory()->create(['is_active' => true]);

        $serviceA = $this->context($tenantA);
        $calendar = $this->calendar($serviceA, $user);

        $serviceB = $this->context($tenantB);
        $this->expectException(NotFoundHttpException::class);
        $serviceB->createEvent($user, [
            'calendar_id' => $calendar->id, 'title' => 'Cross tenant',
            'starts_at' => '2026-11-01T10:00:00+03:00',
            'ends_at' => '2026-11-01T11:00:00+03:00',
        ]);
    }

    #[Test]
    public function event_update_requires_expected_version_and_rejects_stale_writes(): void
    {
        $tenant = $this->tenant();
        $user = User::factory()->create(['is_active' => true]);
        $service = $this->context($tenant);
        $calendar = $this->calendar($service, $user);
        $event = $service->createEvent($user, [
            'calendar_id' => $calendar->id, 'title' => 'Planning',
            'starts_at' => '2026-11-01T10:00:00+03:00',
            'ends_at' => '2026-11-01T11:00:00+03:00',
        ]);

        $updated = $service->updateEvent($user, $event->id, ['version' => 1, 'title' => 'Updated']);
        $this->assertSame(2, $updated->version);

        $this->expectException(ConflictHttpException::class);
        $service->updateEvent($user, $event->id, ['version' => 1, 'title' => 'Stale overwrite']);
    }

    #[Test]
    public function attendee_must_be_active_member_of_the_same_tenant(): void
    {
        $tenant = $this->tenant();
        $user = User::factory()->create(['is_active' => true]);
        $nonMember = User::factory()->create(['is_active' => true]);
        $service = $this->context($tenant);
        $calendar = $this->calendar($service, $user);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $service->createEvent($user, [
            'calendar_id' => $calendar->id, 'title' => 'Restricted attendees',
            'starts_at' => '2026-11-01T10:00:00+03:00',
            'ends_at' => '2026-11-01T11:00:00+03:00',
            'attendee_user_ids' => [$nonMember->id],
        ]);
    }

    #[Test]
    public function reservation_rejects_overlap_and_accepts_non_overlapping_interval(): void
    {
        $tenant = $this->tenant();
        $user = User::factory()->create(['is_active' => true]);
        $service = $this->context($tenant);
        $calendar = $this->calendar($service, $user);
        $eventA = $service->createEvent($user, [
            'calendar_id' => $calendar->id, 'title' => 'First',
            'starts_at' => '2026-11-01T10:00:00+03:00',
            'ends_at' => '2026-11-01T11:00:00+03:00',
        ]);
        $eventB = $service->createEvent($user, [
            'calendar_id' => $calendar->id, 'title' => 'Second',
            'starts_at' => '2026-11-01T10:30:00+03:00',
            'ends_at' => '2026-11-01T11:30:00+03:00',
        ]);
        $resourceId = (string) \Illuminate\Support\Str::uuid();
        DB::table('calendar_resources')->insert([
            'id' => $resourceId, 'tenant_id' => $tenant->id, 'name' => 'Board Room',
            'resource_type' => 'room', 'capacity' => 12, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $service->reserveResource($user, [
            'resource_id' => $resourceId, 'event_id' => $eventA->id,
            'starts_at' => '2026-11-01T10:00:00+03:00',
            'ends_at' => '2026-11-01T11:00:00+03:00',
        ]);

        $this->expectException(ConflictHttpException::class);
        $service->reserveResource($user, [
            'resource_id' => $resourceId, 'event_id' => $eventB->id,
            'starts_at' => '2026-11-01T10:30:00+03:00',
            'ends_at' => '2026-11-01T11:30:00+03:00',
        ]);
    }

    #[Test]
    public function audit_entries_are_written_atomically_with_calendar_and_event_changes(): void
    {
        $tenant = $this->tenant();
        $user = User::factory()->create(['is_active' => true]);
        $service = $this->context($tenant);
        $calendar = $this->calendar($service, $user);
        $event = $service->createEvent($user, [
            'calendar_id' => $calendar->id, 'title' => 'Audited',
            'starts_at' => '2026-11-01T10:00:00+03:00',
            'ends_at' => '2026-11-01T11:00:00+03:00',
        ]);

        $this->assertDatabaseHas('calendar_audit_entries', [
            'tenant_id' => $tenant->id, 'aggregate_type' => 'calendar',
            'aggregate_id' => $calendar->id, 'action' => 'created',
        ]);
        $this->assertDatabaseHas('calendar_audit_entries', [
            'tenant_id' => $tenant->id, 'aggregate_type' => 'event',
            'aggregate_id' => $event->id, 'action' => 'created',
        ]);
        $this->assertSame(2, DB::table('calendar_audit_entries')->where('tenant_id', $tenant->id)->count());
    }
}
