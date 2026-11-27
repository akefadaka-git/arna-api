<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Calendar\Services\CalendarService;
use MaaleArna\Arna\Core\Domains\Task\Services\TaskService;
use MaaleArna\Arna\Core\Tenancy\Models\Tenant;
use MaaleArna\Arna\Core\Tenancy\Services\TenantContextService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CalendarTaskLinkTest extends TestCase
{
    use RefreshDatabase;

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

    private function services(Tenant $tenant): array
    {
        app(TenantContextService::class)->setTenantById($tenant->id);

        return [app(TaskService::class), app(CalendarService::class)];
    }

    #[Test]
    public function task_creator_can_link_the_authoritative_task_to_a_calendar_event(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        [$tasks, $calendar] = $this->services($tenant);

        $task = $tasks->create($owner, [
            'task_scope' => 'personal',
            'title' => 'Submit monthly report',
        ]);
        $calendarRecord = $calendar->createCalendar($owner, ['name' => 'Work']);
        $event = $calendar->createEvent($owner, [
            'calendar_id' => $calendarRecord->id,
            'title' => 'Monthly report deadline review',
            'starts_at' => '2026-12-01T09:00:00+03:00',
            'ends_at' => '2026-12-01T09:30:00+03:00',
            'timezone' => 'Africa/Addis_Ababa',
            'links' => [[
                'domain' => 'task',
                'resource_type' => 'task',
                'resource_id' => $task->id,
                'relationship' => 'context',
            ]],
        ]);

        $this->assertSame($task->id, $event->links->first()->resource_id);
        $this->assertSame('task', $event->links->first()->domain);

        $listed = $calendar->events($owner, []);
        $this->assertSame($task->id, $listed->items()[0]->links->first()->resource_id);
    }

    #[Test]
    public function calendar_cannot_link_a_task_the_actor_is_not_authorized_to_view(): void
    {
        $tenant = $this->tenant();
        $taskOwner = $this->member($tenant);
        $other = $this->member($tenant);
        [$tasks, $calendar] = $this->services($tenant);

        $task = $tasks->create($taskOwner, [
            'task_scope' => 'personal',
            'title' => 'Private personal task',
        ]);
        $calendarRecord = $calendar->createCalendar($other, ['name' => 'Other calendar']);

        try {
            $calendar->createEvent($other, [
                'calendar_id' => $calendarRecord->id,
                'title' => 'Unauthorized task reference',
                'starts_at' => '2026-12-01T09:00:00+03:00',
                'ends_at' => '2026-12-01T09:30:00+03:00',
                'timezone' => 'Africa/Addis_Ababa',
                'links' => [[
                    'domain' => 'task',
                    'resource_type' => 'task',
                    'resource_id' => $task->id,
                    'relationship' => 'context',
                ]],
            ]);
            $this->fail('Calendar must not bypass D08 task visibility.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('calendar_events')
                ->where('tenant_id', $tenant->id)
                ->where('title', 'Unauthorized task reference')
                ->count());
            $this->assertSame(0, DB::table('calendar_event_links')
                ->where('tenant_id', $tenant->id)
                ->where('resource_id', $task->id)
                ->where('domain', 'task')
                ->count());
        }
    }
}
