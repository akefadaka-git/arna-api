<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Calendar\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Calendar\Models\Calendar;
use MaaleArna\MASES\Core\Calendar\Models\CalendarAttendee;
use MaaleArna\MASES\Core\Calendar\Models\CalendarEvent;
use MaaleArna\MASES\Core\Calendar\Models\CalendarEventLink;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class CalendarService
{
    public function __construct(private readonly TenantContextService $tenantContext)
    {
    }

    public function calendars(): mixed
    {
        return Calendar::query()->where('tenant_id', $this->tenantId())
            ->where('is_active', true)->orderBy('name')->paginate(50);
    }

    public function createCalendar(User $actor, array $data): Calendar
    {
        return DB::transaction(function () use ($actor, $data): Calendar {
            $tenantId = $this->tenantId();
            $id = (string) Str::uuid();
            $calendar = Calendar::query()->create([
                'id' => $id, 'tenant_id' => $tenantId, 'owner_user_id' => $actor->getKey(),
                'name' => $data['name'], 'visibility' => $data['visibility'] ?? 'tenant',
                'timezone' => $data['timezone'] ?? 'Africa/Addis_Ababa',
                'is_default' => false, 'is_active' => true, 'version' => 1,
            ]);
            $this->audit($actor, 'calendar', $id, 'created', ['name' => $calendar->name]);
            return $calendar;
        });
    }

    public function events(array $filters): mixed
    {
        return CalendarEvent::query()->where('tenant_id', $this->tenantId())
            ->when(isset($filters['from']), fn ($q) => $q->where(function ($w) use ($filters) {
                $w->where('starts_at', '>=', $filters['from'])->orWhere('starts_on', '>=', $filters['from']);
            }))
            ->when(isset($filters['to']), fn ($q) => $q->where(function ($w) use ($filters) {
                $w->where('starts_at', '<=', $filters['to'])->orWhere('starts_on', '<=', $filters['to']);
            }))
            ->with(['attendees', 'links'])->orderByRaw('COALESCE(starts_at, starts_on)')->paginate(100);
    }

    public function createEvent(User $actor, array $data): CalendarEvent
    {
        return DB::transaction(function () use ($actor, $data): CalendarEvent {
            $tenantId = $this->tenantId();
            $calendar = Calendar::query()->where('tenant_id', $tenantId)
                ->whereKey($data['calendar_id'])->lockForUpdate()->first();
            if (!$calendar) {
                throw new NotFoundHttpException('Calendar not found in the active tenant.');
            }

            $allDay = (bool) ($data['all_day'] ?? false);
            if ($allDay) {
                if (empty($data['starts_on']) || empty($data['ends_on']) || isset($data['starts_at']) || isset($data['ends_at'])) {
                    throw new BadRequestHttpException('All-day events require date-only starts_on and ends_on; instants are not accepted.');
                }
                if ($data['ends_on'] < $data['starts_on']) {
                    throw new BadRequestHttpException('Event end date must not precede its start date.');
                }
            } else {
                if (empty($data['starts_at']) || empty($data['ends_at']) || strtotime($data['ends_at']) <= strtotime($data['starts_at'])) {
                    throw new BadRequestHttpException('Timed events require ordered ISO-8601 instants with explicit UTC offsets.');
                }
                if (!preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $data['starts_at']) || !preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $data['ends_at'])) {
                    throw new BadRequestHttpException('Timed event instants must include explicit UTC offsets.');
                }
            }

            $id = (string) Str::uuid();
            $event = CalendarEvent::query()->create([
                'id' => $id, 'calendar_id' => $calendar->id, 'tenant_id' => $tenantId,
                'created_by' => $actor->getKey(), 'title' => $data['title'],
                'description' => $data['description'] ?? null, 'event_type' => $data['event_type'] ?? 'event',
                'all_day' => $allDay, 'starts_on' => $allDay ? $data['starts_on'] : null,
                'ends_on' => $allDay ? $data['ends_on'] : null,
                'starts_at' => $allDay ? null : $data['starts_at'], 'ends_at' => $allDay ? null : $data['ends_at'],
                'timezone' => $data['timezone'] ?? $calendar->timezone,
                'status' => 'scheduled', 'recurrence_rule' => $data['recurrence_rule'] ?? null, 'version' => 1,
            ]);

            foreach (array_unique($data['attendee_user_ids'] ?? []) as $userId) {
                $active = DB::table('tenant_memberships')->where('tenant_id', $tenantId)
                    ->where('user_id', $userId)->where('status', 'active')->exists();
                if (!$active) {
                    throw new BadRequestHttpException('Every attendee must be an active member of this tenant.');
                }
                CalendarAttendee::query()->create([
                    'event_id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId,
                    'response' => 'pending',
                ]);
            }

            foreach ($data['links'] ?? [] as $link) {
                CalendarEventLink::query()->create([
                    'event_id' => $id, 'tenant_id' => $tenantId,
                    'domain' => $link['domain'], 'resource_type' => $link['resource_type'],
                    'resource_id' => (string) $link['resource_id'], 'relationship' => $link['relationship'] ?? 'related',
                ]);
            }

            $this->audit($actor, 'event', $id, 'created', ['calendar_id' => $calendar->id, 'title' => $event->title]);
            return $event->load(['attendees', 'links']);
        });
    }

    public function updateEvent(User $actor, string $id, array $data): CalendarEvent
    {
        return DB::transaction(function () use ($actor, $id, $data): CalendarEvent {
            $event = CalendarEvent::query()->where('tenant_id', $this->tenantId())->whereKey($id)->lockForUpdate()->first();
            if (!$event) throw new NotFoundHttpException('Calendar event not found.');
            if ((int) ($data['version'] ?? 0) !== (int) $event->version) {
                throw new ConflictHttpException('Calendar event version conflict; reload before retrying.');
            }
            $allowed = ['title', 'description', 'status'];
            foreach ($allowed as $key) if (array_key_exists($key, $data)) $event->{$key} = $data[$key];
            $event->version++;
            $event->save();
            $this->audit($actor, 'event', $id, 'updated', ['version' => $event->version]);
            return $event->refresh()->load(['attendees', 'links']);
        });
    }

    public function reserveResource(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data): array {
            $tenantId = $this->tenantId();
            $resource = DB::table('calendar_resources')->where('tenant_id', $tenantId)
                ->where('id', $data['resource_id'])->where('is_active', true)->lockForUpdate()->first();
            if (!$resource) throw new NotFoundHttpException('Resource not found in the active tenant.');
            $event = CalendarEvent::query()->where('tenant_id', $tenantId)->whereKey($data['event_id'])->first();
            if (!$event) throw new NotFoundHttpException('Calendar event not found in the active tenant.');
            if (strtotime($data['ends_at']) <= strtotime($data['starts_at'])) {
                throw new BadRequestHttpException('Reservation end must be after start.');
            }
            $overlap = DB::table('calendar_reservations')->where('tenant_id', $tenantId)
                ->where('resource_id', $resource->id)->where('status', 'confirmed')
                ->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])
                ->lockForUpdate()->exists();
            if ($overlap) throw new ConflictHttpException('Resource is already reserved for an overlapping interval.');
            $reservationId = (string) Str::uuid();
            DB::table('calendar_reservations')->insert([
                'id' => $reservationId, 'tenant_id' => $tenantId, 'resource_id' => $resource->id,
                'event_id' => $event->id, 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'],
                'status' => 'confirmed', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($actor, 'reservation', $reservationId, 'reserved', ['resource_id' => $resource->id, 'event_id' => $event->id]);
            return (array) DB::table('calendar_reservations')->where('id', $reservationId)->first();
        }, 3);
    }

    private function tenantId(): int|string
    {
        $id = $this->tenantContext->getTenantId();
        if ($id === null) abort(400, 'TENANT_REQUIRED');
        return $id;
    }

    private function audit(User $actor, string $type, string $id, string $action, array $changes): void
    {
        DB::table('calendar_audit_entries')->insert([
            'tenant_id' => $this->tenantId(), 'actor_user_id' => $actor->getKey(),
            'aggregate_type' => $type, 'aggregate_id' => $id, 'action' => $action,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'request_id' => request()->header('X-Request-ID'), 'created_at' => now(),
        ]);
    }
}
