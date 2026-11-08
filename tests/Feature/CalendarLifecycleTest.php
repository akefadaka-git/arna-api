<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Calendar\Services\CalendarService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use PHPUnit\Framework\Attributes\Test;
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
}
